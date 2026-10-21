<?php

use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use TakepartMedia\Workflows\ServiceProvider;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;

it('boots the addon service provider', function () {
    expect(app()->getProvider(ServiceProvider::class))->toBeInstanceOf(ServiceProvider::class)
        ->and(config('workflows'))->toBeArray()->not->toBeEmpty();
});

it('runs the workflow database in memory with the addon migrations applied', function () {
    expect(config('database.connections.workflow.database'))->toBe(':memory:')
        ->and(Schema::connection('workflow')->hasTable('workflow_states'))->toBeTrue()
        ->and(Schema::connection('workflow')->hasTable('workflow_events'))->toBeTrue();
});

it('keeps stache and blueprints inside the temp directory', function () {
    $root = $this->tempPath();

    expect(Stache::store('entries')->directory())->toStartWith($root)
        ->and(Stache::store('collections')->directory())->toStartWith($root)
        ->and(Blueprint::directory())->toStartWith($root)
        ->and(app(DefinitionLoader::class)->directory())->toBe($this->workflowsDirectory());
});

it('loads a workflow YAML from the temp workflow directory', function () {
    $this->writeWorkflow('simple', $this->minimalWorkflow());

    $loader = app(DefinitionLoader::class);

    expect($loader->all())->toBe(['simple'])
        ->and($loader->raw('simple')['initial_place'])->toBe('draft');

    $definition = $loader->load('simple');

    expect($definition->getPlaces())->toHaveKeys(['draft', 'review', 'published'])
        ->and(collect($definition->getTransitions())->map->getName()->all())->toBe(['submit', 'approve']);
});

it('skips inactive workflows', function () {
    $this->writeWorkflow('on', $this->minimalWorkflow('on'));
    $this->writeWorkflow('off', $this->minimalWorkflow('off', ['active' => false]));

    $loader = app(DefinitionLoader::class);

    expect($loader->all())->toBe(['on'])
        ->and($loader->allIncludingInactive())->toBe(['off', 'on']);
});

it('reports path-tagged errors for a broken definition', function () {
    $this->makeCollection('pages');
    $writer = app(WorkflowDefinitionWriter::class);

    expect($writer->collectErrors($this->minimalWorkflow()))->toBe([]);

    $broken = $this->minimalWorkflow(overrides: [
        'initial_place' => 'nowhere',
        'transitions' => ['submit' => ['to' => 'missing']],
    ]);

    $errors = $writer->collectErrors($broken);
    $paths = array_column($errors, 'path');

    expect($errors)->not->toBeEmpty()
        ->and($paths)->toContain('initial_place')
        ->and($paths)->toContain('transitions.submit');

    foreach ($errors as $error) {
        expect($error)->toHaveKeys(['path', 'code', 'message', 'node']);
    }
});

it('creates a collection with an in-memory blueprint and entries', function () {
    $this->makeCollection('pages', [
        'content' => ['type' => 'bard', 'sets' => []],
        'sections' => ['type' => 'replicator', 'sets' => []],
    ]);

    $collection = Collection::findByHandle('pages');
    $blueprint = $collection->entryBlueprint();

    expect($blueprint->handle())->toBe('page')
        ->and($blueprint->fields()->all()->keys()->all())->toContain('title', 'content', 'sections');

    $entry = $this->makeEntry('pages', ['title' => 'Hello']);

    expect(Entry::find($entry->id())->get('title'))->toBe('Hello')
        ->and(Entry::find($entry->id())->path())->toStartWith($this->tempPath());
});
