<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * Who may take a step, read off the definition — and, on top of that, which
 * PLACES are the ones a subject sits and waits in for a human decision.
 *
 * Extracted from WorkflowController so there is exactly ONE reading of the YAML
 * shape. The controller gates the actual click on it (canApplyTransition), the
 * dashboard's QueueStats card counts what is parked in front of it, and a
 * second guess at where `required_group` lives would let the button and the
 * number disagree about the same workflow.
 *
 * Static, with no state and no dependencies: it answers questions about an
 * already-parsed definition array (DefinitionLoader::raw()) and never loads one
 * itself.
 */
class TransitionGates
{
    /**
     * User-group handles required to apply a transition
     * (`metadata.required_group`, string or list). Empty means the transition is
     * open to every assignee.
     *
     * @param  array<string, mixed>  $transition
     * @return array<int, string>
     */
    public static function groups(array $transition): array
    {
        return self::handles($transition, 'required_group');
    }

    /**
     * The role counterpart — `metadata.required_role`, same shape and same
     * meaning, asking `hasRole()` instead of `isInGroup()`. With `groups()` a
     * UNION, not two hurdles; see WorkflowController::canApplyTransition().
     *
     * @param  array<string, mixed>  $transition
     * @return array<int, string>
     */
    public static function roles(array $transition): array
    {
        return self::handles($transition, 'required_role');
    }

    /**
     * Whether a transition names an audience at all.
     *
     * @param  array<string, mixed>  $transition
     */
    public static function isGated(array $transition): bool
    {
        return self::groups($transition) !== [] || self::roles($transition) !== [];
    }

    /**
     * The APPROVAL GATES of a workflow: the places where a subject waits for a
     * person to decide something, as a list of place handles.
     *
     * Two signals, either of which is enough, because projects say the same
     * thing in two different ways:
     *
     * 1. An outgoing transition names an audience (`required_group` /
     *    `required_role`). The definition has spelled the gate out: not everyone
     *    responsible may take this step, a particular group or role must. That is
     *    an approval in the strict sense and needs no interpretation.
     *
     * 2. The place has TWO OR MORE outgoing transitions. A step with one way out
     *    is a hand-over — the work moves on and the only question is when. Two
     *    ways out is a decision (approve or reject, accept or send back), and a
     *    subject sitting there is waiting for somebody to make it. This is what
     *    catches the common workflow that gates by ASSIGNMENT alone: the
     *    `user_assignment` action already handed the subject to the right people,
     *    so the author never had to write a `required_group` as well.
     *
     * Places marked `metadata.branch: true` are excluded from signal 2 — and only
     * from signal 2, an explicit gate still counts wherever it is declared. A
     * branch is the definition's own word for "a turn-off from the line" (see
     * WorkflowDefinitionWriter): the SLA aging places, the failed-translation
     * park. They have two ways out for a different reason — take the subject back
     * onto the line, or send it back for rework — and they are not something
     * anybody submitted for approval. Counting them would drown the real gates:
     * in a mature project the aging places hold hundreds of posts nobody is
     * waiting on.
     *
     * System transitions are ignored throughout: they are not offered as buttons
     * (see WorkflowController::entryActions()), so they cannot be what a subject
     * is waiting for.
     *
     * @param  array<string, mixed>  $raw  A parsed definition (DefinitionLoader::raw())
     * @return array<int, string>
     */
    public static function gatePlaces(array $raw): array
    {
        $gated = [];
        $outgoing = [];

        foreach ($raw['transitions'] ?? [] as $transition) {
            if (! is_array($transition) || ($transition['metadata']['system'] ?? false) === true) {
                continue;
            }

            foreach ((array) ($transition['from'] ?? []) as $place) {
                $place = (string) $place;

                $outgoing[$place] = ($outgoing[$place] ?? 0) + 1;

                if (self::isGated($transition)) {
                    $gated[$place] = true;
                }
            }
        }

        $places = [];

        foreach ($outgoing as $place => $count) {
            $place = (string) $place;

            if (isset($gated[$place])) {
                $places[] = $place;

                continue;
            }

            if ($count >= 2 && ! self::isBranch($raw, $place)) {
                $places[] = $place;
            }
        }

        return $places;
    }

    /**
     * `metadata.branch: true` on a place — the definition's marker for a
     * turn-off from the main line. Unknown places (a transition naming a place
     * the `places` block does not declare) are not branches; the writer rejects
     * that anyway.
     *
     * @param  array<string, mixed>  $raw
     */
    private static function isBranch(array $raw, string $place): bool
    {
        return ($raw['places'][$place]['metadata']['branch'] ?? false) === true;
    }

    /**
     * One `metadata.<key>` read, as a clean list of handles. Both spellings the
     * YAML allows — a bare string and a list — arrive here as an array, and
     * empty entries are dropped rather than turned into a gate nobody can pass.
     *
     * @param  array<string, mixed>  $transition
     * @return array<int, string>
     */
    private static function handles(array $transition, string $key): array
    {
        return collect((array) ($transition['metadata'][$key] ?? []))
            ->filter()
            ->values()
            ->all();
    }
}
