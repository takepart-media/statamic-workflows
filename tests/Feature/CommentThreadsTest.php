<?php

use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Mail\CommentMentionMail;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowComment;
use TakepartMedia\Workflows\Workflow\WorkflowCommentStore;
use TakepartMedia\Workflows\Workflow\WorkflowEvent;

/*
 * Word-like comment threads: replies, edit, resolve/reopen, mentions + mail,
 * and the counts that must ignore resolved threads.
 */

function cpUser(string $name, bool $super = false, bool $cp = true)
{
    $user = User::make()->email(str($name)->slug().'@example.com')->set('name', $name);

    if ($super) {
        $user->makeSuper();
    } elseif ($cp) {
        $user->assignRole('editor');
    }

    $user->save();

    return User::find($user->id());
}

function commentsUrl(string $route, string $id, array $params = []): string
{
    return cp_route($route, ['id' => $id, 'workflow' => 'simple'] + $params);
}

beforeEach(function () {
    config()->set('statamic.users.repositories.file.paths.roles', $this->tempPath('users/roles.yaml'));
    config()->set('statamic.users.repositories.file.paths.groups', $this->tempPath('users/groups.yaml'));
    config()->set('workflows.mail_delivery', 'sync');
    config()->set('workflows.comment_moderator_groups', []);
    Role::make('editor')->title('Editor')->permissions(['access cp'])->save();

    $this->makeCollection('pages', ['body' => ['type' => 'textarea', 'display' => 'Body']]);
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $this->entry = $this->makeEntry('pages', ['title' => 'Hello', 'body' => 'Some text']);

    $this->admin = cpUser('Ada Admin', super: true);
    $this->alice = cpUser('Alice Author');
    $this->bob = cpUser('Bob Builder');
    $this->outsider = cpUser('Frontend Fred', cp: false);
});

function postComment($test, $user, array $payload)
{
    return $test->actingAs($user)->postJson(commentsUrl('workflows.api.comments', $test->entry->id()), $payload);
}

it('stores a root comment in the full resource shape', function () {
    $response = postComment($this, $this->alice, ['body' => 'Root', 'passage_ref' => 'p1', 'passage_handle' => 'body'])
        ->assertOk();

    $data = $response->json('data');

    expect(array_keys($data))->toBe([
        'id', 'parent_id', 'at', 'edited_at', 'author', 'body', 'mentions', 'ref', 'resolved',
        'can_edit', 'can_delete', 'can_resolve', 'is_new', 'content_stale', 'container_changed',
    ])
        ->and($data['parent_id'])->toBeNull()
        ->and($data['edited_at'])->toBeNull()
        ->and($data['author'])->toMatchArray(['id' => (string) $this->alice->id(), 'name' => 'Alice Author', 'initials' => 'AA'])
        ->and($data['author'])->toHaveKey('avatar')
        ->and($data['mentions'])->toBe([])
        ->and($data['ref'])->toBe(['uuid' => 'p1', 'handle' => 'body'])
        ->and($data['resolved'])->toBeNull()
        ->and($data['can_edit'])->toBeTrue()
        ->and($data['can_delete'])->toBeTrue()
        ->and($data['can_resolve'])->toBeTrue();

    expect(WorkflowEvent::query()->where('type', 'comment_created')->first()->data_snapshot)
        ->toMatchArray(['comment_id' => $data['id'], 'parent_id' => null]);
});

it('stores a reply that inherits the root passage', function () {
    $root = postComment($this, $this->alice, ['body' => 'Root', 'passage_ref' => 'p1', 'passage_handle' => 'body'])->json('data');

    $response = postComment($this, $this->bob, ['body' => 'Reply', 'parent_id' => $root['id'], 'passage_ref' => 'other'])
        ->assertOk();

    expect($response->json('data.parent_id'))->toBe($root['id'])
        ->and($response->json('data.ref'))->toBe(['uuid' => 'p1', 'handle' => 'body'])
        ->and($response->json('data.can_resolve'))->toBeFalse()
        ->and($response->json('data.resolved'))->toBeNull()
        ->and($response->json('root.id'))->toBe($root['id']);
});

it('rejects a reply to a reply or to another subject', function () {
    $root = postComment($this, $this->alice, ['body' => 'Root', 'passage_ref' => 'p1'])->json('data');
    $reply = postComment($this, $this->bob, ['body' => 'Reply', 'parent_id' => $root['id']])->json('data');

    postComment($this, $this->bob, ['body' => 'Nested', 'parent_id' => $reply['id']])
        ->assertStatus(422)->assertJsonValidationErrors('parent_id');

    $other = $this->makeEntry('pages', ['title' => 'Other']);
    $foreign = WorkflowComment::query()->create([
        'subject_id' => $other->id(), 'workflow' => 'simple', 'passage_ref' => 'p1',
        'body' => 'Elsewhere', 'user_id' => $this->alice->id(), 'author_name' => 'Alice Author',
    ]);

    postComment($this, $this->bob, ['body' => 'Cross', 'parent_id' => $foreign->id])
        ->assertStatus(422)->assertJsonValidationErrors('parent_id');
});

it('lets only the author edit and sets edited_at', function () {
    $root = postComment($this, $this->alice, ['body' => 'Root', 'passage_ref' => 'p1'])->json('data');
    $url = commentsUrl('workflows.api.comments.update', $this->entry->id(), ['comment' => $root['id']]);

    $this->actingAs($this->bob)->patchJson($url, ['body' => 'Hijack'])->assertForbidden();
    $this->actingAs($this->admin)->patchJson($url, ['body' => 'Moderated'])->assertForbidden();

    $response = $this->actingAs($this->alice)->patchJson($url, ['body' => 'Edited'])->assertOk();

    expect($response->json('data.body'))->toBe('Edited')
        ->and($response->json('data.edited_at'))->not->toBeNull()
        ->and(WorkflowEvent::query()->where('type', 'comment_edited')->exists())->toBeTrue();
});

it('deletes a root together with its replies and logs it', function () {
    $root = postComment($this, $this->alice, ['body' => 'Root', 'passage_ref' => 'p1'])->json('data');
    $r1 = postComment($this, $this->bob, ['body' => 'R1', 'parent_id' => $root['id']])->json('data');
    $r2 = postComment($this, $this->alice, ['body' => 'R2', 'parent_id' => $root['id']])->json('data');

    $url = fn ($id) => commentsUrl('workflows.api.comments.destroy', $this->entry->id(), ['comment' => $id]);

    $this->actingAs($this->bob)->deleteJson($url($root['id']))->assertForbidden();

    $this->actingAs($this->alice)->deleteJson($url($root['id']))
        ->assertOk()
        ->assertExactJson(['deleted' => [$root['id'], $r1['id'], $r2['id']]]);

    expect(WorkflowComment::query()->count())->toBe(0)
        ->and(WorkflowEvent::query()->where('type', 'comment_deleted')->first()->data_snapshot['comment_id'])->toBe($root['id']);
});

it('lets author, moderators and assignees resolve and reopen — nobody else', function () {
    $root = postComment($this, $this->alice, ['body' => 'Root', 'passage_ref' => 'p1'])->json('data');
    $reply = postComment($this, $this->admin, ['body' => 'Reply', 'parent_id' => $root['id']])->json('data');
    $resolve = commentsUrl('workflows.api.comments.resolve', $this->entry->id(), ['comment' => $root['id']]);
    $reopen = commentsUrl('workflows.api.comments.reopen', $this->entry->id(), ['comment' => $root['id']]);

    // Bob: neither author, moderator nor assignee.
    $this->actingAs($this->bob)->postJson($resolve)->assertForbidden();

    // A reply cannot be resolved on its own.
    $this->actingAs($this->alice)
        ->postJson(commentsUrl('workflows.api.comments.resolve', $this->entry->id(), ['comment' => $reply['id']]))
        ->assertStatus(422);

    $resolved = $this->actingAs($this->alice)->postJson($resolve)->assertOk()->json('data');
    expect($resolved['resolved']['by'])->toBe(['id' => (string) $this->alice->id(), 'name' => 'Alice Author']);

    $this->actingAs($this->admin)->postJson($reopen)->assertOk()->assertJsonPath('data.resolved', null);

    app(WorkflowAssigneeStore::class)->set($this->entry->id(), 'simple', [(string) $this->bob->id()]);
    $this->actingAs($this->bob)->postJson($resolve)->assertOk()->assertJsonPath('data.can_resolve', true);

    expect(WorkflowEvent::query()->where('type', 'comment_resolved')->count())->toBe(2)
        ->and(WorkflowEvent::query()->where('type', 'comment_reopened')->count())->toBe(1);
});

it('reopens a resolved thread when somebody replies', function () {
    $root = postComment($this, $this->alice, ['body' => 'Root', 'passage_ref' => 'p1'])->json('data');
    $this->actingAs($this->alice)
        ->postJson(commentsUrl('workflows.api.comments.resolve', $this->entry->id(), ['comment' => $root['id']]))
        ->assertOk();

    postComment($this, $this->bob, ['body' => 'Not done yet', 'parent_id' => $root['id']])
        ->assertOk()
        ->assertJsonPath('root.resolved', null);

    expect(WorkflowEvent::query()->where('type', 'comment_reopened')->first()->data_snapshot['auto'])->toBeTrue();
});

it('counts only open threads', function () {
    $store = app(WorkflowCommentStore::class);
    $root = postComment($this, $this->alice, ['body' => 'Root', 'passage_ref' => 'p1'])->json('data');
    postComment($this, $this->alice, ['body' => 'Reply', 'parent_id' => $root['id']]);
    postComment($this, $this->alice, ['body' => 'Open', 'passage_ref' => 'p2']);

    $id = $this->entry->id();
    expect($store->countBySubject('simple'))->toBe([$id => 3])
        ->and($store->newCountBySubject((string) $this->bob->id(), 'simple'))->toBe([$id => 3]);

    $this->actingAs($this->alice)
        ->postJson(commentsUrl('workflows.api.comments.resolve', $id, ['comment' => $root['id']]))
        ->assertOk();

    expect($store->countBySubject('simple'))->toBe([$id => 1])
        ->and($store->newCountBySubject((string) $this->bob->id(), 'simple'))->toBe([$id => 1]);
});

it('validates mentions and mails only newly mentioned users, never the author', function () {
    Mail::fake();

    postComment($this, $this->alice, ['body' => 'Hi @Fred', 'passage_ref' => 'p1', 'mentions' => [(string) $this->outsider->id()]])
        ->assertStatus(422)->assertJsonValidationErrors('mentions');
    postComment($this, $this->alice, ['body' => 'Hi @Ghost', 'passage_ref' => 'p1', 'mentions' => ['nope']])
        ->assertStatus(422)->assertJsonValidationErrors('mentions');

    $root = postComment($this, $this->alice, [
        'body' => 'Hi @Bob @Alice', 'passage_ref' => 'p1',
        'mentions' => [(string) $this->bob->id(), (string) $this->alice->id()],
    ])->assertOk()->json('data');

    expect(collect($root['mentions'])->pluck('id')->all())->toBe([(string) $this->bob->id(), (string) $this->alice->id()]);

    Mail::assertSent(CommentMentionMail::class, 1);
    Mail::assertSent(CommentMentionMail::class, fn ($mail) => $mail->hasTo($this->bob->email())
        && str_contains($mail->url, 'comment='.$root['id'])
        && str_ends_with($mail->url, '#passage-p1'));

    // Edit: Bob stays mentioned (no second mail), Ada is new.
    $this->actingAs($this->alice)->patchJson(
        commentsUrl('workflows.api.comments.update', $this->entry->id(), ['comment' => $root['id']]),
        ['body' => 'Hi @Bob @Ada', 'mentions' => [(string) $this->bob->id(), (string) $this->admin->id()]],
    )->assertOk();

    Mail::assertSent(CommentMentionMail::class, 2);
    Mail::assertSent(CommentMentionMail::class, fn ($mail) => $mail->hasTo($this->admin->email()));
    Mail::assertSent(CommentMentionMail::class, fn ($mail) => $mail->hasTo($this->bob->email()));
    Mail::assertNotSent(CommentMentionMail::class, fn ($mail) => $mail->hasTo($this->alice->email()));
});

it('renders the mention mail in the recipient language', function () {
    $mail = new CommentMentionMail('Hello', 'https://example.test/x?comment=1#passage-p1', 'Alice', 'Body', 'Look here', 'Simple');

    $html = $mail->locale('de')->render();

    expect($html)->toContain('Neue Erwähnung in einem Kommentar')
        ->and($html)->toContain('Look here')
        ->and($html)->toContain('Abschnitt');
});

it('ships thread props and mentionable users on the detail page', function () {
    $response = $this->actingAs($this->alice)
        ->get(cp_route('workflows.show', ['id' => $this->entry->id(), 'workflow' => 'simple']));

    $props = $response->viewData('page')['props'] ?? null;

    expect($props)->not->toBeNull()
        ->and($props['currentUser'])->toMatchArray(['id' => (string) $this->alice->id(), 'name' => 'Alice Author', 'initials' => 'AA'])
        ->and(collect($props['mentionableUsers'])->pluck('name')->all())->toBe(['Ada Admin', 'Alice Author', 'Bob Builder'])
        ->and($props['commentUpdateUrlTemplate'])->toContain('__COMMENT__')
        ->and($props['commentResolveUrlTemplate'])->toContain('/resolve')
        ->and($props['commentReopenUrlTemplate'])->toContain('/reopen');
});

it('renders comment events in the history and hides resolved threads from is_new', function () {
    $root = postComment($this, $this->alice, ['body' => 'Root', 'passage_ref' => 'p1'])->json('data');
    postComment($this, $this->alice, ['body' => 'Reply', 'parent_id' => $root['id']]);
    $this->actingAs($this->alice)
        ->postJson(commentsUrl('workflows.api.comments.resolve', $this->entry->id(), ['comment' => $root['id']]))
        ->assertOk();

    $props = $this->actingAs($this->admin)
        ->get(cp_route('workflows.show', ['id' => $this->entry->id(), 'workflow' => 'simple']))
        ->viewData('page')['props'];

    expect(collect($props['comments'])->pluck('is_new')->unique()->all())->toBe([false])
        ->and(collect($props['history'])->pluck('type')->all())->toContain('comment_created', 'comment_resolved');

    $resolved = collect($props['history'])->firstWhere('type', 'comment_resolved');
    expect($resolved['comment'])->toMatchArray(['id' => $root['id'], 'parent_id' => null, 'deleted' => false]);

    $reply = collect($props['history'])->where('type', 'comment_created')->last();
    expect($reply['comment']['parent_id'])->toBe($root['id']);
});
