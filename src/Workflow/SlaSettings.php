<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Facades\User;

/**
 * The `metadata.sla` block: which places mean "getting old" and after how many days.
 *
 * One reader for a block that four parties consult — the ageing command, the
 * progress bar, `finalPlace()` and the writer's validation — because the question
 * "is this place an ageing step" must have exactly one answer. It used to have
 * none: the two places were INJECTED with fixed handles (`needs_update`,
 * `outdated`), so every consumer could hardcode those two strings.
 *
 * They are now the author's own places, picked from the workflow's own list. That
 * is a real change in kind, not just in spelling: an ageing step is an ordinary
 * place with its own label, colour and `allow_publish`, reached by ordinary
 * transitions that carry their own assignment, mail and permission gate. The whole
 * parallel apparatus the overlay needed — reserved handles, five language keys and
 * a private `by`/`assign_to`/`dismiss_by` assignment dialect — is gone with it.
 *
 * What stays here is only what cannot be expressed as a place: the deadline, the
 * pointer saying which place the deadline leads to, and — optionally — who takes
 * over on arrival (assign()). That last one is not the old dialect back: it is one
 * audience picker per stage, and only because no human transition exists whose
 * assignment could say it.
 */
class SlaSettings
{
    /** Metadata key holding the block. */
    public const KEY = 'sla';

    /** The two stages, in the order they age. Order matters: the command tries the later one first. */
    public const STAGE_NEEDS_UPDATE = 'needs_update';

    public const STAGE_OUTDATED = 'outdated';

    /**
     * `<stage>_assign.by` value for "whoever the criteria select" — the same
     * criteria a `user_assignment` action uses, evaluated against the ageing
     * post. Next to group / role / user (SourceViewRules::BY), and SLA-only: the
     * other audiences in this addon are asked without a subject to inspect.
     */
    public const BY_CRITERIA = 'criteria';

    /**
     * The stages a definition actually configures, as
     * `stage => ['days' => int, 'place' => string]`, in ageing order.
     *
     * A stage counts only when BOTH halves are present and the place really
     * exists in this definition. Half a stage is not a lenient half-feature: a
     * deadline with no destination could never fire, and a destination with no
     * deadline would never be reached — reporting nothing is the honest answer,
     * and the writer refuses to save either half on its own.
     *
     * `assign` is the stage's own responsibility (see assign()), null when the
     * stage leaves it where it was.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, array{days: int, place: string, assign: array{by: string, handles: array<int, string>}|null}>
     */
    public static function stages(array $raw): array
    {
        $sla = $raw['metadata'][self::KEY] ?? null;

        if (! is_array($sla)) {
            return [];
        }

        $stages = [];

        foreach ([self::STAGE_NEEDS_UPDATE, self::STAGE_OUTDATED] as $stage) {
            $days = self::days($sla[$stage.'_days'] ?? null);
            $place = $sla[$stage.'_place'] ?? null;

            if ($days === null || ! is_string($place) || $place === '') {
                continue;
            }

            if (! isset($raw['places'][$place])) {
                continue;
            }

            $stages[$stage] = ['days' => $days, 'place' => $place, 'assign' => self::assign($sla, $stage)];
        }

        return $stages;
    }

    /**
     * The configured ageing places.
     *
     * Read by ProgressSteps and WorkflowResolver::finalPlace(), which have to
     * subtract them. That subtraction is not cosmetic: `finalPlace()` is the last
     * declared place, so an ageing step declared after `published` would become
     * the workflow's "done" — and with it every reader of `sourceIsReady()`, which
     * is what lets a localization manager start at all.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public static function places(array $raw): array
    {
        return array_values(array_unique(
            array_column(self::stages($raw), 'place')
        ));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function isAgingPlace(array $raw, string $place): bool
    {
        return in_array($place, self::places($raw), true);
    }

    /**
     * Who becomes responsible when a post reaches this stage, cleaned, or null.
     * `<stage>_assign` is one of two shapes:
     *
     * - `{by: group|role|user, handles: [...]}` — a fixed audience;
     * - `{by: criteria, match, criteria: [...], fallback: {by, handles}}` — the
     *   people the assignment criteria select FOR THIS POST, and optionally a
     *   fixed audience for a post they select nobody for.
     *
     * Needed because nobody clicks into an ageing step — the scheduler moves the
     * post — so there is no transition dialog to ask, and a move without a
     * transition leaves the old assignees in charge (PlaceMover). Those are
     * typically whoever approved the post a year ago. The fixed audience types
     * are the ones SourceViewRules already speaks, so the editor reuses its
     * picker; the criteria are the ones of the `user_assignment` action, so it
     * reuses that builder.
     *
     * @param  array<string, mixed>  $sla
     * @return array{by: string, handles?: array<int, string>, match?: string, criteria?: array<int, array<string, mixed>>, fallback?: array{by: string, handles: array<int, string>}|null}|null
     */
    public static function assign(array $sla, string $stage): ?array
    {
        $block = $sla[$stage.'_assign'] ?? null;

        if (is_array($block) && ($block['by'] ?? null) === self::BY_CRITERIA) {
            return self::criteriaAudience($block);
        }

        return self::audience($block);
    }

    /**
     * A `by: criteria` block, with its criteria normalised the way a
     * `user_assignment` action's are — or null when no criterion is left.
     *
     * Read through TransitionActions on purpose: the criteria tree (groups one
     * level deep, legacy spellings) has exactly one reader there, and a second
     * one here would drift.
     *
     * @param  array<string, mixed>  $block
     * @return array{by: string, match: string, criteria: array<int, array<string, mixed>>, fallback: array{by: string, handles: array<int, string>}|null}|null
     */
    private static function criteriaAudience(array $block): ?array
    {
        $assignment = TransitionActions::userAssignment(['metadata' => ['actions' => [[
            'type' => TransitionActions::USER_ASSIGNMENT,
            'mode' => TransitionActions::MODE_AUTOMATIC,
            'match' => $block['match'] ?? TransitionActions::MATCH_ALL,
            'criteria' => $block['criteria'] ?? [],
        ]]]]);

        if ($assignment === null || $assignment['criteria'] === []) {
            return null;
        }

        return [
            'by' => self::BY_CRITERIA,
            'match' => $assignment['match'] ?? TransitionActions::MATCH_ALL,
            'criteria' => $assignment['criteria'],
            'fallback' => self::audience($block['fallback'] ?? null),
        ];
    }

    /**
     * One `{by, handles}` audience, cleaned — or null when the type is unknown or
     * no handle is left. Shared with SelfAssignmentGuard, which names its
     * fallback audience in the same shape.
     *
     * @return array{by: string, handles: array<int, string>}|null
     */
    public static function audience(mixed $assign): ?array
    {
        if (! is_array($assign)) {
            return null;
        }

        $by = $assign['by'] ?? null;
        $handles = array_values(array_unique(array_filter(
            (array) ($assign['handles'] ?? []),
            fn ($handle) => is_string($handle) && $handle !== '',
        )));

        if (! in_array($by, SourceViewRules::BY, true) || $handles === []) {
            return null;
        }

        return ['by' => $by, 'handles' => $handles];
    }

    /**
     * Who a stage's `assign` hands THIS post to.
     *
     * A fixed audience is the same for every post. A criteria audience is asked
     * per post, and when it selects nobody — the field the criterion reads is
     * empty, the person is gone — its `fallback` audience answers instead. Empty
     * when neither names anyone; the caller then keeps the current assignees.
     *
     * @param  array<string, mixed>  $assign  As returned by assign().
     * @param  \Statamic\Contracts\Entries\Entry|null  $entry
     * @return array<int, string>
     */
    public static function stageAssigneeIds(array $assign, $entry): array
    {
        if ($assign['by'] !== self::BY_CRITERIA) {
            return self::assigneeIds($assign);
        }

        $ids = app(AssignmentCandidates::class)->ids([
            'mode' => TransitionActions::MODE_AUTOMATIC,
            'match' => $assign['match'],
            'criteria' => $assign['criteria'],
        ], $entry);

        if ($ids !== [] || ($assign['fallback'] ?? null) === null) {
            return $ids;
        }

        return self::assigneeIds($assign['fallback']);
    }

    /**
     * The user ids a FIXED audience resolves to right now: members of any of
     * the groups, holders of any of the roles, or the named users that still
     * exist. Empty when nobody matches — the caller then leaves the assignment
     * alone rather than hand the post to nobody.
     *
     * @param  array{by: string, handles: array<int, string>}  $assign
     * @return array<int, string>
     */
    public static function assigneeIds(array $assign): array
    {
        $handles = $assign['handles'];

        $users = $assign['by'] === SourceViewRules::BY_USER
            ? collect($handles)->map(fn (string $id) => User::find($id))->filter()
            : collect(User::all())->filter(fn ($user) => collect($handles)->contains(
                fn (string $handle) => $assign['by'] === SourceViewRules::BY_GROUP
                    ? (bool) $user->isInGroup($handle)
                    : (bool) $user->hasRole($handle),
            ));

        return $users->map(fn ($user) => (string) $user->id())->unique()->values()->all();
    }

    /**
     * A day threshold, or null when unset.
     *
     * `0` is a legitimate value and means "at the next scheduler tick" — which is
     * how the whole thing is tested without waiting a year. Hence the `>= 0`
     * rather than a truthiness check.
     */
    public static function days(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit(ltrim($value, '-')))) {
            return null;
        }

        $int = (int) $value;

        return $int >= 0 ? $int : null;
    }
}
