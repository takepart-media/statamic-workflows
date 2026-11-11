<?php

namespace TakepartMedia\Workflows\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Statamic\Contracts\Entries\Entry as EntryContract;
use TakepartMedia\Workflows\Subjects\EloquentSubject;

/**
 * A transition is about to be written: the guards passed and the state row is
 * locked, but nothing is changed yet.
 *
 * Dispatched INSIDE the transition's database transaction. This is where a
 * project does the domain work the step stands for — record an approval, close
 * a run — so that the place written right after already agrees with it (with
 * `metadata.state_attribute`, a model the listener moved to the target place
 * is left as it is). A listener that throws aborts the step; throw
 * TransitionBlocked to show the editor why.
 */
class TransitionApplying
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
