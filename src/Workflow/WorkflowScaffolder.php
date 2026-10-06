<?php

namespace TakepartMedia\Workflows\Workflow;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the definition of a NEW workflow — blank, from a shipped example, or
 * as a copy of an existing one — and answers the two questions every way of
 * creating one has to ask first: is the handle usable, and which nav slug does
 * the new workflow get.
 *
 * The CP's "create workflow" endpoint and `php artisan workflows:make` both go
 * through here, so a workflow made on the command line and one made in the CP
 * are built by the same rules. Nothing here writes: the definition goes to
 * WorkflowDefinitionWriter::create(), which validates and persists it.
 *
 * Every definition built here starts INACTIVE (`active: false`). A new
 * workflow is a half-finished thing until somebody has looked at its places,
 * groups and collections — switched on right away it would claim the entries
 * of its collections, show up in the nav and the entry editor, and the seeder
 * would hand every existing entry a state from a definition nobody has
 * reviewed. Switching it on is one click in the settings index. The CLI keeps
 * its old default (active) and passes `false` only on `--inactive`.
 */
class WorkflowScaffolder
{
    /**
     * Longest handle accepted. The handle is a file name, a DB key in every
     * state/event row and part of every settings URL — short on purpose.
     */
    public const HANDLE_MAX = 48;

    public const HANDLE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * Handles that would collide with a fixed path segment of the settings
     * routes (`workflows/api/settings/create`, `…/install`, `workflows/settings`,
     * `workflows/instances/…`, `workflows/api/…`).
     */
    public const RESERVED_HANDLES = ['install', 'create', 'settings', 'api', 'instances'];

    /**
     * First path segments the `workflows/{slug}` manager routes refuse (see
     * routes/cp.php: `(?!(?:instances|api|settings)\b)`), plus the two settings
     * verbs for symmetry. A slug starting with one of these as a whole word
     * would make the manager page unreachable.
     */
    public const RESERVED_SLUG_WORDS = ['instances', 'api', 'settings', 'install', 'create'];

    public const DEFAULT_ICON = 'workflow';

    /**
     * After the shipped examples (1–3) in the nav.
     */
    public const DEFAULT_ORDER = 100;

    public function __construct(
        private DefinitionLoader $loader,
        private WorkflowInstaller $installer,
    ) {}

    /**
     * Null when `$handle` can be used for a new workflow, else the lang key
     * (under `workflows::messages.`) saying why not:
     * `create_handle_invalid` (pattern or length), `create_handle_reserved`,
     * `create_handle_taken`.
     *
     * "Taken" compares case-insensitively: a hand-written `PostManager.yaml`
     * and a new `postmanager.yaml` are the same file on a case-insensitive file
     * system (macOS by default), and two workflows whose names differ only in
     * case would be one typo apart everywhere else.
     */
    public function validateHandle(string $handle): ?string
    {
        if ($handle === '' || strlen($handle) > self::HANDLE_MAX || ! preg_match(self::HANDLE_PATTERN, $handle)) {
            return 'create_handle_invalid';
        }

        if (in_array($handle, self::RESERVED_HANDLES, true)) {
            return 'create_handle_reserved';
        }

        if ($this->handleExists($handle)) {
            return 'create_handle_taken';
        }

        return null;
    }

    /**
     * Whether a workflow file with this name exists, ignoring case.
     */
    public function handleExists(string $handle): bool
    {
        $needle = strtolower($handle);

        foreach ($this->loader->allIncludingInactive() as $name) {
            if (strtolower($name) === $needle) {
                return true;
            }
        }

        return is_file($this->loader->path($handle));
    }

    /**
     * A nav slug for a new workflow: the handle with `_` → `-`, suffixed `-2`,
     * `-3`, … until no workflow — active or not — uses it. Inactive ones count
     * because switching them back on must not produce two manager pages under
     * one URL. A slug that would start with a reserved route word is prefixed
     * `workflow-` instead (`api_tools` → `workflow-api-tools`).
     */
    public function uniqueSlug(string $handle): string
    {
        $base = trim(preg_replace('/[^a-z0-9-]+/', '-', str_replace('_', '-', strtolower($handle))), '-');
        $base = $base === '' ? 'workflow' : $base;

        if (preg_match('/^(?:'.implode('|', self::RESERVED_SLUG_WORDS).')(?:-|$)/', $base)) {
            $base = 'workflow-'.$base;
        }

        $taken = $this->takenSlugs();
        $slug = $base;

        for ($i = 2; in_array($slug, $taken, true); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /**
     * Every `metadata.nav.slug` in the project, inactive workflows included.
     *
     * @return array<int, string>
     */
    public function takenSlugs(): array
    {
        $slugs = [];

        foreach ($this->loader->allIncludingInactive() as $name) {
            try {
                $slug = $this->loader->raw($name)['metadata']['nav']['slug'] ?? null;
            } catch (\Throwable) {
                // A broken YAML elsewhere must not block creating a new workflow.
                continue;
            }

            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * The smallest useful workflow: `draft` → `published` and back. No
     * `required_group` anywhere — which groups exist is the project's business,
     * and a guessed one would gate every step on a group that may not be there.
     *
     * @param  array<int, string>  $collections
     * @return array<string, mixed>
     */
    public function blank(string $handle, string $label, array $collections = []): array
    {
        return [
            'name' => $handle,
            'type' => 'state_machine',
            'marking_store' => ['type' => 'method', 'property' => 'current_state'],
            'collections' => array_values($collections),
            'initial_place' => 'draft',
            'active' => false,
            'metadata' => [
                'label' => $label,
                'nav' => $this->nav($handle, $label),
                // A left-to-right start layout for the graph editor, in the
                // node sizes it falls back to before measuring.
                'editor' => [
                    'places' => [
                        'draft' => ['x' => 0, 'y' => 40],
                        'published' => ['x' => 580, 'y' => 40],
                    ],
                    'transitions' => [
                        'publish' => ['x' => 320, 'y' => 0],
                        'unpublish' => ['x' => 320, 'y' => 124],
                    ],
                ],
            ],
            'places' => [
                'draft' => ['metadata' => ['color' => 'default']],
                'published' => ['metadata' => ['color' => 'green', 'allow_publish' => true]],
            ],
            'transitions' => [
                'publish' => ['from' => 'draft', 'to' => 'published', 'metadata' => ['variant' => 'primary']],
                'unpublish' => ['from' => 'published', 'to' => 'draft'],
            ],
        ];
    }

    /**
     * A shipped example under a new handle, slug and label. `$collections`
     * null keeps the example's own list.
     *
     * The example's YAML comments do not survive — the writer dumps the parsed
     * definition. The commented original stays in the addon's
     * `resources/workflows/examples/` as the reference.
     *
     * @param  array<int, string>|null  $collections
     * @return array<string, mixed>
     */
    public function fromExample(string $type, string $handle, string $label, ?array $collections = null): array
    {
        if (! in_array($type, $this->installer->availableTypes(), true)) {
            throw new InvalidArgumentException((string) __('workflows::messages.install_unknown_type', ['type' => $type]));
        }

        $definition = Yaml::parseFile($this->loader->examplesDirectory().'/'.$type.'.yaml') ?: [];

        return $this->rebrand($definition, $handle, $label, $collections);
    }

    /**
     * An existing workflow as it is stored (not as the loader serves it — the
     * auto-injected revision step stays out) under a new handle, slug and
     * label. Editor positions come along; `$collections` null keeps the
     * source's list.
     *
     * @param  array<int, string>|null  $collections
     * @return array<string, mixed>
     */
    public function copyOf(string $existingHandle, string $handle, string $label, ?array $collections = null): array
    {
        $path = $this->loader->path($existingHandle);

        if (! in_array($existingHandle, $this->loader->allIncludingInactive(), true) || ! is_file($path)) {
            throw new InvalidArgumentException((string) __('workflows::messages.writer_workflow_file_missing', ['workflow' => $existingHandle, 'path' => $path]));
        }

        $definition = Yaml::parseFile($path) ?: [];

        foreach (WorkflowDefinitionWriter::RESERVED_PLACE_HANDLES as $reserved) {
            unset($definition['places'][$reserved], $definition['metadata']['editor']['places'][$reserved]);
        }

        foreach (WorkflowDefinitionWriter::RESERVED_TRANSITION_HANDLES as $reserved) {
            unset($definition['transitions'][$reserved], $definition['metadata']['editor']['transitions'][$reserved]);
        }

        return $this->rebrand($definition, $handle, $label, $collections);
    }

    /**
     * Give a definition its new identity: name, label (long form and nav), a
     * fresh nav slug, optionally collections — and switch it off.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>|null  $collections
     * @return array<string, mixed>
     */
    private function rebrand(array $definition, string $handle, string $label, ?array $collections): array
    {
        $metadata = is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [];
        $nav = is_array($metadata['nav'] ?? null) ? $metadata['nav'] : [];

        $metadata['label'] = $label;
        $metadata['nav'] = array_replace($this->nav($handle, $label), array_intersect_key($nav, array_flip(['icon', 'order'])), [
            'label' => $label,
            'slug' => $this->uniqueSlug($handle),
        ]);

        $definition['name'] = $handle;
        $definition['metadata'] = $metadata;
        $definition['active'] = false;

        if ($collections !== null) {
            $definition['collections'] = array_values($collections);
        }

        return $definition;
    }

    /**
     * @return array{label: string, slug: string, icon: string, order: int}
     */
    private function nav(string $handle, string $label): array
    {
        return [
            'label' => $label,
            'slug' => $this->uniqueSlug($handle),
            'icon' => self::DEFAULT_ICON,
            'order' => self::DEFAULT_ORDER,
        ];
    }
}
