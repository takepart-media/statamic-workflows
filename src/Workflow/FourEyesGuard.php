<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * `metadata.four_eyes` — the workflow's refusal to hand a post back to the
 * person who last passed it on.
 *
 *   four_eyes:
 *     by: group              # group | role | user
 *     handles: [chefredaktion]
 *     hint: ''               # optional; empty = the default text
 *
 * A passes the post on, B fires the next transition: A is never a candidate of
 * that transition's `user_assignment`, in either mode. "A" is the actor of the
 * subject's newest hand-made transition (WorkflowEventStore) — assignees are not
 * recorded per state, and only an assignee (or a privileged user) moves a post,
 * so whoever passed it on is the one who held it.
 *
 * When A is the WHOLE candidate set, the picker opens with the audience named
 * here instead (minus A) and explains why with `hint` — the same shape as
 * SelfAssignmentGuard. Both are combined in AssignmentGuard, which is what the
 * callers ask.
 */
final class FourEyesGuard
{
    public const KEY = 'four_eyes';

    /**
     * The block, cleaned — or null when it is absent or unusable (feature off).
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
     * Person A — who passed the subject on last — when the workflow forbids
     * handing it back. Null when the feature is off, for an unsaved subject (the
     * "New post" stub) and for a subject nobody has moved yet.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function previousActorId(array $raw, ?EntryContract $entry, ?string $workflow): ?string
    {
        if (self::settings($raw) === null || $workflow === null || $entry?->id() === null) {
            return null;
        }

        return app(WorkflowEventStore::class)->latestTransitionActorId((string) $entry->id(), $workflow);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function hint(array $raw): string
    {
        return self::settings($raw)['hint'] ?? __('workflows::messages.four_eyes_hint_default');
    }
}
