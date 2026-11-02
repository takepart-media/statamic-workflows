<?php

use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowComment;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;

/*
 * metadata.comment_resolvers / comment_deleters: who may mark threads done and
 * who may delete any comment. Super admins always may; everyone deletes their own.
 */

function permissionUser(string $name, array $groups = [], bool $super = false)
{
    $user = User::make()->email(str($name)->slug().'@example.com')->set('name', $name);
    $super ? $user->makeSuper() : $user->assignRole('editor');

    foreach ($groups as $group) {
        $user->addToGroup($group);
    }

    $user->save();

    return User::find($user->id());
}

beforeEach(function () {
    config()->set('statamic.users.repositories.file.paths.roles', $this->tempPath('users/roles.yaml'));
    config()->set('statamic.users.repositories.file.paths.groups', $this->tempPath('users/groups.yaml'));
    config()->set('workflows.mail_delivery', 'sync');
    config()->set('workflows.comment_moderator_groups', []);
    Role::make('editor')->title('Editor')->permissions(['access cp'])->save();
    UserGroup::make()->handle('redaktion')->title('Redaktion')->save();

    $this->makeCollection('pages', ['body' => ['type' => 'textarea', 'display' => 'Body']]);
    $this->writeWorkflow('simple', $this->minimalWorkflow('simple', ['metadata' => [
        'comment_resolvers' => [['by' => 'group', 'handles' => ['redaktion']]],
        'comment_deleters' => [['by' => 'group', 'handles' => ['redaktion']]],
    ]]));
    $this->entry = $this->makeEntry('pages', ['title' => 'Hello', 'body' => 'Some text']);

    $this->admin = permissionUser('Ada Admin', super: true);
    $this->author = permissionUser('Alice Author');
    $this->editor = permissionUser('Rita Redaktion', ['redaktion']);
    $this->other = permissionUser('Otto Other');

    $this->comment = fn ($user, string $body = 'Root') => WorkflowComment::query()->create([
        'subject_id' => $this->entry->id(), 'workflow' => 'simple', 'passage_ref' => 'p1',
        'body' => $body, 'user_id' => (string) $user->id(), 'author_name' => $user->name(),
    ]);
    $this->url = fn (string $route, $comment) => cp_route($route, ['id' => $this->entry->id(), 'workflow' => 'simple', 'comment' => $comment->id]);
});

it('lets only the configured audience and super admins resolve', function () {
    $root = ($this->comment)($this->author);
    $resolve = ($this->url)('workflows.api.comments.resolve', $root);
    $reopen = ($this->url)('workflows.api.comments.reopen', $root);

    // The author and an assignee lose the right the old rules gave them.
    app(WorkflowAssigneeStore::class)->set($this->entry->id(), 'simple', [(string) $this->other->id()]);
    $this->actingAs($this->author)->postJson($resolve)->assertForbidden();
    $this->actingAs($this->other)->postJson($resolve)->assertForbidden();

    $this->actingAs($this->editor)->postJson($resolve)->assertOk()->assertJsonPath('data.can_resolve', true);
    $this->actingAs($this->admin)->postJson($reopen)->assertOk()->assertJsonPath('data.resolved', null);
});

it('lets everyone delete their own, the audience and super admins any', function () {
    $own = ($this->comment)($this->other, 'Mine');
    $this->actingAs($this->other)->deleteJson(($this->url)('workflows.api.comments.destroy', $own))->assertOk();

    $foreign = ($this->comment)($this->author);
    $this->actingAs($this->other)->deleteJson(($this->url)('workflows.api.comments.destroy', $foreign))->assertForbidden();
    $this->actingAs($this->editor)->deleteJson(($this->url)('workflows.api.comments.destroy', $foreign))->assertOk();

    $again = ($this->comment)($this->author);
    $this->actingAs($this->admin)->deleteJson(($this->url)('workflows.api.comments.destroy', $again))->assertOk();
});

it('sends can_delete and can_resolve to match', function () {
    ($this->comment)($this->author);
    $comments = fn ($user) => $this->actingAs($user)
        ->postJson(cp_route('workflows.api.comments', ['id' => $this->entry->id(), 'workflow' => 'simple']), ['body' => 'Reply', 'parent_id' => WorkflowComment::query()->value('id')])
        ->json('root');

    expect($comments($this->editor))->toMatchArray(['can_delete' => true, 'can_resolve' => true])
        ->and($comments($this->other))->toMatchArray(['can_delete' => false, 'can_resolve' => false]);
});

it('keeps the old rules without the blocks', function () {
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    config()->set('workflows.comment_moderator_groups', ['redaktion']);
    $root = ($this->comment)($this->author);

    $this->actingAs($this->author)->postJson(($this->url)('workflows.api.comments.resolve', $root))->assertOk();
    $this->actingAs($this->other)->deleteJson(($this->url)('workflows.api.comments.destroy', $root))->assertForbidden();
    $this->actingAs($this->editor)->deleteJson(($this->url)('workflows.api.comments.destroy', $root))->assertOk();
});

it('refuses an unusable block in the writer', function (string $key) {
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['metadata'][$key] = [['by' => 'group', 'handles' => ['redaktion']], ['by' => 'user', 'handles' => []]];

    expect(fn () => app(WorkflowDefinitionWriter::class)->save('simple', $raw))
        ->toThrow(DefinitionValidationException::class);
})->with(['comment_resolvers', 'comment_deleters']);
