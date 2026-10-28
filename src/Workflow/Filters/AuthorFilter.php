<?php

namespace TakepartMedia\Workflows\Workflow\Filters;

class AuthorFilter extends WorkflowFilter
{
    protected static $handle = 'author';

    public static function title()
    {
        return __('workflows::messages.author');
    }

    protected function placeholder(): string
    {
        return __('workflows::messages.all_authors');
    }
}
