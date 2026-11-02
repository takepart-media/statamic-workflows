<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * Who sees the plain-language service's QA report ("QS-Hinweise") on the
 * instance page — a setting of the plain-language workflow itself:
 *
 *   qs_notes_visible: false   # absent = true
 *   qs_notes_audience:        # optional AudienceRows, only while visible
 *     - by: group
 *       handles: [medical_writer]
 *
 * Off hides the report from everyone. On without rows shows it to everyone, as
 * before. On with rows shows it to super admins and that audience only. Read
 * from the instance's own workflow, so no other workflow is affected.
 */
final class QsNotesVisibility
{
    public const KEY_VISIBLE = 'qs_notes_visible';

    public const KEY_AUDIENCE = 'qs_notes_audience';

    /**
     * @param  array<string, mixed>  $raw
     * @param  \Statamic\Contracts\Auth\User|null  $user
     */
    public static function allows(array $raw, $user): bool
    {
        if (($raw['metadata'][self::KEY_VISIBLE] ?? true) === false) {
            return false;
        }

        $rows = AudienceRows::clean($raw['metadata'][self::KEY_AUDIENCE] ?? null);

        if ($rows === null) {
            return true;
        }

        return $user !== null && ($user->isSuper() || AudienceRows::matches($user, $rows));
    }
}
