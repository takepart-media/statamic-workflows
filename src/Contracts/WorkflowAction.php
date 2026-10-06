<?php

namespace TakepartMedia\Workflows\Contracts;

use TakepartMedia\Workflows\Workflow\ActionContext;

/**
 * What a project writes to give one of its own action types an effect.
 *
 * The wiring runs over three layers, and the `type` string is what ties them
 * together:
 *
 * 1. `config/workflows.php → actions` maps a handle to THIS class.
 * 2. The workflow YAML puts that handle on a transition or a place — that is
 *    WHERE it applies, and it is edited in the CP like any other action.
 * 3. WorkflowTransitioner::apply() looks the handle up when the transition runs
 *    and calls handle() below.
 *
 * The addon therefore knows nothing about the implementation; it knows a handle.
 *
 * Resolved through the container (`app($class)`), so constructor injection works
 * — a handler may type-hint a mailer, a client, whatever it needs.
 *
 * WHEN it runs, exactly:
 *
 * - a `transition` action AFTER the transition has been applied — state,
 *   published flag, assignees, history entry and revision are all written;
 * - a `place` action right after that, when the subject has ARRIVED in the place
 *   the action sits on. Same moment, tied to the destination instead of the step,
 *   which is what "however it got here, do this" needs.
 *
 * Two consequences worth stating, because both surprise people:
 *
 * - **It cannot stop anything.** By the time handle() runs the transition has
 *   happened. Throwing does not undo it — the exception is caught and logged, the
 *   next handler still runs, and the click still succeeded. Whatever must be able
 *   to say "no" belongs in the definition (`required_group`, `target_missing`),
 *   not here.
 * - **Nobody may be logged in.** `$context->actorId` is null when the scheduled
 *   aging command drives the transition.
 */
interface WorkflowAction
{
    public function handle(ActionContext $context): void;
}
