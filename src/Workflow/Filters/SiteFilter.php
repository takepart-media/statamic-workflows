<?php

namespace TakepartMedia\Workflows\Workflow\Filters;

class SiteFilter extends WorkflowFilter
{
    protected static $handle = 'site';

    public static function title()
    {
        return __('workflows::messages.site');
    }

    protected function placeholder(): string
    {
        return __('workflows::messages.all_sites');
    }
}
