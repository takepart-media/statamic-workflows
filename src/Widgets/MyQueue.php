<?php

namespace TakepartMedia\Workflows\Widgets;

use Illuminate\Support\Collection;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;
use TakepartMedia\Workflows\Widgets\Concerns\PresentsPersonalEntries;
use TakepartMedia\Workflows\Workflow\PersonalWorkItems;

/**
 * CP dashboard card listing the posts assigned to the current user across ALL
 * active workflows — the cross-workflow counterpart of the managers' "My
 * Queue" sections, with identical semantics (direct assignee only, no group
 * expansion). Widget config: `limit` (rows shown per view, default 7) and
 * `workflows` (restrict to a subset of workflow names, default all active).
 *
 * A component widget, not a Blade one: the card carries a workflow filter as a
 * tab row, and switching tabs must not reload the dashboard. `component()`
 * hands the dashboard the Vue component `workflows-my-queue-widget` (registered
 * in `resources/js/addon.js`) together with everything it needs to answer every
 * tab without a round trip.
 */
class MyQueue extends Widget
{
    use PresentsPersonalEntries;

    protected static $handle = 'workflows_my_queue';

    public function component()
    {
        $user = User::current();

        // No html() either, and DashboardController drops a widget only when
        // both are empty — so null is how this card hides itself.
        if ($user === null) {
            return null;
        }

        $limit = (int) $this->config('limit', 7);

        // Grouped in workflow order: the rows arrive that way (PersonalWorkItems
        // iterates DefinitionLoader::all()), and groupBy keeps first-seen order,
        // so the tabs read like the nav.
        $perWorkflow = app(PersonalWorkItems::class)
            ->assignedTo((string) $user->id(), $this->workflowsConfig())
            ->groupBy('workflow');

        return [
            'name' => 'workflows-my-queue-widget',
            'props' => [
                'tabs' => $this->tabs($perWorkflow)->all(),
                'rows' => $this->payload($perWorkflow, $limit)->all(),
                'limit' => $limit,
            ],
        ];
    }

    /**
     * One tab per workflow that actually has rows — an empty tab would only
     * offer the user a way to reach an empty list. `count` is the FULL number
     * of rows in that workflow, not the number shipped: it is what the tab's
     * counter pill shows and what the footer subtracts from to say "+N".
     *
     * `url` is that workflow's manager page, and it is here rather than only on
     * the rows because the FOOTER needs it: it links each "+N" to the listing
     * where the hidden rows actually are. Null for a workflow without
     * `metadata.nav` (no manager page), which the footer renders unlinked.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, array<string, mixed>>>  $perWorkflow
     * @return \Illuminate\Support\Collection<int, array{key: string, label: string, color: string, count: int, url: string|null}>
     */
    private function tabs(Collection $perWorkflow): Collection
    {
        return $perWorkflow
            ->map(fn (Collection $rows, string $workflow) => [
                'key' => $workflow,
                'label' => $rows->first()['workflow_label'],
                'color' => $rows->first()['workflow_color'],
                'count' => $rows->count(),
                'url' => $rows->first()['manager_url'],
            ])
            ->values();
    }

    /**
     * The rows shipped to the client: per workflow its `limit` most urgent
     * ones, merged and sorted by (urgency, title) so the "Alle" tab is the
     * first `limit` of this list and every single-workflow tab is a filter over
     * it — the client slices and filters, it never sorts.
     *
     * Per workflow first, then merge, because the payload has to answer BOTH
     * kinds of tab: a global top-`limit` alone would leave a quiet workflow's
     * own tab empty. And it is a safe superset of that global top-`limit` —
     * whichever rows of a workflow rank highest globally are a prefix of that
     * workflow's own (urgency, title) order, so nothing the "Alle" tab needs
     * gets trimmed away here.
     *
     * The tail (a user with 274 assigned posts) stays on the server: only the
     * counts travel, in `tabs`.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, array<string, mixed>>>  $perWorkflow
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function payload(Collection $perWorkflow, int $limit): Collection
    {
        return $perWorkflow
            ->flatMap(fn (Collection $rows) => $this->byUrgency($rows)->take($limit))
            ->sort($this->byUrgencyThenTitle())
            ->values();
    }

    /**
     * Rows of one workflow, most urgent first. `sortBy` on its own is enough
     * for the title tie-break: PHP's sorts are stable since 8.0 and the rows
     * come in already sorted by title, so equal urgencies keep that order.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function byUrgency(Collection $rows): Collection
    {
        return $rows->sortBy('urgency')->values();
    }

    /**
     * The cross-workflow order. Spelled out as a comparator rather than
     * `sortBy([...])` because the title half has to stay a NATURAL comparison —
     * the same one PersonalWorkItems sorts with, so a merged list cannot
     * disagree with the per-workflow ones about two rows of equal urgency.
     *
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    private function byUrgencyThenTitle(): callable
    {
        return fn (array $a, array $b) => $a['urgency'] <=> $b['urgency']
            ?: strnatcasecmp((string) $a['title'], (string) $b['title']);
    }
}
