<?php

use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use TakepartMedia\Workflows\Workflow\AssignmentGuard;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\FourEyesGuard;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/*
 * metadata.four_eyes: A passes the post on, B fires the next transition — the
 * post must not go back to A. With nobody else left, the picker offers the
 * fallback audience.
 */

function fourEyesWorkflow(array $base, string $mode = 'manual', array $extra = []): array
{
    return array_replace_recursive($base, [
        'metadata' => ['four_eyes' => ['by' => 'group', 'handles' => ['leads']]] + $extra,
        'transitions' => ['approve' => ['metadata' => ['actions' => [[
            'type' => 'user_assignment',
            'mode' => $mode,
            'criteria' => [['field' => 'group', 'operator' => 'is', 'value' => 'authors']],
        ]]]]],
    ]);
}

function fourEyesUser(string $id, array $groups, bool $super = false)
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

    $this->passer = fourEyesUser('passer', ['authors']);   // A
    $this->actor = fourEyesUser('actor', [], super: true); // B
    $this->lead = fourEyesUser('lead', ['leads']);

    $this->url = cp_route('workflows.api.apply', ['id' => $this->entry->id(), 'name' => 'approve']).'?workflow=simple';
});

// A submitted the post: it now sits in review.
function passedOnBy(string $userId, $entry): void
{
    app(WorkflowStateStore::class)->set($entry->id(), 'simple', 'review');
    app(WorkflowEventStore::class)->log($entry->id(), 'simple', 'transition', [
        'transition' => 'submit', 'from' => 'draft', 'to' => 'review', 'user_id' => $userId,
    ]);
}

it('names the person who passed the post on last, skipping system moves', function () {
    $raw = fourEyesWorkflow($this->minimalWorkflow());

    expect(FourEyesGuard::previousActorId($raw, $this->entry, 'simple'))->toBeNull();

    passedOnBy('passer', $this->entry);
    app(WorkflowEventStore::class)->log($this->entry->id(), 'simple', 'transition', ['user_id' => null]);

    expect(FourEyesGuard::previousActorId($raw, $this->entry, 'simple'))->toBe('passer')
        ->and(FourEyesGuard::previousActorId($this->minimalWorkflow(), $this->entry, 'simple'))->toBeNull();
});

it('leaves the person who passed it on out of a manual pick', function () {
    $this->writeWorkflow('simple', fourEyesWorkflow($this->minimalWorkflow()));
    fourEyesUser('other', ['authors']);
    passedOnBy('passer', $this->entry);

    $this->actingAs($this->actor)->postJson($this->url, ['assignees' => ['passer']])->assertStatus(422);
    $this->actingAs($this->actor)->postJson($this->url, ['assignees' => ['other']])->assertOk();

    expect(app(WorkflowAssigneeStore::class)->idsFor($this->entry->id(), 'simple'))->toBe(['other']);
});

it('drops the person who passed it on from an automatic set', function () {
    $this->writeWorkflow('simple', fourEyesWorkflow($this->minimalWorkflow(), 'automatic'));
    fourEyesUser('other', ['authors']);
    passedOnBy('passer', $this->entry);

    $this->actingAs($this->actor)->postJson($this->url)->assertOk();

    expect(app(WorkflowAssigneeStore::class)->idsFor($this->entry->id(), 'simple'))->toBe(['other']);
});

it('falls back to its audience when only that person would be left', function () {
    $this->writeWorkflow('simple', fourEyesWorkflow($this->minimalWorkflow(), 'automatic'));
    passedOnBy('passer', $this->entry);

    $raw = app(DefinitionLoader::class)->raw('simple');
    $transition = $raw['transitions']['approve'];

    expect(AssignmentGuard::reason($raw, $transition, $this->entry, 'simple', 'actor'))->toBe(AssignmentGuard::FOUR_EYES)
        ->and(AssignmentGuard::fallbackIds($raw, $transition, $this->entry, 'simple', 'actor'))->toBe(['lead'])
        ->and(AssignmentGuard::hint($raw, $transition, $this->entry, 'simple', 'actor'))->toBe(__('workflows::messages.four_eyes_hint_default'));

    $actions = $this->actingAs($this->actor)
        ->getJson(cp_route('workflows.api.actions', ['id' => $this->entry->id()]).'?workflow=simple')
        ->json('data');
    $approve = collect($actions)->firstWhere('transition.name', 'approve');

    expect($approve['needs_pick'])->toBeTrue()
        ->and(collect($approve['pickable_assignees'])->pluck('id')->all())->toBe(['lead']);

    $this->actingAs($this->actor)->postJson($this->url)->assertStatus(422);
    $this->actingAs($this->actor)->postJson($this->url, ['assignees' => ['passer']])->assertStatus(422);
    $this->actingAs($this->actor)->postJson($this->url, ['assignees' => ['lead']])->assertOk();

    expect(app(WorkflowAssigneeStore::class)->idsFor($this->entry->id(), 'simple'))->toBe(['lead']);
});

it('keeps both the clicker and the person who passed it on out together with self-assignment', function () {
    $this->writeWorkflow('simple', fourEyesWorkflow($this->minimalWorkflow(), extra: [
        'self_assignment' => ['by' => 'group', 'handles' => ['leads']],
    ]));
    $this->actor->addToGroup('authors')->save();
    fourEyesUser('other', ['authors']);
    passedOnBy('passer', $this->entry);

    $raw = app(DefinitionLoader::class)->raw('simple');

    expect(AssignmentGuard::filter($raw, ['passer', 'actor', 'other'], $this->entry, 'simple', 'actor', 'manual'))->toBe(['other']);

    $this->actingAs($this->actor)->postJson($this->url, ['assignees' => ['actor']])->assertStatus(422);
    $this->actingAs($this->actor)->postJson($this->url, ['assignees' => ['passer']])->assertStatus(422);
    $this->actingAs($this->actor)->postJson($this->url, ['assignees' => ['other']])->assertOk();
});

it('changes nothing without the setting or before anyone passed the post on', function () {
    $off = $this->minimalWorkflow();
    passedOnBy('passer', $this->entry);
    expect(AssignmentGuard::filter($off, ['passer'], $this->entry, 'simple', 'actor', 'manual'))->toBe(['passer']);

    $on = fourEyesWorkflow($this->minimalWorkflow());
    $fresh = $this->makeEntry('pages', ['title' => 'Fresh']);
    expect(AssignmentGuard::filter($on, ['passer'], $fresh, 'simple', 'actor', 'manual'))->toBe(['passer']);
});

it('refuses an unusable block in the writer', function (array $block) {
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['metadata']['four_eyes'] = $block;

    expect(fn () => app(WorkflowDefinitionWriter::class)->save('simple', $raw))
        ->toThrow(DefinitionValidationException::class);
})->with([
    'no handles' => [['by' => 'group', 'handles' => []]],
    'unknown type' => [['by' => 'team', 'handles' => ['leads']]],
    'hint not text' => [['by' => 'group', 'handles' => ['leads'], 'hint' => ['x']]],
]);
