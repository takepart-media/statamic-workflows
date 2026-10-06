<?php

namespace TakepartMedia\Workflows\Mail;

use Illuminate\Support\Facades\Lang;
use TakepartMedia\Workflows\Contracts\WorkflowMail;
use TakepartMedia\Workflows\Workflow\SlaSettings;

/**
 * Which mails the `mail` action can send, and where each one gets its words, its
 * data and its template.
 *
 * The cheap case is the common one and stays cheap: a mail type is a HANDLE plus
 * a block of language keys. One Mailable (WorkflowNotification) and one Blade
 * (`workflows::mail.notification`) serve all of them, and what differs between
 * two types is only which keys they read:
 *
 *     mail_plain_language_ready_subject
 *     mail_plain_language_ready_heading
 *     ...
 *
 * A missing key falls back to the same key of the FALLBACK type — see text(). So
 * a type that only wants its own subject and intro writes exactly those two and
 * inherits the heading, the button and the outro. Forcing every type to spell out
 * all eight would make the DIFFERENCE between two mails harder to see, not easier.
 *
 * What the copy may NOT do is address the reader — no salutation, no name, no
 * du/Sie, and none of the machinery's vocabulary. The rules and the reasoning sit
 * with the strings, in lang/{de,en}/messages.php.
 *
 * Beyond that, a declaration may name two more things, and this is where the
 * class stops being a lookup table:
 *
 *   - `mail`, a class implementing Contracts\WorkflowMail. It composes the mail:
 *     its subject, extra view data, optionally a template. That is the seam for
 *     "the same layout but different data", and for a mail that has to read
 *     something off the entry.
 *   - `view`, a Blade name, for a type that only wants a different template and
 *     no extra data.
 *
 * `assigned` is not special in the code, only in the config: it is the type a
 * mail action without a `mail_type` means, which is what keeps every workflow
 * definition written before the selector existed saying exactly what it said
 * before.
 *
 * WHERE A TYPE IS DECLARED — two places, one format:
 *
 *   - BUILT_IN below, for a type that belongs to this addon. Add the handle, add
 *     its language block to lang/de AND lang/en, and if it needs one, a composer
 *     under src/Mail/Composers/.
 *   - `config/workflows.php → mail_types`, for a type that belongs to a project.
 *     Same keys; the class and the view live in the project.
 *
 * Invalid declarations are DROPPED rather than repaired, and that has the same
 * deliberate second half as CustomActions: the writer refuses to save a workflow
 * naming a dropped handle. A misspelled composer class therefore does not decay
 * into a mail that quietly comes out as the default one — it makes the workflow
 * refuse to save, which is where somebody looks. Built-in handles win a
 * collision, for the same reason built-in action types do.
 */
class MailTypes
{
    /** "A post is yours now." The type a mail action without a `mail_type` means. */
    public const ASSIGNED = 'assigned';

    /**
     * "The plain-language version is ready." Meant for the place a workflow names
     * as `plain_language_completed_place` — the arrival PollPlainLanguageCommand
     * produces without a transition, which is why the mail action exists on places
     * at all. See Workflow\PlaceActions::MAIL.
     */
    public const PLAIN_LANGUAGE_READY = 'plain_language_ready';

    /**
     * "This post has gone stale." Meant for the places a workflow names under
     * `metadata.sla` — the arrivals TransitionAgedPostsCommand produces when a post
     * passes a deadline, which nobody clicked and which therefore have no other way
     * of reaching a person.
     *
     * ONE type for BOTH stages, `needs_update` and `outdated`, which is why it is
     * named after the block rather than after a stage. The two differ in how old the
     * post is, not in what the reader is being told or asked to do — and the mail
     * already says which of them it is, in the state line every mail carries
     * (`field_state`, filled from the place's own label). That is exactly how
     * `assigned` serves every place in every workflow with one block of words. A
     * second type would duplicate four strings to change nothing but a shade of
     * urgency the place label already carries.
     *
     * The copy therefore names NO deadline and NO stage: it has to be true on day
     * 320 and on day 900 alike.
     *
     * Built in rather than left to each project, for the same reason
     * PLAIN_LANGUAGE_READY is: the ageing deadline is this addon's mechanic (see
     * Workflow\SlaSettings), so the sentence that announces it belongs with the
     * mechanic. Without it the only sayable thing was `assigned` — "a post is
     * waiting to be worked on" — which is true of every post in every workflow and
     * says nothing about the one fact that matters here, that this one has been
     * sitting unchanged for the better part of a year.
     */
    public const SLA = SlaSettings::KEY;

    /**
     * "This post has been waiting here for N days." The default mail of the
     * `reminder` place action (Workflow\PlaceActions::REMINDER), sent over and
     * over while a subject sits in one place. Its intro may say `:days` — unlike
     * SLA, the number is right by construction, because the scheduler counts it
     * from the arrival in the very place the mail names.
     */
    public const REMINDER = 'reminder';

    /**
     * What a mail action without a `mail_type` means, and the type every other
     * type borrows its missing words from.
     */
    public const FALLBACK = self::ASSIGNED;

    /**
     * The types this addon ships. An empty declaration is the complete statement
     * for all of them: language keys only, shared template, no composer.
     *
     * @var array<string, array<string, mixed>>
     */
    public const BUILT_IN = [
        self::ASSIGNED => [],
        self::PLAIN_LANGUAGE_READY => [],
        self::SLA => [],
        self::REMINDER => [],
    ];

    /**
     * Resolved declarations, keyed by a hash of the config they came from — the
     * same reason CustomActions memoises: this is asked once per recipient per
     * mail, and a process that swaps the config mid-flight (tinker, tests) must
     * not be handed the previous answer.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $memo = [];

    /**
     * Every usable type, keyed by handle, built-in first and then the project's —
     * which is the order the editor offers them in.
     *
     * @return array<string, array{handle: string, label: string|null, mail: class-string|null, view: string|null}>
     */
    public static function all(): array
    {
        $config = (array) config('workflows.mail_types', []);

        return self::$memo[md5(serialize($config))] ??= self::resolveDeclarations($config);
    }

    /**
     * @return array{handle: string, label: string|null, mail: class-string|null, view: string|null}|null
     */
    public static function find(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /**
     * A stored `mail_type`, made usable: an empty or unknown handle becomes the
     * fallback rather than an error.
     *
     * The writer already refuses to SAVE an unknown handle, so the only way one
     * reaches here is a hand-edited YAML, or a type whose declaration was removed
     * after the fact. Sending the default mail is the better of the two possible
     * answers there — the other is sending nothing, silently, from a definition
     * that plainly asks for a mail.
     */
    public static function resolve(mixed $stored): string
    {
        return is_string($stored) && self::exists($stored) ? $stored : self::FALLBACK;
    }

    /**
     * What the settings editor calls a type. A declared label wins (a project may
     * store a plain string or a translation key — Laravel hands back an unknown key
     * unchanged), then `mail_type_<handle>`, then the bare handle.
     *
     * The last fallback is ugly but honest, and better than hiding a declared type
     * from the dropdown because nobody wrote a label for it yet.
     */
    public static function label(string $type): string
    {
        $declared = self::find($type)['label'] ?? null;

        if ($declared !== null) {
            return $declared;
        }

        $key = 'workflows::messages.mail_type_'.$type;

        return Lang::has($key) ? (string) __($key) : $type;
    }

    /**
     * One line of a mail, in the type's own words or the fallback type's.
     *
     * MUST be called inside the recipient's locale — see WorkflowNotification for
     * why that is a rule and not a preference. Lang::has() is locale-aware, so a
     * type that translates its subject into German but not English falls back per
     * LOCALE and not merely per key.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function text(string $type, string $key, array $replace = []): string
    {
        $own = 'workflows::messages.mail_'.$type.'_'.$key;

        if (Lang::has($own)) {
            return (string) __($own, $replace);
        }

        return (string) __('workflows::messages.mail_'.self::FALLBACK.'_'.$key, $replace);
    }

    /**
     * The composer for a type, or null when it declares none — a type that needs
     * nothing but its language keys, which is most of them.
     *
     * Container-resolved so a composer may type-hint whatever it needs. The class
     * was already checked in resolve(); this only builds it.
     */
    public static function composer(string $type): ?WorkflowMail
    {
        $class = self::find($type)['mail'] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * The template a type declares, or null for the shared one. A composer's own
     * `view` wins over this — see WorkflowNotification::content().
     */
    public static function view(string $type): ?string
    {
        return self::find($type)['view'] ?? null;
    }

    /**
     * Named apart from resolve() above because they answer different questions:
     * that one takes a stored value and hands back a usable handle, this one takes
     * the config and hands back the catalogue.
     *
     * @param  array<mixed>  $config
     * @return array<string, array<string, mixed>>
     */
    private static function resolveDeclarations(array $config): array
    {
        // A project handle may not shadow a built-in type — see the class note.
        // Built-in first so the order in the dropdown is stable no matter what a
        // project declares.
        $declarations = collect(self::BUILT_IN)
            ->merge(collect(self::normaliseConfig($config))->except(array_keys(self::BUILT_IN)));

        return $declarations
            ->map(fn (array $declaration, string $handle) => [
                'handle' => $handle,
                // Through __() so a project may store a plain label or a
                // translation key; Laravel hands back an unknown key unchanged.
                // Null here means "ask the language files", which is what every
                // built-in type does — see label().
                'label' => is_string($declaration['label'] ?? null) && $declaration['label'] !== ''
                    ? (string) __($declaration['label'])
                    : null,
                'mail' => self::composerClass($declaration['mail'] ?? null),
                'view' => is_string($declaration['view'] ?? null) && $declaration['view'] !== ''
                    ? $declaration['view']
                    : null,
            ])
            // A `mail` that named a class is only kept if that class exists and
            // implements the contract. Declaring one and silently getting the
            // default mail instead is the failure this check exists to make loud.
            // Asked against the RAW declaration, which is the only way to tell
            // "named nothing" (fine) from "named something unusable" (drop).
            ->reject(fn (array $type, string $handle) => ($declarations[$handle]['mail'] ?? null) !== null
                && $type['mail'] === null)
            ->all();
    }

    /**
     * The config in one shape. Two spellings are allowed on purpose, because the
     * two cases are genuinely different in size: a bare string for a type that is
     * nothing but language keys, a map for one that names a class or a view.
     * Requiring `'handle' => []` for the cheap case would be ceremony for the
     * common answer.
     *
     * @param  array<mixed>  $config
     * @return array<string, array<string, mixed>>
     */
    private static function normaliseConfig(array $config): array
    {
        $normalised = [];

        foreach ($config as $key => $value) {
            // `['erinnerung']` — a list entry, so the handle is the VALUE.
            if (is_int($key) && is_string($value) && $value !== '') {
                $normalised[$value] = [];

                continue;
            }

            // `['pressefreigabe' => [...]]` — the handle is the key.
            if (is_string($key) && $key !== '' && is_array($value)) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }

    /**
     * @return class-string|null
     */
    private static function composerClass(mixed $raw): ?string
    {
        return is_string($raw) && $raw !== '' && class_exists($raw) && is_subclass_of($raw, WorkflowMail::class)
            ? $raw
            : null;
    }
}
