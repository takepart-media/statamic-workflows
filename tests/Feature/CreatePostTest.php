<?php

use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use TakepartMedia\Workflows\Http\Controllers\WorkflowController;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/*
 * The one "New post" button: its workflow select lists exactly the workflows
 * the user may create in (overviewProps()['creates']), and storePost refuses
 * any other.
 */

function creator(string $name, array $groups = [], bool $super = false)
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
    Role::make('editor')->title('Editor')->permissions(['access cp', 'view pages entries', 'view news entries'])->save();
    UserGroup::make()->handle('redaktion')->title('Redaktion')->save();

    $this->makeCollection('pages');
    $this->makeCollection('news');

    // `simple`: no own creator groups → the config default ([]) → super admins only.
    $this->writeWorkflow('simple', $this->minimalWorkflow('simple'));
    $this->writeWorkflow('newsroom', $this->minimalWorkflow('newsroom', [
        'collections' => ['news'],
        'metadata' => ['label' => 'Newsroom', 'post_creator_groups' => ['redaktion']],
    ]));

    $this->admin = creator('Ada Admin', super: true);
    $this->editor = creator('Rita Redaktion', ['redaktion']);
    $this->outsider = creator('Otto Outsider');
});

function creatableFor($user): array
{
    test()->actingAs($user);

    return collect(app(WorkflowController::class)->overviewProps(null)['creates'] ?? [])
        ->pluck('workflow')
        ->all();
}

it('offers each user exactly the workflows they may create in', function () {
    expect(creatableFor($this->admin))->toEqualCanonicalizing(['newsroom', 'simple'])
        ->and(creatableFor($this->editor))->toBe(['newsroom'])
        ->and(creatableFor($this->outsider))->toBe([]);
});

it('gives every offered workflow what the modal needs', function () {
    $this->actingAs($this->editor);
    $create = app(WorkflowController::class)->overviewProps(null)['creates'][0];

    expect($create)->toHaveKeys(['workflow', 'label', 'createUrl', 'createCta', 'createCollections'])
        ->and($create['createCollections'])->toBe([['value' => 'news', 'label' => 'News']]);
});

it('refuses a workflow the user may not create in', function () {
    $this->actingAs($this->editor)
        ->postJson(cp_route('workflows.api.posts'), ['workflow' => 'simple', 'title' => 'Nope'])
        ->assertForbidden();
});

it('creates the post in the chosen workflow', function () {
    $id = $this->actingAs($this->editor)
        ->postJson(cp_route('workflows.api.posts'), ['workflow' => 'newsroom', 'collection' => 'news', 'title' => 'Hello'])
        ->assertOk()
        ->json('data.id');

    expect(app(WorkflowStateStore::class)->get($id, 'newsroom'))->toBe('draft');
});
