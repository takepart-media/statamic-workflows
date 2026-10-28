<?php

use Statamic\Facades\User;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;

/*
 * The pivot listings (manager page, dashboard overview) filter by assignee and
 * author — matched by user id, labelled by name.
 */

function listingFilters(array $filters): string
{
    return base64_encode(json_encode($filters));
}

beforeEach(function () {
    $this->makeCollection('pages', ['author' => ['type' => 'users']]);
    $this->writeWorkflow('simple', $this->minimalWorkflow());

    $this->admin = tap(User::make()->id('admin')->email('admin@example.com')->set('name', 'Admin')->makeSuper())->save();
    tap(User::make()->id('anna')->email('anna@example.com')->set('name', 'Anna'))->save();
    tap(User::make()->id('ben')->email('ben@example.com')->set('name', 'Ben'))->save();

    $this->byAnna = $this->makeEntry('pages', ['title' => 'By Anna', 'author' => 'anna']);
    $this->byBen = $this->makeEntry('pages', ['title' => 'By Ben', 'author' => 'ben']);

    app(WorkflowAssigneeStore::class)->set($this->byAnna->id(), 'simple', ['ben']);
});

function listedTitles($test, array $filters = []): array
{
    $url = cp_route('workflows.listing', array_filter([
        'workflow' => 'simple',
        'filters' => $filters ? listingFilters($filters) : null,
    ]));

    return collect($test->actingAs($test->admin)->getJson($url)->assertOk()->json('data'))
        ->pluck('title')->sort()->values()->all();
}

it('narrows the pivot to one assignee', function () {
    expect(listedTitles($this, ['assignee' => ['assignee' => 'ben']]))->toBe(['By Anna']);
});

it('narrows the pivot to one author', function () {
    expect(listedTitles($this))->toBe(['By Anna', 'By Ben'])
        ->and(listedTitles($this, ['author' => ['author' => 'anna']]))->toBe(['By Anna'])
        ->and(listedTitles($this, ['author' => ['author' => 'ben']]))->toBe(['By Ben']);
});

it('filters the cross-workflow overview the same way', function () {
    $url = cp_route('workflows.listing', [
        'all_workflows' => 1,
        'filters' => listingFilters(['author' => ['author' => 'ben']]),
    ]);

    expect(collect($this->actingAs($this->admin)->getJson($url)->assertOk()->json('data'))->pluck('title')->all())
        ->toBe(['By Ben']);
});

it('offers the people of the rows, labelled by name', function () {
    $controller = app(\TakepartMedia\Workflows\Http\Controllers\WorkflowController::class);
    $call = fn (string $method, ...$args) => (new ReflectionMethod($controller, $method))->invoke($controller, ...$args);

    $filters = collect($call('filters', $call('rowsFor', 'simple'), 'simple'))->keyBy('handle');

    expect($filters->keys()->all())->toContain('assignee', 'author')
        ->and($filters['assignee']['fields'][0]['options'])->toBe(['ben' => 'Ben'])
        ->and($filters['author']['fields'][0]['options'])->toBe(['anna' => 'Anna', 'ben' => 'Ben']);
});

it('keeps posts waiting longer than the chosen days in an open place', function () {
    $store = app(\TakepartMedia\Workflows\Workflow\WorkflowStateStore::class);

    $this->travelTo(now()->subDays(10));
    $store->set($this->byAnna->id(), 'simple', 'review');
    $store->set($this->byBen->id(), 'simple', 'published'); // done, not waiting
    $this->travelBack();

    $this->makeEntry('pages', ['title' => 'Fresh']);
    $store->set(\Statamic\Facades\Entry::query()->where('title', 'Fresh')->first()->id(), 'simple', 'draft');
    $store->forget('simple');

    expect(listedTitles($this, ['waiting' => ['waiting' => '7']]))->toBe(['By Anna'])
        ->and(listedTitles($this, ['waiting' => ['waiting' => '14']]))->toBe([]);
});
