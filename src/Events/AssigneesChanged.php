<?php

namespace TakepartMedia\Workflows\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The responsible people of a subject were set — by a transition, the assignee
 * drawer, a new post or the SLA take-over. Fired from WorkflowAssigneeStore::set(),
 * the one place every assignment ends in.
 */
class AssigneesChanged
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $userIds  the new set
     * @param  array<int, string>  $previous  the set before
     */
    public function __construct(
        public readonly string $subjectId,
        public readonly string $workflow,
        public readonly array $userIds,
        public readonly array $previous,
    ) {}
}
