<?php

namespace TakepartMedia\Workflows\Listeners;

use Statamic\Events\EntrySaved;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\StaleLocalizations;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * When a post goes to Statamic draft, its other versions follow — into draft and
 * into the place their own workflow names (`metadata.source_draft_place`).
 *
 * Hangs on the entry's published flag rather than on a particular place, so it
 * covers both routes to the same situation: the ageing step that unpublishes a
 * stale post, and somebody withdrawing a post by hand. Both mean the source of
 * every translation just disappeared from the site.
 *
 * Only the ROOT triggers it. That is also what rules out recursion: the demoted
 * versions are saved in turn and raise their own EntrySaved, and every one of them
 * fails the isRoot() test.
 *
 * Registered alongside SyncWorkflowOnUnpublish, which answers the neighbouring
 * question about the entry being saved itself; this one is about the other
 * versions of the same post.
 */
class CascadeSourceDraft
{
    public function handle(EntrySaved $event): void
    {
        $entry = $event->entry;

        if (! method_exists($entry, 'isRoot') || ! $entry->isRoot()) {
            return;
        }

        if ($entry->published()) {
            return;
        }

        $collection = method_exists($entry, 'collectionHandle') ? $entry->collectionHandle() : null;

        if ($collection === null) {
            return;
        }

        $resolver = app(WorkflowResolver::class);
        $workflow = $resolver->forCollection($collection);

        if ($workflow === null) {
            return;
        }

        // Only an authoring workflow's post is a source others depend on. A
        // collection governed solely by a localization workflow has no post whose
        // draft state could mean anything to a sibling version.
        if ($resolver->isLocalizationWorkflow(app(DefinitionLoader::class)->raw($workflow))) {
            return;
        }

        app(StaleLocalizations::class)->cascade($entry);
    }
}
