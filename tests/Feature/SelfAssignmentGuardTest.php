<?php

use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\SelfAssignmentGuard;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/*
 * metadata.self_assignment: an automatic assignment that would land on the
 * clicker alone opens the picker with a fallback audience instead.
 */

function guardedWorkflow(array $base, array $selfAssignment = ['by' => 'group', 'handles' => ['leads']], string $mode = 'automatic'): array
{
    return array_replace_recursive($base, [
        'metadata' => ['self_assignment' => $selfAssignment],
        'transitions' => ['submit' => ['metadata' => ['actions' => [[
            'type' => 'user_assignment',
            'mode' => $mode,
            'criteria' => [['field' => 'group', 'operator' => 'is', 'value' => 'authors']],
        ]]]]],
    ]);
}

function groupUser(string $id, array $groups, bool $super = false)
{
    $user = User::make()->id($id)->email("{$id}@example.com")->set('name', ucfirst($id));

    if ($super) {
        $user->makeSuper();
    }

    foreach ($groups as $group) {
        $user->addToGroup($group);
    }

    $user->save();

    return User::find($id);
}

beforeEach(function () {
    config()->set('statamic.users.repositories.file.paths.groups', $this->tempPath('users/groups.yaml'));
    config()->set('workflows.mail_delivery', 'sync');

    UserGroup::make()->handle('authors')->title('Authors')->save();
    UserGroup::make()->handle('leads')->title('Leads')->save();

    $this->makeCollection('pages');
    $this->entry = $this->makeEntry('pages', ['title' => 'Post']);

    $this->actor = groupUser('actor', ['authors', 'leads'], super: true);
    $this->lead = groupUser('lead', ['leads']);
});

it('applies only when the automatic result is the clicker alone', function () {
    $raw = guardedWorkflow($this->minimalWorkflow());
    $submit = $raw['transitions']['submit'];

    expect(SelfAssignmentGuard::applies($raw, $submit, $this->entry, 'actor'))->toBeTrue()
        ->and(SelfAssignmentGuard::applies($raw, $submit, $this->entry, 'lead'))->toBeFalse()
        ->and(SelfAssignmentGuard::applies($raw, $submit, $this->entry, null))->toBeFalse();

    groupUser('coauthor', ['authors']);
    app()->forgetScopedInstances(); // AssignmentCandidates memoises per request
    expect(SelfAssignmentGuard::applies($raw, $submit, $this->entry, 'actor'))->toBeFalse();
});

it('stays off without settings', function () {
    $off = guardedWorkflow($this->minimalWorkflow());
    unset($off['metadata']['self_assignment']);

    expect(SelfAssignmentGuard::applies($off, $off['transitions']['submit'], $this->entry, 'actor'))->toBeFalse()
        ->and(SelfAssignmentGuard::withoutClicker($off, ['actor', 'lead'], 'actor'))->toBe(['actor', 'lead']);
});

it('also falls back when a manual pick could only name the clicker', function () {
    $manual = guardedWorkflow($this->minimalWorkflow(), mode: 'manual');

    expect(SelfAssignmentGuard::applies($manual, $manual['transitions']['submit'], $this->entry, 'actor'))->toBeTrue();
});

it('leaves the clicker out of a manual pick', function () {
    $this->writeWorkflow('simple', guardedWorkflow($this->minimalWorkflow(), mode: 'manual'));
    app(WorkflowStateStore::class)->set($this->entry->id(), 'simple', 'draft');
    app(WorkflowAssigneeStore::class)->set($this->entry->id(), 'simple', ['actor']);
    groupUser('coauthor', ['authors']);

    $raw = app(DefinitionLoader::class)->raw('simple');
    expect(SelfAssignmentGuard::withoutClicker($raw, ['actor', 'coauthor'], 'actor'))->toBe(['coauthor']);

    $url = cp_route('workflows.api.apply', ['id' => $this->entry->id(), 'name' => 'submit']).'?workflow=simple';

    $this->actingAs($this->actor)->postJson($url, ['assignees' => ['actor']])->assertStatus(422);
    $this->actingAs($this->actor)->postJson($url, ['assignees' => ['coauthor']])->assertOk();

    expect(app(WorkflowAssigneeStore::class)->idsFor($this->entry->id(), 'simple'))->toBe(['coauthor']);
});

it('offers the fallback audience without the clicker', function () {
    expect(SelfAssignmentGuard::candidateIds(guardedWorkflow($this->minimalWorkflow()), 'actor'))->toBe(['lead'])
        ->and(SelfAssignmentGuard::candidateIds(guardedWorkflow($this->minimalWorkflow(), ['by' => 'user', 'handles' => ['lead', 'actor', 'gone']]), 'actor'))->toBe(['lead']);
});

it('uses the configured hint or the default', function () {
    expect(SelfAssignmentGuard::hint(guardedWorkflow($this->minimalWorkflow())))
        ->toBe(__('workflows::messages.self_assignment_hint_default'))
        ->and(SelfAssignmentGuard::hint(guardedWorkflow($this->minimalWorkflow(), ['by' => 'group', 'handles' => ['leads'], 'hint' => ' Ask a lead. '])))
        ->toBe('Ask a lead.');
});

it('refuses an unusable block in the writer', function (array $block) {
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['metadata']['self_assignment'] = $block;

    expect(fn () => app(WorkflowDefinitionWriter::class)->save('simple', $raw))
        ->toThrow(DefinitionValidationException::class);
})->with([
    'no handles' => [['by' => 'group', 'handles' => []]],
    'unknown type' => [['by' => 'team', 'handles' => ['leads']]],
    'hint not text' => [['by' => 'group', 'handles' => ['leads'], 'hint' => ['x']]],
]);

it('makes the clicker pick from the fallback audience', function () {
    $this->writeWorkflow('simple', guardedWorkflow($this->minimalWorkflow()));
    app(WorkflowStateStore::class)->set($this->entry->id(), 'simple', 'draft');
    app(WorkflowAssigneeStore::class)->set($this->entry->id(), 'simple', ['actor']);

    $url = cp_route('workflows.api.apply', ['id' => $this->entry->id(), 'name' => 'submit']).'?workflow=simple';

    $this->actingAs($this->actor)->postJson($url)->assertStatus(422);
    $this->actingAs($this->actor)->postJson($url, ['assignees' => ['actor']])->assertStatus(422);
    $this->actingAs($this->actor)->postJson($url, ['assignees' => ['lead']])->assertOk();

    expect(app(WorkflowAssigneeStore::class)->idsFor($this->entry->id(), 'simple'))->toBe(['lead']);
});
