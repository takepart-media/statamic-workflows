<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * Reads/writes persisted workflow states. This is the foundation of the
 * (eventual) workflow engine — for now it just tracks the current place of each
 * subject per workflow in the dedicated `workflow` SQLite database.
 */
class WorkflowStateStore
{
    /**
     * statesFor() answers already read in THIS request, per workflow. The store
     * is bound `scoped` (ServiceProvider::register()), so the memo dies with the
     * request — it deduplicates one page load, it is not a cache.
     *
     * Worth having because the whole table for a workflow gets read several
     * times per dashboard load: the stats row's "awaiting approval" card
     * (QueueStats::subjectsAtGates), its "failed translations" card through
     * FailedTranslationRetry::subjects(), and one read per ring in
     * WorkflowStatus — the same rows, three times, for three different
     * questions.
     *
     * INVALIDATION: every write method below drops the entry for the workflow it
     * touched. The retry endpoint transitions subjects and then re-counts what
     * is left (FailedTranslationRetry::run() → count()) inside the same request,
     * so a memo that survived a write would report the queue it just emptied.
     *
     * @var array<string, array<string, string>>
     */
    private array $memo = [];

    /** @var array<string, array<string, \Illuminate\Support\Carbon>> enteredAtTimes() per workflow — dropped with $memo */
    private array $enteredMemo = [];

    /**
     * Current state for every subject in a workflow, keyed by subject id.
     *
     * @return array<string, string>
     */
    public function statesFor(string $workflow): array
    {
        return $this->memo[$workflow] ??= WorkflowState::query()
            ->where('workflow', $workflow)
            ->pluck('state', 'subject_id')
            ->all();
    }

    /**
     * Deliberately NOT served from the statesFor() memo: this is the per-subject
     * read the resolver does, it is a single indexed row, and answering it from
     * a whole-table memo would mean a request that asks about one subject loads
     * every state of the workflow to do it. Measured on the reference project
     * (282 calls in one dashboard pass): 11 ms in total, i.e. nothing to win.
     */
    public function get(string $subjectId, string $workflow): ?string
    {
        return WorkflowState::query()
            ->where('workflow', $workflow)
            ->where('subject_id', $subjectId)
            ->value('state');
    }

    /**
     * `entered_at` moves only when the place really changes: a self-loop keeps
     * the arrival time, so it does not restart the `reminder` clock.
     */
    public function set(string $subjectId, string $workflow, string $state): void
    {
        $row = WorkflowState::query()->firstOrNew(['subject_id' => $subjectId, 'workflow' => $workflow]);

        if (! $row->exists || $row->state !== $state || $row->entered_at === null) {
            $row->entered_at = now();
        }

        $row->state = $state;
        $row->save();

        $this->forget($workflow);
    }

    /**
     * Arrival day of every subject in a workflow, keyed by subject id, as a short
     * date in the CP's language — the small date behind the listings' state badge. One query per
     * workflow and request, same reasoning as statesFor().
     *
     * @return array<string, string>
     */
    public function enteredAtLabels(string $workflow): array
    {
        return array_map(
            fn ($time) => $time->locale(app()->getLocale())->isoFormat('L'),
            $this->enteredAtTimes($workflow),
        );
    }

    /**
     * Arrival time of every subject in a workflow whose arrival is known, keyed
     * by subject id. One query per workflow and request; enteredAtLabels() and
     * the listings' "waiting longer than" filter both read it.
     *
     * @return array<string, \Illuminate\Support\Carbon>
     */
    public function enteredAtTimes(string $workflow): array
    {
        return $this->enteredMemo[$workflow] ??= WorkflowState::query()
            ->where('workflow', $workflow)
            ->whereNotNull('entered_at')
            ->get(['subject_id', 'entered_at'])
            ->mapWithKeys(fn (WorkflowState $row) => [$row->subject_id => $row->entered_at])
            ->all();
    }

    /**
     * When the subject arrived in its current place, or null when unknown.
     */
    public function enteredAt(string $subjectId, string $workflow): ?\Illuminate\Support\Carbon
    {
        return WorkflowState::query()
            ->where('workflow', $workflow)
            ->where('subject_id', $subjectId)
            ->first()?->entered_at;
    }

    /**
     * When each subject in the given place arrived there, keyed by subject id.
     * Null for a row whose arrival is unknown.
     *
     * @return array<string, \Illuminate\Support\Carbon|null>
     */
    public function enteredAtInState(string $workflow, string $state): array
    {
        return WorkflowState::query()
            ->where('workflow', $workflow)
            ->where('state', $state)
            ->get(['subject_id', 'entered_at'])
            ->mapWithKeys(fn (WorkflowState $row) => [$row->subject_id => $row->entered_at])
            ->all();
    }

    /**
     * All subject ids currently sitting in the given (workflow, state) pair.
     * Used by the workflow settings editor to preview which entries would be
     * affected when a place is about to be deleted.
     *
     * @return array<int, string>
     */
    public function subjectsInState(string $workflow, string $state): array
    {
        return WorkflowState::query()
            ->where('workflow', $workflow)
            ->where('state', $state)
            ->pluck('subject_id')
            ->all();
    }

    /**
     * Bulk-migrate every subject in `$from` to `$to` for the given workflow.
     * Returns the number of rows moved. Used when deleting a place from the
     * settings editor — the caller supplies the target place the affected
     * entries should land in.
     */
    public function migrateState(string $workflow, string $from, string $to): int
    {
        $moved = WorkflowState::query()
            ->where('workflow', $workflow)
            ->where('state', $from)
            ->update(['state' => $to, 'entered_at' => now()]);

        $this->forget($workflow);

        return $moved;
    }

    /**
     * Drop the stored states of the given subjects within a workflow. Used when
     * a collection is removed from a workflow's `collections` — its entries stop
     * participating, so their workflow rows go with them.
     *
     * Chunked because the workflow tables live on a SQLite connection, which
     * caps a statement at ~999 bound variables.
     *
     * @param  array<int, string>  $subjectIds
     */
    public function deleteForSubjects(string $workflow, array $subjectIds): int
    {
        $deleted = 0;

        foreach (array_chunk(array_values($subjectIds), 500) as $chunk) {
            $deleted += WorkflowState::query()
                ->where('workflow', $workflow)
                ->whereIn('subject_id', $chunk)
                ->delete();
        }

        $this->forget($workflow);

        return $deleted;
    }

    /**
     * Drop the memoized state map of one workflow. Called by every write above;
     * public so a caller that writes the `workflow_states` table around this
     * store (a migration, a test) can say so.
     */
    public function forget(string $workflow): void
    {
        unset($this->memo[$workflow], $this->enteredMemo[$workflow]);
    }
}
