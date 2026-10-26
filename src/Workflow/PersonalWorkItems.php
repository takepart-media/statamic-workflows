<?php

namespace TakepartMedia\Workflows\Workflow;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * One user's work items across every active workflow — the rows behind the
 * cross-workflow dashboard widgets (MyQueue, CreatedByMe, Watched).
 *
 * Semantics deliberately mirror the manager listings so the widget and the
 * manager never disagree about the same user: "assigned" means a direct
 * assignee row (the listing's `mine=1`, no group expansion), "authored" means
 * the entry's `author` field names the user (the listing's `created_by_me=1`,
 * read through Entry::authors(), so a localization inherits its origin's
 * authors), "watched" means a bookmark row of the user's own
 * (WorkflowWatchStore).
 *
 * The same localization can appear once per workflow that tracks it (owning
 * plus localization workflow). Both rows are kept: the managers show it the
 * same way, and the workflow label on the row disambiguates.
 */
class PersonalWorkItems
{
    /**
     * Badge colors handed out to workflows by their position in
     * DefinitionLoader::all() — stable as long as the set of active workflows
     * is, and deliberately distinct from the hues the shipped STATE colors
     * lean on (blue/amber/purple/green/red), so a workflow badge is not read
     * as a state. A workflow can pin its own via `metadata.color` in the YAML
     * (any Statamic Badge color name).
     */
    private const WORKFLOW_COLORS = ['indigo', 'teal', 'fuchsia', 'sky', 'rose', 'lime', 'cyan', 'violet'];

    /**
     * How urgent a row reads, derived from the STATE color rather than from the
     * place handle — lower sorts first. The color is the one thing every place
     * has and every project can tune (`metadata.color` on the place), so
     * ordering by it needs no per-project table: a workflow author who paints a
     * place red has already said it needs attention first, and the widget
     * follows without being told twice.
     *
     * The scale runs warm-to-cool-to-done: red/rose (blocked, failed), then the
     * ambers (waiting on a human), then the blues (in progress), then the
     * neutral/greys, and finally the greens (published, approved, done) —
     * anything finished is the least urgent thing on the card.
     *
     * Unknown names land on the neutral rank, so a typo'd color changes the
     * hue of a row but never its position.
     */
    private const URGENCY = [
        'red' => 0,
        'rose' => 1,
        'orange' => 2,
        'amber' => 3,
        'yellow' => 4,
        'blue' => 5,
        'sky' => 6,
        'indigo' => 7,
        'purple' => 8,
        'violet' => 8,
        'fuchsia' => 8,
        'pink' => 8,
        'default' => 9,
        'gray' => 9,
        'cyan' => 10,
        'teal' => 10,
        'lime' => 11,
        'emerald' => 11,
        'green' => 12,
    ];

    private const URGENCY_FALLBACK = 9;

    /**
     * Row sets already built in THIS request, keyed by caller and arguments
     * (see self::memoKey()). The dashboard asks assignedTo() twice with
     * identical arguments — the "My queue" card wants the rows, the stats row's
     * first card wants the same rows to count and to list (see
     * QueueStats::queueLinks()) — and the second pass was resolving the same
     * ~280 entries all over again for an answer it already had. Measured on the
     * reference project: 221 ms for the first pass, another 85 ms for the
     * second; the memo removes the latter entirely.
     *
     * Request-scoped, not cached: the binding in ServiceProvider::register() is
     * `scoped`, so the memo dies with the request and the dashboard is never a
     * request behind the data. The two callers of this class are read-only
     * widget renderers — nothing writes an assignment, a state or an event
     * between the two calls — and the stores underneath drop their own memos on
     * write anyway (WorkflowStateStore, WorkflowEventStore).
     *
     * The Collection handed back is SHARED between callers, so a caller must
     * not mutate it. All three call sites are non-mutating (take/filter/
     * groupBy/sortBy/sortByDesc/slice all return new collections).
     *
     * @var array<string, \Illuminate\Support\Collection<int, array<string, mixed>>>
     */
    private array $memo = [];

    public function __construct(
        private DefinitionLoader $loader,
        private WorkflowResolver $resolver,
        private WorkflowAssigneeStore $assignees,
        private WorkflowEventStore $events,
        private InstanceUrl $urls,
        private WorkflowWatchStore $watches,
        private WorkflowStateStore $states,
    ) {}

    /**
     * Rows for every subject directly assigned to the user, across all active
     * workflows (or the subset named in $only).
     *
     * The only caller of the two that asks for the `meta` line, so it is the
     * only one that pays for the event-log lookup behind it.
     *
     * @param  array<int, string>|null  $only
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function assignedTo(string $userId, ?array $only = null): Collection
    {
        return $this->memo['assigned|'.self::memoKey($userId, $only)] ??= $this->rows(
            fn (string $workflow) => $this->assignees->subjectIdsForUser($userId, $workflow),
            $only,
            withMeta: true,
        );
    }

    /**
     * Rows for every subject whose `author` field names the user, across all
     * active workflows (or the subset named in $only).
     *
     * @param  array<int, string>|null  $only
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function authoredBy(string $userId, ?array $only = null): Collection
    {
        return $this->memo['authored|'.self::memoKey($userId, $only)] ??= $this->rows(
            fn (string $workflow) => $this->authoredSubjectIds($workflow, $userId),
            $only,
        );
    }

    /**
     * Ids of the entries in a workflow's collections the user is an author of.
     *
     * Participation follows the manager's flat listing: the collection's owning
     * workflow covers every entry, a secondary one (e.g. translation) only those
     * with a stored state — and either way there must be a state to show, since
     * a row without one is not part of the workflow yet.
     *
     * @return array<int, string>
     */
    private function authoredSubjectIds(string $workflow, string $userId): array
    {
        $raw = $this->loader->raw($workflow);
        $states = $this->states->statesFor($workflow);

        return Entry::query()
            ->whereIn('collection', $raw['collections'] ?? [])
            ->get()
            ->filter(fn ($entry) => $entry->authors()->map(fn ($author) => (string) $author)->contains($userId))
            ->filter(fn ($entry) => isset($states[$entry->id()])
                || $this->resolver->forCollection($entry->collectionHandle()) === $workflow)
            ->filter(fn ($entry) => $this->resolver->stateFor($entry, $workflow, $raw) !== null)
            ->map(fn ($entry) => (string) $entry->id())
            ->values()
            ->all();
    }

    /**
     * Rows for every subject the user watches, across all active workflows (or
     * the subset named in $only).
     *
     * @param  array<int, string>|null  $only
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function watchedBy(string $userId, ?array $only = null): Collection
    {
        return $this->memo['watched|'.self::memoKey($userId, $only)] ??= $this->rows(
            fn (string $workflow) => $this->watches->subjectIdsForUser($userId, $workflow),
            $only,
        );
    }

    /**
     * The identity of one row set: the user plus the workflow restriction.
     *
     * The restriction is SORTED and deduplicated because it is a set, not a
     * list — rows() intersects it against DefinitionLoader::all() and takes that
     * order, so two widgets naming the same workflows in a different order ask
     * the same question and must hit the same memo entry. `*` rather than an
     * empty string for "all active", so it cannot collide with a config naming
     * no workflow at all.
     *
     * @param  array<int, string>|null  $only
     */
    private static function memoKey(string $userId, ?array $only): string
    {
        if ($only === null) {
            return $userId.'|*';
        }

        $names = array_values(array_unique($only));
        sort($names);

        return $userId.'|'.implode(',', $names);
    }

    /**
     * Shared inner loop: workflows in declared order (the nav order users
     * already know), titles naturally sorted within each workflow. No limit
     * here — the widget takes what it shows and counts the remainder.
     *
     * `$withMeta` decides whether the rows carry the `meta` line, which costs
     * one aggregate query per workflow — skipped for "My posts", whose
     * card does not show it.
     *
     * @param  callable(string): array<int, string>  $subjectIdsFor
     * @param  array<int, string>|null  $only
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function rows(callable $subjectIdsFor, ?array $only, bool $withMeta = false): Collection
    {
        $all = $this->loader->all();

        $workflows = collect($all)
            ->when($only !== null, fn ($names) => $names->intersect($only)->values());

        $multiSite = Site::hasMultiple();

        return $workflows->flatMap(function (string $workflow) use ($subjectIdsFor, $multiSite, $all, $withMeta) {
            $raw = $this->loader->raw($workflow);
            $label = DefinitionLoader::navLabel($raw['metadata']['nav'] ?? [])
                ?: (string) Str::headline($workflow);
            $color = self::workflowColor($workflow, $raw, $all);
            // null for workflows without metadata.nav — no manager page to
            // link to, same fallback logic as AssignedPosts::managerUrl().
            $slug = $this->urls->navSlug($workflow);
            $managerUrl = $slug === null ? null : cp_route('workflows.workflow', ['slug' => $slug]);
            // One aggregate for the whole workflow, not one query per row.
            $eventTimes = $withMeta ? $this->events->latestEventTimesByWorkflow($workflow) : [];

            return collect($subjectIdsFor($workflow))
                ->map(fn ($id) => Entry::find($id))
                // A store row can outlive its entry — dropped silently, the
                // remainder count is computed after this filter.
                ->filter()
                ->map(fn ($entry) => $this->row($entry, $workflow, $raw, $label, $color, $managerUrl, $multiSite, $eventTimes, $withMeta))
                ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();
        })->values();
    }

    /**
     * The badge color of one workflow: its own `metadata.color` where declared,
     * otherwise from the palette by position among ALL active workflows — not
     * the restricted subset a widget may ask for, so the same workflow keeps
     * the same color on every card.
     *
     * Public and static because the dashboard's status row (WorkflowStatus)
     * paints the same dot for the same workflow and must not be able to pick a
     * different color for it. Static because it reads nothing but its
     * arguments and the palette — no instance of this class is needed to ask
     * what color a workflow has.
     *
     * @param  array<string, mixed>  $raw  The workflow's parsed definition.
     * @param  array<int, string>  $all  Every ACTIVE workflow, in definition order.
     */
    public static function workflowColor(string $workflow, array $raw, array $all): string
    {
        $declared = $raw['metadata']['color'] ?? null;

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        $index = (int) array_search($workflow, $all, true);

        return self::WORKFLOW_COLORS[$index % count(self::WORKFLOW_COLORS)];
    }

    /**
     * The queue row's meta text, built from the subject's newest workflow event.
     *
     * Two readings of the same timestamp, because the question a reader has
     * differs by state: for anything still in flight the answer is a DURATION
     * ("seit 3 Tagen" — how long has this been sitting on me), for anything
     * finished it is a DATE ("heute 09:14", else "31.08.2026" — when did it go
     * out). Green is what "finished" means here: the state colors map
     * published/approved/done to green, so the card needs no list of place
     * handles to tell the two readings apart.
     *
     * Empty for a subject with no events at all — a seeded state carries no
     * history, and "seit 0 Sekunden" would be a lie about the seed run.
     */
    private function meta(?Carbon $time, string $stateColor): string
    {
        if ($time === null) {
            return '';
        }

        if ($stateColor === 'green') {
            return $time->isToday()
                ? __('workflows::messages.widget_meta_today', ['time' => $time->format('H:i')])
                // Numeric on purpose, in both languages: the line is a
                // footnote beside the title, not a sentence.
                : $time->format('d.m.Y');
        }

        return __('workflows::messages.widget_meta_since', [
            // The RELATIVE form ("vor 6 Tagen", "6 days ago") rather than the
            // absolute one, and that is a grammar decision, not a style one.
            // German declines the unit after a preposition, and Carbon carries
            // the dative only in its relative keys: `day_ago` is
            // ":count Tagen", while the bare `day` is ":count Tage". Asking for
            // DIFF_ABSOLUTE and prefixing "seit " ourselves therefore produced
            // "seit 6 Tage" — so Carbon frames the phrase, and the language
            // files below pass it through. See Carbon\Lang\de.php.
            'time' => $time->locale(app()->getLocale())
                ->diffForHumans(['parts' => 1, 'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW]),
        ]);
    }

    /**
     * @param  array<string, \Carbon\Carbon>  $eventTimes
     * @return array<string, mixed>
     */
    private function row($entry, string $workflow, array $raw, string $workflowLabel, string $workflowColor, ?string $managerUrl, bool $multiSite, array $eventTimes = [], bool $withMeta = false): array
    {
        $state = $this->resolver->stateFor($entry, $workflow, $raw);
        $stateColor = $state === null ? 'default' : $this->loader->stateColor($state);

        return [
            // The localization id — what a per-row action (the Watched card's
            // remove button) addresses, together with `workflow`.
            'id' => (string) $entry->id(),
            'title' => (string) $entry->value('title'),
            'workflow' => $workflow,
            'workflow_label' => $workflowLabel,
            'workflow_color' => $workflowColor,
            'manager_url' => $managerUrl,
            // Empty where the version is not part of the workflow yet — same
            // tolerance as the AssignedPosts widget.
            'state_label' => $state === null ? '' : $this->loader->stateLabel($state),
            // Settings-configured place color, same resolution as the listing
            // badges (see DefinitionLoader::stateColor()).
            'state_color' => $stateColor,
            // Sort rank read off the state color, see self::URGENCY.
            'urgency' => self::URGENCY[$stateColor] ?? self::URGENCY_FALLBACK,
            // Entry last-modified as a unix timestamp — the sort key of the
            // "My posts" card, which asks what moved most recently rather
            // than what is most urgent. 0 where the entry cannot say.
            'last_modified' => $entry->lastModified()?->getTimestamp() ?? 0,
            // Second half of the queue row's meta line, '' when the card does
            // not show one or the subject has no history yet.
            'meta' => $withMeta ? $this->meta($eventTimes[$entry->id()] ?? null, $stateColor) : '',
            // The uppercased HANDLE, like every other site chip in the addon
            // (see WorkflowController::siteCode() for why not the locale).
            // null on single-site projects, so the view skips the chip.
            'site_code' => $multiSite ? strtoupper($entry->locale()) : null,
            'url' => $this->urls->for($entry->id(), $workflow),
        ];
    }
}
