<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Symfony\Component\Yaml\Yaml;
use TakepartMedia\Workflows\Mail\MailTypes;
use TakepartMedia\Workflows\Workflow\SourceViewRules;

/**
 * Writes workflow YAMLs from the CP editor. The writer is deliberately narrow:
 * it takes an already-shaped payload, strips the pieces `DefinitionLoader`
 * injects at read time (`pending_revision` + its system transitions), makes
 * sure the definition is self-consistent (`initial_place` exists, every
 * transition points at real places, no reserved handles), and dumps it back
 * atomically. Callers rely on `DefinitionLoader::raw()` always reading fresh
 * from disk, so no cache invalidation is needed after `save()` returns.
 *
 * Note on comments: `Yaml::dump()` cannot preserve comments in the source
 * file. Projects that use the editor accept that trade-off — the YAML becomes
 * the editor's target format, not a hand-authored artifact with commentary.
 */
class WorkflowDefinitionWriter
{
    /**
     * Handles the addon injects at read time and therefore owns.
     *
     * Only the revision pair is left. `needs_update` and `outdated` used to be
     * here as well, injected by an SLA overlay — they are now ordinary places the
     * author writes, names, colours and connects. Which of them the ageing uses is
     * a pointer in `metadata.sla` (see SlaSettings), not a magic handle, so
     * nothing about them needs reserving.
     */
    public const RESERVED_PLACE_HANDLES = ['pending_revision'];

    public const RESERVED_TRANSITION_HANDLES = ['mark_pending', 'publish_revision'];

    /**
     * Badge colours a place may declare via `metadata.color`, mirroring the
     * variants of Statamic's `Badge` UI component.
     *
     * `amber`, `yellow` and `red` were withheld while the two ageing places were
     * injected with fixed colours — they carried the warning semantics and had to
     * stay unambiguous. Now that the author builds those places, the warning
     * colours are exactly what they need.
     */
    public const PLACE_COLORS = [
        'default', 'black', 'white',
        'blue', 'sky', 'cyan', 'teal',
        'green', 'emerald', 'lime',
        'amber', 'yellow', 'orange', 'red',
        'purple', 'violet', 'indigo', 'fuchsia', 'pink', 'rose',
    ];

    /**
     * Button variants a transition may declare via `metadata.variant`, mirroring
     * Statamic's `Button` component. Limited to the four that read as action
     * buttons — `ghost`/`subtle`/`pressed` are chrome, not workflow actions.
     */
    public const TRANSITION_VARIANTS = ['default', 'primary', 'danger', 'filled'];

    public function __construct(private DefinitionLoader $loader) {}

    /**
     * Validate and persist an editor payload. `$definition` is the full raw
     * shape — top-level keys (`name`, `type`, `marking_store`, `collections`,
     * `initial_place`, `metadata`) plus `places` and `transitions`. Missing
     * top-level keys are pulled from the current on-disk YAML so the editor
     * only needs to POST the parts it edits.
     *
     * The three steps are public on their own (prepare → check → persist) so a
     * caller can dry-run a payload, or run work of its own between validating
     * and writing; save() is simply all three in a row.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $removePlaces  Place handles to drop even though
     *                                            the merge is otherwise additive.
     *                                            The ONLY way to delete a place —
     *                                            see merge().
     */
    public function save(string $name, array $definition, array $removePlaces = []): void
    {
        $prepared = $this->prepare($name, $definition, $removePlaces);

        $this->check($prepared);

        $this->persist($name, $prepared);
    }

    /**
     * Everything save() does except writing: merge, strip, validate. Throws the
     * same InvalidArgumentException save() would.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $removePlaces  see save()
     * @return array{definition: array<string, mixed>}
     */
    public function dryRun(string $name, array $definition, array $removePlaces = []): array
    {
        $prepared = $this->prepare($name, $definition, $removePlaces);

        $this->check($prepared);

        return ['definition' => $prepared];
    }

    /**
     * The definition save() would write: the payload merged over the current
     * file (see merge()) with the auto-injected pieces stripped. Not validated.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $removePlaces  see save()
     * @return array<string, mixed>
     */
    public function prepare(string $name, array $definition, array $removePlaces = []): array
    {
        $path = $this->loader->path($name);
        $current = is_file($path) ? (Yaml::parseFile($path) ?: []) : [];

        return $this->stripAutoInjected($this->merge($current, $definition, $name, $removePlaces));
    }

    /**
     * Validate a prepared definition. Returns the list of problems — for now
     * always empty, because validate() still stops at the first one and throws
     * it as an InvalidArgumentException.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, mixed>
     */
    public function check(array $definition): array
    {
        $this->validate($definition);

        return [];
    }

    /**
     * Write a prepared (and checked) definition: back the current file up,
     * replace it atomically, drop the loader's memos.
     *
     * @param  array<string, mixed>  $definition
     */
    public function persist(string $name, array $definition): void
    {
        $this->backup($name);

        File::ensureDirectoryExists($this->loader->directory());
        File::replace($this->loader->path($name), Yaml::dump($definition, 6, 2));

        // The loader memoises parsed definitions per request; without this a
        // caller that reads the workflow back after saving it — which the
        // settings editor does, to answer with the stored version — would be
        // handed the content from before the write.
        DefinitionLoader::flush();
    }

    /**
     * The version token of a workflow file — what the editor sends back with a
     * save so a concurrent change (another admin, a deploy, a hand edit) is
     * detected instead of overwritten. Content-based rather than mtime-based,
     * so a touch without a change does not count as one. Empty when the file
     * does not exist.
     */
    public function fingerprint(string $name): string
    {
        $path = $this->loader->path($name);

        return is_file($path) ? (string) sha1_file($path) : '';
    }

    /**
     * Copy the current file to `storage/app/workflows/backups/<handle>/` before
     * it is overwritten or removed, keeping the newest
     * `config('workflows.editor_backups')` copies. The editor's YAML dump loses
     * comments; the backup is where they survive.
     *
     * Skipped when there is nothing to back up, when backups are switched off
     * (a limit of 0), or when the newest backup already holds exactly this
     * content — a series of layout-only saves would otherwise push every real
     * earlier version out of the window.
     *
     * Returns the path of the backup that holds the current content, or null.
     */
    public function backup(string $name): ?string
    {
        $path = $this->loader->path($name);
        $keep = (int) config('workflows.editor_backups', 20);

        if ($keep < 1 || ! is_file($path)) {
            return null;
        }

        $directory = $this->backupDirectory($name);
        $existing = $this->backups($name);
        $latest = end($existing) ?: null;

        if ($latest !== null && sha1_file($latest) === sha1_file($path)) {
            return $latest;
        }

        File::ensureDirectoryExists($directory);

        $target = $directory.'/'.(new \DateTimeImmutable)->format('Ymd_His_u').'.yaml';
        File::copy($path, $target);

        $all = $this->backups($name);

        foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $stale) {
            File::delete($stale);
        }

        return $target;
    }

    /**
     * Put a backup back in place — the compensation step for a save whose
     * follow-up work (e.g. a state migration) failed after the YAML was
     * written. `$file` is a path returned by backup() or just its basename;
     * either way only files inside this workflow's own backup directory are
     * accepted.
     */
    public function restoreBackup(string $name, string $file): void
    {
        $source = $this->backupDirectory($name).'/'.basename($file);

        if (! is_file($source)) {
            $this->fail('workflow_file_missing', ['workflow' => $name, 'path' => $source]);
        }

        File::ensureDirectoryExists($this->loader->directory());
        File::copy($source, $this->loader->path($name));

        DefinitionLoader::flush();
    }

    /**
     * Existing backups of a workflow, oldest first (the timestamped names sort
     * chronologically).
     *
     * @return array<int, string>
     */
    public function backups(string $name): array
    {
        $files = glob($this->backupDirectory($name).'/*.yaml') ?: [];
        sort($files);

        return $files;
    }

    public function backupDirectory(string $name): string
    {
        return storage_path('app/workflows/backups/'.$name);
    }

    /**
     * Overwrite the project YAML with the example shipped in the addon. Used
     * by the editor's "reset" action. Throws if no example is available.
     */
    public function resetToExample(string $name): void
    {
        $source = $this->loader->examplesDirectory().'/'.$name.'.yaml';

        if (! is_file($source)) {
            $this->fail('no_example', ['workflow' => $name]);
        }

        // Whether the workflow is switched on is orthogonal to its places and
        // transitions, so a reset must not quietly switch a deactivated
        // workflow back on.
        $wasActive = $this->loader->isActive($name);

        $this->backup($name);

        File::ensureDirectoryExists($this->loader->directory());
        File::copy($source, $this->loader->path($name));

        if (! $wasActive) {
            $this->setActive($name, false);
        }
    }

    /**
     * Switch a workflow on or off by writing the top-level `active` key. Only
     * that key is touched — everything else in the definition survives, and the
     * flag is written explicitly (rather than removed when true) so the file
     * states its own status.
     *
     * Deliberately NOT routed through `save()`: switching a workflow off must
     * work even on a definition `validate()` would reject, and it has no
     * business re-writing places or transitions.
     */
    public function setActive(string $name, bool $active): void
    {
        $path = $this->loader->path($name);

        if (! is_file($path)) {
            $this->fail('workflow_file_missing', ['workflow' => $name, 'path' => $path]);
        }

        $raw = Yaml::parseFile($path) ?: [];
        $raw['active'] = $active;

        $this->backup($name);

        File::replace($path, Yaml::dump($raw, 6, 2));

        DefinitionLoader::flush();
    }

    /**
     * Remove a workflow definition from the project.
     *
     * Only the YAML goes. The recorded states, assignees, history and comments
     * stay in the database on purpose: they are keyed by workflow name, so
     * re-installing the same workflow later picks its own history back up, and
     * nothing an editor did is destroyed by a click in the settings screen.
     */
    public function delete(string $name): void
    {
        $path = $this->loader->path($name);

        if (! is_file($path)) {
            $this->fail('workflow_file_missing', ['workflow' => $name, 'path' => $path]);
        }

        $this->backup($name);

        File::delete($path);

        DefinitionLoader::flush();
    }

    /**
     * Merge editor payload over the current definition:
     * - `places` is treated ADDITIVELY: the editor's payload can add new
     *   handles or replace the config of existing ones, but never removes
     *   places implicitly. Deleting a place requires the dedicated
     *   `deletePlace` endpoint because affected entries need migration.
     * - `transitions` is a WHOLESALE REPLACEMENT: transitions have no data
     *   hanging off them, so add/remove/rename via save is safe.
     * - Other top-level keys are replaced when present, kept otherwise.
     * - `metadata.nav.slug` is pinned to the current value (renaming it would
     *   break existing manager URLs / bookmarks).
     * - `name` is force-set to the file basename so it always matches.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    /**
     * @param  array<int, string>  $removePlaces  see save()
     */
    private function merge(array $current, array $payload, string $name, array $removePlaces = []): array
    {
        $merged = array_replace($current, $payload);
        $merged['name'] = $name;

        // Additive merge for places: a payload may add or edit, never delete —
        // dropping a place would strand every subject sitting in it. Deletion goes
        // through `$removePlaces`, which the delete endpoint fills after it has
        // migrated the affected subjects; that endpoint used to rely on simply
        // omitting the place from its payload, which this merge then put straight
        // back, so it reported success and deleted nothing.
        //
        // Built payload-first rather than with array_replace(), which keeps the
        // key order of its FIRST argument: the editor's reordering buttons wrote
        // a new order, the merge silently restored the old one, and the only
        // symptom was a card that jumped back after saving. Places the payload
        // does not mention keep their relative order at the end — that is the
        // "never delete" half, and it cannot claim a position the editor did not
        // give it.
        $places = [];

        foreach ($payload['places'] ?? [] as $handle => $place) {
            $places[$handle] = is_array($place) ? $this->normalizePlaceLabel($place) : $place;
        }

        foreach ($current['places'] ?? [] as $handle => $place) {
            if (! array_key_exists($handle, $places) && ! in_array($handle, $removePlaces, true)) {
                $places[$handle] = $place;
            }
        }

        $merged['places'] = $places;

        $merged = $this->normalizeEditorMeta($merged);

        // Preserve nav.slug — it's the URL identifier for the workflow's manager page.
        $currentSlug = $current['metadata']['nav']['slug'] ?? null;
        if ($currentSlug !== null) {
            $merged['metadata']['nav']['slug'] = $currentSlug;
        }

        return $merged;
    }

    /**
     * Largest canvas coordinate stored for a node. Anything further out is a
     * client bug, not a layout — clamped rather than refused.
     */
    private const EDITOR_COORDINATE_LIMIT = 100000;

    /**
     * `metadata.editor` — the graph editor's own bookkeeping: node positions
     * under `places.<handle>` / `transitions.<handle>` as `{x, y}`, plus flat
     * view settings (e.g. `viewport: {x, y, zoom}`). Nothing at runtime reads
     * it, so it is cleaned rather than validated — a bad position must never
     * be the reason a workflow cannot be saved:
     *
     * - positions of handles the merged definition no longer has are pruned
     *   (that is how a removed node's position goes away), as are positions
     *   of the auto-injected handles, which are never stored;
     * - x/y become rounded integers within ±EDITOR_COORDINATE_LIMIT, and a
     *   position without two finite numbers is dropped;
     * - any other key keeps only a scalar, or a flat map of scalars;
     * - an empty result removes `editor` altogether.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalizeEditorMeta(array $definition): array
    {
        if (! is_array($definition['metadata'] ?? null) || ! array_key_exists('editor', $definition['metadata'])) {
            return $definition;
        }

        $editor = $definition['metadata']['editor'];
        unset($definition['metadata']['editor']);

        if (! is_array($editor)) {
            return $definition;
        }

        $reserved = [
            'places' => self::RESERVED_PLACE_HANDLES,
            'transitions' => self::RESERVED_TRANSITION_HANDLES,
        ];

        $clean = [];

        foreach ($editor as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (isset($reserved[$key])) {
                $known = is_array($definition[$key] ?? null) ? $definition[$key] : [];
                $positions = [];

                foreach (is_array($value) ? $value : [] as $handle => $point) {
                    $handle = (string) $handle;

                    if (! array_key_exists($handle, $known) || in_array($handle, $reserved[$key], true)) {
                        continue;
                    }

                    $point = $this->editorPoint($point);

                    if ($point !== null) {
                        $positions[$handle] = $point;
                    }
                }

                if ($positions !== []) {
                    $clean[$key] = $positions;
                }

                continue;
            }

            if ($this->isEditorScalar($value)) {
                $clean[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $flat = array_filter(
                    $value,
                    fn ($item, $subKey) => is_string($subKey) && $subKey !== '' && $this->isEditorScalar($item),
                    ARRAY_FILTER_USE_BOTH
                );

                if ($flat !== []) {
                    $clean[$key] = $flat;
                }
            }
        }

        if ($clean !== []) {
            $definition['metadata']['editor'] = $clean;
        }

        return $definition;
    }

    /**
     * A node position as `{x, y}` of clamped, rounded integers — or null when
     * either coordinate is not a finite number.
     *
     * @return array{x: int, y: int}|null
     */
    private function editorPoint(mixed $point): ?array
    {
        if (! is_array($point)) {
            return null;
        }

        $coordinates = [];

        foreach (['x', 'y'] as $axis) {
            $value = $point[$axis] ?? null;

            if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                return null;
            }

            $value = (float) $value;

            if (! is_finite($value)) {
                return null;
            }

            $coordinates[$axis] = (int) round(max(-self::EDITOR_COORDINATE_LIMIT, min(self::EDITOR_COORDINATE_LIMIT, $value)));
        }

        return $coordinates;
    }

    /**
     * A value `metadata.editor` may hold as-is: a bool, a finite number or a
     * short string.
     */
    private function isEditorScalar(mixed $value): bool
    {
        return is_bool($value)
            || is_int($value)
            || (is_float($value) && is_finite($value))
            || (is_string($value) && mb_strlen($value) <= 255);
    }

    /**
     * Remove `pending_revision` + its system transitions from the payload
     * before dumping. Loader re-injects them at read time; storing them here
     * would duplicate on next read.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function stripAutoInjected(array $definition): array
    {
        foreach (self::RESERVED_PLACE_HANDLES as $handle) {
            unset($definition['places'][$handle]);
        }

        foreach (self::RESERVED_TRANSITION_HANDLES as $handle) {
            unset($definition['transitions'][$handle]);
        }

        // Any user-authored transition that touches an auto-injected place
        // loses its meaning without the place — drop it too. This can only
        // arise from a malformed payload; the editor UI never renders those.
        foreach ($definition['transitions'] ?? [] as $handle => $transition) {
            $touches = collect((array) ($transition['from'] ?? []))->push($transition['to'] ?? null);
            foreach (self::RESERVED_PLACE_HANDLES as $reserved) {
                if ($touches->contains($reserved)) {
                    unset($definition['transitions'][$handle]);

                    continue 2;
                }
            }
        }

        return $definition;
    }

    /**
     * Raise a validation failure with a translated message.
     *
     * These are NOT developer-only exceptions: WorkflowController catches them
     * around save()/deletePlace() and hands `getMessage()` straight back as the
     * 422 body, which the settings editor shows as a toast (the response message
     * wins over the generic `settings_save_error` fallback). So they resolve in
     * the admin's CP locale. YAML keys, handles and action types inside the text
     * stay verbatim in every language -- they are identifiers the author has to
     * type back into the editor, not prose.
     *
     * @param  array<string, mixed>  $replace
     */
    /**
     * The ageing block: two optional stages, each a day count plus one of THIS
     * workflow's own places.
     *
     * Only an authoring workflow may carry it. A localization manager does not age
     * anything — it reads the post's clock and reacts (see `source_draft_place`),
     * so a deadline there would be a second, competing answer to the same
     * question for the same post.
     *
     * Both halves of a stage are required together. A deadline without a place
     * could never fire and a place without a deadline would never be reached;
     * accepting either would store a setting that silently does nothing, which is
     * worse than a refused save.
     *
     * Reachability is deliberately NOT required. A transition into the place is
     * optional: declare one and the ageing applies it (with its assignment and
     * mail), declare none and the post is moved straight in. Demanding one meant
     * demanding a button nobody ever clicks — the way INTO an ageing place is the
     * scheduler's job, and the transitions an author actually writes are the ones
     * leading back OUT of it.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $places
     */
    /**
     * `metadata.create_transition` — the transition the "New post" modal runs
     * right after creating a post (WorkflowController::storePost()). It has to
     * exist, be a real (non-system) step, and LEAVE THE INITIAL PLACE: a new post
     * sits there and nowhere else, so a transition from any other place would be
     * a button that can never fire. Entry workflows only — a localization
     * workflow creates nothing, it distributes.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $places
     */
    private function validateCreateTransition(array $definition, array $places): void
    {
        $name = $definition['metadata']['create_transition'] ?? null;

        if ($name === null || $name === '') {
            return;
        }

        if (! is_string($name)) {
            $this->fail('create_transition_not_string');
        }

        $transitions = $definition['transitions'] ?? [];

        if (! array_key_exists($name, $transitions) || ! is_array($transitions[$name])) {
            $this->fail('create_transition_unknown', ['transition' => $name]);
        }

        if ($transitions[$name]['metadata']['system'] ?? false) {
            $this->fail('create_transition_system', ['transition' => $name]);
        }

        // The same fallback WorkflowResolver::initialPlace() applies at runtime.
        $initial = $definition['initial_place'] ?? null;
        $initial = is_string($initial) && in_array($initial, $places, true) ? $initial : ($places[0] ?? null);

        if ($initial === null || ! in_array($initial, (array) ($transitions[$name]['from'] ?? []), true)) {
            $this->fail('create_transition_not_from_initial', ['transition' => $name, 'place' => (string) $initial]);
        }

        if (($definition['metadata']['detail'] ?? null) === 'translation') {
            $this->fail('create_transition_needs_entry_workflow');
        }
    }

    /**
     * `metadata.translation_source_view` — who sees the source column how, on
     * the translation detail page (SourceViewRules). A list of rules, each with a
     * known mode, a known audience type and at least one handle.
     *
     * Handles are not checked against the group, role or user repositories, for
     * the same reason `assigner_groups` are not: a group may legitimately be
     * created after the workflow, and a stale handle simply matches nobody.
     *
     * Bound to `detail: translation` like `translation_source_pick`: the default
     * summary page has no source column, so the rules would look effective while
     * changing nothing.
     *
     * @param  array<string, mixed>  $definition
     */
    private function validateSourceView(array $definition): void
    {
        if (! array_key_exists(SourceViewRules::KEY, $definition['metadata'] ?? [])) {
            return;
        }

        $rules = $definition['metadata'][SourceViewRules::KEY];

        if (! is_array($rules) || ($rules !== [] && array_keys($rules) !== range(0, count($rules) - 1))) {
            $this->fail('source_view_not_list');
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                $this->fail('source_view_not_list');
            }

            $mode = $rule['mode'] ?? null;

            if (! in_array($mode, SourceViewRules::MODES, true)) {
                $this->fail('source_view_mode_unknown', [
                    'value' => is_string($mode) ? $mode : gettype($mode),
                    'known' => implode(', ', SourceViewRules::MODES),
                ]);
            }

            $by = $rule['by'] ?? null;

            if (! in_array($by, SourceViewRules::BY, true)) {
                $this->fail('source_view_by_unknown', [
                    'value' => is_string($by) ? $by : gettype($by),
                    'known' => implode(', ', SourceViewRules::BY),
                ]);
            }

            $handles = $rule['handles'] ?? null;

            if (! is_array($handles) || $handles === []) {
                $this->fail('source_view_handles_not_list');
            }

            foreach ($handles as $handle) {
                if (! is_string($handle) || $handle === '') {
                    $this->fail('source_view_handles_not_list');
                }
            }
        }

        if ($rules !== [] && ($definition['metadata']['detail'] ?? null) !== 'translation') {
            $this->fail('source_view_needs_detail');
        }
    }

    private function validateSla(array $definition, array $places): void
    {
        $sla = $definition['metadata'][SlaSettings::KEY] ?? null;

        if ($sla === null || $sla === []) {
            return;
        }

        if (! is_array($sla)) {
            $this->fail('sla_days_invalid', ['key' => SlaSettings::KEY, 'value' => gettype($sla)]);
        }

        if (($definition['metadata']['detail'] ?? null) === WorkflowResolver::DETAIL_TRANSLATION) {
            $this->fail('sla_not_authoring');
        }

        $configured = [];

        foreach ([SlaSettings::STAGE_NEEDS_UPDATE, SlaSettings::STAGE_OUTDATED] as $stage) {
            $rawDays = $sla[$stage.'_days'] ?? null;
            $days = SlaSettings::days($rawDays);
            $place = $sla[$stage.'_place'] ?? null;
            $place = is_string($place) && $place !== '' ? $place : null;

            if ($rawDays !== null && $rawDays !== '' && $days === null) {
                $this->fail('sla_days_invalid', [
                    'key' => $stage.'_days',
                    'value' => is_scalar($rawDays) ? (string) $rawDays : gettype($rawDays),
                ]);
            }

            if ($days === null && $place === null) {
                continue;
            }

            if ($days === null || $place === null) {
                $this->fail('sla_stage_incomplete', ['stage' => $stage]);
            }

            if (! in_array($place, $places, true)) {
                $this->fail('sla_place_unknown', ['stage' => $stage, 'place' => $place]);
            }

            // Optional: who takes over on arrival. Present means usable — an
            // unknown audience type or an empty handle list would silently assign
            // nobody, so it is refused instead (SlaSettings::assign()).
            if (array_key_exists($stage.'_assign', $sla) && SlaSettings::assign($sla, $stage) === null) {
                $this->fail('sla_assign_invalid', ['stage' => $stage]);
            }

            $configured[$stage] = $days;
        }

        $needsUpdate = $configured[SlaSettings::STAGE_NEEDS_UPDATE] ?? null;
        $outdated = $configured[SlaSettings::STAGE_OUTDATED] ?? null;

        // A post cannot become permanently outdated before it is merely due for an
        // update — the second stage is an absolute age, not a grace period, so an
        // earlier `outdated` deadline would make the first stage unreachable.
        if ($needsUpdate !== null && $outdated !== null && $outdated < $needsUpdate) {
            $this->fail('sla_days_order', ['needs_update' => $needsUpdate, 'outdated' => $outdated]);
        }
    }

    /**
     * `metadata.source_draft_place`: where a version goes when its post drops to
     * Statamic draft.
     *
     * The mirror image of the ageing block — only a LOCALIZATION workflow has a
     * source that can fall away underneath it. The place must be one of this
     * workflow's own, and it must not allow publishing: the cascade unpublishes
     * the version, so a publish place there would have the definition and the
     * cascade asserting opposite things about the same version.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $places
     */
    private function validateSourceDraftPlace(array $definition, array $places): void
    {
        $place = $definition['metadata'][StaleLocalizations::PLACE_KEY] ?? null;

        if ($place === null || $place === '') {
            return;
        }

        if (! is_string($place)) {
            $this->fail('source_draft_place_unknown', ['place' => gettype($place)]);
        }

        if (($definition['metadata']['detail'] ?? null) !== WorkflowResolver::DETAIL_TRANSLATION) {
            $this->fail('source_draft_not_localization');
        }

        if (! in_array($place, $places, true)) {
            $this->fail('source_draft_place_unknown', ['place' => $place]);
        }

        if (($definition['places'][$place]['metadata']['allow_publish'] ?? false) === true) {
            $this->fail('source_draft_place_publishes', ['place' => $place]);
        }
    }


    private function fail(string $key, array $replace = []): never
    {
        throw new InvalidArgumentException((string) __("workflows::messages.writer_{$key}", $replace));
    }

    /**
     * Guard against payloads that would produce a non-bootable workflow.
     *
     * @param  array<string, mixed>  $definition
     */
    /**
     * A place `label` is a legacy string or a per-language map over
     * DefinitionLoader::LABEL_LOCALES. Empty entries are dropped, and an empty
     * map drops the key — an unnamed language falls back to the language file,
     * which is what an absent entry already means. Anything else is not a label
     * and is dropped rather than dumped into the YAML.
     *
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    private function normalizePlaceLabel(array $place): array
    {
        $label = $place['label'] ?? null;

        if (is_string($label) && trim($label) !== '') {
            $place['label'] = trim($label);

            return $place;
        }

        $names = [];
        if (is_array($label)) {
            foreach (DefinitionLoader::LABEL_LOCALES as $locale) {
                $value = $label[$locale] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $names[$locale] = trim($value);
                }
            }
        }

        if ($names === []) {
            unset($place['label']);
        } else {
            $place['label'] = $names;
        }

        return $place;
    }

    private function validate(array $definition): void
    {
        $places = array_keys($definition['places'] ?? []);

        if ($places === []) {
            $this->fail('no_places');
        }

        $initial = $definition['initial_place'] ?? null;
        if ($initial === null || ! in_array($initial, $places, true)) {
            $this->fail('initial_place_unknown', ['place' => $initial]);
        }

        foreach ($definition['transitions'] ?? [] as $handle => $transition) {
            $froms = (array) ($transition['from'] ?? []);
            $to = $transition['to'] ?? null;

            foreach ($froms as $from) {
                if (! in_array($from, $places, true)) {
                    $this->fail('transition_from_unknown', ['handle' => $handle, 'place' => $from]);
                }
            }

            if (! in_array($to, $places, true)) {
                $this->fail('transition_to_unknown', ['handle' => $handle, 'place' => $to]);
            }
        }

        // Editor MUST NOT ship reserved handles — the merge/strip pass already
        // dropped them, but a client that ignores the schema would land here.
        foreach (self::RESERVED_PLACE_HANDLES as $reserved) {
            if (in_array($reserved, $places, true)) {
                $this->fail('place_handle_reserved', ['handle' => $reserved]);
            }
        }

        // A place colour must resolve to a real Badge variant, otherwise the CP
        // renders an unstyled badge. Empty/absent means "use the default".
        foreach ($definition['places'] ?? [] as $handle => $place) {
            $color = $place['metadata']['color'] ?? null;

            if ($color !== null && $color !== '' && ! in_array($color, self::PLACE_COLORS, true)) {
                $this->fail('place_color_unsupported', ['handle' => $handle, 'color' => $color]);
            }

            // Opts a place into the plain-language manager's loading state: while a
            // subject sits in this place AND has an open rewrite job
            // (PlainLanguageJobStore::hasOpen()), its instance page shows a spinner
            // instead of the content blocks. The store only ever holds rows for a
            // workflow that named a `plain_language_site` — outside that, the flag
            // would silently do nothing rather than showing anything.
            $pendingSpinner = $place['metadata']['pending_rewrite_spinner'] ?? null;

            if ($pendingSpinner !== null && ! is_bool($pendingSpinner)) {
                $this->fail('place_spinner_not_boolean', ['handle' => $handle]);
            }

            if ($pendingSpinner === true && ($definition['metadata']['plain_language_site'] ?? null) === null) {
                $this->fail('place_spinner_needs_site', ['handle' => $handle]);
            }

            // Draws this place off the progress bar's main line instead of as a
            // step on it — see Workflow\ProgressSteps. Purely presentational: it
            // gates nothing and assigns nothing, which is why the only checks here
            // are that it is a boolean and that it is not on the initial place.
            $branch = $place['metadata'][ProgressSteps::BRANCH] ?? null;

            if ($branch !== null && ! is_bool($branch)) {
                $this->fail('place_branch_not_boolean', ['handle' => $handle]);
            }

            // The start of the flow cannot be a turn-off from it. Every new subject
            // would begin beside the line rather than on it, and the bar would
            // open on a branch with nothing to hang off — a nonsense picture that
            // would otherwise render quietly rather than being reported.
            if ($branch === true && ($definition['initial_place'] ?? null) === $handle) {
                $this->fail('place_branch_initial', ['handle' => $handle]);
            }

            // Retired. A place no longer hands the subject to a group when a
            // transition brings it in — that is the `user_assignment` action's job
            // now, and only where a transition declares one. Rejected by name
            // rather than ignored: silently keeping a key that used to reassign
            // everybody is the one outcome nobody could debug, and its whole
            // purpose was to name an assignment target apart from the permission
            // gate, which `required_group` still is.
            if (array_key_exists('assign_group', $place['metadata'] ?? [])) {
                $this->fail('place_assign_group_retired', [
                    'handle' => $handle,
                    'action' => TransitionActions::USER_ASSIGNMENT,
                ]);
            }

            $this->validatePlaceActions($handle, $place['metadata']['actions'] ?? null, $definition);
        }

        // Same for a transition's button variant.
        $startLocalizationCount = 0;

        foreach ($definition['transitions'] ?? [] as $handle => $transition) {
            $variant = $transition['metadata']['variant'] ?? null;

            if ($variant !== null && $variant !== '' && ! in_array($variant, self::TRANSITION_VARIANTS, true)) {
                $this->fail('transition_variant_unsupported', ['handle' => $handle, 'variant' => $variant]);
            }

            // The gate: who may take this step, on top of being responsible for
            // the subject. Two keys because the two answers are asked of Statamic
            // differently (isInGroup / hasRole); declaring both is a UNION, not
            // two hurdles — see WorkflowController::canApplyTransition(). Handles
            // stay unchecked against their repositories, same as everywhere else:
            // a group or role may be created after the workflow.
            //
            // The settings editor writes only ONE of them per transition (a type
            // and a list), which is the shape the question actually has. The union
            // remains readable for a hand-written YAML that wants it.
            foreach (['required_group', 'required_role'] as $key) {
                foreach ((array) ($transition['metadata'][$key] ?? []) as $gate) {
                    if (! is_string($gate) || $gate === '') {
                        $this->fail('transition_key_not_handle_list', ['handle' => $handle, 'key' => $key]);
                    }
                }
            }

            // The "rewrite again" flag: this transition re-submits the plain-language
            // job for a version that already exists instead of creating one. It only
            // means something in the plain-language manager, so it is rejected
            // elsewhere rather than silently ignored — a flag that does nothing is
            // worse than one that refuses to be set.
            $resubmit = $transition['metadata']['plain_language_resubmit'] ?? null;

            if ($resubmit !== null && ! is_bool($resubmit)) {
                $this->fail('transition_resubmit_not_boolean', ['handle' => $handle]);
            }

            if ($resubmit === true && ($definition['metadata']['plain_language_site'] ?? null) === null) {
                $this->fail('transition_resubmit_needs_site', ['handle' => $handle]);
            }

            // Same shape of guard as `plain_language_resubmit` below: a flag that
            // means nothing outside the plain-language manager is rejected rather
            // than silently ignored. Unlike `resubmit`, this one is also capped
            // workflow-wide — there is still exactly one place in the diagram
            // "the transition that creates the target version" can point at, an
            // explicit action just lets the author choose WHICH one instead of it
            // always being the first in the list.
            if ($this->declaresAction($transition, TransitionActions::START_LOCALIZATION)) {
                $startLocalizationCount++;

                // Any localization workflow, not just the plain-language manager.
                // The restriction used to be `plain_language_site` — which is
                // exactly the manager where the language dialog does NOT appear
                // (its target is a setting), so the action could never actually
                // move that dialog anywhere. In the translation manager, where the
                // dialog lives, "which transition creates the target version" was
                // position-only: the first in the list, and the dialog rode along.
                // Pinning it here is what makes the language dialog assignable
                // without inventing a second, storable `site_modal`.
                if (($definition['metadata']['detail'] ?? null) !== 'translation') {
                    $this->fail('transition_start_localization_needs_detail', [
                        'handle' => $handle,
                        'action' => TransitionActions::START_LOCALIZATION,
                    ]);
                }
            }

            // Same coupling the boolean form of this flag already enforces above —
            // the action-shaped spelling is not exempt from it.
            if ($this->declaresAction($transition, TransitionActions::PLAIN_LANGUAGE_RESUBMIT)
                && ($definition['metadata']['plain_language_site'] ?? null) === null) {
                $this->fail('transition_resubmit_action_needs_site', [
                    'handle' => $handle,
                    'action' => TransitionActions::PLAIN_LANGUAGE_RESUBMIT,
                ]);
            }

            $this->validateActions(
                $handle,
                $transition['metadata']['actions'] ?? null,
                is_string($definition['name'] ?? null) ? $definition['name'] : null,
            );
        }

        if ($startLocalizationCount > 1) {
            $this->fail('start_localization_once', ['action' => TransitionActions::START_LOCALIZATION]);
        }

        // And a localization workflow needs it SOMEWHERE. Without it no transition
        // creates the target version, so every button in the manager would move
        // the source's state and nothing else — a workflow that looks complete and
        // silently does half its job. There used to be a positional fallback (the
        // first transition), which spared this check at the price of putting the
        // language dialog on a step nobody chose.
        if (($definition['metadata']['detail'] ?? null) === 'translation' && $startLocalizationCount === 0) {
            $this->fail('start_localization_required', ['action' => TransitionActions::START_LOCALIZATION]);
        }

        // The "finished" place used when backfilling states for entries that
        // predate the workflow. An unknown handle would seed every published
        // entry into a state no transition leads out of.
        $donePlace = $definition['metadata']['seed_published_place'] ?? null;

        if ($donePlace !== null && $donePlace !== '' && ! in_array($donePlace, $places, true)) {
            $this->fail('seed_published_place_unknown', ['place' => $donePlace]);
        }

        $this->validateCreateTransition($definition, $places);

        // Where PollPlainLanguageCommand moves a subject once its rewrite job
        // completes successfully — see there. Optional; absent means today's
        // behaviour (stays put until a human clicks submit_plain_language).
        // Only means something alongside plain_language_site, same coupling as
        // pending_rewrite_spinner and plain_language_resubmit.
        $completedPlace = $definition['metadata']['plain_language_completed_place'] ?? null;

        if ($completedPlace !== null && $completedPlace !== '') {
            if (! in_array($completedPlace, $places, true)) {
                $this->fail('completed_place_unknown', ['place' => $completedPlace]);
            }

            if (($definition['metadata']['plain_language_site'] ?? null) === null) {
                $this->fail('completed_place_needs_site');
            }
        }

        // The failure counterpart: where PollPlainLanguageCommand parks a version whose
        // rewrite terminally failed, instead of deleting it. Optional; absent means the
        // older behaviour (the untranslated copy is deleted). Worth setting, because a
        // place is the only durable, reload-surviving signal the CP has — a toast is
        // gone in 3.5 seconds and the poll runs unattended, with no session to flash one
        // into. Give it a transition carrying `plain_language_resubmit` and a retry is
        // one click. Same coupling to plain_language_site as the completed place.
        $failedPlace = $definition['metadata']['plain_language_failed_place'] ?? null;

        if ($failedPlace !== null && $failedPlace !== '') {
            if (! in_array($failedPlace, $places, true)) {
                $this->fail('failed_place_unknown', ['place' => $failedPlace]);
            }

            if (($definition['metadata']['plain_language_site'] ?? null) === null) {
                $this->fail('failed_place_needs_site');
            }
        }

        // User groups allowed to create entries in this workflow, and those allowed
        // to assign responsibles in it. Handles are not checked against the user-group
        // repository: a group may legitimately be created after the workflow, and a
        // stale handle simply grants nobody.
        foreach (['post_creator_groups', 'assigner_groups'] as $key) {
            foreach ((array) ($definition['metadata'][$key] ?? []) as $handle) {
                if (! is_string($handle) || $handle === '') {
                    $this->fail('metadata_not_group_list', ['key' => $key]);
                }
            }
        }

        $this->validateSla($definition, $places);
        $this->validateSourceDraftPlace($definition, $places);

        // Blueprint field handles forced read-only for localization workflows.
        // Not checked against a blueprint — the same workflow can govern several
        // collections with different blueprints, and a handle that no blueprint
        // declares is harmlessly ignored (ensureFieldHasConfig no-ops).
        foreach ((array) ($definition['metadata']['site_fields'] ?? []) as $handle) {
            if (! is_string($handle) || $handle === '') {
                $this->fail('site_fields_not_list');
            }
        }

        // Blueprint field handles a finished rewrite empties on the plain-language
        // version. Not checked against a blueprint either, for the reason above —
        // and an unknown handle is harmless here too: it shadows a field nothing
        // reads. What IS checked is the pairing, like the two place keys: without
        // `plain_language_site` nothing in this workflow ever produces a rewrite,
        // so the key could only look effective while emptying nothing.
        $clearFields = (array) ($definition['metadata']['plain_language_clear_fields'] ?? []);

        foreach ($clearFields as $handle) {
            if (! is_string($handle) || $handle === '') {
                $this->fail('clear_fields_not_list');
            }
        }

        if ($clearFields !== [] && ($definition['metadata']['plain_language_site'] ?? null) === null) {
            $this->fail('clear_fields_needs_site');
        }

        // The site that carries the plain-language version, and the site a
        // localization workflow translates FROM. Both optional.
        //
        // `plain_language_site` says two things at once, on purpose: it names the
        // target site AND it makes this workflow the plain-language manager — the
        // one whose start transition asks no language question and whose requests
        // go to the rewriting service instead of DeepL. There is deliberately no
        // separate `mode:` key that could contradict it.
        //
        // Both are checked against the real site list, unlike post_creator_groups
        // and site_fields above: those stay unchecked because a group or a
        // blueprint field may legitimately be created after the workflow, while
        // sites are effectively static. A typo here would switch the feature off
        // (or silently translate from the wrong version) without saying so, which
        // is exactly the failure the collection check below exists to prevent.
        foreach (['plain_language_site', 'translation_source_site'] as $key) {
            $site = $definition['metadata'][$key] ?? null;

            if ($site === null || $site === '') {
                continue;
            }

            if (! is_string($site) || Site::get($site) === null) {
                $printable = is_string($site) ? $site : gettype($site);

                $this->fail('metadata_site_unknown', ['key' => $key, 'site' => $printable]);
            }
        }

        // Translating a version into itself has no meaning, and it would make the
        // source disappear from its own manager's targets.
        if (($definition['metadata']['plain_language_site'] ?? null) !== null
            && ($definition['metadata']['plain_language_site'] ?? null) === ($definition['metadata']['translation_source_site'] ?? null)) {
            $this->fail('translation_source_same_as_plain');
        }

        // Letting each run pick its own source. Rejected where there is no dialog to
        // put the choice in — the plain-language manager asks nothing on purpose, so
        // a flag that silently does nothing there would be a trap.
        // How the progress bar on the instance page is laid out. Presentation only,
        // so an absent key is the default — but a value nobody can render is
        // refused rather than silently corrected, because the author would
        // otherwise see a horizontal bar and no reason why.
        $orientation = $definition['metadata'][ProgressSteps::ORIENTATION] ?? null;

        if ($orientation !== null && ! in_array($orientation, ProgressSteps::ORIENTATIONS, true)) {
            $this->fail('progress_orientation_unknown', [
                'value' => is_string($orientation) ? $orientation : gettype($orientation),
                'known' => implode(', ', ProgressSteps::ORIENTATIONS),
            ]);
        }

        $sourcePick = $definition['metadata']['translation_source_pick'] ?? null;

        if ($sourcePick !== null && ! is_bool($sourcePick)) {
            $this->fail('source_pick_not_boolean');
        }

        if ($sourcePick === true) {
            if (($definition['metadata']['detail'] ?? null) !== 'translation') {
                $this->fail('source_pick_needs_detail');
            }

            if (($definition['metadata']['plain_language_site'] ?? null) !== null) {
                $this->fail('source_pick_conflicts_plain');
            }
        }

        $this->validateSourceView($definition);

        // No credential keys are validated here because none belong in this file.
        // The translation services read their key and token from
        // `config/workflows.php` → `.env` (see Translation\DeeplConfig): this
        // YAML is committed, so anything secret stored in it would land in every
        // clone, backup and CI log. The two site keys above are the only
        // translation settings that are genuinely per workflow.

        // Every governed collection must actually exist, otherwise the workflow
        // silently governs nothing and the manager page renders an empty listing.
        //
        // Deliberately NOT validated: whether another workflow already lists the
        // same collection. Overlap is a supported setup — the translation
        // workflow shares `posts` with the post manager — and
        // WorkflowResolver::forCollection() resolves it as first-wins over the
        // alphabetically sorted definitions. The settings editor surfaces which
        // workflow owns a collection instead of forbidding the overlap.
        foreach ((array) ($definition['collections'] ?? []) as $handle) {
            if (! is_string($handle) || Collection::find($handle) === null) {
                $printable = is_string($handle) ? $handle : gettype($handle);

                $this->fail('collection_unknown', ['collection' => $printable]);
            }
        }
    }

    /**
     * Whether a transition's `metadata.actions` names the given type. Raw array
     * access rather than `TransitionActions::for()` — this runs before that
     * normalisation would be safe to trust (validate() is what decides whether
     * the shape is even well-formed), the same reason the `plain_language_resubmit`
     * check above reads its key directly instead of going through the class.
     *
     * @param  array<string, mixed>  $transition
     */
    private function declaresAction(array $transition, string $type): bool
    {
        return collect($transition['metadata']['actions'] ?? [])
            ->contains(fn ($action) => is_array($action) && ($action['type'] ?? null) === $type);
    }

    /**
     * A transition's `metadata.actions` — the steps that run between the click
     * and the transition (see Workflow\TransitionActions).
     *
     * @param  mixed  $actions
     */
    /**
     * The `values` of a project-declared action, against the fields its config
     * declares. Shared by both scopes because the declaration format is one.
     *
     * Checked: that a key is declared at all, and that its value fits the
     * declared type — `number` also against `min`/`max`. NOT checked: what a
     * `select`/`multiselect` value points at. That is the same tolerance group
     * handles and criterion values already get, for the same reason — a group, a
     * role or a term may legitimately be created after the workflow.
     *
     * @param  string  $where  the prefix that names the transition or place
     * @param  array<string, mixed>  $declaration
     * @param  mixed  $values
     */
    private function validateActionValues(string $where, array $declaration, $values): void
    {
        if ($values === null) {
            return;
        }

        if (! is_array($values)) {
            $this->fail('action_values_not_map', ['where' => $where, 'action' => $declaration['handle']]);
        }

        foreach ($values as $key => $value) {
            $field = $declaration['fields'][$key] ?? null;

            if ($field === null) {
                $printable = is_string($key) ? $key : gettype($key);

                $declaredKeys = implode(', ', array_keys($declaration['fields']))
                    ?: (string) __('workflows::messages.writer_declared_none');

                $this->fail('action_value_undeclared', [
                    'where' => $where,
                    'action' => $declaration['handle'],
                    'value' => $printable,
                    'declared' => $declaredKeys,
                ]);
            }

            $ok = match ($field['type']) {
                'number' => is_numeric($value)
                    && ($field['min'] === null || $value >= $field['min'])
                    && ($field['max'] === null || $value <= $field['max']),
                'multiselect' => is_array($value),
                default => is_string($value) || is_numeric($value),
            };

            if (! $ok) {
                $bounds = $field['type'] === 'number' && ($field['min'] !== null || $field['max'] !== null)
                    ? ' ('.($field['min'] ?? '−∞').'…'.($field['max'] ?? '∞').')'
                    : '';

                $this->fail('action_value_invalid', [
                    'where' => $where,
                    'action' => $declaration['handle'],
                    'key' => $key,
                    'expected' => $field['type'].$bounds,
                ]);
            }
        }
    }

    private function validateActions(string $handle, $actions, ?string $workflow = null): void
    {
        if ($actions === null) {
            return;
        }

        if (! is_array($actions)) {
            $this->fail('transition_actions_not_list', ['handle' => $handle]);
        }

        $modals = 0;
        $assignments = 0;
        $mails = 0;

        foreach ($actions as $action) {
            if (! is_array($action)) {
                $this->fail('transition_action_not_map', ['handle' => $handle]);
            }

            $type = $action['type'] ?? null;

            // The site dialog is derived from the workflow's shape, never stored
            // — see TransitionActions::opensSitePicker(). Naming it here would
            // put it on a transition of the author's choosing, which is exactly
            // the mistake the derivation removes, so it gets its own message
            // rather than the generic "unknown type".
            if ($type === TransitionActions::SITE_MODAL) {
                $this->fail('transition_site_modal_not_configurable', [
                    'handle' => $handle,
                    'action' => TransitionActions::SITE_MODAL,
                ]);
            }

            // The pre-`user_assignment` spelling. Still READ (TransitionActions
            // folds it into a manual assignment), never written again — so it
            // gets its own message rather than the generic "unknown type", which
            // would read as if the concept had gone away.
            if ($type === TransitionActions::USER_MODAL) {
                $this->fail('transition_user_modal_retired', [
                    'handle' => $handle,
                    'action' => TransitionActions::USER_MODAL,
                    'replacement' => TransitionActions::USER_ASSIGNMENT,
                ]);
            }

            // The older spelling of the mail action. Folded rather than rejected,
            // because unlike `user_modal` above it is not retired — it means
            // exactly what `mail` means and every reader already treats it that
            // way (MailAction::handle()). Validating the folded handle is what
            // keeps a definition written before the rename saveable.
            $type = is_string($type) ? MailAction::handle($type) : $type;

            // Built-in, or declared by the project for THIS workflow. Any other
            // type is rejected rather than ignored, unlike a criterion VALUE
            // below: a typo here would simply do nothing, and the first sign of it
            // would be a dialog that never opens in production.
            $declared = CustomActions::forScope(CustomActions::SCOPE_TRANSITION, $workflow);

            if (! is_string($type) || (! in_array($type, TransitionActions::TYPES, true) && ! isset($declared[$type]))) {
                $printable = is_string($type) ? $type : gettype($type);

                $this->fail('transition_action_type_unknown', [
                    'handle' => $handle,
                    'type' => $printable,
                    'known' => implode(', ', array_merge(TransitionActions::TYPES, array_keys($declared))),
                ]);
            }

            if (isset($declared[$type])) {
                $this->validateActionValues(
                    (string) __('workflows::messages.writer_scope_transition', ['handle' => $handle]),
                    $declared[$type],
                    $action['values'] ?? null
                );

                continue;
            }

            // Only a dialog competes for the one moment between click and
            // transition. `target_missing` opens nothing, and neither does an
            // AUTOMATIC user assignment — so neither needs nor consumes that
            // slot, and a transition can carry a dialog AND several of them.
            if (TransitionActions::opensDialog($action)) {
                $modals++;
            }

            if ($type === TransitionActions::USER_ASSIGNMENT) {
                $assignments++;
                $this->validateAssignment($handle, $action);
            }

            if ($type === TransitionActions::MAIL) {
                $mails++;
                $this->validateMailType('transition_action_mail_unknown', $handle, $action);
            }

            // The site a `target_missing` gate watches. Checked against the real
            // site list, like plain_language_site above and for the same reason: a
            // typo would silently turn the gate into a no-op (an unknown site never
            // matches an existing version) rather than blocking anything, and that
            // failure is the opposite of what the gate is FOR.
            if ($type === TransitionActions::TARGET_MISSING) {
                $site = $action['site'] ?? null;

                if (! is_string($site) || $site === '' || Site::get($site) === null) {
                    $printable = is_string($site) && $site !== '' ? $site : gettype($site);

                    $this->fail('transition_target_missing_site_unknown', ['handle' => $handle, 'site' => $printable]);
                }
            }
        }

        // Only one dialog fits between the click and the transition — a second
        // one would have no moment in which to run, and their order would be a
        // claim the CP cannot honour.
        if ($modals > 1) {
            $this->fail('transition_multiple_dialogs', ['handle' => $handle]);
        }

        // A separate rule from the dialog limit above, and not covered by it: two
        // AUTOMATIC assignments open no dialog at all, so they would slip past
        // that check — and then only the first would ever run, because
        // TransitionActions::userAssignment() returns the first match. There is
        // one assignee set per subject; two actions claiming it is a question
        // with no answer.
        if ($assignments > 1) {
            $this->fail('transition_multiple_assignments', [
                'handle' => $handle,
                'action' => TransitionActions::USER_ASSIGNMENT,
            ]);
        }

        // Two of these would send the same mail twice to the same people, and
        // their order in the list would be a distinction without a difference.
        // Refused rather than deduplicated, for the same reason the two rules
        // above are: an author who wrote it twice meant something, and silently
        // collapsing it would answer a question they should be asked.
        if ($mails > 1) {
            $this->fail('transition_multiple_mails', [
                'handle' => $handle,
                'action' => TransitionActions::MAIL,
            ]);
        }
    }

    /**
     * A `user_assignment` action — its mode, how its criteria are joined, and the
     * criteria themselves.
     *
     * The criterion HANDLE and its OPERATOR are checked; the value is not. That
     * asymmetry is the same one the group handles had before this: a group, a
     * role or a term may legitimately be created after the workflow, so a value
     * that resolves to nobody today is a normal intermediate state. A criterion
     * handle that does not exist is not — nothing will ever create it, and the
     * only symptom would be an assignment that quietly matches no one.
     *
     * @param  array<string, mixed>  $action
     */
    private function validateAssignment(string $handle, array $action): void
    {
        $mode = $action['mode'] ?? TransitionActions::MODE_MANUAL;

        if (! is_string($mode) || ! in_array($mode, TransitionActions::MODES, true)) {
            $printable = is_string($mode) ? $mode : gettype($mode);

            $this->fail('assignment_mode_unknown', [
                'handle' => $handle,
                'action' => TransitionActions::USER_ASSIGNMENT,
                'mode' => $printable,
                'known' => implode(', ', TransitionActions::MODES),
            ]);
        }

        $match = $action['match'] ?? TransitionActions::MATCH_ALL;

        if (! is_string($match) || ! in_array($match, TransitionActions::MATCHES, true)) {
            $printable = is_string($match) ? $match : gettype($match);

            $this->fail('assignment_match_unknown', [
                'handle' => $handle,
                'action' => TransitionActions::USER_ASSIGNMENT,
                'match' => $printable,
                'known' => implode(', ', TransitionActions::MATCHES),
            ]);
        }

        $criteria = $action['criteria'] ?? [];

        if (! is_array($criteria)) {
            $this->fail('assignment_criteria_not_list', [
                'handle' => $handle,
                'action' => TransitionActions::USER_ASSIGNMENT,
            ]);
        }

        // No criteria means "everybody" — fine when a human is going to pick one
        // of them, never fine when nobody is: the automatic mode would hand the
        // subject to the entire user base. Refused here rather than silently
        // reinterpreted, because both possible reinterpretations (assign nobody,
        // assign everybody) are things an author would want to have been told.
        if ($criteria === [] && $mode === TransitionActions::MODE_AUTOMATIC) {
            $this->fail('assignment_automatic_without_criteria', [
                'handle' => $handle,
                'action' => TransitionActions::USER_ASSIGNMENT,
            ]);
        }

        $known = AssignmentCriteria::all();

        foreach ($criteria as $node) {
            if (! is_array($node)) {
                $this->fail('assignment_criterion_not_map', [
                    'handle' => $handle,
                    'action' => TransitionActions::USER_ASSIGNMENT,
                ]);
            }

            if (TransitionActions::isCriteriaGroup($node)) {
                $this->validateCriteriaGroup($handle, $node, $known);

                continue;
            }

            $this->validateCriterion($handle, $node, $known);
        }

        // The same objection as the empty list above, one step subtler: a
        // criterion that only inspects the SUBJECT holds or fails
        // identically for everybody, so an automatic assignment can end up
        // handing the subject to the entire user base without ever naming a
        // person.
        //
        // How many such criteria are safe depends on the join, and the two
        // answers are opposites:
        //
        // - `all`: every criterion has to hold, so ONE that narrows users is
        //   enough — the entry conditions can only ever remove people from what
        //   it selected.
        // - `any`: one satisfied criterion admits a user, so a single entry
        //   condition that holds admits EVERYBODY, whatever else is in the list.
        //   All of them must narrow users.
        //
        // Not checked for the manual mode: there an entry condition decides
        // whether the picker opens with anybody in it, and a human still chooses.
        if ($mode === TransitionActions::MODE_AUTOMATIC) {
            // A GROUP counts as one node, and whether it narrows is the same
            // question asked of its own contents under its own join — see
            // narrowsUnder(). Without that recursion an `all` list whose only
            // user-narrowing condition sits inside an `any` group would read as
            // unsafe, and the safe mixed condition this format exists for could
            // never be saved.
            $safe = $this->narrowsUnder($criteria, $match, $known);

            if (! $safe) {
                $this->fail(
                    $match === TransitionActions::MATCH_ANY
                        ? 'assignment_subject_only_any'
                        : 'assignment_subject_only_all',
                    ['handle' => $handle, 'action' => TransitionActions::USER_ASSIGNMENT]
                );
            }
        }
    }

    /**
     * Does this criteria list actually narrow the set of USERS, under its join?
     *
     * The two answers are opposites, which is why the join has to be passed in:
     *
     * - `all`: every node has to hold, so ONE narrowing node is enough — the
     *   others can only remove people from what it selected.
     * - `any`: one satisfied node admits a user, so a single node that inspects
     *   only the subject admits EVERYBODY. All of them must narrow.
     *
     * A group is one node whose answer is this same function applied to its
     * contents. An unknown field contributes nothing rather than counting as
     * narrowing — validateCriterion() has already refused it by the time this
     * runs, so it cannot mask a real problem.
     *
     * @param  array<int, mixed>  $criteria
     * @param  array<string, array<string, mixed>>  $known
     */
    private function narrowsUnder(array $criteria, string $match, array $known): bool
    {
        $answers = collect($criteria)
            ->filter(fn ($node) => is_array($node))
            ->map(function (array $node) use ($known) {
                if (TransitionActions::isCriteriaGroup($node)) {
                    return $this->narrowsUnder(
                        is_array($node['criteria'] ?? null) ? $node['criteria'] : [],
                        in_array($node['match'] ?? null, TransitionActions::MATCHES, true)
                            ? $node['match']
                            : TransitionActions::MATCH_ANY,
                        $known,
                    );
                }

                $field = $node['field'] ?? null;

                return is_string($field) && isset($known[$field])
                    ? AssignmentCriteria::narrowsUsers($known[$field])
                    : null;
            })
            ->filter(fn ($answer) => $answer !== null);

        return $match === TransitionActions::MATCH_ANY
            ? $answers->every(fn (bool $narrows) => $narrows)
            : $answers->contains(true);
    }

    /**
     * One criterion of a `user_assignment`: a known field, an operator it allows,
     * and a value where the operator needs one.
     *
     * @param  array<string, mixed>  $criterion
     * @param  array<string, array<string, mixed>>  $known
     */
    private function validateCriterion(string $handle, array $criterion, array $known): void
    {
        $field = $criterion['field'] ?? null;

        if (! is_string($field) || ! isset($known[$field])) {
            $printable = is_string($field) ? $field : gettype($field);

            $this->fail('assignment_criterion_unknown', [
                'handle' => $handle,
                'criterion' => $printable,
                'known' => implode(', ', array_keys($known)),
            ]);
        }

        $operator = $criterion['operator'] ?? AssignmentCriteria::IS;
        $allowed = AssignmentCriteria::operatorsFor($known[$field]);

        if (! is_string($operator) || ! in_array($operator, $allowed, true)) {
            $printable = is_string($operator) ? $operator : gettype($operator);

            $this->fail('assignment_operator_unsupported', [
                'handle' => $handle,
                'criterion' => $field,
                'operator' => $printable,
                'supported' => implode(', ', $allowed),
            ]);
        }

        // Most operators compare against a value the author writes down;
        // `matches_entry` takes it from the subject instead, so it is the one
        // allowed to leave it empty.
        if (in_array($operator, AssignmentCriteria::VALUE_OPERATORS, true)) {
            $value = $criterion['value'] ?? null;

            if (! is_string($value) || $value === '') {
                $this->fail('assignment_criterion_no_value', ['handle' => $handle, 'criterion' => $field]);
            }
        }
    }

    /**
     * A criteria GROUP: its own join plus its own criteria, one level deep.
     *
     * The depth limit is the only rule here that is not about the contents. The
     * matcher would happily recurse further, but the settings editor draws one
     * level — and a condition an author cannot see in the editor is worse than
     * one the format refuses to store.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, array<string, mixed>>  $known
     */
    private function validateCriteriaGroup(string $handle, array $group, array $known): void
    {
        $match = $group['match'] ?? null;

        if ($match !== null && ! in_array($match, TransitionActions::MATCHES, true)) {
            $this->fail('assignment_match_invalid', [
                'handle' => $handle,
                'value' => is_string($match) ? $match : gettype($match),
                'known' => implode(', ', TransitionActions::MATCHES),
            ]);
        }

        $inner = $group['criteria'] ?? null;

        if (! is_array($inner) || $inner === []) {
            $this->fail('assignment_group_empty', ['handle' => $handle]);
        }

        foreach ($inner as $criterion) {
            if (! is_array($criterion)) {
                $this->fail('assignment_criterion_not_map', [
                    'handle' => $handle,
                    'action' => TransitionActions::USER_ASSIGNMENT,
                ]);
            }

            if (TransitionActions::isCriteriaGroup($criterion)) {
                $this->fail('assignment_group_nested', ['handle' => $handle]);
            }

            $this->validateCriterion($handle, $criterion, $known);
        }
    }

    /**
     * A place's `metadata.actions` — the behaviours that apply while a subject
     * sits in this place (see Workflow\PlaceActions). Deliberately its own
     * method rather than a shared one with validateActions(): the two type
     * sets are disjoint, and neither the dialog-slot limit nor `target_missing`'s
     * site check has a place-side counterpart to share.
     *
     * @param  mixed  $actions
     * @param  array<string, mixed>  $definition  the whole workflow definition
     */
    private function validatePlaceActions(string $handle, $actions, array $definition): void
    {
        if ($actions === null) {
            return;
        }

        if (! is_array($actions)) {
            $this->fail('place_actions_not_list', ['handle' => $handle]);
        }

        $mails = 0;
        $reminders = 0;
        $autos = 0;

        foreach ($actions as $action) {
            if (! is_array($action)) {
                $this->fail('place_action_not_map', ['handle' => $handle]);
            }

            $type = $action['type'] ?? null;

            // The older spelling of the mail action, folded exactly as on the
            // transition side — see validateActions().
            $type = is_string($type) ? MailAction::handle($type) : $type;

            // Built-in, or declared by the project for THIS workflow — the
            // place-side twin of the transition check.
            $declared = CustomActions::forScope(
                CustomActions::SCOPE_PLACE,
                is_string($definition['name'] ?? null) ? $definition['name'] : null,
            );

            if (! is_string($type) || (! in_array($type, PlaceActions::TYPES, true) && ! isset($declared[$type]))) {
                $printable = is_string($type) ? $type : gettype($type);

                $this->fail('place_action_type_unknown', [
                    'handle' => $handle,
                    'type' => $printable,
                    'known' => implode(', ', array_merge(PlaceActions::TYPES, array_keys($declared))),
                ]);
            }

            if (isset($declared[$type])) {
                $this->validateActionValues(
                    (string) __('workflows::messages.writer_scope_place', ['handle' => $handle]),
                    $declared[$type],
                    $action['values'] ?? null
                );

                continue;
            }

            // Same reasoning as the boolean form above: PlainLanguageJobStore
            // never holds a row for a workflow without a plain_language_site, so
            // the action would silently do nothing rather than showing anything.
            if ($type === PlaceActions::PENDING_REWRITE_SPINNER
                && ($definition['metadata']['plain_language_site'] ?? null) === null) {
                $this->fail('place_spinner_action_needs_site', [
                    'handle' => $handle,
                    'action' => PlaceActions::PENDING_REWRITE_SPINNER,
                ]);
            }

            // General-purpose, unlike the spinner: no site coupling, just a sane
            // number the client can turn into a timer. Capped at a day — anything
            // longer belongs in the SLA/ageing system, not a page-open reminder.
            if ($type === PlaceActions::REFRESH_INTERVAL) {
                $minutes = $action['minutes'] ?? null;

                if (! is_numeric($minutes) || (int) $minutes != $minutes || (int) $minutes < 1 || (int) $minutes > 1440) {
                    $printable = is_scalar($minutes) ? $minutes : gettype($minutes);

                    $this->fail('place_refresh_interval_invalid', ['handle' => $handle, 'value' => $printable]);
                }
            }

            // Nothing tying it to a particular kind of workflow, unlike the
            // spinner: any place in any workflow can reasonably want to announce
            // that a subject arrived. Only the mail type is checked.
            if ($type === PlaceActions::MAIL) {
                $mails++;
                $this->validateMailType('place_action_mail_unknown', $handle, $action);
            }

            // Whole days, from one to a year. Zero would mail on every scheduler
            // run; past a year the reminder has stopped being one.
            if ($type === PlaceActions::REMINDER) {
                $reminders++;
                $days = $action['every_days'] ?? null;

                if (! is_numeric($days) || (int) $days != $days || (int) $days < 1 || (int) $days > 365) {
                    $printable = is_scalar($days) ? $days : gettype($days);

                    $this->fail('place_reminder_invalid', ['handle' => $handle, 'value' => $printable]);
                }

                $this->validateMailType('place_action_reminder_unknown', $handle, $action);
            }

            // Must be a real step OUT of this place: a system transition belongs to
            // the engine, and one that does not start here could never apply.
            if ($type === PlaceActions::AUTO_TRANSITION) {
                $autos++;
                $days = $action['after_days'] ?? null;

                if (! is_numeric($days) || (int) $days != $days || (int) $days < 1 || (int) $days > 365) {
                    $printable = is_scalar($days) ? $days : gettype($days);

                    $this->fail('place_auto_transition_days_invalid', ['handle' => $handle, 'value' => $printable]);
                }

                $name = $action['transition'] ?? null;
                $target = is_string($name) ? ($definition['transitions'][$name] ?? null) : null;

                if (! is_array($target)
                    || ($target['metadata']['system'] ?? false) === true
                    || ! in_array($handle, (array) ($target['from'] ?? []), true)) {
                    $this->fail('place_auto_transition_invalid', [
                        'handle' => $handle,
                        'transition' => is_scalar($name) ? (string) $name : gettype($name),
                    ]);
                }
            }
        }

        if ($autos > 1) {
            $this->fail('place_multiple_auto_transitions', ['handle' => $handle]);
        }

        // Two reminders on one place would be two clocks for one question.
        if ($reminders > 1) {
            $this->fail('place_multiple_reminders', ['handle' => $handle]);
        }

        // Same rule and same reasoning as transition-side (see validateActions()):
        // two of these would mail the same people twice for one arrival, and their
        // order in the list would be a distinction without a difference.
        if ($mails > 1) {
            $this->fail('place_multiple_mails', [
                'handle' => $handle,
                'action' => PlaceActions::MAIL,
            ]);
        }
    }

    /**
     * The `mail_type` key of a mail action: which of the configured mail types it
     * sends.
     *
     * Absent is VALID and means MailTypes::FALLBACK — every mail action written
     * before the selector existed omits it, and they all meant the assignment
     * mail. An unknown handle is rejected rather than silently falling back, for
     * the same reason an unknown action TYPE is: a typo would still send a mail,
     * just the wrong one, and nothing about the workflow would look broken.
     *
     * Shared by both scopes because the key means exactly the same thing on a
     * place as on a transition — only the error message differs, which is why the
     * caller passes it in.
     *
     * @param  array<string, mixed>  $action
     */
    private function validateMailType(string $message, string $handle, array $action): void
    {
        // Both spellings, because the older one is still READ everywhere else —
        // see MailAction. Whichever is present has to name a type that exists.
        $mail = $action[MailAction::KEY] ?? $action[MailAction::LEGACY_KEY] ?? null;

        if ($mail === null || $mail === '') {
            return;
        }

        if (! is_string($mail) || ! MailTypes::exists($mail)) {
            $printable = is_scalar($mail) ? $mail : gettype($mail);

            $this->fail($message, [
                'handle' => $handle,
                'mail' => $printable,
                'known' => implode(', ', array_keys(MailTypes::all())),
            ]);
        }
    }
}
