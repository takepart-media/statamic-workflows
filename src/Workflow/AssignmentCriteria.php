<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/**
 * Which properties of a user a `user_assignment` action may filter on — and what
 * each of them means when matched against a user and the subject entry.
 *
 * Two criteria ship with the addon: `group` and `role`. Everything else is the
 * consuming project's business and is declared in
 * `config/workflows.php → assignment_criteria`, because the interesting
 * questions are project-shaped ("does this editor's specialism cover the post's
 * category?") and the addon cannot invent the field handles they read.
 *
 * The split runs along one line: the built-in pair asks Statamic's own
 * membership APIs (`isInGroup`, `hasRole`) and has no field to name, while a
 * configured criterion is purely declarative — a field handle on the user, an
 * optional field handle on the entry, and where the value dropdown gets its
 * suggestions. That is why `matches()` branches on the handle first and only
 * then falls through to the generic field comparison.
 *
 * This class answers three questions and nothing else: what criteria exist
 * (`all()`), which operators one of them allows (`operatorsFor()`), and whether
 * a given user satisfies one (`matches()`). Who the resulting candidates ARE is
 * AssignmentCandidates' job.
 */
class AssignmentCriteria
{
    /**
     * Resolved criteria, keyed by a hash of the config they came from — see
     * all() for why the key is not simply "resolved yet or not".
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $memo = [];

    /** @var \Illuminate\Support\Collection<int, string>|null */
    private static $userFields = null;

    /** @var \Illuminate\Support\Collection<int, string>|null */
    private static $entryFields = null;

    /**
     * The criterion's own value contains the configured value.
     *
     * "Its own" is the whole point: WHICH side gets read is the criterion's
     * business, not the operator's — see side(). A criterion called "Kategorie
     * des Beitrags" reads the post, one called "Zugehörigkeit" reads the person,
     * and `is` means the same thing in both sentences.
     *
     * There used to be a second pair, `entry_is`/`entry_is_not`, so that a
     * criterion declaring both fields could test either one. It read as a
     * tautology wherever the criterion already named its subject ("Kategorie des
     * Beitrags · am Beitrag ist gleich") and, worse, made the operator dropdown
     * the place where the reader had to work out what a condition was about. A
     * criterion now answers that on its own, and a project that wants both
     * questions declares both criteria — one line of config each.
     */
    public const IS = 'is';

    /** The inverse of IS — the escape hatch for "everything except". */
    public const IS_NOT = 'is_not';

    /**
     * The user's value and the SUBJECT ENTRY's value overlap. Carries no value of
     * its own: the entry supplies it per run, which is the whole point — it is
     * what makes "assign to whoever handles this post's category" expressible
     * without naming the category in the workflow.
     *
     * The one operator that genuinely spans both sides, and therefore the only
     * one that needs both fields declared. The built-in `group`/`role` never
     * qualify, because a post has neither.
     */
    public const MATCHES_ENTRY = 'matches_entry';

    /**
     * The operators that compare against a value the author writes down.
     * `matches_entry` is the one that does not — it takes its value from the
     * subject — which is why the writer keys its "value required" check on this
     * list rather than on a single exception.
     */
    public const VALUE_OPERATORS = [self::IS, self::IS_NOT];

    public const OPERATORS = [self::IS, self::IS_NOT, self::MATCHES_ENTRY];

    public const GROUP = 'group';

    public const ROLE = 'role';

    /**
     * Every criterion an action may name, keyed by handle — the built-in pair
     * first, then whatever the project declared. A project handle that collides
     * with `group` or `role` wins, deliberately: overriding what "group" means
     * is a legitimate (if unusual) project decision, and silently ignoring the
     * config would be the more surprising of the two answers.
     *
     * Value options are NOT resolved here — see options(). The writer calls this
     * on every save just to check a handle, and building a term list for that
     * would be work nobody asked for.
     *
     * @return array<string, array{handle: string, label: string, user_field: string|null, entry_field: string|null, operators: array<int, string>|null, options: mixed}>
     */
    public static function all(): array
    {
        $config = (array) config('workflows.assignment_criteria', []);

        // Memoised because matches() reaches find() and therefore this method
        // once PER USER PER CRITERION (see AssignmentCandidates::resolve()), and
        // resolving now costs blueprint loads rather than a config read.
        //
        // Keyed on the config rather than a plain `??=`: a process that swaps the
        // config mid-flight — every tinker verification, every future test — must
        // not be handed the previous answer.
        return self::$memo[md5(serialize($config))] ??= self::resolve($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, array<string, mixed>>
     */
    private static function resolve(array $config): array
    {
        $builtIn = [
            self::GROUP => [
                'handle' => self::GROUP,
                'label' => __('workflows::messages.assignment_criterion_group'),
                'user_field' => null,
                'entry_field' => null,
                'operators' => null,
                'options' => 'groups',
            ],
            self::ROLE => [
                'handle' => self::ROLE,
                'label' => __('workflows::messages.assignment_criterion_role'),
                'user_field' => null,
                'entry_field' => null,
                'operators' => null,
                'options' => 'roles',
            ],
        ];

        $configured = collect($config)
            ->filter(fn ($definition, $handle) => is_array($definition) && is_string($handle) && $handle !== '')
            ->map(fn (array $definition, string $handle) => [
                'handle' => $handle,
                // Run through __() so a project may store either a plain label or
                // a translation key; Laravel hands back an unknown key unchanged.
                'label' => __(is_string($definition['label'] ?? null) && $definition['label'] !== ''
                    ? $definition['label']
                    : $handle),
                'user_field' => self::handleOrNull($definition['user_field'] ?? null),
                'entry_field' => self::handleOrNull($definition['entry_field'] ?? null),
                // null means "not declared" — every operator the fields allow.
                // A list narrows that; see operatorsFor().
                'operators' => self::operatorList($definition['operators'] ?? null),
                'options' => $definition['options'] ?? null,
            ])
            // A declared field that does not exist is a typo, and a typo here is
            // invisible in the worst possible way: `is` against a missing field
            // matches nobody, and `is_not` — its plain negation — matches
            // EVERYBODY. In the automatic mode that is a silent assignment to the
            // entire user base. So the criterion is dropped rather than offered.
            //
            // The cost is deliberate and worth stating: a criterion whose field is
            // still to be created is invisible until it exists, and a workflow
            // YAML already naming it cannot be saved in the meantime (the writer
            // rejects unknown criterion handles). Unlike group handles, which are
            // tolerated for exactly that reason, a blueprint field is something
            // this addon CAN check — and the failure mode above is not one worth
            // being tolerant about.
            ->filter(fn (array $definition) => self::fieldsExist($definition))
            // Nothing left to offer: either no side to read at all, or a declared
            // `operators` list that the fields cannot support. Both mean the
            // criterion could never be used, so neither reaches the dropdown.
            ->filter(fn (array $definition) => self::operatorsFor($definition) !== [])
            ->all();

        return array_merge($builtIn, $configured);
    }

    /**
     * @return array{handle: string, label: string, user_field: string|null, entry_field: string|null, options: mixed}|null
     */
    public static function find(string $handle): ?array
    {
        return self::all()[$handle] ?? null;
    }

    /**
     * The operators this criterion allows: always `is`/`is_not` — every criterion
     * reads something, so a fixed-value test always means something — plus
     * `matches_entry` where it reads both sides, narrowed by an optional
     * `operators` list from the config.
     *
     * Short, because the side is no longer the operator's business. What a
     * criterion is MEANT for is still a question only the project can answer, and
     * `operators` in the config states it: a criterion declaring both fields can
     * express three things while being intended for one.
     *
     * Intersection, never addition: a listed operator the fields cannot support is
     * dropped rather than reported, the same tolerance `options` already has. The
     * PROJECT's order wins, so the list also decides which operator a fresh
     * condition row starts on (the editor takes the first).
     *
     * @param  array{handle?: string, user_field?: string|null, entry_field?: string|null, operators?: array<int, string>|null}  $criterion
     * @return array<int, string>
     */
    public static function operatorsFor(array $criterion): array
    {
        $bothSides = self::narrowsUsers($criterion) && ($criterion['entry_field'] ?? null) !== null;

        $derived = [
            self::IS,
            self::IS_NOT,
            ...($bothSides ? [self::MATCHES_ENTRY] : []),
        ];

        $declared = $criterion['operators'] ?? null;

        if ($declared === null) {
            return $derived;
        }

        return array_values(array_filter(
            $declared,
            fn (string $operator) => in_array($operator, $derived, true),
        ));
    }

    /**
     * Which side `is`/`is_not` read for this criterion — the person where it has
     * a user side, otherwise the subject.
     *
     * The precedence only matters for a criterion that declares BOTH fields, and
     * there the user side wins because that is what the surrounding feature is
     * for: `user_assignment` filters people, and a criterion that can speak about
     * a person is speaking about one. Its cross-side question has its own
     * operator (`matches_entry`), and a project that also wants a fixed-value test
     * on the post declares a second, entry-only criterion — which then reads the
     * post under the very same `is`.
     *
     * @param  array{handle?: string, user_field?: string|null, entry_field?: string|null}  $criterion
     */
    public static function readsUser(array $criterion): bool
    {
        return self::narrowsUsers($criterion);
    }

    /**
     * Whether this criterion says anything about the PERSON — as opposed to one
     * that only inspects the subject, which holds or fails identically for
     * everybody.
     *
     * The built-in pair reads no field yet narrows users, so the question cannot
     * be answered from `user_field` alone. Asked by the writer: an automatic
     * assignment built only from entry conditions would hand the subject to the
     * entire user base whenever those conditions hold.
     *
     * @param  array{handle?: string, user_field?: string|null}  $criterion
     */
    public static function narrowsUsers(array $criterion): bool
    {
        return in_array($criterion['handle'] ?? null, [self::GROUP, self::ROLE], true)
            || ($criterion['user_field'] ?? null) !== null;
    }

    /**
     * Suggestions for the value input in the settings editor, as
     * `[{value, label}]`. Only ever suggestions: the input is taggable, so an
     * admin may type a handle that does not exist yet — the same tolerance the
     * writer already grants group handles, and for the same reason (a group, a
     * role or a term may legitimately be created after the workflow).
     *
     * @param  array{options: mixed}  $criterion
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(array $criterion): array
    {
        $options = $criterion['options'] ?? null;

        if (is_array($options)) {
            return collect($options)
                ->map(fn ($label, $key) => is_array($label)
                    // Already in [{value, label}] shape — passed through so a
                    // project can control ordering and duplicate labels.
                    ? ['value' => (string) ($label['value'] ?? ''), 'label' => (string) ($label['label'] ?? '')]
                    : ['value' => (string) $key, 'label' => (string) $label])
                ->filter(fn (array $option) => $option['value'] !== '')
                ->values()
                ->all();
        }

        if (! is_string($options) || $options === '') {
            return [];
        }

        if (Str::startsWith($options, 'taxonomy:')) {
            // One row per SITE comes back — a term localized into four sites is
            // four results with the same slug, which listed the whole taxonomy
            // two to four times over. Deduplicated by slug because that is what
            // the comparison uses (valueList() reduces both sides to it), so the
            // localizations are the same option, not different ones.
            //
            // The default site's title wins where they differ: this dropdown is
            // read by an admin in the CP, and picking whichever locale the query
            // happened to return first would make the label unstable.
            $default = Site::default()?->handle();

            return Term::query()
                ->where('taxonomy', Str::after($options, 'taxonomy:'))
                ->get()
                ->map(fn ($term) => [
                    'value' => (string) $term->slug(),
                    'label' => (string) ($term->title() ?: $term->slug()),
                    'default' => $term->locale() === $default,
                ])
                ->sortByDesc('default')
                ->unique('value')
                ->map(fn (array $option) => ['value' => $option['value'], 'label' => $option['label']])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        }

        return match ($options) {
            'groups' => collect(UserGroup::all())
                ->map(fn ($group) => ['value' => (string) $group->handle(), 'label' => (string) ($group->title() ?: $group->handle())])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'roles' => collect(Role::all())
                ->map(fn ($role) => ['value' => (string) $role->handle(), 'label' => (string) ($role->title() ?: $role->handle())])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'sites' => collect(Site::all())
                ->map(fn ($site) => ['value' => (string) $site->handle(), 'label' => (string) $site->name()])
                ->values()
                ->all(),
            default => [],
        };
    }

    /**
     * Whether this user satisfies one criterion of a `user_assignment` action.
     *
     * An unknown handle, an unknown operator or a missing value answers FALSE,
     * never true: a criterion nobody can interpret must narrow the candidate set
     * to nothing rather than quietly widening it. The writer rejects all three on
     * save, so this is the second line of defence — for a YAML edited by hand, or
     * for a project criterion that was configured once and later removed.
     *
     * @param  \Statamic\Contracts\Auth\User  $user
     * @param  \Statamic\Contracts\Entries\Entry|null  $entry
     * @param  array{field: string, operator: string, value: string|null}  $criterion
     */
    public static function matches($user, $entry, array $criterion): bool
    {
        $definition = self::find($criterion['field'] ?? '');

        if ($definition === null) {
            return false;
        }

        $operator = $criterion['operator'] ?? self::IS;

        if (! in_array($operator, self::operatorsFor($definition), true)) {
            return false;
        }

        if ($operator === self::MATCHES_ENTRY) {
            if ($entry === null) {
                return false;
            }

            $shared = array_intersect(
                self::valueList($user->get($definition['user_field'])),
                self::valueList($entry->value($definition['entry_field'])),
            );

            return $shared !== [];
        }

        $value = $criterion['value'] ?? null;

        if (! is_string($value) || $value === '') {
            return false;
        }

        // `is`/`is_not` against whichever side this criterion is about — see
        // readsUser(). One comparison, one branch per source of the value.
        if (self::readsUser($definition)) {
            $has = match ($definition['handle']) {
                // Statamic's own membership APIs rather than a data field: group
                // and role membership is not stored where get() would find it.
                self::GROUP => (bool) $user->isInGroup($value),
                self::ROLE => (bool) $user->hasRole($value),
                default => in_array(self::bareSlug($value), self::valueList($user->get($definition['user_field'])), true),
            };

            return $operator === self::IS ? $has : ! $has;
        }

        // The subject side. Same answer for every user by construction: it holds
        // for this post or it does not, which makes such a criterion a
        // precondition on the whole assignment rather than a filter on people.
        // Without a subject there is nothing to inspect, and the answer is the
        // narrow one (nobody), like every other unanswerable case here.
        if ($entry === null) {
            return false;
        }

        $has = in_array(
            self::bareSlug($value),
            self::valueList($entry->value($definition['entry_field'])),
            true,
        );

        return $operator === self::IS ? $has : ! $has;
    }

    /**
     * A stored field value as a comparable list of strings. Handles the three
     * shapes a relationship field reaches us in — a single value, a list, or
     * nothing — and reduces every entry to its bare slug.
     *
     * The bare slug is what makes the comparison work at all: a `terms` field
     * stores `gesundheit` when it points at one taxonomy and
     * `themen::gesundheit` when it may point at several (see
     * Content\SnapshotDiffer::termTitle() for the same normalisation on the
     * display side), and the user's field and the entry's field routinely differ
     * in exactly that. The cost is that two different taxonomies sharing a slug
     * would compare equal — acceptable, because a criterion names one field on
     * each side and the admin picks both.
     *
     * @return array<int, string>
     */
    private static function valueList($raw): array
    {
        return collect(is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]))
            ->filter(fn ($value) => is_string($value) || is_numeric($value))
            ->map(fn ($value) => self::bareSlug((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function bareSlug(string $value): string
    {
        return str_contains($value, '::') ? Str::after($value, '::') : $value;
    }

    private static function handleOrNull($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A config `operators` entry as a clean list, or null when none was declared
     * — the difference matters: null means "everything the fields allow", while
     * an empty list means "nothing", which drops the criterion.
     *
     * @return array<int, string>|null
     */
    private static function operatorList($raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        return collect(is_array($raw) ? $raw : [$raw])
            ->filter(fn ($operator) => is_string($operator) && in_array($operator, self::OPERATORS, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether every field this criterion declares actually exists. A criterion
     * declaring neither fails here too — there is nothing to read.
     *
     * @param  array{user_field: string|null, entry_field: string|null}  $definition
     */
    private static function fieldsExist(array $definition): bool
    {
        if ($definition['user_field'] === null && $definition['entry_field'] === null) {
            return false;
        }

        if ($definition['user_field'] !== null && ! self::userFields()->contains($definition['user_field'])) {
            return false;
        }

        return $definition['entry_field'] === null
            || self::entryFields()->contains($definition['entry_field']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private static function userFields()
    {
        return self::$userFields ??= collect(User::blueprint()?->fields()->all()->keys() ?? []);
    }

    /**
     * Every field handle in every entry blueprint of the project.
     *
     * Project-wide rather than per workflow, because this registry is global —
     * it is read without knowing which workflow is asking, and a criterion is
     * declared once for all of them. Loose on purpose: it catches the typo,
     * not the mismatch of pointing a criterion at a field that exists in some
     * other collection than the one governed.
     *
     * Cached for the process, unlike all() — blueprints do not change with the
     * config, and this is the expensive half (43 handles over 14 ms in the
     * reference project).
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private static function entryFields()
    {
        return self::$entryFields ??= collect(Collection::all())
            ->flatMap(fn ($collection) => $collection->entryBlueprints()
                ->flatMap(fn ($blueprint) => $blueprint->fields()->all()->keys()))
            ->unique()
            ->values();
    }
}
