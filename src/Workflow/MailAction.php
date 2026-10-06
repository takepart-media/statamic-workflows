<?php

namespace TakepartMedia\Workflows\Workflow;

use TakepartMedia\Workflows\Mail\MailTypes;

/**
 * The `mail` action — ONE action, in both scopes, and this class is why that
 * sentence is true.
 *
 * A transition can carry it ("when this step runs, mail the assignees") and so
 * can a place ("when a subject arrives here, mail the assignees"). That is one
 * effect with two triggers, not two features: the difference is WHERE the author
 * hangs it, which is the same choice `CustomActions` already offers for project
 * handlers. Everything else — who gets the mail, which mail, what it may fail at
 * — is identical, so it lives here once instead of twice in TransitionActions and
 * PlaceActions. Those two classes still own their own NORMALISATION (they have
 * different neighbouring keys), but they ask this class what the action means.
 *
 * WHICH mail is the action's only setting: `mail_type`, a MailTypes handle. The
 * words are not in the action and not in this class — see MailTypes.
 *
 * TWO OLDER SPELLINGS reach this class, both from the short window before the
 * type selector existed, and both are read rather than migrated:
 *
 *   - `type: notify_assignees` instead of `type: mail`. It named the RECIPIENTS,
 *     which stopped being the interesting half the moment there was more than one
 *     mail to send. Folded to `mail` on read; the editor writes only `mail` from
 *     here on.
 *   - `mail: <handle>` instead of `mail_type: <handle>`. Renamed because
 *     `type: mail` next to `mail: assigned` reads like a mistake.
 *
 * Neither is in TYPES, so the writer never stores them again — the same treatment
 * `user_modal` gets in TransitionActions, minus its outright rejection: that one
 * was RETIRED and must be corrected, these are merely old and correct.
 */
class MailAction
{
    /** The action's handle, in both scopes. */
    public const TYPE = 'mail';

    /** Its only key: which mail to send. */
    public const KEY = 'mail_type';

    /** Read, never written — see the class note. */
    public const LEGACY_TYPE = 'notify_assignees';

    /** Read, never written — see the class note. */
    public const LEGACY_KEY = 'mail';

    /**
     * One raw action's type, with the older spelling folded in. Every other type
     * passes through untouched, so both normalisers can call this
     * unconditionally.
     */
    public static function handle(string $type): string
    {
        return $type === self::LEGACY_TYPE ? self::TYPE : $type;
    }

    /**
     * The `mail_type` of one RAW action, tolerating the older key. Null for an
     * action that names neither — which is every action that is not a mail, and
     * also a mail written before the selector existed. Callers turn that null into
     * MailTypes::FALLBACK, never into "send nothing".
     *
     * @param  array<string, mixed>  $action
     */
    public static function storedType(array $action): ?string
    {
        foreach ([self::KEY, self::LEGACY_KEY] as $key) {
            $value = $action[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * WHICH mail this list of ALREADY-NORMALISED actions sends, or null when none
     * of them is a mail action.
     *
     * The answer is always a usable MailTypes handle, never the raw stored value:
     * a mail action with no `mail_type` resolves to MailTypes::FALLBACK, and so
     * does one naming a type that no longer exists — see MailTypes::resolve() for
     * why a missing type sends the default mail rather than nothing.
     *
     * The first match wins. At most one mail action per transition and per place
     * is a rule the writer enforces, so a second one can only come from a
     * hand-edited YAML; taking the first is the same tie-break
     * TransitionActions::userAssignment() makes, for the same reason.
     *
     * @param  array<int, array<string, mixed>>  $actions
     */
    public static function typeIn(array $actions): ?string
    {
        foreach ($actions as $action) {
            if (($action['type'] ?? null) === self::TYPE) {
                return MailTypes::resolve($action[self::KEY] ?? null);
            }
        }

        return null;
    }
}
