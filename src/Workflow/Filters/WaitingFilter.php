<?php

namespace TakepartMedia\Workflows\Workflow\Filters;

class WaitingFilter extends WorkflowFilter
{
    protected static $handle = 'waiting';

    public static function title()
    {
        return __('workflows::messages.waiting');
    }

    protected function placeholder(): string
    {
        return __('workflows::messages.all_waiting');
    }
}
