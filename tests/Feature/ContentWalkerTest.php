<?php

use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Content\ContentWalker;
use TakepartMedia\Workflows\Content\EntryContentPreview;

/*
 * The walker reads whatever structure the workflow names as content: here a
 * Replicator of sections, each holding a Replicator of content rows (plain
 * Bard, a Group with a Bard inside, code, video), next to a Bard field with
 * sets. Blueprints and entries live in the test's temp Stache.
 */

function walkerFields(): array
{
    $text = fn (string $display, array $extra = []) => ['type' => 'text', 'display' => $display] + $extra;

    return [
        'body' => [
            'type' => 'bard',
            'display' => 'Body',
            'sets' => [
                'quote' => ['display' => 'Quote', 'fields' => [
                    ['handle' => 'quote', 'field' => $text('Quote text')],
                    ['handle' => 'author', 'field' => $text('Author')],
                    ['handle' => 'big', 'field' => ['type' => 'toggle']],
                ]],
            ],
        ],
        'sections' => [
            'type' => 'replicator',
            'display' => 'Sections',
            'sets' => [
                'section' => ['display' => 'Section', 'fields' => [
                    ['handle' => 'background', 'field' => ['type' => 'select', 'options' => ['white' => 'White']]],
                    ['handle' => 'anchor', 'field' => $text('Anchor', ['visibility' => 'hidden'])],
                    ['handle' => 'content', 'field' => [
                        'type' => 'replicator',
                        'display' => 'Content',
                        'sets' => [
                            'text_block' => ['display' => 'Text block', 'fields' => [
                                ['handle' => 'text', 'field' => ['type' => 'bard', 'display' => 'Text']],
                            ]],
                            'teaser' => ['display' => 'Teaser', 'fields' => [
                                ['handle' => 'box', 'field' => ['type' => 'group', 'display' => 'Box', 'fields' => [
                                    ['handle' => 'title', 'field' => $text('Box title')],
                                    ['handle' => 'body', 'field' => ['type' => 'bard', 'display' => 'Box body']],
                                ]]],
                            ]],
                            'code_block' => ['display' => 'Code', 'fields' => [
                                ['handle' => 'code', 'field' => ['type' => 'code', 'display' => 'Snippet']],
                            ]],
                            'video_block' => ['display' => 'Video', 'fields' => [
                                ['handle' => 'url', 'field' => ['type' => 'video', 'display' => 'Video URL']],
                            ]],
                            'spacer' => ['display' => 'Spacer', 'fields' => [
                                ['handle' => 'large', 'field' => ['type' => 'toggle']],
                            ]],
                        ],
                    ]],
                ]],
            ],
        ],
    ];
}

function paragraph(string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
}

function walkerData(array $overrides = []): array
{
    return array_replace_recursive([
        'title' => 'Walker',
        'body' => [
            paragraph('Intro paragraph'),
            ['type' => 'set', 'attrs' => ['id' => 'quote-1', 'values' => ['type' => 'quote', 'quote' => 'To be', 'author' => 'Hamlet', 'big' => true]]],
            paragraph('Outro paragraph'),
        ],
        'sections' => [
            [
                'id' => 'sec-1',
                'type' => 'section',
                'background' => 'white',
                'anchor' => 'top',
                'content' => [
                    ['id' => 'row-text', 'type' => 'text_block', 'text' => [paragraph('Section text')]],
                    ['id' => 'row-teaser', 'type' => 'teaser', 'box' => ['title' => 'Teaser title', 'body' => [paragraph('Teaser body')]]],
                    ['id' => 'row-code', 'type' => 'code_block', 'code' => ['code' => '<b>hi</b>', 'mode' => 'html']],
                    ['id' => 'row-video', 'type' => 'video_block', 'url' => 'https://youtu.be/abc123'],
                    ['id' => 'row-spacer', 'type' => 'spacer', 'large' => true],
                    ['id' => 'row-off', 'type' => 'text_block', 'enabled' => false, 'text' => [paragraph('Disabled')]],
                ],
            ],
            // Empty section: only a select — no content, no block.
            ['id' => 'sec-empty', 'type' => 'section', 'background' => 'white'],
        ],
    ], $overrides);
}

function walkerEntry(array $data = [])
{
    return Entry::find(test()->makeEntry('pages', $data ?: walkerData())->id());
}

beforeEach(function () {
    $this->makeCollection('pages', walkerFields());
});

it('walks nested replicators, groups and bard into a flat list with depths', function () {
    $blocks = (new ContentWalker)->walk(walkerEntry(), ['sections']);

    $summary = array_map(fn ($b) => [$b['depth'], $b['kind'], $b['uuid'], $b['container'], $b['label']], $blocks);

    expect($summary)->toBe([
        [0, 'fields', 'sec-1', 'sections', 'Section'],
        [1, 'fields', 'row-text', 'sec-1', 'Text block'],
        [2, 'html', 'row-text.text', 'row-text', 'Text'],
        [1, 'fields', 'row-teaser', 'sec-1', 'Teaser'],
        [2, 'fields', 'row-teaser.box', 'row-teaser', 'Box'],
        [3, 'html', 'row-teaser.box.body', 'row-teaser.box', 'Box body'],
        [1, 'fields', 'row-code', 'sec-1', 'Code'],
        [1, 'fields', 'row-video', 'sec-1', 'Video'],
    ]);

    $byUuid = collect($blocks)->keyBy('uuid');

    // The section's select and hidden anchor are no items; the group carries its text.
    expect($byUuid['sec-1']['items'])->toBe([])
        ->and($byUuid['row-teaser.box']['items'])->toBe([['label' => 'Box title', 'kind' => 'text', 'text' => 'Teaser title']])
        ->and($byUuid['row-code']['items'][0])->toMatchArray(['kind' => 'code', 'code' => '<b>hi</b>'])
        ->and($byUuid['row-video']['items'][0])->toMatchArray([
            'kind' => 'video',
            'url' => 'https://youtu.be/abc123',
            'embedUrl' => 'https://www.youtube-nocookie.com/embed/abc123',
        ])
        ->and($byUuid['row-text.text']['html'])->toContain('Section text')
        ->and($byUuid['row-text.text']['path'])->toBe('sections.0.content.0.text');
});

it('reads bard text chunks and sets with the legacy uuids', function () {
    $blocks = (new ContentWalker)->walk(walkerEntry(), ['body']);

    expect(array_column($blocks, 'uuid'))->toBe(['body:0', 'quote-1', 'body:2'])
        ->and(array_column($blocks, 'container'))->toBe(['body', 'body', 'body'])
        ->and(array_column($blocks, 'handle'))->toBe(['body', 'quote', 'body'])
        ->and($blocks[1]['kind'])->toBe('fields')
        ->and($blocks[1]['label'])->toBe('Quote')
        // the toggle is not content
        ->and(array_column($blocks[1]['items'], 'text'))->toBe(['To be', 'Hamlet']);
});

it('skips disabled rows, empty nodes and unsupported types', function () {
    $uuids = array_column((new ContentWalker)->walk(walkerEntry(), ['sections']), 'uuid');

    expect($uuids)->not->toContain('row-off')
        ->and($uuids)->not->toContain('row-spacer')
        ->and($uuids)->not->toContain('sec-empty');
});

it('keeps uuids unique when a row id repeats', function () {
    $data = walkerData();
    $data['sections'][0]['content'][1]['id'] = 'row-text';

    $uuids = array_column((new ContentWalker)->walk(walkerEntry($data), ['body', 'sections']), 'uuid');

    expect($uuids)->toHaveCount(count(array_unique($uuids)))
        ->and($uuids)->toContain('row-text', 'row-text~2');
});

it('hashes passages stably and flips only the edited one', function () {
    $preview = app(EntryContentPreview::class);
    $this->writeWorkflow('simple', $this->minimalWorkflow(overrides: ['metadata' => ['content_fields' => ['sections']]]));

    $entry = walkerEntry();
    $refs = array_column($preview->blocks($entry, 'simple'), 'uuid');

    $first = $preview->contentHashesForPassages($entry, $refs, 'simple');
    Blink::store('workflows-content-blocks')->flush();
    $second = $preview->contentHashesForPassages($entry, $refs, 'simple');

    expect($first)->toBe($second)->and($first)->not->toContain(null);

    $containerBefore = $preview->containerHashForPassage($entry, 'row-teaser.box', 'simple');

    $data = walkerData();
    $data['sections'][0]['content'][0]['text'] = [paragraph('Section text, edited')];
    $edited = walkerEntry($data);
    $after = $preview->contentHashesForPassages($edited, $refs, 'simple');

    expect($after['row-text.text'])->not->toBe($first['row-text.text'])
        ->and($after['row-teaser.box.body'])->toBe($first['row-teaser.box.body'])
        ->and($after['sec-1'])->toBe($first['sec-1'])
        ->and($preview->containerHashForPassage($edited, 'row-teaser.box', 'simple'))->toBe($containerBefore)
        ->and($preview->contentHashForPassage($edited, 'missing', 'simple'))->toBeNull();
});

it('reads the workflow content fields, else auto-detects', function () {
    $preview = app(EntryContentPreview::class);
    $this->writeWorkflow('simple', $this->minimalWorkflow(overrides: ['metadata' => ['content_fields' => ['sections', 'body']]]));
    $entry = walkerEntry();

    expect($preview->fields($entry, 'simple'))->toBe(['sections', 'body'])
        // auto: the first Bard / Markdown / Replicator field
        ->and($preview->fields($entry))->toBe(['body'])
        ->and($preview->fields($entry, 'unknown'))->toBe(['body'])
        ->and(array_column($preview->blocks($entry), 'uuid'))->toBe(['body:0', 'quote-1', 'body:2']);
});
