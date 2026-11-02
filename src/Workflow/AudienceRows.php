<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * A list of `{by, handles}` audiences — the "rows" settings such as
 * RevisionPublishers and CommentPermissions share:
 *
 *   - by: group            # group | role | user
 *     handles: [redaktion]
 *   - by: user
 *     handles: [<user id>]
 *
 * A user matching ANY row qualifies. Each row is read like an SLA take-over
 * (SlaSettings::audience()).
 */
final class AudienceRows
{
    /**
     * The rows, cleaned — null when the block is absent or names nobody.
     * Unusable rows are dropped.
     *
     * @return array<int, array{by: string, handles: array<int, string>}>|null
     */
    public static function clean(mixed $block): ?array
    {
        if (! is_array($block) || ! array_is_list($block)) {
            return null;
        }

        $rows = array_values(array_filter(array_map(fn ($row) => SlaSettings::audience($row), $block)));

        return $rows === [] ? null : $rows;
    }

    /**
     * Whether the writer may store the block: a non-empty list of usable rows.
     * A half-filled row would quietly narrow the audience, so it fails instead.
     */
    public static function valid(mixed $block): bool
    {
        return is_array($block) && array_is_list($block) && $block !== []
            && ! collect($block)->contains(fn ($row) => SlaSettings::audience($row) === null);
    }

    /**
     * @param  \Statamic\Contracts\Auth\User  $user
     * @param  array<int, array{by: string, handles: array<int, string>}>  $rows
     */
    public static function matches($user, array $rows): bool
    {
        return collect($rows)->contains(fn (array $row) => collect($row['handles'])->contains(
            fn (string $handle) => match ($row['by']) {
                SourceViewRules::BY_USER => (string) $user->id() === $handle,
                SourceViewRules::BY_GROUP => (bool) $user->isInGroup($handle),
                default => (bool) $user->hasRole($handle),
            },
        ));
    }
}
