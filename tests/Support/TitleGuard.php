<?php

namespace TakepartMedia\Workflows\Tests\Support;

use TakepartMedia\Workflows\Contracts\TransitionGuard;
use TakepartMedia\Workflows\Workflow\ActionContext;

/**
 * Refuses the step while the ticket is marked blocked.
 */
class TitleGuard implements TransitionGuard
{
    public function check(ActionContext $context): ?string
    {
        return $context->entry->value('status') === 'blocked' ? 'Ticket is blocked.' : null;
    }
}
