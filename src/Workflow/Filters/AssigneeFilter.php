<?php

namespace TakepartMedia\Workflows\Workflow\Filters;

class AssigneeFilter extends WorkflowFilter
{
    protected static $handle = 'assignee';

    public static function title()
    {
        return __('workflows::messages.assignee');
    }

    protected function placeholder(): string
    {
        return __('workflows::messages.all_assignees');
    }
}
