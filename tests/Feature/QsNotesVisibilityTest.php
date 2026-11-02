<?php

use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\QsNotesVisibility;
use TakepartMedia\Workflows\Workflow\WorkflowComment;
use TakepartMedia\Workflows\Workflow\WorkflowCommentStore;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;

/*
 * metadata.qs_notes_visible / qs_notes_audience: who sees the plain-language
 * QA report — and the report itself never shows up in the comment thread.
 */

function qsUser(string $name, array $groups = [], bool $super = false)
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
    config()->set('workflows.comment_moderator_groups', []);
    Role::make('editor')->title('Editor')->permissions(['access cp'])->save();
    UserGroup::make()->handle('medical_writer')->title('Medical writer')->save();

    $this->writer = qsUser('Mia Medical', ['medical_writer']);
    $this->other = qsUser('Otto Other');
    $this->admin = qsUser('Ada Admin', super: true);
});

it('shows the notes to everyone by default, to nobody when switched off', function () {
    expect(QsNotesVisibility::allows(['metadata' => []], $this->other))->toBeTrue()
        ->and(QsNotesVisibility::allows(['metadata' => ['qs_notes_visible' => true]], $this->other))->toBeTrue()
        ->and(QsNotesVisibility::allows(['metadata' => ['qs_notes_visible' => false]], $this->admin))->toBeFalse();
});

it('limits the notes to the audience and super admins', function () {
    $raw = ['metadata' => ['qs_notes_audience' => [['by' => 'group', 'handles' => ['medical_writer']]]]];

    expect(QsNotesVisibility::allows($raw, $this->writer))->toBeTrue()
        ->and(QsNotesVisibility::allows($raw, $this->admin))->toBeTrue()
        ->and(QsNotesVisibility::allows($raw, $this->other))->toBeFalse()
        ->and(QsNotesVisibility::allows($raw, null))->toBeFalse();
});

it('keeps the report out of the comment thread and its endpoints', function () {
    $this->makeCollection('pages');
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $entry = $this->makeEntry('pages', ['title' => 'Post']);

    $store = app(WorkflowCommentStore::class);
    $report = $store->add([
        'subject_id' => $entry->id(), 'workflow' => 'simple', 'body' => 'Report',
        'user_id' => null, 'author_name' => 'System', 'kind' => WorkflowComment::KIND_PLAIN_LANGUAGE_QS,
    ]);
    $store->add([
        'subject_id' => $entry->id(), 'workflow' => 'simple', 'body' => 'Reply', 'parent_id' => $report->id,
        'user_id' => (string) $this->other->id(), 'author_name' => 'Otto Other',
    ]);
    $normal = $store->add([
        'subject_id' => $entry->id(), 'workflow' => 'simple', 'passage_ref' => 'p1', 'body' => 'Normal',
        'user_id' => (string) $this->other->id(), 'author_name' => 'Otto Other',
    ]);

    expect($store->forSubject($entry->id(), 'simple')->pluck('id')->all())->toBe([$normal->id])
        ->and($store->findRoot($report->id, $entry->id(), 'simple'))->toBeNull()
        ->and($store->latestOfKind($entry->id(), 'simple', WorkflowComment::KIND_PLAIN_LANGUAGE_QS)?->id)->toBe($report->id);

    $props = $this->actingAs($this->admin)
        ->get(cp_route('workflows.show', ['id' => $entry->id(), 'workflow' => 'simple']))
        ->viewData('page')['props'];
    expect(collect($props['comments'])->pluck('id')->all())->toBe([$normal->id]);

    $this->actingAs($this->admin)
        ->deleteJson(cp_route('workflows.api.comments.destroy', ['id' => $entry->id(), 'workflow' => 'simple', 'comment' => $report->id]))
        ->assertNotFound();
});

it('refuses unusable values in the writer', function (string $key, mixed $value) {
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['metadata'][$key] = $value;

    expect(fn () => app(WorkflowDefinitionWriter::class)->save('simple', $raw))
        ->toThrow(DefinitionValidationException::class);
})->with([
    'flag not boolean' => ['qs_notes_visible', 'no'],
    'row without entries' => ['qs_notes_audience', [['by' => 'group', 'handles' => []]]],
]);
