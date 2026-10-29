<?php

use TakepartMedia\Workflows\Workflow\PersonalWorkItems;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowCommentStore;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/*
 * The personal dashboard cards carry the same comment flag as the listings:
 * open comments, and how many of them the reader has not seen.
 */

it('counts open and unseen comments on each widget row', function () {
    $this->makeCollection('pages');
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $entry = $this->makeEntry('pages', ['title' => 'Post']);
    app(WorkflowStateStore::class)->set($entry->id(), 'simple', 'draft');
    app(WorkflowAssigneeStore::class)->set($entry->id(), 'simple', ['reader']);

    $store = app(WorkflowCommentStore::class);
    $base = ['subject_id' => $entry->id(), 'workflow' => 'simple', 'passage_ref' => 'p1', 'author_name' => 'X'];
    $store->add($base + ['body' => 'Mine', 'user_id' => 'reader']);
    $store->add($base + ['body' => 'Theirs', 'user_id' => 'other']);
    $store->add($base + ['body' => 'Done', 'user_id' => 'other', 'resolved_at' => now()]);

    $row = app(PersonalWorkItems::class)->assignedTo('reader')->first();

    expect($row['comment_count'])->toBe(2)
        ->and($row['new_comment_count'])->toBe(1);
});
