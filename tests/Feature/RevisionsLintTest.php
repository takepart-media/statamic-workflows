<?php

use Statamic\Facades\Collection;
use TakepartMedia\Workflows\Workflow\CollectionRevisions;
use TakepartMedia\Workflows\Workflow\DefinitionLinter;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;

/*
 * A governed collection without revisions edits published posts live — the
 * linter says so, since the addon cannot switch them on itself.
 */

beforeEach(function () {
    config()->set('statamic.editions.pro', true);
    config()->set('statamic.revisions.enabled', true);

    $this->makeCollection('pages');
});

function revisionCodes(array $definition): array
{
    return collect(app(DefinitionLinter::class)->lint('simple', $definition))
        ->where('code', 'collection_revisions_off')
        ->pluck('path')
        ->all();
}

it('warns about a governed collection without revisions', function () {
    expect(revisionCodes($this->minimalWorkflow()))->toBe(['collections.0']);
});

it('stays quiet once the collection has revisions', function () {
    Collection::find('pages')->revisionsEnabled(true)->save();

    expect(revisionCodes($this->minimalWorkflow()))->toBe([]);
});

it('stays quiet for a workflow that never publishes, or with workflows.revisions off', function () {
    $noPublish = $this->minimalWorkflow();
    unset($noPublish['places']['published']['metadata']);

    expect(revisionCodes($noPublish))->toBe([]);

    config()->set('workflows.revisions', false);
    expect(revisionCodes($this->minimalWorkflow()))->toBe([]);
});

it('follows metadata.revisions instead of the collections', function () {
    $on = $this->minimalWorkflow('simple', ['metadata' => ['revisions' => true]]);
    $off = $this->minimalWorkflow('simple', ['metadata' => ['revisions' => false]]);

    expect(revisionCodes($on))->toBe([])
        ->and(revisionCodes($off))->toBe([]);

    config()->set('statamic.editions.pro', false);
    $codes = collect(app(DefinitionLinter::class)->lint('simple', $on))->pluck('code')->all();

    expect($codes)->toContain('revisions_unavailable');
});

it('writes metadata.revisions into the collections', function () {
    expect(CollectionRevisions::apply($this->minimalWorkflow()))->toBe([])
        ->and(CollectionRevisions::stored('pages'))->toBeFalse();

    expect(CollectionRevisions::apply($this->minimalWorkflow('simple', ['metadata' => ['revisions' => true]])))->toBe(['pages'])
        ->and(CollectionRevisions::stored('pages'))->toBeTrue()
        ->and(Collection::find('pages')->revisionsEnabled())->toBeTrue();

    // Unchanged: nothing written again.
    expect(CollectionRevisions::apply($this->minimalWorkflow('simple', ['metadata' => ['revisions' => true]])))->toBe([]);

    CollectionRevisions::apply($this->minimalWorkflow('simple', ['metadata' => ['revisions' => false]]));
    expect(CollectionRevisions::stored('pages'))->toBeFalse();
});

it('refuses a revisions value that is not a boolean', function () {
    $this->writeWorkflow('simple', $this->minimalWorkflow());
    $raw = app(DefinitionLoader::class)->raw('simple');
    $raw['metadata']['revisions'] = 'yes';

    expect(fn () => app(WorkflowDefinitionWriter::class)->save('simple', $raw))
        ->toThrow(DefinitionValidationException::class);
});
