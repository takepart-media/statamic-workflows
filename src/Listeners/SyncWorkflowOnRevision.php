<?php

namespace TakepartMedia\Workflows\Listeners;

use Statamic\Events\RevisionDeleted;
use Statamic\Events\RevisionSaved;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Content\EntrySnapshot;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DraftSaveLogger;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/**
 * Keeps the workflow state in sync with Statamic's working-copy lifecycle when
 * the governed collection has revisions enabled: as soon as a published entry
 * gains an unpublished working copy it moves to `pending_revision`, and once
 * that working copy is published (no working copy left) it returns to the
 * publish place. Driven off RevisionSaved / RevisionDeleted and idempotent —
 * it reconciles against the entry's actual hasWorkingCopy() state rather than
 * the specific event, so the two steps move back and forth automatically.
 *
 * It also logs the draft saves of a working copy (see logWorkingCopySave). That
 * belongs here rather than in a second RevisionSaved listener because Statamic
 * gives no ordering guarantee between listeners on the same event, and these two
 * rows must appear in order: first the step ("Arbeitskopie angelegt"), then what
 * the save changed.
 */
class SyncWorkflowOnRevision
{
    public function handle(RevisionSaved|RevisionDeleted $event): void
    {
        $revision = $event->revision ?? null;

        if ($revision === null) {
            return;
        }

        // Revision keys are paths: collections/<collection>/<locale>/<id>.
        $parts = explode('/', (string) $revision->key());
        $id = end($parts);

        if (! $id || ! ($entry = Entry::find($id))) {
            return;
        }

        if (! $entry->revisionsEnabled()) {
            return;
        }

        $resolver = app(WorkflowResolver::class);
        // Per entry version — a translation's working copies belong to the
        // localization workflow, same as its draft saves and transitions.
        $workflow = $resolver->forEntry($entry);

        if ($workflow === null) {
            return;
        }

        $this->syncState($resolver, $entry, $workflow);

        // Deliberately outside syncState(): a draft save is worth recording whether
        // or not this workflow gates publishing, and syncState() bails early when it
        // doesn't. Kept AFTER it so the two rows land in reading order — the step
        // ("Arbeitskopie angelegt") before what the save changed.
        if ($event instanceof RevisionSaved) {
            $this->logWorkingCopySave($revision, $entry, $workflow);
        }
    }

    /**
     * Move the entry between its publish place and `pending_revision` to match
     * whether a working copy currently exists.
     */
    private function syncState(WorkflowResolver $resolver, $entry, string $workflow): void
    {
        $raw = app(DefinitionLoader::class)->raw($workflow);
        $publishPlaces = $resolver->publishPlaces($raw);

        if ($publishPlaces === []) {
            return; // Workflow doesn't gate publishing.
        }

        $store = app(WorkflowStateStore::class);
        $current = $store->get($entry->id(), $workflow);

        // Only reconcile a known, settled state — never resurrect an entry that
        // is still in draft/review just because a working copy was touched.
        if ($current === null) {
            return;
        }

        $events = app(WorkflowEventStore::class);

        if ($entry->hasWorkingCopy() && in_array($current, $publishPlaces, true)) {
            $store->set($entry->id(), $workflow, 'pending_revision');
            $events->log($entry->id(), $workflow, 'revision_sync', [
                'from' => $current,
                'to' => 'pending_revision',
                'user_id' => null,
                'note' => __('workflows::messages.event_note_revision_created'),
                // Without a snapshot this event becomes a dead end for the History
                // diff: it is picked as the comparison point for the NEXT event,
                // which then reports `no_diff_data` too.
                'snapshot' => app(EntrySnapshot::class)->take($entry),
            ]);
        } elseif (! $entry->hasWorkingCopy() && $current === 'pending_revision') {
            $store->set($entry->id(), $workflow, $publishPlaces[0]);
            // Publishing a working copy IS bringing the content up to date, so it
            // resets the ageing deadline — same rule as reaching a publish place
            // through a transition (WorkflowTransitioner::stampContentTimestamp()).
            // Without this an ordinary editorial revision would leave the clock
            // untouched and the post would age as if nobody had worked on it.
            app(ContentTimestamp::class)->touch($entry);
            $events->log($entry->id(), $workflow, 'revision_sync', [
                'from' => $current,
                'to' => $publishPlaces[0],
                'user_id' => null,
                'note' => __('workflows::messages.event_note_revision_published'),
                'snapshot' => app(EntrySnapshot::class)->take($entry),
            ]);
        }
    }

    /**
     * Record a working-copy save as a `draft_save`.
     *
     * A published entry's edits never touch the stored entry — Statamic diverts
     * them into a working copy, so `$entry` here still holds the LIVE version and
     * snapshotting it would report no changes forever. `fromWorkingCopy()` rebuilds
     * the entry from the revision that was just persisted (`Revision::save()`
     * stores before it dispatches), which is what the editor actually typed.
     *
     * Only the first working-copy save produced a History row before this: the
     * `revision_sync` above fires on the state flip, and every later save of the
     * same working copy flips nothing. Those saves are now visible too.
     */
    private function logWorkingCopySave($revision, $entry, string $workflow): void
    {
        // Manual revisions (the addon writes one per workflow step) and the
        // publish/unpublish revisions are not draft saves.
        if (! method_exists($revision, 'isWorkingCopy') || ! $revision->isWorkingCopy()) {
            return;
        }

        try {
            $workingCopy = $entry->fromWorkingCopy();
        } catch (\Throwable $e) {
            return;
        }

        $snapshot = app(EntrySnapshot::class)->take($workingCopy);

        if ($snapshot === []) {
            return;
        }

        // A working copy is by definition unpublished work, whatever the live
        // entry's published flag says — hence the explicit note key.
        app(DraftSaveLogger::class)->attempt($entry, $workflow, $snapshot, 'event_note_draft_saved');
    }
}
