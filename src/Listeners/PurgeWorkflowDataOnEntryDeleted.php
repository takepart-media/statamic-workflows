<?php

namespace TakepartMedia\Workflows\Listeners;

use Statamic\Events\EntryDeleted;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowDataPurger;

/**
 * When an entry is deleted, take its workflow data with it.
 *
 * Without this the rows simply stay: the reference project had 32 state rows, 95
 * history rows and 9 assignments pointing at entries that no longer exist, and
 * nothing in the CP could reach them again. `workflows:prune-states` cleans that
 * up after the fact; this is what keeps it from coming back.
 *
 * Across ALL workflows, including switched-off ones, and without asking: unlike a
 * collection leaving a workflow (where the settings editor confirms first, because
 * the entries live on), there is nothing left to preserve here. Statamic deletes a
 * localization on its own when its origin goes, and each version fires its own
 * event — so a whole post's worth of versions is cleaned up one event at a time.
 */
class PurgeWorkflowDataOnEntryDeleted
{
    public function handle(EntryDeleted $event): void
    {
        $id = (string) ($event->entry->id() ?? '');

        if ($id === '') {
            return;
        }

        $purger = app(WorkflowDataPurger::class);

        foreach (app(DefinitionLoader::class)->allIncludingInactive() as $workflow) {
            $purger->purge($workflow, [$id]);
        }
    }
}
