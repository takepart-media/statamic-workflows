<?php

namespace TakepartMedia\Workflows\Workflow;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Statamic\Facades\User;

/**
 * Append-only writer + reader for the workflow event log. Every workflow
 * mutation (state transition, admin override, creation, localization) flows
 * through here so the instance summary can render a chronological History.
 */
class WorkflowEventStore
{
    /**
     * The comment-layer events. Their `data_snapshot` holds COMMENT metadata
     * (comment id, passage, body preview), not entry field values — so every
     * reader that treats a snapshot as "the entry at that moment" must skip
     * them: latestSnapshot() here and WorkflowController::historyDiff().
     */
    public const COMMENT_TYPES = [
        'comment_created',
        'comment_edited',
        'comment_deleted',
        'comment_resolved',
        'comment_reopened',
    ];

    /**
     * The two per-workflow AGGREGATES this store answers, memoized for the
     * lifetime of ONE request — the store is bound `scoped`
     * (ServiceProvider::register()), so nothing here outlives the response.
     *
     * Both are asked more than once per dashboard load: the newest-event times
     * back the queue rows' meta line (PersonalWorkItems::rows) AND the "awaiting
     * approval" card's note (QueueStats::oldestSince), and the creator map is
     * there for callers that need who brought a subject in (the personal
     * "My posts" lists go by the entry's `author` field instead).
     *
     * Only the aggregates. The per-subject reads (forSubject, latestOfType,
     * latestSnapshot) stay uncached: they are indexed single-subject queries, and
     * they are the ones a transition reads immediately after writing.
     *
     * INVALIDATION: log() and deleteForSubjects() drop both maps for the workflow
     * they touched, so a read after a write in the same request cannot miss the
     * event that write appended.
     *
     * @var array<string, array<string, string>>
     */
    private array $creators = [];

    /** @var array<string, array<string, \Carbon\Carbon>> */
    private array $latestTimes = [];

    /**
     * Append a single event row. `actor_id` defaults to the current CP user;
     * pass an explicit id when logging from a background context or listener.
     * The `snapshot` key stores the entry's field values (raw) so the History
     * diff view can compare any two events without touching Statamic's
     * revision store — see WorkflowController::historyDiff.
     *
     * @param  array{transition?: ?string, from?: ?string, to?: ?string, user_id?: ?string, note?: ?string, snapshot?: ?array<string, mixed>}  $data
     */
    public function log(string $subjectId, string $workflow, string $type, array $data = []): WorkflowEvent
    {
        // Dropped before the insert rather than after it, which keeps the
        // create() a single returned expression. Discarding a memo one moment
        // early costs one re-read at worst; keeping it one moment too long would
        // hide the row this call is appending.
        $this->forget($workflow);

        return WorkflowEvent::query()->create([
            'subject_id' => $subjectId,
            'workflow' => $workflow,
            'type' => $type,
            'transition' => $data['transition'] ?? null,
            'from_state' => $data['from'] ?? null,
            'to_state' => $data['to'] ?? null,
            'user_id' => $data['user_id'] ?? (string) (User::current()?->id() ?? '') ?: null,
            'note' => $data['note'] ?? null,
            'data_snapshot' => array_key_exists('snapshot', $data) && is_array($data['snapshot'])
                ? $data['snapshot']
                : null,
        ]);
    }

    /**
     * All events for a subject/workflow, oldest first.
     */
    /**
     * The newest event of one type, or null when the subject has none.
     *
     * Deliberately not `forSubject()->firstWhere(...)`: that method orders ASCENDING and
     * loads the whole history, so the caller would get the oldest match — and this one is
     * called from the status endpoint the detail pages poll, where reading a full history
     * every minute is waste. Same shape as WorkflowCommentStore::latestOfKind().
     */
    public function latestOfType(string $subjectId, string $workflow, string $type): ?WorkflowEvent
    {
        return WorkflowEvent::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->where('type', $type)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * When the newest event of one type written while the subject sat in `$place`
     * (`from_state`) was logged, or null. SendPlaceRemindersCommand's "last
     * reminder" — scoped to the place, so a reminder from another place never
     * counts for this one.
     *
     * `from_state` and not `to_state`: a reminder is not an arrival, and
     * subjectIdsEnteringStates() reads every `to_state` as one.
     */
    public function latestOfTypeInPlace(string $subjectId, string $workflow, string $type, string $place): ?Carbon
    {
        $at = WorkflowEvent::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->where('type', $type)
            ->where('from_state', $place)
            ->max('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    public function forSubject(string $subjectId, string $workflow): Collection
    {
        return WorkflowEvent::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The most recent snapshot recorded for a subject, whatever event carried it.
     * This is the baseline a draft save diffs against (DraftSaveLogger).
     *
     * Skips rows without a snapshot rather than stopping at the newest event, so a
     * single legacy row can't blind the change check. Returns null when the subject
     * has no snapshot at all — there is nothing to compare against then, and a
     * "changed since what?" row would be a guess.
     *
     * `first()` rather than `value()`: the latter bypasses the model's `array` cast
     * and would hand back the raw JSON string.
     *
     * @return array<string, mixed>|null
     */
    public function latestSnapshot(string $subjectId, string $workflow): ?array
    {
        $snapshot = WorkflowEvent::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->whereNotNull('data_snapshot')
            ->whereNotIn('type', self::COMMENT_TYPES)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['data_snapshot'])
            ?->data_snapshot;

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * Map of subject_id => creator user_id, derived from the event that brought
     * each subject into the workflow. Bulk lookup for listings (mirrors
     * WorkflowAssigneeStore::byWorkflow). Rows with a null actor
     * (system-created) are dropped.
     *
     * Two event types qualify, one per kind of workflow: `created` for a new
     * entry (`storePost`), `localized` for a translation task (`transfer`).
     * Without the second, the map was always empty for a localization
     * workflow — those subjects never get a `created` row. A subject can only ever
     * carry one of the two within a single workflow: a localization workflow
     * cannot create entries, and creating writes `created` for the collection's
     * OWNING workflow. Ordering oldest-first keeps the pick deterministic
     * regardless.
     *
     * @return array<string, string>
     */
    public function creatorsByWorkflow(string $workflow): array
    {
        return $this->creators[$workflow] ??= WorkflowEvent::query()
            ->where('workflow', $workflow)
            ->whereIn('type', ['created', 'localized'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['subject_id', 'user_id'])
            ->reject(fn ($event) => $event->user_id === null)
            ->keyBy('subject_id')
            ->map(fn ($event) => (string) $event->user_id)
            ->all();
    }

    /**
     * Map of subject_id => Carbon of the NEWEST event, for every subject with
     * any history in this workflow. Bulk lookup for the dashboard widgets'
     * meta line ("seit 3 Tagen" / "heute 09:14"), where the alternative would
     * be one query per row.
     *
     * Aggregated in SQL rather than by loading and grouping the rows: the log
     * of a live workflow runs into five figures, while the answer is one
     * timestamp per subject. `max(created_at)` works on the stored string
     * because the column format is lexicographically ordered.
     *
     * The strings are parsed with Carbon in the app timezone — the same
     * conversion Eloquent's `datetime` cast applies to `created_at`, so these
     * values and `$event->created_at` mean the same instant.
     *
     * @return array<string, \Carbon\Carbon>
     */
    public function latestEventTimesByWorkflow(string $workflow): array
    {
        return $this->latestTimes[$workflow] ??= WorkflowEvent::query()
            ->where('workflow', $workflow)
            // A reminder is the system nagging, not activity on the subject — it
            // must not make a post that nobody touched look freshly worked on.
            ->where('type', '!=', 'reminder_sent')
            ->groupBy('subject_id')
            ->selectRaw('subject_id, max(created_at) as latest_at')
            ->pluck('latest_at', 'subject_id')
            ->reject(fn ($value) => $value === null || $value === '')
            ->map(fn ($value) => Carbon::parse($value))
            ->all();
    }

    /**
     * How many DISTINCT subjects entered one of the given states inside a time
     * window, across every workflow. The figure behind the dashboard's
     * "published this week" card and its previous-week comparison.
     *
     * Transitions rather than stored states, and that is the point of the
     * method: "published this week" is an event, and a subject that went out
     * on Monday and back into revision on Thursday still went out this week —
     * WorkflowStateStore can no longer say so, the log can.
     *
     * Distinct because a subject can enter a green place more than once in a
     * week (published, revised, published again) and the card counts posts,
     * not transitions.
     *
     * Not scoped to a workflow: the caller collects the place handles from the
     * workflows it cares about, and those handles are unique across workflows
     * in practice — a workflow filter would only add a redundant condition.
     *
     * Delegates to subjectIdsEnteringStates() rather than running its own
     * COUNT: the dashboard shows the figure and lists the posts behind it, and
     * two queries answering "how many" and "which ones" separately are two
     * chances to disagree. The window is a calendar week of transitions, so the
     * set this counts is small enough to fetch.
     *
     * @param  array<int, string>  $states  Place handles, as recorded in `to_state`.
     */
    public function subjectsEnteringStates(array $states, Carbon $from, Carbon $to): int
    {
        return array_sum(array_map(
            'count',
            $this->subjectIdsEnteringStates($states, $from, $to)
        ));
    }

    /**
     * The same question, answered with the subjects themselves: DISTINCT
     * subject ids that entered one of the given states in the window, grouped
     * by the workflow whose event recorded it.
     *
     * Grouped by workflow because every consumer needs that grouping anyway —
     * an instance URL is only resolvable with the workflow in hand, and the
     * "+N more" links point at per-workflow manager pages.
     *
     * DISTINCT ACROSS WORKFLOWS, not within each: a subject that entered a green
     * place in two workflows in the same week appears under the first one only.
     * That keeps the sum of the groups equal to the count above — the card's
     * figure and the length of its list have to be the same number, and a post
     * listed twice under two managers would be the same post either way.
     *
     * @param  array<int, string>  $states  Place handles, as recorded in `to_state`.
     * @return array<string, array<int, string>>  workflow => subject ids
     */
    public function subjectIdsEnteringStates(array $states, Carbon $from, Carbon $to): array
    {
        // whereIn with an empty list matches nothing anyway, but a workflow set
        // without a single state in the family is the normal case for a young
        // project, not an edge — so it never reaches the database.
        if ($states === []) {
            return [];
        }

        $rows = WorkflowEvent::query()
            ->whereIn('to_state', array_values(array_unique($states)))
            // Comment events record the state they were written IN as `to_state`
            // (the history shows it); they are not arrivals.
            ->whereNotIn('type', self::COMMENT_TYPES)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->distinct()
            ->get(['workflow', 'subject_id']);

        $seen = [];
        $grouped = [];

        foreach ($rows as $row) {
            $subjectId = (string) $row->subject_id;

            // The DISTINCT above is per (workflow, subject_id) pair, so the same
            // subject can still arrive twice under two workflows. This is where
            // it becomes one.
            if (isset($seen[$subjectId])) {
                continue;
            }

            $seen[$subjectId] = true;
            $grouped[(string) $row->workflow][] = $subjectId;
        }

        return $grouped;
    }

    /**
     * Subject ids that have any history in this workflow, as a lookup map.
     *
     * Seeding writes no events, so a subject absent from this map has never been
     * worked on: its stored state can only have come from a previous seed. That is
     * what lets `workflows:seed-states --redo` re-derive stale rows without
     * touching progress a human made.
     *
     * @return array<string, true>
     */
    public function subjectsWithHistory(string $workflow): array
    {
        return WorkflowEvent::query()
            ->where('workflow', $workflow)
            ->distinct()
            ->pluck('subject_id')
            ->mapWithKeys(fn ($id) => [$id => true])
            ->all();
    }

    /**
     * Drop the history of the given subjects within a workflow. The log stays
     * append-only for everything a user does inside a workflow — this is the one
     * lifecycle exit: when a collection is removed from a workflow's
     * `collections`, its entries are no longer part of it and their history goes
     * with them. Irreversible by design; the caller confirms first.
     *
     * @param  array<int, string>  $subjectIds
     */
    public function deleteForSubjects(string $workflow, array $subjectIds): int
    {
        $deleted = 0;

        foreach (array_chunk(array_values($subjectIds), 500) as $chunk) {
            $deleted += WorkflowEvent::query()
                ->where('workflow', $workflow)
                ->whereIn('subject_id', $chunk)
                ->delete();
        }

        $this->forget($workflow);

        return $deleted;
    }

    /**
     * Drop both memoized aggregate maps of one workflow. Called by every write
     * above; public so a caller that writes the `workflow_events` table around
     * this store (a migration, a test) can say so.
     */
    public function forget(string $workflow): void
    {
        unset($this->creators[$workflow], $this->latestTimes[$workflow]);
    }
}
