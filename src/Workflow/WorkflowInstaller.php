<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Statamic\Facades\Collection;
use Symfony\Component\Yaml\Yaml;

/**
 * Install helpers behind the interactive `workflows:install` command — the CLI
 * owns the prompting, the four primitive steps (list types, copy example,
 * rewrite collections, backfill) live here. The CP's "create workflow" modal
 * reads the type list from here too (describedTypes(), projectCollections());
 * creating from an example goes through WorkflowScaffolder.
 */
class WorkflowInstaller
{
    public function __construct(
        private DefinitionLoader $loader,
        private WorkflowStateSeeder $seeder,
        private WorkflowDefinitionWriter $writer,
    ) {}

    /**
     * Names of workflow types shipped with the addon (files under
     * `resources/workflows/examples/*.yaml`).
     *
     * @return array<int, string>
     */
    public function availableTypes(): array
    {
        return collect(glob($this->loader->examplesDirectory().'/*.yaml') ?: [])
            ->map(fn ($f) => basename($f, '.yaml'))
            ->values()
            ->all();
    }

    /**
     * The shipped types in a shape a human can choose from: handle, the name the
     * workflow gives itself, and what it does.
     *
     * Both derive from the example YAML rather than from a list in the addon — the
     * definition already says what it is (`metadata.nav` is what the CP menu reads,
     * `metadata.label` the long form), and a second list would drift the first time
     * somebody ships another example. Both go through the translations first, so the
     * modal names a manager exactly as the sidebar will. The handle stays visible
     * next to the label: it is the file name and the key every state row carries.
     *
     * @return array<int, array{value: string, label: string, description: string|null, installed: bool}>
     */
    public function describedTypes(): array
    {
        return collect($this->availableTypes())
            ->map(function (string $type) {
                $metadata = $this->exampleMetadata($type);
                $nav = is_array($metadata['nav'] ?? null) ? $metadata['nav'] : [];

                // Same precedence as the CP nav: an explicit `nav.label` wins, an
                // empty one hands the name to `messages.nav.<slug>`. The shipped
                // examples deliberately leave it empty so the modal, the sidebar
                // and the assignment mail all read the same translated name.
                $navLabel = DefinitionLoader::navLabel($nav);

                // The long form. Translated per type where a key exists, so the
                // description is not frozen in the language the example was
                // written in; `metadata.label` stays the fallback for examples a
                // project adds itself.
                $descriptionKey = "workflows::messages.install_description_{$type}";
                $description = __($descriptionKey);

                return [
                    'value' => $type,
                    'label' => $navLabel !== '' ? $navLabel : (string) ($metadata['label'] ?? $type),
                    'description' => $description !== $descriptionKey
                        ? (string) $description
                        : (isset($metadata['label']) ? (string) $metadata['label'] : null),
                    'installed' => $this->isInstalled($type),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * `metadata` of a shipped example, or an empty array when the file is missing or
     * unreadable. Read straight from the example, never from the project's own copy:
     * this describes what WOULD be installed.
     *
     * @return array<string, mixed>
     */
    private function exampleMetadata(string $type): array
    {
        $path = $this->loader->examplesDirectory().'/'.$type.'.yaml';

        if (! File::exists($path)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($path) ?: [];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return is_array($parsed['metadata'] ?? null) ? $parsed['metadata'] : [];
    }

    /**
     * True when the project already has a definition file for this type at
     * `resources/workflows/<type>.yaml`.
     *
     * The create modal marks such a type as installed (it can still be used
     * as a template under a new handle). The interactive CLI re-runs over an
     * installed type — it only touches the `collections:` key and optionally
     * seeds, and it asks first.
     */
    public function isInstalled(string $type): bool
    {
        return File::exists($this->loader->path($type));
    }

    /**
     * Current `collections:` value for an installed type, or an empty list if
     * the type is not installed yet. Read by the CLI installer to name the
     * collections it is about to backfill.
     *
     * @return array<int, string>
     */
    public function currentCollections(string $type): array
    {
        if (! $this->isInstalled($type)) {
            return [];
        }

        return (array) ($this->loader->raw($type)['collections'] ?? []);
    }

    /**
     * The Statamic collections that exist in the host project — the pool the
     * operator picks from when attaching a workflow.
     *
     * @return array<int, string>
     */
    public function projectCollections(): array
    {
        return Collection::handles()->all();
    }

    /**
     * Copy the shipped example into the project when the workflow doesn't
     * exist yet. No-op if the target is already there — the file is the
     * project's own from that point on and is never overwritten.
     */
    public function copyExampleIfMissing(string $type): void
    {
        $target = $this->loader->path($type);

        if (File::exists($target)) {
            return;
        }

        $source = $this->loader->examplesDirectory().'/'.$type.'.yaml';

        if (! File::exists($source)) {
            throw new InvalidArgumentException("Type [{$type}] not found. Available: ".implode(', ', $this->availableTypes()));
        }

        File::ensureDirectoryExists($this->loader->directory());
        File::copy($source, $target);
    }

    /**
     * Write the `collections:` key of an installed workflow definition. Only
     * that one key is touched; all other fields (places, transitions,
     * metadata, sla) survive as-is. Unknown collection handles are filtered
     * out — the caller may inspect the return value to know what stuck.
     *
     * @param  array<int, string>  $collections
     * @return array<int, string>  the sanitised list actually persisted
     */
    public function rewriteCollections(string $type, array $collections): array
    {
        $available = $this->projectCollections();
        $sanitised = collect($collections)
            ->map(fn ($h) => is_string($h) ? trim($h) : '')
            ->filter(fn ($h) => $h !== '' && in_array($h, $available, true))
            ->unique()
            ->values()
            ->all();

        $path = $this->loader->path($type);
        $raw = Yaml::parseFile($path) ?: [];
        $raw['collections'] = $sanitised;

        $this->writer->backup($type);

        File::replace($path, Yaml::dump($raw, 6, 2));

        DefinitionLoader::flush();

        return $sanitised;
    }

    /**
     * Backfill workflow states for the entries that already exist. Skipped by the
     * caller when the workflow has no collections attached — there'd be nothing
     * to seed.
     *
     * Calls the seeder directly rather than shelling out to
     * `workflows:seed-states`, so the caller gets the number of state rows
     * written and can report the same figure the CP's per-row "backfill states"
     * action reports.
     *
     * @return int  Number of state rows written.
     */
    public function backfill(string $type): int
    {
        return $this->seeder->seed($type);
    }
}
