<?php

namespace TakepartMedia\Workflows\Widgets;

use Carbon\Carbon;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;
use TakepartMedia\Workflows\Http\Controllers\WorkflowController;
use TakepartMedia\Workflows\Widgets\Concerns\PresentsPersonalEntries;
use TakepartMedia\Workflows\Workflow\FailedTranslationRetry;
use TakepartMedia\Workflows\Workflow\PersonalWorkItems;
use TakepartMedia\Workflows\Workflow\StatsPresets;
use TakepartMedia\Workflows\Workflow\SubjectLinks;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;

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
 * EVERY card is a way to the posts BEHIND its figure (`link`): a number a
 * reader cannot follow to the work is a number they have to go and look for by
 * hand. Not a list of its own any more — the cards opened a slide-over stack
 * first, which duplicated, badly, two lists the dashboard already has:
 *
 * - card 1 jumps to the "My queue" widget (`#workflows-my-queue`), which IS the
 *   list behind it, paged and filterable;
 * - cards 2-4 filter the "Alle Beiträge" widget (`#workflows-overview`) to the
 *   posts they count — the listing's `preset` parameter, see StatsPresets — and
 *   scroll to it.
 *
 * Card 1 is PERSONAL (what is assigned to the viewer), cards 2-4 are
 * PROJECT-WIDE; what they count is StatsPresets' business, see there. Cards 2-4
 * count POSTS, not site-versions: the figure is the total of the table they
 * filter (WorkflowController::matchingPostCount()), so a post waiting for
 * approval in two languages is one, as it is one row.
 *
 * A component widget, not a Blade one: the row is built from Statamic's own
 * `Card`/`Heading`/`Description`, which are Vue components with no Blade
 * equivalent. `component()` hands the dashboard
 * `workflows-queue-stats-widget` (registered in `resources/js/addon.js`)
 * together with four finished cards — every string, including the notes, is
 * translated here, so the client renders and does not compute.
 *
 * Widget config: `workflows` (restrict to a subset of workflow names, default
 * all active), shared with the list widgets through PresentsPersonalEntries.
 * Give the overview widget the same list, or a card's figure and the table it
 * filters span different workflows.
 */
class QueueStats extends Widget
{
    use PresentsPersonalEntries;

    protected static $handle = 'workflows_queue_stats';

    /**
     * The anchors the cards point at — the root elements of MyQueueWidget.vue
     * and WorkflowsOverviewWidget.vue (and of the manager page's "My queue"
     * section, the card-1 fallback).
     */
    public const MY_QUEUE_ANCHOR = 'workflows-my-queue';

    public const OVERVIEW_ANCHOR = 'workflows-overview';

    public function component()
    {
        $user = User::current();

        // No html() either, and DashboardController drops a widget only when
        // both are empty — so null is how this row hides itself.
        if ($user === null) {
            return null;
        }

        $only = $this->workflowsConfig();
        $presets = app(StatsPresets::class);
        $posts = app(WorkflowController::class);
        $retry = app(FailedTranslationRetry::class);

        $review = $presets->subjects(StatsPresets::AWAITING_APPROVAL, $only);
        $reviewCount = $posts->matchingPostCount($review, $only);
        $failedCount = $posts->matchingPostCount($presets->subjects(StatsPresets::FAILED_TRANSLATIONS, $only), $only);

        // Card 1 uses the SAME code path as the "My queue" widget — one call to
        // PersonalWorkItems::assignedTo() — rather than a cheaper `count()` over
        // the assignee rows. The card links to that widget, so the two must not
        // be able to disagree: assignedTo() drops assignee rows whose entry no
        // longer exists, and a raw row count would report those orphans as work.
        $assigned = app(PersonalWorkItems::class)->assignedTo((string) $user->id(), $only);

        return [
            'name' => 'workflows-queue-stats-widget',
            'props' => [
                'cards' => [
                    $this->card(
                        __('workflows::messages.widget_stats_queue'),
                        $assigned->count(),
                        'indigo',
                        link: [
                            'target' => self::MY_QUEUE_ANCHOR,
                            // Without the widget on the dashboard: the same list on
                            // the manager page of the first workflow with work in
                            // it (its section carries the same anchor). Nothing to
                            // fall back to with an empty queue.
                            'fallback' => ($url = $assigned->first()['manager_url'] ?? null)
                                ? $url.'#'.self::MY_QUEUE_ANCHOR
                                : null,
                        ],
                    ),
                    $this->card(
                        __('workflows::messages.widget_stats_review'),
                        $reviewCount,
                        'blue',
                        // Empty at count 0 — there is no oldest item then, and
                        // an empty card carries no note at all (no placeholder,
                        // no dash).
                        note: $reviewCount === 0 ? '' : $this->oldestSince($review, app(WorkflowEventStore::class)),
                        link: $this->presetLink(StatsPresets::AWAITING_APPROVAL, $presets, $only),
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
                        link: $this->presetLink(StatsPresets::FAILED_TRANSLATIONS, $presets, $only),
                    ),
                    $this->publishedCard($presets, $posts, $only),
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
     * @param  array{target: string, fallback: string|null, preset?: string, workflows?: array<int, string>}  $link
     * @return array<string, mixed>
     */
    private function card(
        string $label,
        int $value,
        string $color,
        string $note = '',
        string $tone = 'neutral',
        ?array $action = null,
        array $link = [],
    ): array {
        return [
            'label' => $label,
            'value' => (string) $value,
            'note' => $note,
            // Where the card goes: `target` is the id of the dashboard widget
            // that lists its posts, `fallback` a page to go to when that widget
            // is not on this dashboard (null: the card is then not a link).
            // Cards 2-4 add the overview `preset` they apply and the
            // `workflows` it can draw from (StatsPresets::scope()) — one name
            // selects that workflow's button, several select "Alle".
            'link' => $link,
            // The one interactive thing in the row besides the links, and it
            // stays a plain {url, label}: the client posts, reads the counts
            // back and re-renders. What "restart" means is decided in
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
     * A project-wide card's link: the overview, filtered to the preset.
     *
     * The fallback — the overview widget is not on this dashboard — is the
     * manager page of the preset's workflow when there is exactly one (the
     * failed translations live in one manager on every project so far), and
     * nothing when the preset spans several: a manager page shows ONE workflow,
     * and picking one of several for the reader would be a guess. The manager
     * page is not filtered; its state filter is one click away.
     *
     * @param  array<int, string>|null  $only
     * @return array{target: string, fallback: string|null, preset: string, workflows: array<int, string>}
     */
    private function presetLink(string $preset, StatsPresets $presets, ?array $only): array
    {
        $scope = $presets->scope($preset, $only);

        return [
            'target' => self::OVERVIEW_ANCHOR,
            'fallback' => count($scope) === 1 ? app(SubjectLinks::class)->managerUrl($scope[0]) : null,
            'preset' => $preset,
            'workflows' => $scope,
        ];
    }

    /**
     * Card 4: posts with a version that ENTERED a green (done/published) state
     * this calendar week, with the same figure for last week as the note — see
     * StatsPresets::published() for why transitions and not the current state.
     */
    private function publishedCard(StatsPresets $presets, WorkflowController $posts, ?array $only): array
    {
        // ISO weeks (Monday-Sunday) via Carbon's own defaults — the week the
        // CP's users mean when they say "diese Woche".
        $thisWeek = $posts->matchingPostCount($presets->subjects(StatsPresets::PUBLISHED_THIS_WEEK, $only), $only);

        // Last week is the note's comparison figure and nothing links to it —
        // but it is counted in the same unit, posts, or the difference would
        // compare two different things.
        $lastWeek = $posts->matchingPostCount($presets->published(
            $only,
            Carbon::now()->subWeek()->startOfWeek(),
            Carbon::now()->subWeek()->endOfWeek(),
        ), $only);

        $diff = $thisWeek - $lastWeek;

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
            link: $this->presetLink(StatsPresets::PUBLISHED_THIS_WEEK, $presets, $only),
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
