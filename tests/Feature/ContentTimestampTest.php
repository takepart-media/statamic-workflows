<?php

use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;
use TakepartMedia\Workflows\Workflow\WorkflowTransitioner;

/*
 * `content_updated_at`: stamped by the addon and super-admin-only by default;
 * `workflows.content_timestamp` turns it into a date kept by hand, editable by
 * holders of ContentTimestamp::PERMISSION.
 */

function timestampUser(string $id, ?string $role = null, bool $super = false)
{
    $user = User::make()->id($id)->email("{$id}@example.com");

    $super && $user->makeSuper();
    $role && $user->assignRole($role);

    $user->save();

    return User::find($id);
}

// What a CP save does, up to the point the guard sees it. Dispatched by hand
// instead of through save(): save() looks the entry up first, and the array
// cache of the test environment hands back this very instance with its
// originals re-synced, which a real cache store never does.
function saveWithDate($entry, string $date): mixed
{
    $entry->set(ContentTimestamp::FIELD, $date);

    EntrySaving::dispatch($entry);

    return $entry->get(ContentTimestamp::FIELD);
}

beforeEach(function () {
    config()->set('statamic.users.repositories.file.paths.roles', $this->tempPath('users/roles.yaml'));

    Role::make('editor')->title('Editor')->permissions(['access cp'])->save();
    Role::make('dater')->title('Dater')->permissions(['access cp', ContentTimestamp::PERMISSION])->save();

    $this->makeCollection('pages');
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $this->entry = $this->makeEntry('pages', ['title' => 'Post']);
});

function approve($entry, array $raw): mixed
{
    app(WorkflowStateStore::class)->set($entry->id(), 'simple', 'review');

    app(WorkflowTransitioner::class)->apply($entry, 'simple', 'approve', $raw, notify: false);

    return Entry::find($entry->id())->get(ContentTimestamp::FIELD);
}

it('stamps the clock on approval by default', function () {
    expect(approve($this->entry, $this->minimalWorkflow()))->toBe(ContentTimestamp::stamp(now()));
});

it('leaves the clock alone on approval when kept by hand', function () {
    config()->set('workflows.content_timestamp.auto', false);

    expect(approve($this->entry, $this->minimalWorkflow()))->toBeNull();
});

it('registers the permission only when opted in', function (bool $enabled) {
    config()->set('workflows.content_timestamp.permission', $enabled);

    expect(Permission::boot()->get(ContentTimestamp::PERMISSION) !== null)->toBe($enabled);
})->with([true, false]);

it('lets only a super admin set the date by default', function (string $id, ?string $role, bool $super, ?string $kept) {
    $this->actingAs(timestampUser($id, $role, $super));

    expect(saveWithDate($this->entry, '2026-11-20'))->toBe($kept);
})->with([
    'role with the permission' => ['dater', 'dater', false, null],
    'role without it' => ['editor', 'editor', false, null],
    'super admin' => ['root', null, true, '2026-11-20'],
]);

it('lets holders of the permission set the date when opted in', function (string $id, ?string $role, bool $super, ?string $kept) {
    config()->set('workflows.content_timestamp.permission', true);

    $this->actingAs(timestampUser($id, $role, $super));

    expect(saveWithDate($this->entry, '2026-11-20'))->toBe($kept);
})->with([
    'role with the permission' => ['dater', 'dater', false, '2026-11-20'],
    'role without it' => ['editor', 'editor', false, null],
    'super admin' => ['root', null, true, '2026-11-20'],
]);
