<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * `metadata.revision_publishers` — who may put a working copy live.
 *
 *   revision_publishers:     # rows; a user matching ANY row qualifies
 *     - by: group            # group | role | user
 *       handles: [redaktion]
 *     - by: user
 *       handles: [<user id>, <user id>]
 *
 * Statamic opens a working copy when a LIVE entry is edited with revisions on
 * (CollectionRevisions); the workflow then sits in `pending_revision` until the
 * copy is published with Statamic's own "Publish…" button. That button needs the
 * `publish` ability (Policies\EntryPolicy), which normally only the privileged
 * hold. With this block set, while a working copy exists, the ability goes to
 * super admins and this audience instead — and to nobody else, the privileged
 * groups included.
 *
 * Without a working copy nothing changes: drafts still go live through the
 * workflow only.
 */
final class RevisionPublishers
{
    public const KEY = 'revision_publishers';

    /**
     * The rows, cleaned — null when the block is absent or names nobody
     * (feature off). Each row is one `{by, handles}` audience, read like an
     * SLA take-over (SlaSettings::audience()); unusable rows are dropped.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, array{by: string, handles: array<int, string>}>|null
     */
    public static function settings(array $raw): ?array
    {
        return self::clean($raw['metadata'][self::KEY] ?? null);
    }

    /**
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
     * Whether the user may publish this entry's working copy — or null when the
     * rule does not apply (no working copy, no workflow, no block), so the
     * caller falls back to its usual answer.
     *
     * @param  \Statamic\Contracts\Auth\User|null  $user
     */
    public static function allows($user, $entry): ?bool
    {
        if (! $entry instanceof EntryContract || ! method_exists($entry, 'hasWorkingCopy') || ! $entry->hasWorkingCopy()) {
            return null;
        }

        $workflow = app(WorkflowResolver::class)->forEntry($entry);

        if ($workflow === null) {
            return null;
        }

        $settings = self::settings(app(DefinitionLoader::class)->raw($workflow));

        if ($settings === null) {
            return null;
        }

        if ($user === null) {
            return false;
        }

        return $user->isSuper() || collect($settings)->contains(fn (array $row) => collect($row['handles'])->contains(
            fn (string $handle) => match ($row['by']) {
                SourceViewRules::BY_USER => (string) $user->id() === $handle,
                SourceViewRules::BY_GROUP => (bool) $user->isInGroup($handle),
                default => (bool) $user->hasRole($handle),
            },
        ));
    }
}
