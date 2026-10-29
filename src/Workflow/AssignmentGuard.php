<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Who a click may NOT hand a subject to, and where the picker turns instead —
 * SelfAssignmentGuard (the clicker) and FourEyesGuard (whoever passed it on
 * last) combined into one answer.
 *
 * One class for the question because four callers ask it — the action payload
 * (needs_pick, pickable_assignees, pick_hint), applyTransition(), and the "New
 * post" modal's createCta()/storePost() — and "offered" and "accepted" must stay
 * one answer, the same reason AssignmentCandidates exists. Unattended callers
 * (the SLA scheduler) have no clicker and are never affected.
 *
 * The two rules differ in the AUTOMATIC mode on purpose:
 *
 * - Person A (four eyes) is simply dropped from an automatic set — the post must
 *   not go back to them, whoever else is in the group.
 * - The clicker (self-assignment) stays in an automatic group they merely belong
 *   to — somebody else is in it, which is what the definition asked for. Only a
 *   set that is the clicker ALONE counts as handing it back.
 *
 * When nothing is left of a non-empty candidate set, the guard "applies": the
 * picker opens with the fallback audience of whichever rule emptied it.
 */
final class AssignmentGuard
{
    public const FOUR_EYES = 'four_eyes';

    public const SELF = 'self_assignment';

    /**
     * Candidate ids minus everyone the workflow forbids for this click.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    public static function filter(array $raw, array $ids, ?EntryContract $entry, ?string $workflow, ?string $actorId, ?string $mode): array
    {
        if ($actorId === null || $actorId === '') {
            return $ids;
        }

        $previous = FourEyesGuard::previousActorId($raw, $entry, $workflow);
        $self = SelfAssignmentGuard::settings($raw) !== null;

        $ids = array_values(array_filter($ids, fn (string $id) => $id !== $previous));

        if (! $self) {
            return $ids;
        }

        if ($mode === TransitionActions::MODE_AUTOMATIC) {
            return $ids === [$actorId] ? [] : $ids;
        }

        return array_values(array_filter($ids, fn (string $id) => $id !== $actorId));
    }

    /**
     * Which rule left this click with nobody to hand over to — null when the
     * assignment goes ahead as defined.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $transition
     */
    public static function reason(array $raw, array $transition, ?EntryContract $entry, ?string $workflow, ?string $actorId): ?string
    {
        if ($actorId === null || $actorId === '') {
            return null;
        }

        $assignment = TransitionActions::userAssignment($transition);

        if ($assignment === null) {
            return null;
        }

        $ids = app(AssignmentCandidates::class)->ids($assignment, $entry);

        if ($ids === [] || self::filter($raw, $ids, $entry, $workflow, $actorId, $assignment['mode']) !== []) {
            return null;
        }

        $previous = FourEyesGuard::previousActorId($raw, $entry, $workflow);

        return $previous !== null && in_array($previous, $ids, true) ? self::FOUR_EYES : self::SELF;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $transition
     */
    public static function applies(array $raw, array $transition, ?EntryContract $entry, ?string $workflow, ?string $actorId): bool
    {
        return self::reason($raw, $transition, $entry, $workflow, $actorId) !== null;
    }

    /**
     * Who the picker offers instead: the fallback audience of the rule that
     * applied, minus everyone the workflow forbids — otherwise the way around
     * the rule would be one checkbox away.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $transition
     * @return array<int, string>
     */
    public static function fallbackIds(array $raw, array $transition, ?EntryContract $entry, ?string $workflow, ?string $actorId): array
    {
        $settings = match (self::reason($raw, $transition, $entry, $workflow, $actorId)) {
            self::FOUR_EYES => FourEyesGuard::settings($raw),
            self::SELF => SelfAssignmentGuard::settings($raw),
            default => null,
        };

        if ($settings === null) {
            return [];
        }

        return self::filter($raw, SlaSettings::assigneeIds($settings), $entry, $workflow, $actorId, TransitionActions::MODE_MANUAL);
    }

    /**
     * The line above the picker, or null when no rule applied.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $transition
     */
    public static function hint(array $raw, array $transition, ?EntryContract $entry, ?string $workflow, ?string $actorId): ?string
    {
        return match (self::reason($raw, $transition, $entry, $workflow, $actorId)) {
            self::FOUR_EYES => FourEyesGuard::hint($raw),
            self::SELF => SelfAssignmentGuard::hint($raw),
            default => null,
        };
    }
}
