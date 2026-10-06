<?php

namespace TakepartMedia\Workflows\Listeners;

use Statamic\Events\EntrySaved;
use TakepartMedia\Workflows\Workflow\DraftSaveLogger;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * Logs a `draft_save` History event when an editor saves a draft.
 *
 * This is the direct-save half of the story. Statamic's CP only routes a save
 * into a working copy when the collection has revisions AND the entry is already
 * published (EntriesController::update); a draft therefore takes the plain
 * `$entry->save()` path and fires EntrySaved with no revision event at all. The
 * working-copy half lives in SyncWorkflowOnRevision, which already owns
 * RevisionSaved and can order the two rows it writes.
 *
 * Whether the save is worth a row is DraftSaveLogger's decision, not this class's.
 */
class LogDraftSave
{
    public function handle(EntrySaved $event): void
    {
        $entry = $event->entry;

        if (! is_object($entry) || ! method_exists($entry, 'collectionHandle')) {
            return;
        }

        // Per entry version: a translation's draft saves belong in the
        // localization workflow's history, next to its own transitions — not in
        // the authoring workflow's, where nothing else about it is recorded.
        $workflow = app(WorkflowResolver::class)->forEntry($entry);

        if ($workflow === null) {
            return;
        }

        app(DraftSaveLogger::class)->attempt($entry, $workflow);
    }
}
