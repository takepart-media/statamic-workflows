<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Keeps an entry's Statamic published flag in step with its workflow state:
 * entering a place with `metadata.allow_publish` publishes it, any other place
 * unpublishes it. This is what makes a "… & publish" step actually publish.
 *
 * One implementation, three callers — WorkflowTransitioner (every transition),
 * WorkflowController::setState() (the manual override) and StaleLocalizations
 * (a post's versions following it into draft). It lived twice before, verbatim, in
 * the first two; a third copy for the cascade was the moment to stop.
 */
class PublishedFlag
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function sync(EntryContract $entry, array $raw, string $state): void
    {
        // Revision-pending places keep the live (published) version online while an
        // unpublished working copy exists — never touch the published flag.
        if (($raw['places'][$state]['metadata']['revision_pending'] ?? false) === true) {
            return;
        }

        $publishPlaces = app(WorkflowResolver::class)->publishPlaces($raw);

        // No publish place → this workflow does not gate publishing. Without this
        // guard a state change in a secondary workflow would unpublish live content
        // that the authoring workflow is perfectly happy with.
        if ($publishPlaces === []) {
            return;
        }

        $shouldPublish = in_array($state, $publishPlaces, true);

        if ($entry->published() !== $shouldPublish) {
            $entry->published($shouldPublish)->save();
        }
    }
}
