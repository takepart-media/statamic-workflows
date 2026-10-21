<?php

namespace TakepartMedia\Workflows\Tests\Concerns;

use Illuminate\Support\Facades\File;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint as BlueprintObject;
use Symfony\Component\Yaml\Yaml;

/**
 * Builders for the content a test needs: collections with a blueprint, entries,
 * and workflow YAMLs. Everything lands in the TestCase's temp directory.
 */
trait CreatesContent
{
    /**
     * Create (and save) a collection whose single entry blueprint has the given
     * fields. `$fields` is either a flat `handle => field config` map (one tab,
     * one section) or a full blueprint contents array (has a `tabs` key).
     *
     * @param  array<string, mixed>  $fields
     */
    protected function makeCollection(string $handle, array $fields = [], ?string $blueprint = null): CollectionContract
    {
        $collection = Collection::make($handle)->title(ucfirst($handle));
        $collection->save();

        $this->makeBlueprint("collections.$handle", $blueprint ?? str($handle)->singular()->toString(), $fields);

        return Collection::findByHandle($handle);
    }

    /**
     * Create (and save) a blueprint in a namespace, e.g. `collections.pages`.
     *
     * @param  array<string, mixed>  $fields  see makeCollection()
     */
    protected function makeBlueprint(string $namespace, string $handle, array $fields): BlueprintObject
    {
        $blueprint = Blueprint::make($handle)
            ->setNamespace($namespace)
            ->setContents($this->blueprintContents($fields));

        $blueprint->save();

        return $blueprint;
    }

    /**
     * Turn a `handle => config` map into blueprint contents. A full contents
     * array (with `tabs`) passes through untouched.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function blueprintContents(array $fields): array
    {
        if (isset($fields['tabs'])) {
            return $fields;
        }

        $fields = ['title' => ['type' => 'text', 'display' => 'Title']] + $fields;

        return [
            'tabs' => [
                'main' => [
                    'sections' => [[
                        'fields' => collect($fields)
                            ->map(fn (array $config, string $handle) => ['handle' => $handle, 'field' => $config])
                            ->values()
                            ->all(),
                    ]],
                ],
            ],
        ];
    }

    /**
     * Create (and save) an entry.
     *
     * @param  array<string, mixed>  $data
     */
    protected function makeEntry(string $collection, array $data = [], ?string $slug = null): EntryContract
    {
        $entry = Entry::make()
            ->collection($collection)
            ->slug($slug ?? str($data['title'] ?? uniqid('entry-'))->slug()->toString())
            ->data($data);

        $entry->save();

        return $entry;
    }

    /**
     * Write a workflow YAML into the test's workflow directory. Accepts an
     * array (dumped as YAML) or a raw YAML string.
     *
     * @param  array<string, mixed>|string  $definition
     */
    protected function writeWorkflow(string $name, array|string $definition): string
    {
        $path = $this->workflowsDirectory().'/'.$name.'.yaml';

        File::ensureDirectoryExists(dirname($path));
        File::put($path, is_string($definition) ? $definition : Yaml::dump($definition, 6, 2));

        \TakepartMedia\Workflows\Workflow\DefinitionLoader::flush();

        return $path;
    }

    /**
     * A small but complete state machine, for tests that just need "a
     * workflow". Override keys via `$overrides` (merged recursively).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function minimalWorkflow(string $name = 'simple', array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => $name,
            'type' => 'state_machine',
            'marking_store' => ['type' => 'method', 'property' => 'current_state'],
            'collections' => ['pages'],
            'initial_place' => 'draft',
            'metadata' => ['label' => 'Simple'],
            'places' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
                'published' => ['label' => 'Published', 'metadata' => ['allow_publish' => true]],
            ],
            'transitions' => [
                'submit' => ['from' => 'draft', 'to' => 'review'],
                'approve' => ['from' => 'review', 'to' => 'published'],
            ],
        ], $overrides);
    }
}
