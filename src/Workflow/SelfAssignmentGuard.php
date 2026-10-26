<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * `metadata.self_assignment` — the workflow's refusal to hand a post back to the
 * person who just clicked.
 *
 *   self_assignment:
 *     by: group              # group | role | user
 *     handles: [projektleitung]
 *     hint: ''               # optional; empty = the default text
 *
 * With this block set, the clicker is never a candidate of a `user_assignment`,
 * in either mode:
 *
 * - MANUAL: the picker simply leaves the clicker out (withoutClicker()).
 * - AUTOMATIC: assigning a group the clicker merely belongs to is still what the
 *   definition asked for — somebody else is in it — so nothing changes there.
 *
 * And when the clicker is the WHOLE candidate set (applies()), in either mode,
 * there is nobody left to hand over to: the picker opens with the audience named
 * here (minus the clicker) and explains why with `hint`. For criteria like "the
 * post's author" that is exactly the editor moving the post on.
 *
 * One class for the question because four callers ask it — the action payload
 * (needs_pick, pickable_assignees, pick_hint), applyTransition(), and the "New
 * post" modal's createCta()/storePost() — and "offered" and "accepted" must stay
 * one answer, the same reason AssignmentCandidates exists. Unattended callers
 * (the SLA scheduler) have no clicker and are never affected.
 */
final class SelfAssignmentGuard
{
    public const KEY = 'self_assignment';

    /**
     * The block, cleaned — or null when it is absent or unusable, which means
     * the feature is off. The audience is read like an SLA take-over
     * (SlaSettings::assign()); the writer refuses anything else at save time.
     *
     * @param  array<string, mixed>  $raw
     * @return array{by: string, handles: array<int, string>, hint: string|null}|null
     */
    public static function settings(array $raw): ?array
    {
        $block = $raw['metadata'][self::KEY] ?? null;

        if (! is_array($block)) {
            return null;
        }

        $audience = SlaSettings::audience($block);

        if ($audience === null) {
            return null;
        }

        $hint = $block['hint'] ?? null;

        return $audience + ['hint' => is_string($hint) && trim($hint) !== '' ? trim($hint) : null];
    }

    /**
     * Would this click hand the subject back to the clicker alone — and does the
     * workflow forbid that? Then the fallback audience is asked instead.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $transition
     */
    public static function applies(array $raw, array $transition, ?EntryContract $entry, ?string $actorId): bool
    {
        if ($actorId === null || $actorId === '' || self::settings($raw) === null) {
            return false;
        }

        $assignment = TransitionActions::userAssignment($transition);

        if ($assignment === null) {
            return false;
        }

        return app(AssignmentCandidates::class)->ids($assignment, $entry) === [$actorId];
    }

    /**
     * A manual assignment's candidate ids, minus the clicker when the workflow
     * forbids self-assignment — what the picker offers and what a pick is
     * checked against.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    public static function withoutClicker(array $raw, array $ids, ?string $actorId): array
    {
        if ($actorId === null || self::settings($raw) === null) {
            return $ids;
        }

        return array_values(array_filter($ids, fn (string $id) => $id !== $actorId));
    }

    /**
     * Who the picker offers instead: the configured audience, without the
     * clicker — otherwise the way around the rule would be one checkbox away.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public static function candidateIds(array $raw, ?string $actorId): array
    {
        $settings = self::settings($raw);

        if ($settings === null) {
            return [];
        }

        return array_values(array_filter(
            SlaSettings::assigneeIds($settings),
            fn (string $id) => $id !== $actorId,
        ));
    }

    /**
     * The line above the picker: the author's own text, or the default.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function hint(array $raw): string
    {
        return self::settings($raw)['hint'] ?? __('workflows::messages.self_assignment_hint_default');
    }
}
