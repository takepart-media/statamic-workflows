<?php

namespace TakepartMedia\Workflows\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Statamic\Contracts\Entries\Entry as EntryContract;
use TakepartMedia\Workflows\Subjects\EloquentSubject;

/**
 * A transition was written: state, assignees and history row.
 *
 * Dispatched INSIDE the transition's database transaction, before mails and
 * project action handlers run. A listener may therefore write the project's own
 * records in the same commit — and a listener that throws rolls the whole step
 * back, state included. Throw TransitionBlocked to refuse with a reason the
 * editor sees. Keep listeners quick; anything slow belongs on a queue.
 */
class TransitionApplied
{
    use Dispatchable;

    public function __construct(
        public readonly EntryContract|EloquentSubject $subject,
        public readonly string $workflow,
        public readonly string $transition,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $actorId,
    ) {}
}
