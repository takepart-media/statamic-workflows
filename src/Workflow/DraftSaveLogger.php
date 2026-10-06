<?php

namespace TakepartMedia\Workflows\Workflow;

use TakepartMedia\Workflows\Content\EntrySnapshot;
use TakepartMedia\Workflows\Content\SnapshotDiffer;
use TakepartMedia\Workflows\Support\EventLogSuppressor;

/**
 * Records a `draft_save` event when an editor saves an entry and the save
 * actually changed something — plus the very first save of an entry that has no
 * logged snapshot yet, which is what gives the change check something to compare
 * against (see log()).
 *
 * The workflow History used to jump from step to step: everything an editor did
 * between "Zur Prüfung" and "Freigeben" surfaced in one lump on the later step.
 * This adds the intermediate rows — but only where there is something to show. A
 * save that changed nothing leaves no trace, so the History doesn't fill up with
 * empty rows every time somebody hits Save (or every time the CP autosaves a
 * still-dirty form).
 *
 * "Changed something" means what the History diff would DISPLAY, which is why the
 * check runs through SnapshotDiffer rather than comparing snapshots directly:
 * Bard and Replicator rewrite their set UIDs on every save, so raw inequality is
 * the normal case and proves nothing. The `metadata.history_fields` whitelist is
 * honoured for the same reason — a row whose diff panel then reports "no field
 * changes" would be worse than no row.
 *
 * Called from LogDraftSave (unpublished drafts, which Statamic saves directly) and
 * from SyncWorkflowOnRevision (published entries, whose saves land in a working
 * copy instead).
 */
class DraftSaveLogger
{
    public function __construct(
        private readonly WorkflowStateStore $states,
        private readonly WorkflowEventStore $events,
        private readonly SnapshotDiffer $differ,
    ) {}

    /**
     * Log the save if it changed anything displayable. Returns whether a row was
     * written.
     *
     * @param  object  $entry  The entry to snapshot and to resolve the blueprint from.
     * @param  array<string, mixed>|null  $snapshot  Pre-taken snapshot — pass the working copy's, which `$entry` itself does not hold.
     */
    public function attempt($entry, string $workflow, ?array $snapshot = null, ?string $noteKey = null): bool
    {
        // A failed History note must never break the editor's save. Same stance as
        // WorkflowController::createStatamicRevision() — a convenience, not a
        // dependency of the save. Reported rather than swallowed outright, because
        // a History that silently stops recording looks exactly like a History
        // where nothing happened.
        try {
            return $this->log($entry, $workflow, $snapshot, $noteKey);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function log($entry, string $workflow, ?array $snapshot, ?string $noteKey): bool
    {
        // The addon's own saves (transition, set_state, localize, create) each log
        // their own event with their own snapshot.
        if (EventLogSuppressor::active()) {
            return false;
        }

        $id = (string) $entry->id();

        // No stored state → this entry does not participate in the workflow yet.
        // Also what keeps storePost()'s initial save quiet, which happens before
        // the state row exists.
        if ($this->states->get($id, $workflow) === null) {
            return false;
        }

        $snapshot ??= app(EntrySnapshot::class)->take($entry);

        if ($snapshot === []) {
            return false;
        }

        $baseline = $this->events->latestSnapshot($id, $workflow);

        // No baseline → this entry has never been logged, which is the normal state
        // of every entry that `workflows:seed-states` put into a workflow (the seeder
        // writes states, not events). Log this save unchanged rather than declining:
        // declining would mean an existing project never records a single save until
        // each entry happens to pass through a workflow step, i.e. the feature does
        // nothing at all on the content that already exists.
        //
        // The cost is one row per entry whose diff panel reports "no previous step to
        // compare with" — once, on the first save after this ships. From the second
        // save on, the change check applies exactly.
        if ($baseline === null) {
            $this->write($id, $workflow, $snapshot, $entry, $noteKey);

            return true;
        }

        // Same whitelist historyDiff() renders through, so a row never ends up with
        // a diff panel that reports "no field changes".
        $whitelist = app(DefinitionLoader::class)->historyFields($workflow);

        if (! $this->differ->hasChanges($baseline, $snapshot, $entry, $whitelist)) {
            return false;
        }

        $this->write($id, $workflow, $snapshot, $entry, $noteKey);

        return true;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function write(string $id, string $workflow, array $snapshot, $entry, ?string $noteKey): void
    {
        $this->events->log($id, $workflow, 'draft_save', [
            // No from/to: a save is not a state change, and writing the current
            // place into `to_state` would make saves look like transitions to
            // everything that queries it (TransitionAgedPostsCommand reads
            // `to_state` to find out when an entry last entered its publish place).
            'note' => __('workflows::messages.'.($noteKey ?? $this->noteKey($entry))),
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * Drafts are the normal case. A published entry only reaches this point on a
     * collection without revisions (no Pro licence, or `workflows.revisions` off) —
     * calling that a draft save would be a lie, so it gets its own wording.
     */
    private function noteKey($entry): string
    {
        return method_exists($entry, 'published') && $entry->published()
            ? 'event_note_entry_saved'
            : 'event_note_draft_saved';
    }
}
