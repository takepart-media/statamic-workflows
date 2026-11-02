<?php

namespace TakepartMedia\Workflows\Workflow;


/**
 * The actions a transition runs before it is applied — and the only place that
 * knows how they are written down.
 *
 * An action is a step or a precondition attached to a transition.
 * `user_assignment` in its manual mode and `site_modal` are dialogs — something
 * the click opens before the transition runs. `target_missing` is not: it is a
 * silent gate, blocking (via `can_apply`/`reason`, the same channel every other
 * precondition uses) unless a named site has no version of the post yet. The
 * list is deliberately a list, so either kind — a future dialog, a future gate,
 * a notification, a webhook — has somewhere to go without another schema change.
 * `mail` is the first of those to arrive: neither a dialog nor a gate but an
 * EFFECT, and the only type here that a PLACE can carry too — see MailAction.
 *
 * Three spellings reach this class. The current one is `metadata.actions` with
 * `type: user_assignment`; before it there was `type: user_modal` carrying a
 * `groups` list, and before THAT the ad-hoc booleans `assign_from_group` and
 * `needs_site_pick`. Callers see none of them — they ask here and get one shape.
 * That is what keeps the readers (entryActions, pickableAssignees,
 * applyTransition) free of `?? isset(...)` chains that would each have to be
 * kept in step.
 */
class TransitionActions
{
    /**
     * Hands the subject to somebody — either by asking (manual: a picker opens
     * between the click and the transition) or by deciding (automatic: the
     * matching users become the assignees, no dialog).
     *
     * Both modes draw from the SAME candidate set, resolved from `criteria` by
     * AssignmentCandidates. That is the whole design: the mode says who makes
     * the final call, never who is eligible, so switching a transition from
     * manual to automatic cannot silently change the pool.
     */
    public const USER_ASSIGNMENT = 'user_assignment';

    /**
     * The pre-`user_assignment` spelling: a dialog whose candidates were the
     * union of the members of a `groups` list. Read, never written — `for()`
     * folds it into a manual assignment with one `group is <handle>` criterion
     * per entry and `match: any`, which is exactly what the union meant. Absent
     * from TYPES so the writer refuses to store it again.
     */
    public const USER_MODAL = 'user_modal';

    /** The picker opens; the editor chooses from the candidates. */
    public const MODE_MANUAL = 'manual';

    /** No dialog; every candidate becomes an assignee. */
    public const MODE_AUTOMATIC = 'automatic';

    public const MODES = [self::MODE_MANUAL, self::MODE_AUTOMATIC];

    /** A user must satisfy every criterion. The default. */
    public const MATCH_ALL = 'all';

    /** A user must satisfy at least one criterion. */
    public const MATCH_ANY = 'any';

    public const MATCHES = [self::MATCH_ALL, self::MATCH_ANY];

    /**
     * The target-site dialog. Not a configurable type: it is DERIVED from the
     * workflow's shape — see opensSitePicker(). Kept as a constant because the
     * runtime still names the concept, and because the writer rejects it by name
     * when somebody tries to store it.
     */
    public const SITE_MODAL = 'site_modal';

    /**
     * Blocks the transition unless the named site has no version of the post yet.
     *
     * This is the explicit, assignable form of the check the fixed-target start
     * transition already runs implicitly for its own `plain_language_site` (see
     * WorkflowController::mechanicalBlocker()). Pulled out as an action because
     * the same rule is useful on a transition that is neither the start of a
     * localization workflow nor its resubmit — a custom workflow may want ANY
     * step gated on "this site must still be empty", and a hidden, hardcoded
     * check cannot be assigned there.
     *
     * Additive by design: it only ever blocks, never grants. A transition that
     * does not declare it is completely unaffected, and a transition that does is
     * gated by it ON TOP of whatever else already applies (assignee, group, the
     * localization-specific checks).
     */
    public const TARGET_MISSING = 'target_missing';

    /**
     * Marks the transition that creates the target version of a localization
     * workflow — see startsLocalization(). Nothing to configure; its presence is
     * the whole statement.
     *
     * EXACTLY ONE per localization workflow (`metadata.detail: translation`), and
     * rejected outside one — the writer enforces both. Mandatory because it is the
     * only thing that names the creating step: without it no transition would
     * create anything, and the manager would look complete while doing half its
     * job.
     *
     * It replaced a derivation — "the first transition in the list" — for the same
     * reason `target_missing` replaced the fixed-target start's implicit check:
     * position is a fragile way to say "this is the one". A workflow that wants a
     * step or two BEFORE the version is created (an approval, a confirmation) had
     * no way to say so, and in the translation manager the language dialog rode
     * along with the guess.
     */
    public const START_LOCALIZATION = 'start_localization';

    /**
     * Re-submits the plain-language rewrite for a version that already exists,
     * instead of creating one — see resubmitsPlainLanguage(). Nothing to
     * configure, like `start_localization`, but with no count limit: "rewrite
     * again" can legitimately sit on more than one transition (out of review,
     * out of approved). The writer rejects it outside a plain-language
     * workflow.
     */
    public const PLAIN_LANGUAGE_RESUBMIT = 'plain_language_resubmit';

    /**
     * Mails every assignee of the subject — see MailAction, which owns the concept
     * because a PLACE can carry the same action (PlaceActions::MAIL). One action,
     * two triggers: here it means "after this step ran", there "when a subject
     * arrived".
     *
     * The odd one out among the built-ins on this class, in a way worth stating:
     * every other type here either opens something before the transition
     * (`user_assignment`) or decides whether it may run at all (`target_missing`),
     * and the remaining two are pure markers something else reads. This one is an
     * EFFECT — it happens AFTER the step, and it is the first thing this addon does
     * that leaves the installation.
     *
     * Three consequences of that, all deliberate:
     *
     * - It does NOT go through the `CustomActions` handler path. That path
     *   (WorkflowTransitioner::runHandlers()) only reaches PROJECT-declared
     *   handlers, by design — `TransitionActions::custom()` filters on a
     *   declaration existing. A built-in effect is called explicitly by the code
     *   that owns it, same as every other built-in is READ explicitly.
     * - It cannot fail the transition. A refused SMTP connection is logged; the
     *   click still succeeded, because by the time the mail goes out the state,
     *   the assignees and the revision are already written.
     * - Recipients are read from the store AFTER assignment, so a transition
     *   carrying both `user_assignment` and this one notifies the NEW assignees.
     *   Ordering inside `metadata.actions` has nothing to do with it — assignment
     *   is applied by the transitioner long before either is looked at.
     *
     * One thing to configure, and only one: `mail_type`, WHICH mail to send (a
     * MailTypes handle). Omitted means `assigned`, the only mail this action ever
     * sent before the selector existed. The recipients are not configurable and
     * deliberately so — they are whoever is assigned, which is a question the
     * workflow already answers elsewhere.
     *
     * At most one per transition (the writer enforces it) — a second would mean two
     * mails for one click, and their order would be a claim with no meaning.
     */
    public const MAIL = MailAction::TYPE;

    /**
     * Retired: the account reminder used to be an action and now runs on every
     * transition (AccountReminder). Old entries are ignored on read and dropped
     * by the writer on the next save.
     */
    public const RETIRED_ACCOUNT_REMINDER = 'account_reminder';

    /** Every type that may be stored in `metadata.actions`. */
    public const TYPES = [self::USER_ASSIGNMENT, self::TARGET_MISSING, self::START_LOCALIZATION, self::PLAIN_LANGUAGE_RESUBMIT, self::MAIL];

    /**
     * Types that CAN open a dialog between the click and the transition. At most
     * one dialog may sit on a transition — see WorkflowDefinitionWriter. A gate
     * like `target_missing` opens nothing, so it is exempt from that limit and
     * can coexist with a dialog, or with several gates.
     *
     * "Can", not "does": `user_assignment` only opens one in its manual mode.
     * Ask opensDialog() rather than this list wherever the limit is actually
     * being enforced.
     */
    public const DIALOG_TYPES = [self::USER_ASSIGNMENT];

    /**
     * The transition's actions, normalised: every entry has a `type`, a `site` and
     * a `mail_type` (both null for types that do not use one) and — for
     * `user_assignment` — a `mode`, a `match` and a `criteria` list.
     *
     * @param  array<string, mixed>  $transition
     * @return array<int, array{type: string, site: string|null, mail_type: string|null, mode: string|null, match: string|null, criteria: array<int, array{field: string, operator: string, value: string|null}>}>
     */
    public static function for(array $transition): array
    {
        $metadata = $transition['metadata'] ?? [];

        if (isset($metadata['actions'])) {
            return collect((array) $metadata['actions'])
                ->filter(fn ($action) => is_array($action) && is_string($action['type'] ?? null))
                ->reject(fn (array $action) => $action['type'] === self::RETIRED_ACCOUNT_REMINDER)
                ->map(fn (array $action) => self::normalise($action))
                ->values()
                ->all();
        }

        return self::fromLegacyKeys($metadata);
    }

    /**
     * The transition's user-assignment action, or null.
     *
     * @param  array<string, mixed>  $transition
     * @return array{type: string, site: string|null, mail_type: string|null, mode: string|null, match: string|null, criteria: array<int, array{field: string, operator: string, value: string|null}>}|null
     */
    public static function userAssignment(array $transition): ?array
    {
        foreach (self::for($transition) as $action) {
            if ($action['type'] === self::USER_ASSIGNMENT) {
                return $action;
            }
        }

        return null;
    }

    /**
     * The transition's PROJECT-declared actions, in the order the YAML lists them
     * — the public way for project code to read its own actions back.
     *
     * Only declared ones: a handle the config does not (or no longer) describe is
     * left out, so a caller never has to guard against a type it cannot interpret.
     * Order is kept because it is the order handlers run in, and the settings
     * editor lets an admin change it deliberately.
     *
     * @param  array<string, mixed>  $transition
     * @return array<int, array{type: string, values: array<string, mixed>}>
     */
    public static function custom(array $transition): array
    {
        return collect(self::for($transition))
            ->filter(fn (array $action) => CustomActions::find($action['type']) !== null)
            ->map(fn (array $action) => ['type' => $action['type'], 'values' => $action['values']])
            ->values()
            ->all();
    }

    /**
     * Whether this transition opens the assignee picker — a user assignment in
     * MANUAL mode, and nothing else.
     *
     * The distinction the whole rewrite turns on: an automatic assignment is an
     * action with no click moment at all, so it neither sets `needs_pick` nor
     * competes for the one dialog slot. A transition may therefore carry an
     * automatic assignment next to some future dialog without the writer
     * complaining, which would be nonsense for the manual one.
     *
     * @param  array<string, mixed>  $transition
     */
    public static function opensUserDialog(array $transition): bool
    {
        $action = self::userAssignment($transition);

        return $action !== null && self::opensDialog($action);
    }

    /**
     * Whether a single, already-normalised action opens a dialog. Kept separate
     * from opensUserDialog() because the writer validates actions one at a time,
     * before there is a transition to ask about.
     *
     * @param  array{type: string, mode: string|null}  $action
     */
    public static function opensDialog(array $action): bool
    {
        if (! in_array($action['type'] ?? null, self::DIALOG_TYPES, true)) {
            return false;
        }

        return ($action['mode'] ?? self::MODE_MANUAL) !== self::MODE_AUTOMATIC;
    }

    /**
     * Sites this transition requires to still be missing (no version yet) before
     * it may run — the `target_missing` actions, site handles only. Usually one,
     * but nothing stops a transition from naming several.
     *
     * @param  array<string, mixed>  $transition
     * @return array<int, string>
     */
    public static function requiredMissingSites(array $transition): array
    {
        return collect(self::for($transition))
            ->filter(fn (array $action) => $action['type'] === self::TARGET_MISSING)
            ->map(fn (array $action) => $action['site'])
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether this transition creates the target version — the step of a
     * localization workflow that produces a version in another site.
     *
     * The `start_localization` action says so, and nothing else does. It used to
     * fall back to the first transition in the list when no action existed, which
     * meant a workflow could open the language dialog on a step its author never
     * pointed at — and the only way to find out was to click the button and watch
     * a dialog appear. Position is a fine default for something visible; it is a
     * poor one for a dialog that creates content in another site.
     *
     * At most one transition may carry the action, and a localization workflow
     * must carry it somewhere — the writer enforces both, so this returning false
     * everywhere cannot be the silent state of a saved definition.
     *
     * @param  array<string, mixed>  $definition  the whole workflow definition
     */
    public static function startsLocalization(array $definition, string $transitionName): bool
    {
        if (($definition['metadata']['detail'] ?? null) !== 'translation') {
            return false;
        }

        return self::explicitStartTransition($definition) === $transitionName;
    }

    /**
     * The transition explicitly pinned as the one that starts a localization
     * workflow (metadata.actions declaring `start_localization`), or null when
     * none is — in which case startsLocalization() falls back to position.
     *
     * @param  array<string, mixed>  $definition  the whole workflow definition
     */
    public static function explicitStartTransition(array $definition): ?string
    {
        foreach ($definition['transitions'] ?? [] as $name => $transition) {
            foreach (self::for($transition) as $action) {
                if ($action['type'] === self::START_LOCALIZATION) {
                    return (string) $name;
                }
            }
        }

        return null;
    }

    /**
     * Whether this transition opens the target-site dialog.
     *
     * The starting transition of a localization workflow — EXCEPT in the
     * plain-language manager, where `metadata.plain_language_site` already names
     * the one site this manager writes into. Asking "which language?" when there
     * is exactly one possible answer is the question this split removes; the
     * target is a setting, not a per-click decision.
     *
     * So the dialog now follows from two facts, and the branch that creates the
     * localization can no longer be keyed on it — use startsLocalization().
     *
     * @param  array<string, mixed>  $definition  the whole workflow definition
     */
    public static function opensSitePicker(array $definition, string $transitionName): bool
    {
        if (! self::startsLocalization($definition, $transitionName)) {
            return false;
        }

        return ($definition['metadata']['plain_language_site'] ?? null) === null;
    }

    /**
     * Whether this transition re-submits the plain-language rewrite for a version
     * that already exists, instead of creating one.
     *
     * Two spellings reach this, like the class as a whole: the current one is a
     * `metadata.actions` entry (PLAIN_LANGUAGE_RESUBMIT), the older one — still
     * read for definitions the settings editor has not re-saved yet — is the
     * solo boolean `metadata.plain_language_resubmit`.
     *
     * @param  array<string, mixed>  $transition  the transition, not the definition
     */
    public static function resubmitsPlainLanguage(array $transition): bool
    {
        if (($transition['metadata']['plain_language_resubmit'] ?? false) === true) {
            return true;
        }

        foreach (self::for($transition) as $action) {
            if ($action['type'] === self::PLAIN_LANGUAGE_RESUBMIT) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this transition mails its assignees once it has run.
     *
     * No legacy spelling to absorb, unlike resubmitsPlainLanguage() below it —
     * the type never existed as anything but an `actions` entry.
     *
     * @param  array<string, mixed>  $transition  the transition, not the definition
     */
    public static function notifiesAssignees(array $transition): bool
    {
        return self::notificationMail($transition) !== null;
    }

    /**
     * WHICH mail this transition sends, or null when it sends none. Answered by
     * MailAction, so a transition and a place cannot disagree about what the same
     * action means.
     *
     * @param  array<string, mixed>  $transition  the transition, not the definition
     */
    public static function notificationMail(array $transition): ?string
    {
        return MailAction::typeIn(self::for($transition));
    }

    /**
     * One stored action in the shape every reader expects.
     *
     * Where the two older spellings are absorbed. A `user_modal` becomes a manual
     * `user_assignment` whose criteria are its groups — one `group is <handle>`
     * each, joined by `match: any`, which is precisely what "the union of the
     * members of these groups" meant. Nothing is lost in the translation, and
     * nothing has to be re-saved for it to take effect.
     *
     * @param  array<string, mixed>  $action
     * @return array{type: string, site: string|null, mail_type: string|null, mode: string|null, match: string|null, criteria: array<int, array{field: string, operator: string, value: string|null}>, values: array<string, mixed>}
     */
    private static function normalise(array $action): array
    {
        $type = $action['type'];

        $normalised = [
            // Two older spellings folded in one line each: `user_modal` becomes a
            // manual assignment (below), `notify_assignees` becomes `mail`.
            'type' => $type === self::USER_MODAL ? self::USER_ASSIGNMENT : MailAction::handle($type),
            'site' => is_string($action['site'] ?? null) && $action['site'] !== '' ? $action['site'] : null,
            // Which mail a `mail` action sends. Null for every other type, and null
            // for a mail written before the selector existed — MailAction::typeIn()
            // turns that into the fallback type, so an old definition keeps meaning
            // exactly what it meant. The older key `mail` is read here too.
            MailAction::KEY => MailAction::storedType($action),
            'mode' => null,
            'match' => null,
            'criteria' => [],
            // A project-declared type keeps its own inputs here rather than at the
            // top level. Every built-in key (`site`, `mode`, `match`, `criteria`)
            // is one a project could plausibly want to name too, and a flat merge
            // would let a declaration quietly collide with the machinery. Empty
            // for the built-ins, which have their own keys.
            'values' => CustomActions::values($type, $action['values'] ?? null),
        ];

        if ($normalised['type'] !== self::USER_ASSIGNMENT) {
            return $normalised;
        }

        if ($type === self::USER_MODAL) {
            // `group` (singular) is accepted so a hand-written YAML that copied
            // the older key's habit still reads.
            $groups = self::groupList($action['groups'] ?? $action['group'] ?? []);

            return array_merge($normalised, [
                'mode' => self::MODE_MANUAL,
                'match' => self::MATCH_ANY,
                'criteria' => array_map(fn (string $handle) => [
                    'field' => AssignmentCriteria::GROUP,
                    'operator' => AssignmentCriteria::IS,
                    'value' => $handle,
                ], $groups),
            ]);
        }

        return array_merge($normalised, [
            'mode' => in_array($action['mode'] ?? null, self::MODES, true) ? $action['mode'] : self::MODE_MANUAL,
            'match' => in_array($action['match'] ?? null, self::MATCHES, true) ? $action['match'] : self::MATCH_ALL,
            'criteria' => self::criteriaList($action['criteria'] ?? []),
        ]);
    }

    /**
     * The pre-`actions` metadata key, read as an action.
     *
     * `needs_site_pick` has no counterpart here: the site dialog is derived now
     * (see opensSitePicker()), and for every definition that set the flag the
     * derivation names the same transition — it was always the one out of the
     * initial place. Old YAMLs therefore keep working with the key simply
     * ignored, rather than being read into a shape that can no longer exist.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<int, array{type: string, site: string|null, mode: string|null, match: string|null, criteria: array<int, array{field: string, operator: string, value: string|null}>}>
     */
    private static function fromLegacyKeys(array $metadata): array
    {
        $group = $metadata['assign_from_group'] ?? null;

        if ($group === null || $group === '') {
            return [];
        }

        return [self::normalise(['type' => self::USER_MODAL, 'groups' => $group])];
    }

    /**
     * The criteria of a user assignment, normalised. An entry is either a single
     * criterion or a one-level GROUP with its own `match`. An entry without a
     * `field` is dropped rather than defaulted — there is no sensible criterion to guess,
     * and a silently invented one would filter on something nobody wrote.
     *
     * @param  mixed  $criteria
     * @return array<int, array{field: string, operator: string, value: string|null}>
     */
    private static function criteriaList($criteria, bool $nested = false): array
    {
        return collect(is_array($criteria) ? $criteria : [])
            ->filter(fn ($node) => is_array($node))
            ->map(function (array $node) use ($nested) {
                // A GROUP: its own join plus its own list. What makes a mixed
                // condition sayable — see AssignmentCandidates::satisfies().
                // Inside a group the key is ignored, so a nested group collapses
                // to nothing here rather than silently becoming a criterion; the
                // writer says so out loud instead.
                if (! $nested && self::isCriteriaGroup($node)) {
                    return [
                        'match' => in_array($node['match'] ?? null, self::MATCHES, true)
                            ? $node['match']
                            : self::MATCH_ANY,
                        'criteria' => self::criteriaList($node['criteria'] ?? [], true),
                    ];
                }

                if (! is_string($node['field'] ?? null) || $node['field'] === '') {
                    return null;
                }

                return [
                    'field' => $node['field'],
                    'operator' => is_string($node['operator'] ?? null) && $node['operator'] !== ''
                        ? $node['operator']
                        : AssignmentCriteria::IS,
                    'value' => is_scalar($node['value'] ?? null) && (string) $node['value'] !== ''
                        ? (string) $node['value']
                        : null,
                ];
            })
            // A criterion without a field is dropped rather than defaulted —
            // there is no sensible criterion to guess. Same for a group that
            // ended up empty: it would admit nobody and say nothing.
            ->filter(fn ($node) => $node !== null
                && (! self::isCriteriaGroup($node) || $node['criteria'] !== []))
            ->values()
            ->all();
    }

    /**
     * Is this criteria entry a group rather than a single criterion?
     *
     * Recognised by carrying its own `criteria`, not by a type key: the two
     * shapes have no overlapping keys, so the presence of the list IS the
     * distinction, and there is nothing to keep in sync.
     *
     * @param  array<string, mixed>  $node
     */
    public static function isCriteriaGroup(array $node): bool
    {
        // Die eigene Liste macht die Gruppe aus. Ein `match` ohne `criteria` zählt
        // ebenfalls als Gruppe — als leere: so kommt eine Zeile an, deren letztes
        // Kriterium der Autor gelöscht hat. Ohne diesen zweiten Fall liefe sie in
        // die Kriterien-Prüfung und meldete „unbekanntes Kriterium [NULL]", was
        // beschreibt, was der Code sieht, und nicht, was der Autor getan hat.
        return array_key_exists('criteria', $node)
            || (! array_key_exists('field', $node) && array_key_exists('match', $node));
    }

    /**
     * @param  mixed  $groups
     * @return array<int, string>
     */
    private static function groupList($groups): array
    {
        return collect(is_array($groups) ? $groups : [$groups])
            ->filter(fn ($handle) => is_string($handle) && $handle !== '')
            ->unique()
            ->values()
            ->all();
    }
}
