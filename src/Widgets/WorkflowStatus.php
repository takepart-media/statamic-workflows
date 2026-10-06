<?php

namespace TakepartMedia\Workflows\Widgets;

use Illuminate\Support\Str;
use Statamic\Widgets\Widget;
use TakepartMedia\Workflows\Widgets\Concerns\PresentsPersonalEntries;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\PersonalWorkItems;
use TakepartMedia\Workflows\Workflow\SubjectLinks;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/**
 * "Inhalte nach Status": one card per active workflow, each showing how that
 * workflow's tracked subjects are distributed across its states — a doughnut
 * ring plus a legend naming every state with its absolute count.
 *
 * ONE widget for the whole row, for the same reason QueueStats is one widget
 * for its four cards: the cards are a single statement (how the project's
 * content sits, workflow by workflow), they have to line up and stay the same
 * height, and one card per dashboard slot would hand that layout to the
 * dashboard's width arithmetic instead of to a grid.
 *
 * Project-wide, not personal: the question is what the content is doing, not
 * what is on the viewer's desk. That is also why nothing here filters by
 * assignee or by site.
 *
 * Entirely settings-driven — there is no list of states, colors or workflows in
 * this file:
 *
 * - WHICH workflows: DefinitionLoader::all() (i.e. `active: true`), optionally
 *   narrowed by the widget's `workflows` config through the same trait the
 *   list widgets use.
 * - WHICH states: whatever WorkflowStateStore::statesFor() actually holds. A
 *   state nothing sits in contributes no segment — the ring shows what IS, not
 *   what a definition allows. (A workflow with no tracked subjects at all is a
 *   different case; see below.)
 * - HOW they are labeled and colored: DefinitionLoader::stateLabel() /
 *   stateColor(), so a project renaming or recoloring a place in the settings
 *   editor changes the ring with it.
 *
 * A workflow with zero tracked subjects is NEVER hidden: it travels with
 * `total: 0` and no segments, and the client draws an empty grey ring. A
 * missing card would read as "this workflow does not exist" rather than as
 * "nothing is in it yet" — and on a freshly installed workflow the second is
 * the interesting statement.
 *
 * A component widget, not a Blade one: the ring is a canvas drawn by Chart.js
 * after mount, and the card shell is the CP's own `Card`/`Heading`/
 * `Subheading`, which are Vue components with no Blade twin. `component()`
 * hands the dashboard `workflows-status-widget` (registered in
 * `resources/js/addon.js`) with every card finished — labels translated here,
 * segments already in display order, totals already summed. The client draws;
 * it does not decide.
 */
class WorkflowStatus extends Widget
{
    use PresentsPersonalEntries;

    /**
     * The addon's `workflows_` prefix convention (see the four widgets beside
     * it). The design brief called this widget `workflow_status`, singular and
     * unprefixed; the prefix wins, because the dashboard config lists all of
     * them together and one odd name out is a name people mistype.
     */
    protected static $handle = 'workflows_status';

    /**
     * The order states appear in — in the ring AND in the legend, which are the
     * same list read twice and must not disagree.
     *
     * Keyed on the state's COLOR NAME rather than on the place handle, for the
     * same reason PersonalWorkItems::URGENCY is: the color is the one property
     * every place has and every project can tune (`metadata.color`), so an
     * order derived from it needs no per-project table. A workflow author who
     * paints a place green has already said it is the finished end of the
     * scale.
     *
     * The scale runs done → in progress → waiting → broken: the greens
     * (published, approved, done), then the blues (in translation, in review),
     * then the purples (machine steps), then the neutrals (draft, queued,
     * source ready), then the ambers (waiting on a human), and red/rose last.
     * On the reference project that reproduces the order the design was drawn
     * in — Veröffentlicht, Finale Freigabe, Übersetzungsentwurf, Quelle bereit,
     * Failed — without naming a single one of those places.
     *
     * Note this is the REVERSE of the urgency order the queue rows sort by, and
     * deliberately so: a queue answers "what do I do next" (most urgent first),
     * a distribution answers "where did everything end up" (and the pile that
     * is finished is the one to read first).
     *
     * A color name not listed here sorts after everything listed, so a typo'd
     * `metadata.color` moves a segment to the end instead of dropping it.
     */
    private const COLOR_ORDER = [
        'green', 'emerald', 'lime', 'teal', 'cyan',
        'blue', 'sky', 'indigo',
        'purple', 'violet', 'fuchsia', 'pink',
        'default', 'gray',
        'amber', 'yellow', 'orange',
        'red', 'rose',
    ];

    public function component()
    {
        $loader = app(DefinitionLoader::class);
        $states = app(WorkflowStateStore::class);

        // All ACTIVE workflows, in definition order. The unrestricted list is
        // kept as well: the workflow's fallback color is assigned by position
        // among all of them, so a widget configured to show a subset still
        // paints each workflow the color it has on every other card.
        $all = $loader->all();

        $workflows = collect($all)
            ->when($this->workflowsConfig() !== null, fn ($names) => $names->intersect($this->workflowsConfig())->values())
            ->all();

        // No html() either, and DashboardController drops a widget only when
        // both are empty — so null is how this row hides itself on a project
        // with no active workflows (or a config naming none that exist).
        if ($workflows === []) {
            return null;
        }

        return [
            'name' => 'workflows-status-widget',
            'props' => [
                'cards' => array_map(
                    fn (string $workflow) => $this->card($workflow, $loader, $states, $all),
                    $workflows,
                ),
            ],
        ];
    }

    /**
     * One card: the workflow's identity plus its state distribution.
     *
     * One query per workflow (statesFor), grouped in memory. Counting per state
     * in SQL would be one query per state instead, and the row set here is the
     * same one the manager listings already page through.
     *
     * @param  array<int, string>  $all  Every active workflow, for the color palette.
     * @return array<string, mixed>
     */
    private function card(string $workflow, DefinitionLoader $loader, WorkflowStateStore $states, array $all): array
    {
        $raw = $loader->raw($workflow);

        $counts = [];

        foreach ($states->statesFor($workflow) as $state) {
            $state = (string) $state;

            // A blank state is a broken row, not a state — it has no label and
            // no color, and a nameless slice of the ring would be unreadable.
            if ($state === '') {
                continue;
            }

            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        $segments = [];

        foreach ($counts as $state => $count) {
            $state = (string) $state;

            $segments[] = [
                'state' => $state,
                'label' => $loader->stateLabel($state),
                // A Statamic badge color NAME, never a hex: the client maps it
                // through one table that colors both the ring segment and its
                // legend square (resources/js/support/widgetColors.js).
                'color' => $loader->stateColor($state),
                'count' => $count,
                // Where the posts behind this segment can be FETCHED, not the
                // posts themselves: the client calls this when the segment is
                // clicked and keeps the answer. Sending the lists along would
                // put every post of every state into the dashboard's payload —
                // hundreds of entry lookups per load, for lists nobody may open.
                'links_url' => cp_route('workflows.api.stats.state-subjects', [
                    'workflow' => $workflow,
                    'state' => $state,
                ]),
            ];
        }

        // Sorted here rather than in the client: the ring and the legend read
        // the same array, and an order computed twice is an order that can
        // disagree with itself. Ties (two states sharing a color, e.g. two
        // blues) fall back to the handle, so the order is stable across
        // requests instead of following whatever the database returned.
        usort($segments, fn (array $a, array $b) => [$this->colorRank($a['color']), $a['state']]
            <=> [$this->colorRank($b['color']), $b['state']]);

        return [
            'workflow' => $workflow,
            // Same fallback chain as PersonalWorkItems and QueueStats, so a
            // workflow is named identically on every dashboard card.
            'label' => DefinitionLoader::navLabel($raw['metadata']['nav'] ?? [])
                ?: (string) Str::headline($workflow),
            // The WORKFLOW's color (the dot in the header), as opposed to the
            // state colors in the segments. Shared with the list widgets rather
            // than re-derived — see PersonalWorkItems::workflowColor().
            'color' => PersonalWorkItems::workflowColor($workflow, $raw, $all),
            'total' => array_sum($counts),
            // The manager page, which the total in the ring's hole links to —
            // the whole workflow, as opposed to the one state a segment opens.
            // null for a workflow without `metadata.nav`: there is no page to
            // send anyone to, and the client then renders the total unlinked.
            'manager_url' => app(SubjectLinks::class)->managerUrl($workflow),
            'segments' => $segments,
        ];
    }

    /**
     * Where a color name sits in COLOR_ORDER; unknown names sort last.
     */
    private function colorRank(string $color): int
    {
        $rank = array_search($color, self::COLOR_ORDER, true);

        return $rank === false ? count(self::COLOR_ORDER) : (int) $rank;
    }
}
