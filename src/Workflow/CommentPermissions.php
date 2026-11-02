<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * Who may resolve and who may delete comments in a workflow — two optional
 * AudienceRows blocks:
 *
 *   comment_resolvers:       # may mark threads done and reopen them
 *     - by: group
 *       handles: [redaktion]
 *   comment_deleters:        # may delete ANY comment
 *     - by: role
 *       handles: [chefredaktion]
 *
 * Super admins always may both, and everyone may still delete their OWN
 * comments. Without a block the old rules stay: deleting falls back to
 * `config/workflows.php → comment_moderator_groups`, resolving to the thread's
 * author, the moderators and the subject's current assignees. With
 * `comment_resolvers` set, ONLY super admins and that audience resolve — the
 * author and the assignees included in no other way.
 */
final class CommentPermissions
{
    public const RESOLVERS = 'comment_resolvers';

    public const DELETERS = 'comment_deleters';

    public const KEYS = [self::RESOLVERS, self::DELETERS];

    /**
     * Whether the user matches the block — null when the workflow has none, so
     * the caller falls back to its usual answer.
     *
     * @param  array<string, mixed>  $raw
     * @param  \Statamic\Contracts\Auth\User|null  $user
     */
    public static function allows(array $raw, string $key, $user): ?bool
    {
        $rows = AudienceRows::clean($raw['metadata'][$key] ?? null);

        if ($rows === null) {
            return null;
        }

        if ($user === null) {
            return false;
        }

        return $user->isSuper() || AudienceRows::matches($user, $rows);
    }
}
