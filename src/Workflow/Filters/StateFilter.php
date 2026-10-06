<?php

namespace TakepartMedia\Workflows\Workflow\Filters;

class StateFilter extends WorkflowFilter
{
    protected static $handle = 'state';

    public static function title()
    {
        return __('workflows::messages.state');
    }

    protected function placeholder(): string
    {
        return __('workflows::messages.all_states');
    }
}
