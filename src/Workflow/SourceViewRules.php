<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Auth\User as UserContract;

/**
 * How the translation detail page shows the SOURCE column to a given user —
 * `metadata.translation_source_view` of a localization workflow.
 *
 * The page is a provenance comparison: the source on the left, this version on
 * the right. For some readers the left half is noise (a reviewer judging the
 * target text on its own terms) or must not be there at all. A rule names an
 * audience and a mode:
 *
 *   translation_source_view:
 *     - mode: hidden        # the source is not even sent to the browser
 *       by: group           # group | role | user
 *       handles: [medical_writer]
 *     - mode: collapsed     # the page opens with one column; a button reveals it
 *       by: user
 *       handles: ['<user id>']
 *
 * Whoever matches no rule gets the comparison as it always was. When several
 * rules match, the STRICTER one wins (`hidden` over `collapsed`) — a rule that
 * hides is a statement about what someone should not see, and a second, looser
 * rule may not quietly undo it.
 *
 * This is a DISPLAY rule, not a permission, so there is no super-admin bypass:
 * a super admin listed in a rule gets the view the rule describes. The
 * permission-style helpers (WorkflowController::canAssign() and friends) exempt
 * super admins because a lock without a key is not a permission model; a view
 * preference has no such problem.
 *
 * Deliberately NOT the assignment-criteria vocabulary (AssignmentCriteria /
 * AssignmentCandidates): that vocabulary knows groups, roles and project fields
 * but not single users, its one-user matcher is private to the candidate
 * search, and a criteria tree is more machinery than three predicates need.
 * Should a project ever want "everyone whose entity matches the post", that is
 * the moment to reconsider.
 */
final class SourceViewRules
{
    public const KEY = 'translation_source_view';

    public const SIDE_BY_SIDE = 'side_by_side';

    public const COLLAPSED = 'collapsed';

    public const HIDDEN = 'hidden';

    /** The modes a rule may name — SIDE_BY_SIDE is the default, never written. */
    public const MODES = [self::HIDDEN, self::COLLAPSED];

    public const BY_GROUP = 'group';

    public const BY_ROLE = 'role';

    public const BY_USER = 'user';

    public const BY = [self::BY_GROUP, self::BY_ROLE, self::BY_USER];

    /**
     * The rules of a raw definition, cleaned: only entries with a known mode, a
     * known audience type and at least one string handle. The writer rejects
     * anything else at save time (WorkflowDefinitionWriter::validateSourceView),
     * so this is belt and braces for a YAML edited by hand.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, array{mode: string, by: string, handles: array<int, string>}>
     */
    public static function rules(array $raw): array
    {
        $rules = $raw['metadata'][self::KEY] ?? [];

        if (! is_array($rules)) {
            return [];
        }

        $clean = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $mode = $rule['mode'] ?? null;
            $by = $rule['by'] ?? null;
            $handles = array_values(array_filter(
                (array) ($rule['handles'] ?? []),
                fn ($handle) => is_string($handle) && $handle !== ''
            ));

            if (! in_array($mode, self::MODES, true) || ! in_array($by, self::BY, true) || $handles === []) {
                continue;
            }

            $clean[] = ['mode' => $mode, 'by' => $by, 'handles' => $handles];
        }

        return $clean;
    }

    /**
     * The mode for one user: SIDE_BY_SIDE, COLLAPSED or HIDDEN. No user (an
     * unattended context) sees the default — there is nobody to match.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function modeFor(array $raw, ?UserContract $user): string
    {
        if ($user === null) {
            return self::SIDE_BY_SIDE;
        }

        $mode = self::SIDE_BY_SIDE;

        foreach (self::rules($raw) as $rule) {
            if (! self::matches($user, $rule)) {
                continue;
            }

            if ($rule['mode'] === self::HIDDEN) {
                return self::HIDDEN;
            }

            $mode = self::COLLAPSED;
        }

        return $mode;
    }

    /**
     * @param  array{mode: string, by: string, handles: array<int, string>}  $rule
     */
    private static function matches(UserContract $user, array $rule): bool
    {
        foreach ($rule['handles'] as $handle) {
            $hit = match ($rule['by']) {
                self::BY_GROUP => (bool) $user->isInGroup($handle),
                self::BY_ROLE => (bool) $user->hasRole($handle),
                self::BY_USER => (string) $user->id() === $handle,
                default => false,
            };

            if ($hit) {
                return true;
            }
        }

        return false;
    }
}
