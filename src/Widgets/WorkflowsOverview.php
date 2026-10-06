<?php

namespace TakepartMedia\Workflows\Widgets;

use Statamic\Widgets\Widget;
use TakepartMedia\Workflows\Http\Controllers\WorkflowController;
use TakepartMedia\Workflows\Widgets\Concerns\PresentsPersonalEntries;

/**
 * "Alle Beiträge" as a CP dashboard card: the manager pages' pivot listing
 * across every active entry workflow at once — one row per post, a workflow
 * column, one state column per managed site. Widget config: `per_page` (rows
 * per page, default 15) and `workflows` (restrict to a subset of workflow
 * names, default all active).
 *
 * A component widget: the card IS the listing (`workflows-overview-widget`,
 * registered in `resources/js/addon.js`), which pages, searches and filters
 * against the regular listing endpoint with `all_workflows=1`. Everything it
 * starts from comes from WorkflowController::overviewProps(), so the columns,
 * filters and create buttons cannot drift from what that endpoint answers.
 */
class WorkflowsOverview extends Widget
{
    use PresentsPersonalEntries;

    protected static $handle = 'workflows_overview';

    public function component()
    {
        $props = app(WorkflowController::class)->overviewProps($this->workflowsConfig());

        // No html() either, and DashboardController drops a widget only when
        // both are empty — so null is how this card hides itself when the scope
        // holds no workflow.
        if ($props === null) {
            return null;
        }

        return [
            'name' => 'workflows-overview-widget',
            'props' => $props + [
                'perPage' => (int) $this->config('per_page', 15),
                'workflows' => $this->workflowsConfig(),
            ],
        ];
    }
}
