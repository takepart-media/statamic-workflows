<?php

namespace TakepartMedia\Workflows\Workflow\Filters;

/**
 * "Only posts with an out-of-date version".
 *
 * A single-option select rather than a toggle, because the CP's native filter bar
 * is built from selects and a lone checkbox in that row reads like a broken
 * control. The one option is also the honest shape of the question: the opposite
 * ("only posts where everything is current") is what clearing the filter shows,
 * and offering it as a second value would suggest a third state that does not
 * exist.
 */
class StaleFilter extends WorkflowFilter
{
    protected static $handle = 'stale';

    public static function title()
    {
        return __('workflows::messages.filter_stale');
    }

    protected function placeholder(): string
    {
        return __('workflows::messages.filter_stale_any');
    }
}
