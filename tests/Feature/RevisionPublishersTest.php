<?php

use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\RevisionPublishers;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;

/*
 * metadata.revision_publishers: while a working copy exists, super admins and
 * this audience may publish it — nobody else, the privileged groups included.
 */

function revisionUser(string $id, array $groups = [], bool $super = false)
{
    $user = User::make()->id($id)->email("{$id}@example.com");

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
    config()->set('statamic.editions.pro', true);
    config()->set('statamic.revisions.enabled', true);
    config()->set('statamic.users.repositories.file.paths.groups', $this->tempPath('users/groups.yaml'));

    UserGroup::make()->handle('chefredaktion')->title('Chefredaktion')->save();
    UserGroup::make()->handle('admins')->title('Admins')->save();

    $this->makeCollection('pages');
    Collection::find('pages')->revisionsEnabled(true)->save();

    $this->writeWorkflow('simple', $this->minimalWorkflow('simple', ['metadata' => [
        'revision_publishers' => [
            ['by' => 'group', 'handles' => ['chefredaktion']],
            ['by' => 'user', 'handles' => ['tester']],
        ],
    ]]));

    $this->entry = $this->makeEntry('pages', ['title' => 'Post']);
    $this->entry->published(true)->save();

    $this->chief = revisionUser('chief', ['chefredaktion']);
    $this->admin = revisionUser('admin', ['admins']);
    $this->editor = revisionUser('editor');
    $this->super = revisionUser('super', super: true);
    $this->tester = revisionUser('tester');
});

it('lets super admins and the audience publish a working copy, nobody else', function () {
    $this->entry->makeWorkingCopy()->save();

    expect($this->super->can('publish', $this->entry))->toBeTrue()
        ->and($this->chief->can('publish', $this->entry))->toBeTrue()
        ->and($this->tester->can('publish', $this->entry))->toBeTrue()
        ->and($this->admin->can('publish', $this->entry))->toBeFalse()
        ->and($this->editor->can('publish', $this->entry))->toBeFalse();
});

it('accepts a role among the mixed audience', function () {
    config()->set('statamic.users.repositories.file.paths.roles', $this->tempPath('users/roles.yaml'));
    Role::make('medical')->title('Medical')->save();
    $this->writeWorkflow('simple', $this->minimalWorkflow('simple', ['metadata' => [
        'revision_publishers' => [
            ['by' => 'group', 'handles' => ['chefredaktion']],
            ['by' => 'role', 'handles' => ['medical']],
        ],
    ]]));
    DefinitionLoader::flush();
    $medic = revisionUser('medic');
    $medic->assignRole('medical')->save();
    $this->entry->makeWorkingCopy()->save();

    expect(User::find('medic')->can('publish', $this->entry))->toBeTrue()
        ->and($this->editor->can('publish', $this->entry))->toBeFalse();
});

it('changes nothing while no working copy exists', function () {
    expect(RevisionPublishers::allows($this->chief, $this->entry))->toBeNull()
        ->and($this->chief->can('publish', $this->entry))->toBeFalse()
        ->and($this->admin->can('publish', $this->entry))->toBeTrue();
});

it('keeps the old rule without the setting', function () {
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    DefinitionLoader::flush();
    $this->entry->makeWorkingCopy()->save();

    expect(RevisionPublishers::allows($this->chief, $this->entry))->toBeNull()
        ->and($this->admin->can('publish', $this->entry))->toBeTrue()
        ->and($this->chief->can('publish', $this->entry))->toBeFalse();
});

it('refuses an unusable block in the writer', function () {
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['metadata']['revision_publishers'] = [['by' => 'group', 'handles' => ['chefredaktion']], ['by' => 'user', 'handles' => []]];

    expect(fn () => app(WorkflowDefinitionWriter::class)->save('simple', $raw))
        ->toThrow(DefinitionValidationException::class);
});
