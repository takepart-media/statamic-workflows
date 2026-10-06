<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowCommentStore;
use TakepartMedia\Workflows\Workflow\WorkflowDataPurger;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * Removes workflow rows that no longer describe anything.
 *
 * The counterpart of `workflows:seed-states`: that one fills in states for entries
 * a workflow gained, this one drops the ones it lost. Four reasons a row stops
 * meaning something, reported separately because they deserve different trust:
 *
 * - `deleted`   the entry is gone. Nothing can point at it again.
 * - `ungoverned` the entry's collection is not in the workflow's `collections` any
 *                more. Same thing the settings editor purges when a collection is
 *                removed there — this catches the YAML edited by hand.
 * - `foreign`   the version sits in a site this workflow is not responsible for.
 *                Happens to a localization manager when its source site changes:
 *                with `translation_source_site: simple` every German version's
 *                translation state is a leftover of the previous setting.
 * - `unstarted` a localization workflow holds a state for a post that is not
 *                published. Such a post is not part of the manager at all
 *                (WorkflowResolver::derivedState answers null), so the row claims a
 *                readiness that the manager itself denies — typically left behind by
 *                a backfill that ran before that rule existed.
 *
 * Deliberately NOT pruned: a row that merely repeats what derivedState() would
 * compute. Stored state is sticky, derived state follows the entry — dropping such
 * a row would silently re-derive the post manager's `published` back to the initial
 * place the next time somebody unpublishes. That is a behaviour change, not a
 * cleanup.
 *
 * `deleted` is unconditional. The others spare any subject that carries history,
 * assignees or comments in that workflow: somebody worked there, and a row with work
 * behind it is evidence, not litter.
 */
class PruneStatesCommand extends Command
{
    protected $signature = 'workflows:prune-states
        {--workflow= : Prune only this workflow (defaults to all, including switched-off ones)}
        {--dry-run : Report what would go, change nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Remove workflow rows whose subject is gone, ungoverned, in a site this workflow does not answer for, or not published yet.';

    public function handle(
        DefinitionLoader $loader,
        WorkflowResolver $resolver,
        WorkflowDataPurger $purger,
        WorkflowEventStore $events,
        WorkflowAssigneeStore $assignees,
        WorkflowCommentStore $comments,
    ): int {
        $only = $this->option('workflow');
        $dryRun = (bool) $this->option('dry-run');

        // Inactive workflows included on purpose: switched off is not deleted, and
        // their leftovers are just as dead.
        $available = $loader->allIncludingInactive();
        $names = $only ? [$only] : $available;

        if ($only && ! in_array($only, $available, true)) {
            $this->warn("Workflow [{$only}] not found — nothing to prune.");

            return self::SUCCESS;
        }

        $plan = [];
        $rows = [];

        foreach ($names as $workflow) {
            $raw = $loader->raw($workflow);
            $governed = (array) ($raw['collections'] ?? []);
            $managed = $resolver->managedSites($workflow);
            $isLocalization = $resolver->isLocalizationWorkflow($raw);

            // Protected: anything somebody has actually worked on.
            $protected = $events->subjectsWithHistory($workflow)
                + array_map(fn () => true, $assignees->byWorkflow($workflow))
                + array_map(fn () => true, $comments->countBySubject($workflow));

            $buckets = ['deleted' => [], 'ungoverned' => [], 'foreign' => [], 'unstarted' => [], 'protected' => 0];

            foreach ($purger->subjectIds($workflow) as $subjectId) {
                $entry = Entry::find($subjectId);

                if ($entry === null) {
                    $buckets['deleted'][] = $subjectId;

                    continue;
                }

                $reason = null;

                if (! in_array($entry->collectionHandle(), $governed, true)) {
                    $reason = 'ungoverned';
                } elseif ($isLocalization && ! in_array((string) $entry->locale(), $managed, true)) {
                    $reason = 'foreign';
                } elseif ($isLocalization && ! $resolver->postIsPublished($entry)) {
                    $reason = 'unstarted';
                }

                if ($reason === null) {
                    continue;
                }

                if (isset($protected[$subjectId])) {
                    $buckets['protected']++;

                    continue;
                }

                $buckets[$reason][] = $subjectId;
            }

            $total = $this->countPruneable($buckets);

            if ($total === 0 && $buckets['protected'] === 0) {
                continue;
            }

            $plan[$workflow] = $buckets;
            $rows[] = [
                $workflow,
                count($buckets['deleted']),
                count($buckets['ungoverned']),
                count($buckets['foreign']),
                count($buckets['unstarted']),
                $buckets['protected'],
            ];
        }

        if ($rows === []) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        $this->table(['workflow', 'deleted', 'ungoverned', 'foreign', 'unstarted', 'protected (kept)'], $rows);

        $subjects = collect($plan)->sum(fn (array $b) => $this->countPruneable($b));

        if ($subjects === 0) {
            $this->info('Nothing to prune — everything found is protected.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line("Dry run: {$subjects} subject(s) would be erased from the tables above. Nothing was changed.");

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Erase every workflow row of {$subjects} subject(s)? This cannot be undone.")) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $removed = [];

        foreach ($plan as $workflow => $buckets) {
            $ids = array_merge(
                $buckets['deleted'],
                $buckets['ungoverned'],
                $buckets['foreign'],
                $buckets['unstarted'],
            );

            foreach ($purger->purge($workflow, $ids) as $table => $count) {
                $removed[$table] = ($removed[$table] ?? 0) + $count;
            }
        }

        foreach ($removed as $table => $count) {
            $this->line(" • {$table}: {$count} row(s)");
        }

        $this->info("Pruned {$subjects} subject(s).");

        return self::SUCCESS;
    }

    /**
     * Subjects in a workflow's buckets that would actually go. `protected` is a
     * count, not a list, and never part of it.
     *
     * @param  array<string, mixed>  $buckets
     */
    private function countPruneable(array $buckets): int
    {
        return count($buckets['deleted'])
            + count($buckets['ungoverned'])
            + count($buckets['foreign'])
            + count($buckets['unstarted']);
    }
}
