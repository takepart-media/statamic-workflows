<?php

namespace TakepartMedia\Workflows\Listeners;

use Statamic\Events\EntrySaved;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/**
 * When an entry is unpublished directly in the entry editor, move its workflow
 * state back to the initial place (draft). Keeps the workflow in sync with a
 * manual "set to draft" in Statamic itself.
 */
class SyncWorkflowOnUnpublish
{
    public function handle(EntrySaved $event): void
    {
        $entry = $event->entry;

        // Only react to entries that are now unpublished.
        if ($entry->published()) {
            return;
        }

        $resolver = app(WorkflowResolver::class);
        // The workflow that governs THIS version — so unpublishing a translation
        // is judged against the localization workflow, not the authoring one.
        $workflow = $resolver->forEntry($entry);

        if ($workflow === null) {
            return;
        }

        $raw = app(DefinitionLoader::class)->raw($workflow);

        // Workflow doesn't gate publishing — which is the normal case for a
        // localization workflow, so this is where translations drop out.
        if ($resolver->publishPlaces($raw) === []) {
            return;
        }

        $store = app(WorkflowStateStore::class);
        $current = $store->get($entry->id(), $workflow);

        // Only reset when the workflow still thinks it's published — including
        // the revision-pending place, so a fully-unpublished entry that was
        // mid-revision doesn't get stuck there.
        //
        // The `$current !== null` guard is deliberate: without a stored state this
        // listener must not create one, or unpublishing a draft would "resurrect"
        // it into the workflow. That is why it reads the store directly instead of
        // going through WorkflowResolver::stateFor().
        $publishedStates = array_merge($resolver->publishPlaces($raw), ['pending_revision']);

        if ($current !== null && in_array($current, $publishedStates, true)) {
            $store->set($entry->id(), $workflow, $resolver->initialPlace($raw));
        }
    }
}
