<?php

namespace TakepartMedia\Workflows\Contracts;

use TakepartMedia\Workflows\Workflow\ActionContext;

/**
 * A project's veto on a transition, checked BEFORE anything is written.
 *
 * Declared like an action (`config/workflows.php → actions.<handle>.guard`) and
 * put on a transition in the workflow YAML the same way. Unlike a WorkflowAction
 * handler it runs ahead of the step:
 *
 * - on the instance page, to disable the button and show the reason as its
 *   tooltip;
 * - in WorkflowTransitioner::apply() for every caller — a click, a command, the
 *   scheduler — which then refuses with TransitionBlocked.
 *
 * So check() must not write anything. The context describes the step that WOULD
 * happen: `from` is the current place, `to` the target.
 */
interface TransitionGuard
{
    /**
     * Why the transition may not run, as a sentence for the editor — or null
     * when it may.
     */
    public function check(ActionContext $context): ?string;
}
