<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Str;
use SplObjectStorage;
use Statamic\Facades\Collection;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads a committed workflow YAML (resources/workflows/<name>.yaml) into a
 * Symfony Workflow Definition. The YAML schema is a superset of Symfony's own
 * config: addon-only keys (collections, marking_store, top-level metadata) are
 * carried as metadata or ignored so the same file feeds both the diagram
 * dumper and — later — the runtime engine and the CP definitions endpoint.
 *
 * Definitions are project-owned: each project defines its own workflows in
 * `resources/workflows/*.yaml`. The addon ships example definitions in
 * `resources/workflows/examples/` — copy one over to get started, or run
 * `php artisan workflows:install` to publish them.
 */
class DefinitionLoader
{
    /**
     * The place injected by withRevisionStep(): "published, and an unpublished
     * working copy exists". Named here because it is this class that creates it,
     * and because a stored state can outlive the injection — a collection whose
     * revisions get switched off leaves rows behind that still say
     * `pending_revision`, and readers have to recognise them without the place
     * being in the definition any more.
     */
    public const REVISION_PLACE = 'pending_revision';

    /**
     * The languages a place can be named in from the settings editor — the ones
     * the addon ships language files for. A place's YAML `label` is either a map
     * over these (`{de: …, en: …}`, written by the editor) or a single legacy
     * string. See stateLabel() for which of the two wins over the language file.
     */
    public const LABEL_LOCALES = ['de', 'en'];

    /**
     * The project-level workflow directory. Every workflow the addon knows
     * about must live here — the addon ships example YAMLs in `examples/`
     * as templates, but they are not auto-loaded.
     */
    public function directory(): string
    {
        return resource_path('workflows');
    }

    /**
     * The `examples/` directory shipped with the addon. Not auto-loaded —
     * consulted only by the install command / documentation to seed a project
     * with a starting set of definitions.
     */
    public function examplesDirectory(): string
    {
        return dirname(__DIR__, 2).'/resources/workflows/examples';
    }

    public function path(string $name): string
    {
        return $this->directory().'/'.$name.'.yaml';
    }

    /**
     * The workflow names (file basenames without extension) the project defines
     * AND has switched on. An empty project has no workflows — call
     * `php artisan workflows:install` to copy in the shipped examples.
     *
     * Everything that acts on a workflow reads it from here, so `active: false`
     * in the YAML takes it out of the CP nav, the manager routes, the
     * entry-editor panel, `WorkflowResolver` and the console commands in one
     * move — no per-caller opt-out to keep in sync.
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->names(),
            fn (string $name) => $this->isActive($name)
        ));
    }

    /**
     * Every workflow YAML the project has, switched on or not. Only the settings
     * area uses this: a deactivated workflow still has to be listed, editable
     * and switchable back on.
     *
     * @return array<int, string>
     */
    public function allIncludingInactive(): array
    {
        return $this->names();
    }

    /**
     * Whether a workflow is switched on. An absent `active` key means yes —
     * every definition predates the switch and must keep working untouched.
     *
     * Goes through parse(), so it shares the memo and its invalidation — see the
     * note there for why a cache is safe here despite the settings editor
     * rewriting files mid-request.
     */
    public function isActive(string $name): bool
    {
        $path = $this->path($name);

        if (! is_file($path)) {
            return false;
        }

        return (($this->parse($path)['active'] ?? true) !== false);
    }

    /**
     * File basenames of every workflow YAML in the project directory, sorted.
     *
     * @return array<int, string>
     */
    private function names(): array
    {
        $names = [];

        foreach (glob($this->directory().'/*.yaml') ?: [] as $file) {
            $names[] = basename($file, '.yaml');
        }

        sort($names);

        return $names;
    }

    /**
     * Parsed YAML per file, and the finished definition per workflow — both
     * per-request, both keyed by the file's mtime AND size.
     *
     * This class used to read from disk on every call on purpose: no cache meant
     * no invalidation to get wrong when the settings editor or the installer
     * rewrites a file mid-request. The cost of that turned out to be real — the
     * listing of a localization manager asks `forEntry()` once per post, each of
     * those walks every definition, and each walk parsed every YAML again. 316
     * posts came to several thousand parses and 2.6 s per request, measured.
     *
     * The guarantee is kept rather than traded away, by two means at once:
     *
     *  - the key carries mtime and size, so a rewritten file misses the memo by
     *    itself. Size is in there because `filemtime()` has one-second resolution
     *    and a write plus a read inside the same second is exactly what the
     *    settings editor does;
     *  - `flush()` is called by everything that writes a definition, which makes
     *    the answer not depend on the clock at all. The key is the safety net.
     *
     * Static rather than an instance property: DefinitionLoader is resolved fresh
     * by most callers, so a per-instance memo would be a memo of one call.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $parsed = [];

    /** @var array<string, array<string, mixed>> */
    private static array $definitions = [];

    /**
     * The cross-workflow place maps behind rawPlaceLabels(), placeColors() and
     * placeLabels() (per locale). Static and dropped by flush() like the two
     * memos above — as function-local statics they survived a save, and the
     * settings editor's response then carried the colours and names from
     * before it.
     *
     * @var array<string, string|array<string, string>>|null
     */
    private static ?array $rawPlaceLabels = null;

    /** @var array<string, string>|null */
    private static ?array $placeColors = null;

    /** @var array<string, array<string, string>> */
    private static array $placeLabels = [];

    /**
     * Drop the memos. Called by every writer of a definition file — see
     * WorkflowDefinitionWriter and WorkflowInstaller — so a caller reading back
     * what it just wrote never sees the previous content.
     */
    public static function flush(): void
    {
        self::$parsed = [];
        self::$definitions = [];
        self::$rawPlaceLabels = null;
        self::$placeColors = null;
        self::$placeLabels = [];
    }

    /**
     * The cache key for a file: its path plus the two cheap facts that change
     * when its content does. `stat()` is one syscall and the whole point — it is
     * what makes this cheaper than parsing YAML again.
     */
    private function fingerprint(string $path): string
    {
        return $path.':'.(@filemtime($path) ?: 0).':'.(@filesize($path) ?: 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $path): array
    {
        return self::$parsed[$this->fingerprint($path)] ??= (Yaml::parseFile($path) ?: []);
    }

    /**
     * Return the raw parsed YAML for a workflow.
     *
     * Memoised beyond parse() because `withRevisionStep()` is not free either: it
     * asks every governed collection whether revisions are on, and that is a
     * Statamic lookup per call rather than a line of array work.
     *
     * @return array<string, mixed>
     */
    public function raw(string $name): array
    {
        $path = $this->path($name);

        if (! is_file($path)) {
            throw new \InvalidArgumentException("Workflow [{$name}] not found at {$path}.");
        }

        return self::$definitions[$this->fingerprint($path)] ??= $this->withRevisionStep($this->parse($path));
    }

    /**
     * When a governed collection has Statamic revisions enabled, append a
     * `pending_revision` place after the workflow's publish place: it marks that
     * an unpublished working copy of the entry exists while the published
     * version stays online. The workflow moves between the publish place and
     * this one automatically as the working copy is created and published (see
     * SyncWorkflowOnRevision). Without revisions the definition is untouched.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function withRevisionStep(array $raw): array
    {
        if (isset($raw['places'][self::REVISION_PLACE]) || ! $this->governedCollectionHasRevisions($raw)) {
            return $raw;
        }

        // The publish place is the one that unlocks publishing (real workflows
        // declare exactly one); use the first as the canonical partner.
        $publish = collect($raw['places'] ?? [])
            ->filter(fn ($place) => ($place['metadata']['allow_publish'] ?? false) === true)
            ->keys()
            ->first();

        if ($publish === null) {
            return $raw;
        }

        // No `required_group`: the two transitions in and out of this place are
        // `system`, driven by the working-copy lifecycle rather than a click, and
        // a place-level group gates nothing anyway — canApplyTransition() has only
        // ever asked the transition.
        $raw['places'][self::REVISION_PLACE] = [
            'label' => 'Unpublished changes',
            'metadata' => ['revision_pending' => true],
        ];

        // `system` transitions: they complete the state diagram and keep the
        // place reachable, but are hidden from the UI's action buttons because
        // movement happens automatically via the working-copy lifecycle.
        $raw['transitions']['mark_pending'] = [
            'from' => $publish,
            'to' => self::REVISION_PLACE,
            'metadata' => ['label' => 'Unpublished changes', 'system' => true],
        ];

        $raw['transitions']['publish_revision'] = [
            'from' => self::REVISION_PLACE,
            'to' => $publish,
            'metadata' => ['label' => 'Publish changes', 'system' => true],
        ];

        return $raw;
    }

    /**
     * Whether any collection the workflow governs has revisions enabled.
     * `revisionsEnabled()` already folds in the Statamic Pro and global-config
     * gates, so this is the only check needed.
     *
     * @param  array<string, mixed>  $raw
     */
    private function governedCollectionHasRevisions(array $raw): bool
    {
        foreach ($raw['collections'] ?? [] as $handle) {
            if (Collection::find($handle)?->revisionsEnabled()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Label for a workflow's nav entry / queue title.
     *
     * An explicitly configured `metadata.nav.label` WINS. It is what the
     * settings editor writes when someone fills in "Nav-Titel", and a value
     * typed there has to show up — the previous order made that field silently
     * inert for every workflow whose slug happened to have a translation, which
     * is all the shipped ones.
     *
     * Leaving the label empty hands the title back to
     * `workflows::messages.nav.<slug>`, so the menu follows the CP language.
     * That is the trade: an explicit label is a fixed string and is NOT
     * translated, so a multilingual CP wants the field cleared and the slug
     * translated instead — either in the addon's own lang files or in a
     * project-level `lang/vendor/workflows/<locale>/messages.php`.
     *
     * Returns an empty string when neither side supplies anything. Callers that
     * decide whether a nav entry exists at all must test the RESOLVED value
     * rather than `$navMeta['label']` (see ServiceProvider::bootNav()), or
     * clearing the field would remove the entry instead of translating it.
     *
     * @param  array<string, mixed>  $navMeta
     */
    public static function navLabel(array $navMeta): string
    {
        $label = trim((string) ($navMeta['label'] ?? ''));

        if ($label !== '') {
            return $label;
        }

        $slug = $navMeta['slug'] ?? null;

        if (! $slug) {
            return '';
        }

        $key = "workflows::messages.nav.$slug";
        $translated = __($key);

        return $translated === $key ? '' : $translated;
    }

    /**
     * Localized transition label. Prefers `workflows::messages.action_{name}` (the
     * translation file, so it follows the reader's locale); falls back to the YAML
     * `metadata.label`; finally to the humanized handle.
     *
     * Static and here rather than in the controller because the transitioner needs
     * the same answer when it writes a Statamic revision message -- same reasoning
     * as navLabel() and stateLabel() above.
     */
    public static function transitionLabel(string $name, ?string $fallback = null): string
    {
        $key = "workflows::messages.action_{$name}";
        $label = __($key);

        if ($label !== $key) {
            return (string) $label;
        }

        return $fallback ?: (string) Str::of($name)->replace('_', ' ')->title();
    }

    /**
     * The workflow's `metadata.history_fields` whitelist, normalised to a list of
     * string handles. Empty means "surface every changed field".
     *
     * Read by both History consumers — WorkflowController::historyDiff() to decide
     * what to show, and DraftSaveLogger to decide whether a save is worth a row.
     * Those two answers have to agree, so they read the same list from here.
     *
     * @return array<int, string>
     */
    public function historyFields(string $name): array
    {
        return collect($this->raw($name)['metadata']['history_fields'] ?? [])
            ->map(fn ($handle) => (string) $handle)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Localised label for a place (workflow state), in the current locale.
     * Preference order:
     *  1. the author's name for THIS language — `places.<handle>.label.<locale>`,
     *     what the settings editor writes. The author named it on purpose, so it
     *     beats the shipped wording.
     *  2. `workflows::messages.state_{handle}` in the translation file
     *  3. any other name the author gave: a legacy string `label`, or the entry
     *     for another language (a place named only in German still reads German
     *     in the English CP, rather than as its handle)
     *  4. Humanised handle
     *
     * A legacy string sits BELOW the language file on purpose: it is one fixed
     * string in an unknown language, and most of them are just the English
     * scaffold ("Draft") next to a shipped German translation. Promoting them
     * would rename those places behind the author's back.
     *
     * Place handles are workflow-scoped in principle, but in practice projects
     * keep them unique across workflows (they show up in unified queues, event
     * logs, etc.), so a global scan is unambiguous and simpler than threading
     * the workflow name through every caller.
     */
    public function stateLabel(string $state): string
    {
        if ($state === '') {
            return '';
        }

        $own = $this->ownPlaceLabel($state, self::labelLocale());
        if ($own !== null) {
            return $own;
        }

        $translated = self::shippedStateLabel($state);
        if ($translated !== null) {
            return $translated;
        }

        $fallback = $this->resolvePlaceLabel($state);
        if ($fallback !== null && $fallback !== '') {
            return $fallback;
        }

        return (string) Str::of($state)->replace('_', ' ')->title();
    }

    /**
     * The shipped (or project-overridden) language-file label for a place, or
     * null when there is none. `$locale` null means the current one.
     */
    public static function shippedStateLabel(string $state, ?string $locale = null): ?string
    {
        $key = "workflows::messages.state_{$state}";
        $translated = $locale === null ? __($key) : __($key, [], $locale);

        return is_string($translated) && $translated !== $key ? $translated : null;
    }

    /**
     * The current locale reduced to the language part that LABEL_LOCALES uses —
     * `de_DE` and `de-AT` both name a place in `de`.
     */
    public static function labelLocale(?string $locale = null): string
    {
        $locale = strtolower(str_replace('-', '_', $locale ?? app()->getLocale()));

        return Str::before($locale, '_');
    }

    /**
     * The author's name for a place in exactly this language, or null.
     */
    private function ownPlaceLabel(string $handle, string $locale): ?string
    {
        $label = $this->rawPlaceLabels()[$handle] ?? null;
        $value = is_array($label) ? ($label[$locale] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Raw YAML `label` per place handle across all project workflows — a string
     * or a per-language map, exactly as stored. First-wins, memoised per request.
     * Deliberately NOT resolved here: the resolution depends on the locale, and
     * mail renders several locales in one request.
     *
     * @return array<string, string|array<string, string>>
     */
    private function rawPlaceLabels(): array
    {
        if (self::$rawPlaceLabels === null) {
            $cache = [];
            foreach ($this->allIncludingInactive() as $name) {
                foreach ($this->raw($name)['places'] ?? [] as $handle => $place) {
                    if (array_key_exists($handle, $cache)) {
                        continue; // first-wins; keeps behaviour stable across reloads
                    }
                    $label = $place['label'] ?? null;
                    if ((is_string($label) && $label !== '') || (is_array($label) && $label !== [])) {
                        $cache[$handle] = $label;
                    }
                }
            }

            self::$rawPlaceLabels = $cache;
        }

        return self::$rawPlaceLabels;
    }

    /**
     * Built-in badge colors for the shipped place handles — the server-side
     * MIRROR of STATE_COLORS in resources/js/support/states.js, and the two
     * must not disagree: the listings resolve colors in the browser while the
     * dashboard widgets resolve them here, for the same states.
     *
     * @var array<string, string>
     */
    private const STATE_COLORS = [
        'draft' => 'default',
        'in_review' => 'blue',
        'linguistic_review' => 'blue',
        'expert_review' => 'amber',
        'queued' => 'default',
        'script_generation' => 'purple',
        'voiceover' => 'purple',
        'rendering' => 'purple',
        'review' => 'amber',
        'source_ready' => 'default',
        'in_translation' => 'blue',
        'published' => 'green',
        'pending_revision' => 'orange',
        'approved' => 'green',
        'done' => 'green',
        'failed' => 'red',
    ];

    /**
     * Badge color for a place — the server-side twin of stateColor() in
     * resources/js/support/states.js, same order: the color configured in the
     * settings (`metadata.color` on the place, via placeColors()) wins, then
     * the built-in defaults, then neutral 'default' (grey).
     */
    public function stateColor(string $state): string
    {
        return $this->placeColors()[$state]
            ?? self::STATE_COLORS[$state]
            ?? 'default';
    }

    /**
     * The author's fallback name for a place handle — step 3 of stateLabel():
     * a legacy string `label`, or else the first non-empty per-language entry
     * (current locale first). Null if the author named the place nowhere.
     */
    public function resolvePlaceLabel(string $handle): ?string
    {
        $label = $this->rawPlaceLabels()[$handle] ?? null;

        if (is_string($label)) {
            return $label !== '' ? $label : null;
        }

        if (is_array($label)) {
            $own = $this->ownPlaceLabel($handle, self::labelLocale());
            if ($own !== null) {
                return $own;
            }
            foreach ($label as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Map of place handle => Badge colour for every place that declares
     * `metadata.color`, across all project workflows. Shipped to the CP so the
     * client can colour state badges without knowing which workflow a state
     * belongs to. Same first-wins, memoised-per-request contract as
     * resolvePlaceLabel() — place handles are unique across workflows by
     * convention, since they surface together in unified queues.
     *
     * @return array<string, string>
     */
    public function placeColors(): array
    {
        if (self::$placeColors === null) {
            $cache = [];
            foreach ($this->allIncludingInactive() as $name) {
                foreach ($this->raw($name)['places'] ?? [] as $handle => $place) {
                    if (isset($cache[$handle])) {
                        continue;
                    }
                    $color = $place['metadata']['color'] ?? null;
                    if (is_string($color) && $color !== '') {
                        $cache[$handle] = $color;
                    }
                }
            }

            self::$placeColors = $cache;
        }

        return self::$placeColors;
    }

    /**
     * Map of place handle => RESOLVED label (stateLabel(), current locale) for
     * every place that declares a label, across all project workflows.
     *
     * The client-side twin of stateLabel(), shipped alongside placeColors()
     * for the same reason: the listing renders its state badges in the browser and
     * does not know which workflow a given state belongs to.
     *
     * Needed since the ageing places stopped being injected. While they were, their
     * names lived in the language files (`state_needs_update` and friends) and the
     * client's `humanize()` found them there. An author's own place has no such key
     * — its name is in the definition — so without this the badge fell back to a
     * title-cased handle and a place labelled „Zu aktualisieren" rendered as "Needs
     * Update". The filter chip, resolved server-side, said the right thing all
     * along; this is what makes both agree.
     *
     * Resolved here rather than shipped raw so the browser does not have to repeat
     * the per-language precedence: `humanize()` takes these first, as they are.
     *
     * Same first-wins, memoised-per-request contract as its two neighbours.
     *
     * @return array<string, string>
     */
    public function placeLabels(): array
    {
        // Keyed by locale — a per-request memo that ignored it would hand a mail
        // rendered in English the German labels of the CP page next to it.
        $locale = self::labelLocale();

        if (! isset(self::$placeLabels[$locale])) {
            $labels = [];
            foreach (array_keys($this->rawPlaceLabels()) as $handle) {
                $labels[$handle] = $this->stateLabel($handle);
            }
            self::$placeLabels[$locale] = $labels;
        }

        return self::$placeLabels[$locale];
    }

    public function load(string $name): Definition
    {
        $config = $this->raw($name);

        $places = array_keys($config['places'] ?? []);
        $builder = new DefinitionBuilder($places);

        if (! empty($config['initial_place'])) {
            $builder->setInitialPlaces($config['initial_place']);
        }

        $transitionsMetadata = new SplObjectStorage();

        foreach ($config['transitions'] ?? [] as $transitionName => $transition) {
            $object = new Transition(
                $transitionName,
                $transition['from'],
                $transition['to'],
            );

            $builder->addTransition($object);

            if (! empty($transition['metadata'])) {
                $transitionsMetadata[$object] = $transition['metadata'];
            }
        }

        $placesMetadata = [];
        foreach ($config['places'] ?? [] as $placeName => $place) {
            $meta = $place['metadata'] ?? [];
            // Resolved, not raw: a per-language map is not a label the diagram
            // dumper can print.
            if (isset($place['label'])) {
                $meta['label'] = $this->stateLabel((string) $placeName);
            }
            if ($meta !== []) {
                $placesMetadata[$placeName] = $meta;
            }
        }

        $workflowMetadata = $config['metadata'] ?? [];
        $workflowMetadata['name'] = $config['name'] ?? $name;

        $builder->setMetadataStore(
            new InMemoryMetadataStore($workflowMetadata, $placesMetadata, $transitionsMetadata)
        );

        return $builder->build();
    }
}
