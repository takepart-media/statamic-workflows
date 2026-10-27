<?php

use Statamic\Facades\User;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;
use TakepartMedia\Workflows\Workflow\WorkflowTransitioner;
use TakepartMedia\Workflows\Workflow\WorkflowWatchStore;

/*
 * Being assigned starts a watch — for the newly added only, so a deliberate
 * unwatch survives the next save of the same assignment.
 */

beforeEach(function () {
    $this->assignees = app(WorkflowAssigneeStore::class);
    $this->watches = app(WorkflowWatchStore::class);
});

it('watches for everyone newly assigned', function () {
    $this->assignees->set('post-1', 'simple', ['a', 'b']);

    expect($this->watches->isWatching('a', 'post-1', 'simple'))->toBeTrue()
        ->and($this->watches->isWatching('b', 'post-1', 'simple'))->toBeTrue()
        ->and($this->watches->isWatching('a', 'post-1', 'other'))->toBeFalse();
});

it('does not re-subscribe somebody who stays assigned and unwatched', function () {
    $this->assignees->set('post-1', 'simple', ['a']);
    $this->watches->unwatch('a', 'post-1', 'simple');

    $this->assignees->set('post-1', 'simple', ['a', 'b']);

    expect($this->watches->isWatching('a', 'post-1', 'simple'))->toBeFalse()
        ->and($this->watches->isWatching('b', 'post-1', 'simple'))->toBeTrue();
});

it('leaves the watch of somebody who is no longer assigned', function () {
    $this->assignees->set('post-1', 'simple', ['a']);
    $this->assignees->set('post-1', 'simple', ['b']);

    expect($this->watches->isWatching('a', 'post-1', 'simple'))->toBeTrue();
});

it('watches for the people a transition hands the post to', function () {
    $this->makeCollection('pages');
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $entry = $this->makeEntry('pages', ['title' => 'Post']);
    app(WorkflowStateStore::class)->set($entry->id(), 'simple', 'draft');
    tap(User::make()->id('picked')->email('picked@example.com'))->save();

    $raw = app(DefinitionLoader::class)->raw('simple');
    app(WorkflowTransitioner::class)->apply($entry, 'simple', 'submit', $raw, null, ['picked']);

    expect($this->watches->isWatching('picked', $entry->id(), 'simple'))->toBeTrue();
});
