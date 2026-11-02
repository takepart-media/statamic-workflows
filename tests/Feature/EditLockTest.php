<?php

use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/*
 * metadata.assignees_only_edit: only the current assignees (and edit_exempt,
 * and super admins) may edit an entry the workflow governs. Viewing stays.
 */

function lockUser(string $name, array $groups = [], bool $super = false)
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
    Role::make('editor')->title('Editor')->permissions([
        'access cp', 'view pages entries', 'edit pages entries', 'edit other authors pages entries',
        'view news entries', 'edit news entries', 'edit other authors news entries',
    ])->save();
    UserGroup::make()->handle('chefredaktion')->title('Chefredaktion')->save();

    $this->makeCollection('pages');
    $this->makeCollection('news');
    $this->writeWorkflow('simple', $this->minimalWorkflow('simple', ['metadata' => [
        'assignees_only_edit' => true,
        'edit_exempt' => [['by' => 'group', 'handles' => ['chefredaktion']]],
    ]]));
    $this->entry = $this->makeEntry('pages', ['title' => 'Post']);
    app(WorkflowStateStore::class)->set($this->entry->id(), 'simple', 'review');

    $this->assignee = lockUser('Anna Assigned');
    $this->other = lockUser('Otto Other');
    $this->chief = lockUser('Carla Chief', ['chefredaktion']);
    $this->admin = lockUser('Ada Admin', super: true);

    app(WorkflowAssigneeStore::class)->set($this->entry->id(), 'simple', [(string) $this->assignee->id()]);
});

it('lets only assignees, the exempt and super admins edit', function () {
    expect($this->assignee->can('edit', $this->entry))->toBeTrue()
        ->and($this->chief->can('edit', $this->entry))->toBeTrue()
        ->and($this->admin->can('edit', $this->entry))->toBeTrue()
        ->and($this->other->can('edit', $this->entry))->toBeFalse()
        ->and($this->other->can('update', $this->entry))->toBeFalse();
});

it('still lets everyone with the permission view, read-only', function () {
    expect($this->other->can('view', $this->entry))->toBeTrue();

    $this->actingAs($this->other)
        ->patchJson(cp_route('collections.entries.update', ['pages', $this->entry->id()]), ['title' => 'Hijack'])
        ->assertForbidden();
});

it('hides the edit button on the detail page from everyone kept out', function () {
    $cpUrl = fn ($user) => $this->actingAs($user)
        ->get(cp_route('workflows.show', ['id' => $this->entry->id(), 'workflow' => 'simple']))
        ->viewData('page')['props']['instance']['subject']['cp_url'];

    expect($cpUrl($this->assignee))->not->toBeNull()
        ->and($cpUrl($this->chief))->not->toBeNull()
        ->and($cpUrl($this->other))->toBeNull();
});

it('leaves collections without a workflow and the setting off alone', function () {
    $news = $this->makeEntry('news', ['title' => 'Elsewhere']);
    expect($this->other->can('edit', $news))->toBeTrue();

    $this->writeWorkflow('simple', $this->minimalWorkflow());
    expect($this->other->can('edit', $this->entry))->toBeTrue();
});

it('never grants more than the role allows', function () {
    $bare = User::make()->email('bare@example.com')->set('name', 'Bare');
    $bare->save();
    app(WorkflowAssigneeStore::class)->set($this->entry->id(), 'simple', [(string) $bare->id()]);

    expect(User::find($bare->id())->can('edit', $this->entry))->toBeFalse();
});

it('refuses unusable values in the writer', function (string $key, mixed $value) {
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['metadata'][$key] = $value;

    expect(fn () => app(WorkflowDefinitionWriter::class)->save('simple', $raw))
        ->toThrow(DefinitionValidationException::class);
})->with([
    'flag not boolean' => ['assignees_only_edit', 'yes'],
    'row without entries' => ['edit_exempt', [['by' => 'group', 'handles' => []]]],
]);
