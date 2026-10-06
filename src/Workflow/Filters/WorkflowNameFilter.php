<?php

namespace TakepartMedia\Workflows\Workflow\Filters;

/**
 * "Only posts of this workflow" — offered by the cross-workflow listing on the
 * dashboard, where rows of every active workflow sit in one table. A manager
 * page lists a single workflow and never shows it.
 */
class WorkflowNameFilter extends WorkflowFilter
{
    protected static $handle = 'workflow';

    public static function title()
    {
        return __('workflows::messages.column_workflow');
    }

    protected function placeholder(): string
    {
        return __('workflows::messages.all_workflows');
    }
}
