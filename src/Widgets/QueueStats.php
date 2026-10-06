<?php

namespace TakepartMedia\Workflows\Widgets;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;
use TakepartMedia\Workflows\Widgets\Concerns\PresentsPersonalEntries;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\FailedTranslationRetry;
use TakepartMedia\Workflows\Workflow\PersonalWorkItems;
use TakepartMedia\Workflows\Workflow\SubjectLinks;
use TakepartMedia\Workflows\Workflow\TransitionGates;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/**
 * The dashboard's top row: four key figures — "in my queue", "awaiting
 * approval", "failed translations", "published this week". Read-only but for
 * one button: card 3 offers a restart when there is something to restart and
 * the viewer may restart it (see FailedTranslationRetry).
 *
 * ONE widget for the whole row rather than four: the four cards are a single
 * statement about the project's state, they have to line up and stay the same
 * height, and splitting them across four dashboard slots would hand that
 * layout to the dashboard's width arithmetic instead of to a grid.
 *
 * EVERY card carries the posts BEHIND its figure (`items`, `more`), which the
 * client turns into a slide-over stack opened from the value: a number a reader
 * cannot follow to the work is a number they have to go and look for by hand.
 *
 * Three of the four sources are subject-id sets that go through links(); card 1
 * is the exception and goes through queueLinks(), because the "My queue" row
 * builder has already resolved its entries and re-resolving them here would
 * mean the same lookups for the same answer.
 *
 * Card 1 is PERSONAL (what is assigned to the viewer), cards 2-4 are
 * PROJECT-WIDE. What counts towards a card is read off the DEFINITION, never
 * off a hardcoded list of place handles — but not all three the same way, and
 * the difference is the point:
 *
 * - card 2 counts the STRUCTURE of the workflow (TransitionGates::gatePlaces():
 *   a place whose exits name a group or role, or a non-branch place with more
 *   than one exit — a decision, not a hand-over);
 * - card 3 counts the one place a workflow NAMES as its failure park
 *   (`metadata.plain_language_failed_place`);
 * - card 4 counts the state COLOR, because "done" genuinely is what
 *   `metadata.color: green` says, and the same reading already drives the
 *   urgency order in PersonalWorkItems::URGENCY.
 *
 * Card 2 used to read the color too — blue meant "waiting". It counted 278 on a
 * live project, because the initial place of the plain-language manager is blue
 * and 277 posts have simply never been translated. Nobody is waiting for a
 * decision on those; a color says how a place LOOKS, and only the transitions
 * say whether somebody has to act.
 *
 * A component widget, not a Blade one: the row is built from Statamic's own
 * `Card`/`Heading`/`Description`, which are Vue components with no Blade
 * equivalent. `component()` hands the dashboard
 * `workflows-queue-stats-widget` (registered in `resources/js/addon.js`)
 * together with four finished cards — every string, including the notes, is
 * translated here, so the client renders and does not compute.
 *
 * Widget config: `workflows` (restrict to a subset of workflow names, default
 * all active), shared with the two list widgets through
 * PresentsPersonalEntries.
 */
class QueueStats extends Widget
{
    use PresentsPersonalEntries;

    protected static $handle = 'workflows_queue_stats';

    /**
     * "Finished". The same family PersonalWorkItems::meta() reads as "done"
     * when it decides between a duration and a date.
     */
    private const DONE_COLORS = ['green', 'emerald'];

    /**
     * How many posts a card names by title before it stops and says "+N more".
     *
     * Card 1 builds its list here rather than through SubjectLinks (see
     * queueLinks()), so it has to be capped at the same number — hence the
     * borrowed constant rather than a second 50 sitting next to the first.
     */
    private const ITEM_CAP = SubjectLinks::ITEM_CAP;

    public function component()
    {
        $user = User::current();

        // No html() either, and DashboardController drops a widget only when
        // both are empty — so null is how this row hides itself.
        if ($user === null) {
            return null;
        }

        $only = $this->workflowsConfig();
        $loader = app(DefinitionLoader::class);
        $events = app(WorkflowEventStore::class);

        $workflows = collect($loader->all())
            ->when($only !== null, fn ($names) => $names->intersect($only)->values())
            ->all();

        $retry = app(FailedTranslationRetry::class);

        $review = $this->subjectsAtGates($workflows, $loader);
        $failed = $retry->subjects($only);

        $reviewCount = $this->total($review);
        $failedCount = $this->total($failed);

        // The posts behind the two figures. Built from the subject ids the
        // counting already collected, so the stack costs entry lookups and
        // nothing else — no second pass over the state store.
        $reviewLinks = $this->links($review);
        $failedLinks = $this->links($failed);

        // Card 1 is the one card whose rows are already RESOLVED — the queue
        // widget's own row builder hands back title, url and site_code — so it
        // takes a different route to the same shape (see queueLinks()) and
        // resolves no entry a second time.
        $assigned = app(PersonalWorkItems::class)->assignedTo((string) $user->id(), $only);
        $assignedLinks = $this->queueLinks($assigned);

        return [
            'name' => 'workflows-queue-stats-widget',
            'props' => [
                'cards' => [
                    $this->card(
                        __('workflows::messages.widget_stats_queue'),
                        $assigned->count(),
                        'indigo',
                        items: $assignedLinks['items'],
                        more: $assignedLinks['more'],
                    ),
                    $this->card(
                        __('workflows::messages.widget_stats_review'),
                        $reviewCount,
                        'blue',
                        // Empty at count 0 — there is no oldest item then, and
                        // an empty card carries no note at all (no placeholder,
                        // no dash).
                        note: $reviewCount === 0 ? '' : $this->oldestSince($review, $events),
                        items: $reviewLinks['items'],
                        more: $reviewLinks['more'],
                    ),
                    $this->card(
                        __('workflows::messages.widget_stats_failed'),
                        $failedCount,
                        'red',
                        // Static, not a count: the number above already says how
                        // many, the note says what to do about them.
                        note: $failedCount === 0 ? '' : __('workflows::messages.widget_stats_restart_hint'),
                        tone: $failedCount === 0 ? 'neutral' : 'negative',
                        // The same words as the note, as a button — when there
                        // is anything the VIEWER may restart. Not merely when
                        // something failed: a button that 403s on click is worse
                        // than a sentence, and the permission is the ordinary
                        // transition gate, so a reviewer who is not an assignee
                        // of the parked version keeps the plain note.
                        action: $failedCount > 0 && $retry->retryableBy($only)
                            ? [
                                'url' => cp_route('workflows.api.stats.retry'),
                                'label' => __('workflows::messages.widget_stats_restart_hint'),
                            ]
                            : null,
                        items: $failedLinks['items'],
                        more: $failedLinks['more'],
                    ),
                    $this->publishedCard($workflows, $loader, $events),
                ],
            ],
        ];
    }

    /**
     * One card of the row. `value` travels as a string because it is a label,
     * not a number the client does arithmetic with.
     *
     * @param  array{url: string, label: string}|null  $action  Turns the note into
     *                                                a button. Null on every card
     *                                                but the failed one, and on
     *                                                that one whenever there is
     *                                                nothing this viewer could do.
     * @param  array<int, array{title: string, url: string, site_code: string|null}>  $items
     * @param  array<int, array{count: int, label: string, url: string|null}>  $more
     * @return array<string, mixed>
     */
    private function card(
        string $label,
        int $value,
        string $color,
        string $note = '',
        string $tone = 'neutral',
        ?array $action = null,
        array $items = [],
        array $more = [],
    ): array {
        return [
            'label' => $label,
            'value' => (string) $value,
            'note' => $note,
            // The posts behind the figure, for the stack the value opens.
            // Empty on the cards that have none, which is also how the client
            // decides whether the value is a trigger at all — there is no second
            // "has a stack" flag to keep in step with the list.
            'items' => $items,
            // What the cap is hiding, per workflow
            // (PresentsPersonalEntries::overflow()).
            'more' => $more,
            // The one interactive thing in the row, and it stays a plain
            // {url, label}: the client posts, reads the counts back and
            // re-renders. What "restart" means is decided in
            // FailedTranslationRetry, never here and never there.
            'action' => $action,
            // neutral | negative | positive — the note's text color in the
            // client, not a color name: the three tones have to stay a closed
            // set, so the component can map them to classes Tailwind has
            // actually generated.
            'tone' => $tone,
            // Statamic color names, not hex: the dot beside the label is the
            // one thing in this row without a kit component, and a name keeps
            // the hex out of PHP. Purple-ish = my own work, blue = review,
            // red = broken, green = done.
            'color' => $color,
        ];
    }

    /**
     * Card 1's links, straight off the rows the count was taken from.
     *
     * Card 1 uses the SAME code path as the "My queue" widget — one call to
     * PersonalWorkItems::assignedTo() — rather than a cheaper `count()` over
     * the assignee rows. The two cards sit on the same dashboard and a reader
     * will compare them, so they must not be able to disagree: assignedTo()
     * drops assignee rows whose entry no longer exists, and a raw row count
     * would report those orphans as work.
     *
     * The upshot for the stack is that this card needs no links() pass at all.
     * Those rows are already resolved entries carrying `title`, `url` and
     * `site_code` — resolving them a second time would be the same lookups for
     * the same answer, and the row builder's own conventions (the site chip is
     * the uppercased HANDLE, not the locale) would get a second, subtly
     * different implementation here.
     *
     * The remainder likewise comes from the trait's overflow(), grouped per
     * workflow so each "+3 Beiträge" can link to its manager page. Only its
     * extra `color` key is dropped, to keep every card's `more` the same shape.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array{items: array<int, array{title: string, url: string, site_code: string|null}>, more: array<int, array{count: int, label: string, url: string|null}>}
     */
    private function queueLinks(Collection $rows): array
    {
        return [
            'items' => $rows->take(self::ITEM_CAP)
                ->map(fn (array $row) => [
                    'title' => (string) $row['title'],
                    'url' => (string) $row['url'],
                    'site_code' => $row['site_code'] ?? null,
                ])
                ->values()
                ->all(),
            'more' => $this->overflow($rows, self::ITEM_CAP)
                ->map(fn (array $entry) => [
                    'count' => $entry['count'],
                    'label' => $entry['label'],
                    'url' => $entry['url'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Card 4: distinct subjects that ENTERED a green (done/published) state
     * this calendar week, with the same figure for last week as the note.
     *
     * Transitions, not the current state: "published this week" is an event,
     * and a subject that went out on Monday and back into revision on Thursday
     * still went out this week. The stored state cannot answer that; the event
     * log can.
     */
    private function publishedCard(array $workflows, DefinitionLoader $loader, WorkflowEventStore $events): array
    {
        $green = $this->doneStates($workflows, $loader);

        // ISO weeks (Monday-Sunday) via Carbon's own defaults — the week the
        // CP's users mean when they say "diese Woche".
        //
        // The ids, not just the tally: this card's stack lists the posts that
        // went out, and subjectsEnteringStates() counts exactly what this
        // returns (it delegates to it), so the figure and the list cannot come
        // apart.
        $thisWeekIds = $events->subjectIdsEnteringStates(
            $green,
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek(),
        );

        $thisWeek = $this->total($thisWeekIds);

        // Last week stays a bare count. It is the note's comparison figure and
        // nothing links to it — the stack is about what is going on NOW, and a
        // list mixing two weeks would need a heading per week to be readable.
        $lastWeek = $events->subjectsEnteringStates(
            $green,
            Carbon::now()->subWeek()->startOfWeek(),
            Carbon::now()->subWeek()->endOfWeek(),
        );

        $diff = $thisWeek - $lastWeek;
        $links = $this->links($thisWeekIds);

        return $this->card(
            __('workflows::messages.widget_stats_published_week'),
            $thisWeek,
            'green',
            // Nothing in either week means there is no comparison to draw, and
            // "±0 vs. last week" under a "0" would be noise. Any other
            // combination is a real figure, including a drop to zero.
            note: $thisWeek === 0 && $lastWeek === 0
                ? ''
                : __('workflows::messages.widget_stats_vs_last_week', ['diff' => $this->signed($diff)]),
            // Only an increase is good news worth coloring. A decrease is not
            // an error — fewer posts went out, that happens — so it stays
            // neutral rather than turning the card red.
            tone: $diff > 0 ? 'positive' : 'neutral',
            items: $links['items'],
            more: $links['more'],
        );
    }

    /**
     * "+3", "−2" or "±0". The minus is U+2212, not a hyphen: it sits beside a
     * plus in the same line and has to have the same weight.
     */
    private function signed(int $diff): string
    {
        if ($diff === 0) {
            return '±0';
        }

        return $diff > 0 ? '+'.$diff : '−'.abs($diff);
    }

    /**
     * Card 2: subject ids per workflow whose CURRENT stored state is one of that
     * workflow's approval gates. Workflows contributing nothing are dropped, so
     * the caller only pays for an event lookup where there is something to look
     * up.
     *
     * The gate set is computed ONCE PER WORKFLOW from the raw definition
     * (TransitionGates::gatePlaces() — see there for what makes a place a gate)
     * and the stored states are then matched against it. The other way round —
     * asking per subject — would re-walk the transitions for every row.
     *
     * Project-wide on purpose: cards 2-4 are about the project, not about the
     * viewer. No entry resolution here either — this counts workflow rows, and
     * a row whose entry is gone is a data problem the managers surface, not
     * something this card should quietly hide.
     *
     * @param  array<int, string>  $workflows
     * @return array<string, array<int, string>>
     */
    private function subjectsAtGates(array $workflows, DefinitionLoader $loader): array
    {
        $states = app(WorkflowStateStore::class);
        $found = [];

        foreach ($workflows as $workflow) {
            $gates = TransitionGates::gatePlaces($loader->raw($workflow));

            if ($gates === []) {
                continue;
            }

            $matching = [];

            foreach ($states->statesFor($workflow) as $subjectId => $state) {
                if (in_array((string) $state, $gates, true)) {
                    $matching[] = (string) $subjectId;
                }
            }

            if ($matching !== []) {
                $found[$workflow] = $matching;
            }
        }

        return $found;
    }

    /**
     * Every place handle across the given workflows whose color reads as
     * "done". Handles rather than colors, because the event log records the
     * place a transition landed in, not its color.
     *
     * @param  array<int, string>  $workflows
     * @return array<int, string>
     */
    private function doneStates(array $workflows, DefinitionLoader $loader): array
    {
        $handles = [];

        foreach ($workflows as $workflow) {
            foreach (array_keys($loader->raw($workflow)['places'] ?? []) as $handle) {
                if (in_array($loader->stateColor((string) $handle), self::DONE_COLORS, true)) {
                    $handles[] = (string) $handle;
                }
            }
        }

        // Place handles are unique across workflows in practice, but two
        // workflows declaring the same green place would otherwise bind it
        // twice in the IN clause.
        return array_values(array_unique($handles));
    }

    /**
     * The posts behind a card's figure: up to ITEM_CAP of them by name, plus
     * what the cap hides, per workflow.
     *
     * Fed the SAME per-workflow subject ids the count was taken from, so the
     * stack cannot list a post the number does not include. Nothing is
     * re-queried here; the only cost is one entry lookup per link shown.
     *
     * The work itself is SubjectLinks' — the cap, the orphan handling, the site
     * chip and the per-workflow remainder all live there, because the status
     * row's stacks (WorkflowController::stateSubjects) hand their readers the
     * same list and the two must not be able to differ. See that class for why
     * each of those rules is what it is.
     *
     * @param  array<string, array<int, string>>  $perWorkflow
     * @return array{items: array<int, array{title: string, url: string, site_code: string|null}>, more: array<int, array{count: int, label: string, url: string|null}>}
     */
    private function links(array $perWorkflow): array
    {
        return app(SubjectLinks::class)->forSubjects($perWorkflow);
    }

    /**
     * @param  array<string, array<int, string>>  $perWorkflow
     */
    private function total(array $perWorkflow): int
    {
        return array_sum(array_map('count', $perWorkflow));
    }

    /**
     * Card 2's note: how long the oldest of the counted subjects has been
     * without any workflow activity, in WHOLE days.
     *
     * Whole days rather than Carbon's absolute diff, and a `trans_choice` key
     * rather than a formatted phrase, because German declines the unit after
     * "seit": the dative "seit 4 Tagen" only comes out of a plural form we
     * write ourselves. (PersonalWorkItems::meta() solves the same problem the
     * other way round, by letting Carbon frame the whole phrase — it can,
     * because it wants the relative "vor 4 Tagen".)
     *
     * Empty below one day: "seit 0 Tagen" is not a sentence, and a queue that
     * moved this morning has nothing to report. Also empty when none of the
     * counted subjects has any history — a seeded state carries none.
     *
     * @param  array<string, array<int, string>>  $perWorkflow
     */
    private function oldestSince(array $perWorkflow, WorkflowEventStore $events): string
    {
        $oldest = null;

        foreach ($perWorkflow as $workflow => $subjectIds) {
            // One aggregate per workflow, not one query per subject.
            $times = $events->latestEventTimesByWorkflow((string) $workflow);

            foreach ($subjectIds as $subjectId) {
                $time = $times[$subjectId] ?? null;

                if ($time !== null && ($oldest === null || $time->lt($oldest))) {
                    $oldest = $time;
                }
            }
        }

        if ($oldest === null) {
            return '';
        }

        $days = (int) $oldest->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay());

        if ($days < 1) {
            return '';
        }

        return trans_choice('workflows::messages.widget_stats_oldest_since', $days, ['count' => $days]);
    }
}
