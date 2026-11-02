<?php

namespace TakepartMedia\Workflows\Workflow;

use TakepartMedia\Workflows\Contracts\WorkflowAction;

/**
 * Action types a PROJECT declares, in `config/workflows.php → actions`.
 *
 * The addon ships four transition types and two place types, and every one of
 * them is behaviour written into this addon. Anything a project wanted on top —
 * "notify the press desk", "stamp a field", "mark this step as legally reviewed"
 * — meant patching the addon in eight places. This class is the seam that removes
 * that, along the same line `assignment_criteria` already draws: the addon owns
 * the mechanism, the project owns the meaning.
 *
 * Three things a declaration says, and each answers a question the addon cannot:
 *
 * - `scope` — transition or place. They are separate type sets with separate
 *   validation, so an action has to pick one.
 * - `workflows` — where it may be used. Absent means everywhere. This keeps a
 *   press-desk action out of the translation manager's dropdown instead of
 *   relying on nobody picking it.
 * - `handler` — the class that runs. Optional: without it the action is a pure
 *   marker that the project reads back itself, which is a legitimate use (the
 *   built-in `start_localization` is exactly that shape).
 *
 * Built-in types always win a handle collision. Letting a project redefine
 * `user_assignment` would not extend the addon, it would quietly disable half of
 * it.
 *
 * Invalid entries are DROPPED rather than repaired, and that has a deliberate
 * second half: the writer rejects any definition naming a dropped handle. So a
 * typo does not decay into an action that silently never runs — it makes the
 * workflow refuse to save, which is where somebody looks.
 */
class CustomActions
{
    public const SCOPE_TRANSITION = 'transition';

    public const SCOPE_PLACE = 'place';

    public const SCOPES = [self::SCOPE_TRANSITION, self::SCOPE_PLACE];

    /** The field types a declaration may use for its own inputs. */
    public const FIELD_TYPES = ['text', 'number', 'select', 'multiselect'];

    /**
     * Resolved declarations, keyed by a hash of the config they came from — the
     * same reason AssignmentCriteria memoises: this is asked once per action per
     * row, and a process that swaps the config mid-flight (tinker, tests) must
     * not be handed the previous answer.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $memo = [];

    /**
     * Every valid project-declared action, keyed by handle.
     *
     * @return array<string, array{handle: string, label: string, scope: string, workflows: array<int, string>|null, handler: class-string|null, fields: array<string, array<string, mixed>>}>
     */
    public static function all(): array
    {
        $config = (array) config('workflows.actions', []);

        return self::$memo[md5(serialize($config))] ??= self::resolve($config);
    }

    /**
     * The declarations available for one scope, optionally narrowed to a single
     * workflow — what the settings editor offers and what the writer accepts.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forScope(string $scope, ?string $workflow = null): array
    {
        return collect(self::all())
            ->filter(fn (array $action) => $action['scope'] === $scope)
            ->filter(fn (array $action) => $workflow === null
                || $action['workflows'] === null
                || in_array($workflow, $action['workflows'], true))
            ->all();
    }

    /**
     * @return array{handle: string, label: string, scope: string, workflows: array<int, string>|null, handler: class-string|null, fields: array<string, array<string, mixed>>}|null
     */
    public static function find(string $handle): ?array
    {
        return self::all()[$handle] ?? null;
    }

    /**
     * A stored action's values, keeping only what the declaration asks for and
     * coercing each to its declared type.
     *
     * Undeclared keys are dropped rather than carried: they cannot be edited, the
     * writer would reject them anyway, and a value nobody can see or change is
     * worse than no value. An unknown handle yields nothing at all.
     *
     * @return array<string, mixed>
     */
    public static function values(string $handle, mixed $stored): array
    {
        $definition = self::find($handle);

        if ($definition === null || ! is_array($stored)) {
            return [];
        }

        $values = [];

        foreach ($definition['fields'] as $key => $field) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }

            $value = self::coerce($field, $stored[$key]);

            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * The handler instance for an action, or null when it declares none (a pure
     * marker) or names a handle nobody declared.
     *
     * Container-resolved so a handler can type-hint its dependencies. The class
     * was already checked in resolve(); this only builds it.
     */
    public static function handler(string $handle): ?WorkflowAction
    {
        $class = self::find($handle)['handler'] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * Value suggestions for one declared field, in the `[{value, label}]` shape
     * the editor's inputs speak.
     *
     * Delegates to AssignmentCriteria so `groups`, `roles`, `sites` and
     * `taxonomy:<handle>` mean exactly the same thing in both places — including
     * the deduplication of localized terms, which cost a bug to get right once.
     *
     * @param  array<string, mixed>  $field
     * @return array<int, array{value: string, label: string}>
     */
    public static function fieldOptions(array $field): array
    {
        return AssignmentCriteria::options(['options' => $field['options'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, array<string, mixed>>
     */
    private static function resolve(array $config): array
    {
        $builtIn = array_merge(TransitionActions::TYPES, PlaceActions::TYPES, [
            TransitionActions::SITE_MODAL,
            TransitionActions::USER_MODAL,
            // The retired spellings count as taken too: a project handle that
            // collides with one would be folded or rejected by the readers before
            // its own declaration ever came up.
            MailAction::LEGACY_TYPE,
            TransitionActions::RETIRED_ACCOUNT_REMINDER,
        ]);

        return collect($config)
            ->filter(fn ($declaration, $handle) => is_array($declaration) && is_string($handle) && $handle !== '')
            // A project handle may not shadow a built-in type — see the class note.
            ->reject(fn ($declaration, string $handle) => in_array($handle, $builtIn, true))
            ->map(fn (array $declaration, string $handle) => [
                'handle' => $handle,
                // Through __() so a project may store a plain label or a
                // translation key; Laravel hands back an unknown key unchanged.
                'label' => is_string($declaration['label'] ?? null) && $declaration['label'] !== ''
                    ? (string) __($declaration['label'])
                    : null,
                'scope' => in_array($declaration['scope'] ?? null, self::SCOPES, true)
                    ? $declaration['scope']
                    : null,
                'workflows' => self::workflowList($declaration['workflows'] ?? null),
                'handler' => self::handlerClass($declaration['handler'] ?? null),
                'fields' => self::fieldList($declaration['fields'] ?? []),
            ])
            // No label and no scope are the two things a declaration cannot be
            // read without: one names it in the dropdown, the other decides which
            // dropdown that is.
            ->filter(fn (array $action) => $action['label'] !== null && $action['scope'] !== null)
            // A `handler` that named a class is only kept if that class exists and
            // implements the contract. Declaring one and getting nothing is the
            // failure this check exists to make loud.
            ->reject(fn (array $action, string $handle) => ($config[$handle]['handler'] ?? null) !== null
                && $action['handler'] === null)
            ->all();
    }

    /**
     * @return array<int, string>|null  null means "every workflow"
     */
    private static function workflowList(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $names = collect(is_array($raw) ? $raw : [$raw])
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->unique()
            ->values()
            ->all();

        // An empty list would read as "no workflow at all", which nobody writes on
        // purpose — treated as the absent key.
        return $names === [] ? null : $names;
    }

    /**
     * @return class-string|null
     */
    private static function handlerClass(mixed $raw): ?string
    {
        return is_string($raw) && $raw !== '' && class_exists($raw) && is_subclass_of($raw, WorkflowAction::class)
            ? $raw
            : null;
    }

    /**
     * @param  mixed  $raw
     * @return array<string, array<string, mixed>>
     */
    private static function fieldList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($field, $key) => is_array($field) && is_string($key) && $key !== '')
            ->map(fn (array $field, string $key) => [
                'handle' => $key,
                'type' => in_array($field['type'] ?? null, self::FIELD_TYPES, true) ? $field['type'] : 'text',
                'label' => is_string($field['label'] ?? null) && $field['label'] !== ''
                    ? (string) __($field['label'])
                    : $key,
                'min' => is_numeric($field['min'] ?? null) ? (int) $field['min'] : null,
                'max' => is_numeric($field['max'] ?? null) ? (int) $field['max'] : null,
                'options' => $field['options'] ?? null,
            ])
            ->all();
    }

    /**
     * One stored value in the shape its declared type promises. Null means "not a
     * usable value for this field" and the caller drops the key.
     */
    private static function coerce(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'number' => is_numeric($value) ? (int) $value : null,
            'multiselect' => collect(is_array($value) ? $value : [$value])
                ->filter(fn ($entry) => (is_string($entry) && $entry !== '') || is_numeric($entry))
                ->map(fn ($entry) => (string) $entry)
                ->unique()
                ->values()
                ->all() ?: null,
            // `text` and `select` are both a single scalar; numbers are accepted
            // and stringified so a YAML that wrote `2024` unquoted still reads.
            default => (is_string($value) && $value !== '') || is_numeric($value) ? (string) $value : null,
        };
    }
}
