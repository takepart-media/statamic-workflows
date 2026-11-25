<?php

use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Support\EventLogSuppressor;
use TakepartMedia\Workflows\Workflow\AssignmentCriteria;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\SlaSettings;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/*
 * Ageing can hand a post to "whoever the assignment criteria select" instead of
 * a fixed group — here the person named in the post's own `owner` field, which
 * a criterion reads with `user_field: ':id'`.
 */

function slaUser(string $id, array $groups = [])
{
    $user = User::make()->id($id)->email("{$id}@example.com");

    foreach ($groups as $group) {
        $user->addToGroup($group);
    }

    $user->save();

    return User::find($id);
}

/** A workflow that ages published posts into `needs_update` after 30 days. */
function agingWorkflow(array $base, ?array $assign): array
{
    return array_replace_recursive($base, [
        'metadata' => ['sla' => array_filter([
            'needs_update_days' => 30,
            'needs_update_place' => 'needs_update',
            'needs_update_assign' => $assign,
        ])],
        'places' => ['needs_update' => ['label' => 'Needs update', 'metadata' => ['allow_publish' => true]]],
    ]);
}

const OWNER_CRITERIA = [
    'by' => 'criteria',
    'match' => 'all',
    'criteria' => [['field' => 'owner', 'operator' => 'matches_entry']],
];

beforeEach(function () {
    config()->set('statamic.users.repositories.file.paths.groups', $this->tempPath('users/groups.yaml'));
    config()->set('workflows.mail_delivery', 'sync');
    config()->set('workflows.assignment_criteria', [
        'owner' => ['label' => 'Owner', 'user_field' => AssignmentCriteria::USER_ID, 'entry_field' => 'owner', 'operators' => ['matches_entry']],
    ]);

    UserGroup::make()->handle('desk')->title('Desk')->save();

    $this->makeCollection('pages', ['owner' => ['type' => 'users', 'max_items' => 1]]);
    AssignmentCriteria::flush();

    $this->owner = slaUser('owner');
    $this->desk = slaUser('desk-member', ['desk']);
    $this->previous = slaUser('previous');
});

/** Marks a post as published long ago and currently with `previous`. */
function aged($entry)
{
    // As the addon's own write: a hand on the clock is a super admin's, and
    // ProtectContentTimestamp reverts anybody else's.
    EventLogSuppressor::while(fn () => $entry->set(ContentTimestamp::FIELD, '2020-01-01')->save());

    app(WorkflowStateStore::class)->set($entry->id(), 'simple', 'published');
    app(WorkflowAssigneeStore::class)->set($entry->id(), 'simple', ['previous']);

    return $entry;
}

it('matches a user by id against an entry field', function () {
    $criterion = ['field' => 'owner', 'operator' => 'matches_entry'];
    $post = $this->makeEntry('pages', ['title' => 'Post', 'owner' => ['owner']]);

    expect(AssignmentCriteria::find('owner'))->not->toBeNull()
        ->and(AssignmentCriteria::narrowsUsers(AssignmentCriteria::find('owner')))->toBeTrue()
        ->and(AssignmentCriteria::matches($this->owner, $post, $criterion))->toBeTrue()
        ->and(AssignmentCriteria::matches($this->desk, $post, $criterion))->toBeFalse();
});

it('still drops a criterion whose user field does not exist', function () {
    config()->set('workflows.assignment_criteria', [
        'owner' => ['label' => 'Owner', 'user_field' => 'no_such_field', 'entry_field' => 'owner'],
    ]);
    AssignmentCriteria::flush();

    expect(AssignmentCriteria::find('owner'))->toBeNull();
});

it('reads a criteria assignment with its fallback', function () {
    $assign = SlaSettings::assign([
        'needs_update_assign' => OWNER_CRITERIA + ['fallback' => ['by' => 'group', 'handles' => ['desk']]],
    ], 'needs_update');

    expect($assign['by'])->toBe(SlaSettings::BY_CRITERIA)
        ->and($assign['criteria'])->toHaveCount(1)
        ->and($assign['fallback'])->toBe(['by' => 'group', 'handles' => ['desk']])
        // Nothing to assign by is no assignment at all.
        ->and(SlaSettings::assign(['needs_update_assign' => ['by' => 'criteria', 'criteria' => []]], 'needs_update'))->toBeNull();
});

it('hands an aged post to the person its criteria select', function () {
    $this->writeWorkflow('simple', agingWorkflow($this->minimalWorkflow(), OWNER_CRITERIA + [
        'fallback' => ['by' => 'group', 'handles' => ['desk']],
    ]));
    $post = aged($this->makeEntry('pages', ['title' => 'Post', 'owner' => ['owner']]));

    $this->artisan('workflows:transition-aged')->assertSuccessful();

    expect(app(WorkflowStateStore::class)->get($post->id(), 'simple'))->toBe('needs_update')
        ->and(app(WorkflowAssigneeStore::class)->idsFor($post->id(), 'simple'))->toBe(['owner']);
});

it('falls back to the fixed audience when the criteria select nobody', function () {
    $this->writeWorkflow('simple', agingWorkflow($this->minimalWorkflow(), OWNER_CRITERIA + [
        'fallback' => ['by' => 'group', 'handles' => ['desk']],
    ]));
    $post = aged($this->makeEntry('pages', ['title' => 'Post']));

    $this->artisan('workflows:transition-aged')->assertSuccessful();

    expect(app(WorkflowAssigneeStore::class)->idsFor($post->id(), 'simple'))->toBe(['desk-member']);
});

it('keeps the assignees when the criteria select nobody and there is no fallback', function () {
    $this->writeWorkflow('simple', agingWorkflow($this->minimalWorkflow(), OWNER_CRITERIA));
    $post = aged($this->makeEntry('pages', ['title' => 'Post']));

    $this->artisan('workflows:transition-aged')->assertSuccessful();

    expect(app(WorkflowStateStore::class)->get($post->id(), 'simple'))->toBe('needs_update')
        ->and(app(WorkflowAssigneeStore::class)->idsFor($post->id(), 'simple'))->toBe(['previous']);
});

it('saves a criteria assignment through the writer', function () {
    $this->writeWorkflow('simple', agingWorkflow($this->minimalWorkflow(), null));
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['metadata']['sla']['needs_update_assign'] = OWNER_CRITERIA + ['fallback' => ['by' => 'group', 'handles' => ['desk']]];

    app(WorkflowDefinitionWriter::class)->save('simple', $raw);

    expect(SlaSettings::assign(app(DefinitionLoader::class)->raw('simple')['metadata']['sla'], 'needs_update')['by'])
        ->toBe(SlaSettings::BY_CRITERIA);
});

it('refuses an unusable criteria assignment in the writer', function (array $block) {
    $this->writeWorkflow('simple', agingWorkflow($this->minimalWorkflow(), null));
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['metadata']['sla']['needs_update_assign'] = $block;

    expect(fn () => app(WorkflowDefinitionWriter::class)->save('simple', $raw))
        ->toThrow(DefinitionValidationException::class);
})->with([
    'no criteria' => [['by' => 'criteria', 'criteria' => []]],
    'unknown criterion' => [['by' => 'criteria', 'criteria' => [['field' => 'nope', 'operator' => 'matches_entry']]]],
    'half a fallback' => [OWNER_CRITERIA + ['fallback' => ['by' => 'group', 'handles' => []]]],
]);
