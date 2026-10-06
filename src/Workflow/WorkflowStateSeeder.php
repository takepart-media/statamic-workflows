<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Facades\Entry;

/**
 * Backfills workflow states for entries that already existed before a workflow
 * governed them. Without this, such entries have no row at all: the queue shows
 * them via the derived default, but nothing is persisted — so they never take
 * part in SLA aging, and every workflow that is not the collection's owner sees
 * an empty personal queue (participation is signalled by a stored state).
 *
 * The written value comes from `WorkflowResolver::derivedState()`, the same
 * authority the read paths use, so seeding can never disagree with what the UI
 * was already showing.
 *
 * Deliberately narrow: it writes state rows and nothing else. No published-flag
 * sync (seeding must not publish or unpublish content), no assignee sync, no
 * events, no revisions — backfilling several hundred entries should not produce
 * several hundred history entries for something no user did.
 *
 * Idempotent: an entry that already has a state for the workflow is skipped, so
 * running it again after adding a collection only touches the new entries.
 */
class WorkflowStateSeeder
{
    public function __construct(
        private DefinitionLoader $loader,
        private WorkflowResolver $resolver,
        private WorkflowStateStore $states,
        private WorkflowEventStore $events,
    ) {}

    /**
     * Seed one workflow. Pass `$collections` to restrict the run to a subset of
     * the workflow's governed collections — that is what the settings editor does
     * when a collection is newly added.
     *
     * Covers every localization: an unfiltered entry query returns each site
     * version as its own entry with its own id, which is the granularity
     * `workflow_states.subject_id` uses everywhere else in the addon.
     *
     * With `$redo` the derivation is applied again to entries that already carry a
     * state — but only to those nobody has worked on. Seeding writes no events, so
     * "has no event in this workflow" is an exact proof that the current value can
     * only have come from a previous seed. An entry someone actually moved has a
     * `transition` or `set_state` event and is never overwritten.
     *
     * @param  array<int, string>|null  $collections
     * @return int  Number of state rows written.
     */
    public function seed(string $workflow, ?array $collections = null, bool $redo = false): int
    {
        if (! in_array($workflow, $this->loader->all(), true)) {
            return 0;
        }

        $raw = $this->loader->raw($workflow);
        $governed = (array) ($raw['collections'] ?? []);

        // Never seed a collection the workflow doesn't actually govern.
        $targets = $collections === null
            ? $governed
            : array_values(array_intersect($governed, $collections));

        if ($targets === []) {
            return 0;
        }

        // One query for the whole set instead of a lookup per entry.
        $existing = $this->states->statesFor($workflow);

        // Subjects a human has already moved. Only needed for a redo, where they
        // are the rows that must survive untouched.
        $touched = $redo ? $this->events->subjectsWithHistory($workflow) : [];
        $seeded = 0;

        // Sites this workflow answers for. A localization manager governs the whole
        // collection but only some of its sites, and seeding the rest would write
        // rows its own listing never shows — the very rows
        // `workflows:prune-states` reports as `foreign`. Null for a workflow that
        // is not site-specific: everything counts.
        $managedSites = $this->resolver->isLocalizationWorkflow($raw)
            ? $this->resolver->managedSites($workflow)
            : null;

        // Group by logical post, the way the manager listing does. The group names
        // every site the post exists in, for every version at once, so
        // derivedState() needs no per-entry descendant lookup.
        Entry::query()
            ->whereIn('collection', $targets)
            ->get()
            ->groupBy(fn ($entry) => $entry->root()->id())
            ->each(function ($group) use ($raw, $workflow, $existing, $touched, $redo, $managedSites, &$seeded) {
                $groupLocales = $group->map(fn ($entry) => (string) $entry->locale())
                    ->unique()
                    ->values()
                    ->all();

                foreach ($group as $entry) {
                    $id = $entry->id();

                    if ($managedSites !== null && ! in_array((string) $entry->locale(), $managedSites, true)) {
                        continue;
                    }
                    $hasState = array_key_exists($id, $existing);

                    if ($hasState && ! $redo) {
                        continue;
                    }

                    // Never overwrite progress somebody made by hand.
                    if ($hasState && array_key_exists($id, $touched)) {
                        continue;
                    }

                    $derived = $this->resolver->derivedState($raw, $entry, $groupLocales);

                    // No derived state means the entry is not part of this workflow —
                    // an unpublished post in a localization manager. Backfilling would
                    // put it in `source_ready` and claim it is ready to translate,
                    // which is exactly what the null answer exists to prevent. Rows
                    // that are already there stay: removing them is
                    // `workflows:prune-states`' job, which asks about history first.
                    if ($derived === null) {
                        continue;
                    }

                    if ($hasState && $existing[$id] === $derived) {
                        continue;
                    }

                    $this->states->set($id, $workflow, $derived);
                    $seeded++;
                }
            });

        return $seeded;
    }

    /**
     * True when the workflow declares no place meaning "finished", so every
     * already-published entry is seeded into the initial place. Callers surface
     * this as a hint — it is a legitimate setup, not an error.
     */
    public function lacksDonePlace(string $workflow): bool
    {
        return $this->resolver->donePlace($this->loader->raw($workflow)) === null;
    }
}
