<?php

use Illuminate\Support\Facades\Http;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Content\TextLeaf;
use TakepartMedia\Workflows\Content\TextLeafWalker;
use TakepartMedia\Workflows\Translation\PlainLanguageJob;
use TakepartMedia\Workflows\Translation\PlainLanguageJobStore;
use TakepartMedia\Workflows\Translation\PlainLanguageLeafDocument;
use TakepartMedia\Workflows\Translation\PlainLanguageLeafWriter;
use TakepartMedia\Workflows\Translation\PlainLanguageSections;
use TakepartMedia\Workflows\Translation\PlainLanguageSubmission;

/*
 * The plain-language round trip per text leaf: walking the content into leaves,
 * sending them as sections, parsing the answer strictly and writing every section
 * back into its own leaf. The service is always Http::fake()d.
 */

function plPara(string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
}

/** A paragraph as Bard's parser returns it (with the alignment it adds). */
function plParsed(string $text): array
{
    return ['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [['type' => 'text', 'text' => $text]]];
}

function plSet(string $id, string $type, array $values): array
{
    return ['type' => 'set', 'attrs' => ['id' => $id, 'values' => ['type' => $type] + $values]];
}

function plFields(): array
{
    $text = fn (string $display, array $extra = []) => ['type' => 'text', 'display' => $display] + $extra;

    return [
        'intro' => ['type' => 'bard', 'display' => 'Intro'],
        'content' => [
            'type' => 'bard',
            'display' => 'Content',
            'sets' => [
                'heading' => ['display' => 'Heading', 'fields' => [
                    ['handle' => 'heading', 'field' => $text('Heading')],
                    ['handle' => 'heading_index', 'field' => ['type' => 'integer']],
                ]],
                'hintbox' => ['display' => 'Hint', 'fields' => [
                    ['handle' => 'text', 'field' => ['type' => 'bard', 'display' => 'Text']],
                    ['handle' => 'title', 'field' => $text('Title')],
                ]],
                'video' => ['display' => 'Video', 'fields' => [
                    ['handle' => 'url', 'field' => ['type' => 'video']],
                ]],
            ],
        ],
        'sections' => [
            'type' => 'replicator',
            'display' => 'Sections',
            'sets' => [
                'section' => ['display' => 'Section', 'fields' => [
                    ['handle' => 'background', 'field' => ['type' => 'select', 'options' => ['blue' => 'Blue']]],
                    ['handle' => 'anchor', 'field' => $text('Anchor')],
                    ['handle' => 'content', 'field' => [
                        'type' => 'replicator',
                        'sets' => [
                            'text_block' => ['fields' => [['handle' => 'text', 'field' => ['type' => 'bard']]]],
                            'teaser' => ['fields' => [['handle' => 'box', 'field' => ['type' => 'group', 'fields' => [
                                ['handle' => 'title', 'field' => $text('Box title')],
                                ['handle' => 'body', 'field' => ['type' => 'bard']],
                            ]]]]],
                            'md_block' => ['fields' => [['handle' => 'body', 'field' => ['type' => 'markdown']]]],
                            'note' => ['fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
                            'embed' => ['fields' => [['handle' => 'embed_code', 'field' => $text('Embed')]]],
                            'link' => ['fields' => [['handle' => 'url', 'field' => $text('Link', ['input_type' => 'url'])]]],
                        ],
                    ]],
                ]],
            ],
        ],
    ];
}

function plData(): array
{
    return [
        'title' => 'Walker',
        'intro' => [plPara('Die Einleitung.')],
        'content' => [
            plPara('Erster Absatz.'),
            plSet('h1', 'heading', ['heading' => 'Was ist das?', 'heading_index' => 1]),
            plPara('Zweiter Absatz.'),
            plSet('v1', 'video', ['url' => 'https://youtu.be/abc']),
            plSet('hb1', 'hintbox', ['text' => [plPara('Hinweis.')], 'title' => 'Achtung']),
        ],
        'sections' => [[
            'id' => 's1', 'type' => 'section', 'enabled' => true, 'background' => 'blue', 'anchor' => 'start',
            'content' => [
                ['id' => 'r1', 'type' => 'text_block', 'text' => [plPara('Text im Abschnitt.')]],
                ['id' => 'r2', 'type' => 'teaser', 'box' => ['title' => 'Teaser', 'body' => [plPara('Teaser Text.')]]],
                ['id' => 'r3', 'type' => 'md_block', 'body' => "## Titel\n\nEin **fetter** Absatz."],
                ['id' => 'r4', 'type' => 'note', 'text' => "Zeile eins\nZeile zwei\n\nNeuer Absatz"],
                ['id' => 'r5', 'type' => 'embed', 'embed_code' => '<iframe src="https://x.test"></iframe>'],
                ['id' => 'r6', 'type' => 'link', 'url' => 'https://example.com'],
                ['id' => 'r7', 'type' => 'text_block', 'enabled' => false, 'text' => [plPara('Versteckt.')]],
            ],
        ]],
    ];
}

function plRaw(array $metadata = []): array
{
    return [
        'metadata' => array_merge([
            'plain_language_site' => 'default',
            'content_fields' => ['intro', 'content', 'sections'],
            'plain_language_skip_fields' => ['section.anchor'],
        ], $metadata),
    ];
}

/** A complete, well-formed answer to plData() (8 leaves). */
function plAnswer(array $override = []): array
{
    $sections = array_replace([
        1 => '<div class="intro"><p>Neue Einleitung.</p></div>',
        2 => '<p>Neu eins.</p><div class="heading" data-wf-set="h1">Was ist das jetzt?</div><h2>Neue Frage</h2><p>Neu zwei.</p><div class="hintbox" data-wf-set="hb1"><p>Neuer Hinweis.</p></div>',
        3 => '<p>Neuer Abschnittstext.</p>',
        4 => 'Neuer Teaser',
        5 => '<p>Neuer Teaser Text.</p>',
        6 => '<h2>Neuer Titel</h2><p>Ein <strong>neuer</strong> Absatz.</p>',
        7 => '<p>Eins<br>Zwei</p><p>Drei</p>',
        8 => '<p>Weiter versteckt.</p>',
    ], $override);

    $blocks = ['<h1>Neuer Titel</h1>'];

    foreach ($sections as $n => $html) {
        if ($html !== null) {
            $blocks[] = '<section data-wf-leaf="'.$n.'">'.$html.'</section>';
        }
    }

    return $blocks;
}

beforeEach(function () {
    config()->set('workflows.content_fields.intro', 'intro');
    $this->makeCollection('pages', plFields());
});

// ---- Walker ----------------------------------------------------------------

it('walks replicator > group > bard into leaves and skips non-prose', function () {
    $entry = $this->makeEntry('pages', plData());
    $walker = app(TextLeafWalker::class);

    $leaves = $walker->leaves($entry, $entry->data()->all(), ['intro', 'content', 'sections'], ['section.anchor']);

    expect(array_map(fn (TextLeaf $leaf) => $leaf->path.':'.$leaf->type, $leaves))->toBe([
        'intro:bard',
        'content:bard',
        'sections.0.content.0.text:bard',
        'sections.0.content.1.box.title:text',
        'sections.0.content.1.box.body:bard',
        'sections.0.content.2.body:markdown',
        'sections.0.content.3.text:textarea',
        'sections.0.content.6.text:bard',
    ]);

    // The Bard leaf knows which set field carries the text; the video set has none.
    expect($leaves[1]->sets)->toBe(['h1' => 'heading', 'hb1' => 'text'])
        ->and($leaves[3]->context)->toBe('box')
        ->and($leaves[2]->context)->toBe('text_block');

    expect(collect($walker->skipped())->pluck('reason', 'path')->all())->toBe([
        'sections.0.anchor' => 'configured',
        'sections.0.content.4.embed_code' => 'embed',
        'sections.0.content.5.url' => 'input_type:url',
    ]);
});

it('honours *.field skip rules and can leave disabled rows out', function () {
    config()->set('workflows.translation.plain_language.include_disabled', false);
    $entry = $this->makeEntry('pages', plData());

    $paths = array_map(
        fn (TextLeaf $leaf) => $leaf->path,
        app(TextLeafWalker::class)->leaves($entry, $entry->data()->all(), ['sections'], ['*.title', '*.anchor']),
    );

    expect($paths)->not->toContain('sections.0.content.1.box.title')
        ->and($paths)->not->toContain('sections.0.content.6.text')
        ->and($paths)->toContain('sections.0.content.1.box.body');
});

it('builds a skeleton that only differs when structure differs', function () {
    $data = ['sections' => plData()['sections']];
    $leaves = [['path' => 'sections.0.content.0.text', 'type' => 'bard']];

    $changedText = $data;
    $changedText['sections'][0]['content'][0]['text'] = [plPara('Anders.')];

    $changedStructure = $data;
    $changedStructure['sections'][0]['background'] = 'red';

    expect(TextLeafWalker::skeleton($data, $leaves))->toBe(TextLeafWalker::skeleton($changedText, $leaves))
        ->and(TextLeafWalker::skeleton($data, $leaves))->not->toBe(TextLeafWalker::skeleton($changedStructure, $leaves));
});

// ---- Sending ---------------------------------------------------------------

it('sends the title and one marked section per leaf', function () {
    $entry = $this->makeEntry('pages', plData());
    $document = app(PlainLanguageLeafDocument::class);

    $plan = $document->plan($entry, plRaw());
    $texts = $document->texts($plan, $entry);

    expect($plan['mode'])->toBe('sections')
        ->and($plan['leaves'])->toHaveCount(8)
        ->and($plan['skeleton'])->toHaveKeys(['intro', 'content', 'sections'])
        ->and($document->requestExtras($plan))->toBe(['generate_intro' => true]);

    expect($texts)->toHaveCount(9)
        ->and($texts[0])->toBe('<h1>Walker</h1>')
        ->and($texts[1])->toStartWith('<section data-wf-leaf="1" data-wf-type="bard"><div class="intro"><p>Die Einleitung.</p>')
        ->and($texts[2])->toContain('<div class="heading" data-wf-set="h1">Was ist das?</div>')
        ->and($texts[2])->toContain('<div class="hintbox" data-wf-set="hb1"><p>Hinweis.</p></div>')
        ->and($texts[2])->not->toContain('youtu')
        ->and($texts[4])->toBe('<section data-wf-leaf="4" data-wf-type="text">Teaser</section>')
        ->and($texts[6])->toContain('<h2>Titel</h2>')
        ->and($texts[7])->toBe('<section data-wf-leaf="7" data-wf-type="textarea"><p>Zeile eins<br>Zeile zwei</p><p>Neuer Absatz</p></section>');
});

it('does not ask for a generated intro without an intro leaf', function () {
    $entry = $this->makeEntry('pages', plData());
    $document = app(PlainLanguageLeafDocument::class);

    $plan = $document->plan($entry, plRaw(['content_fields' => ['sections']]));

    expect($document->requestExtras($plan))->toBe(['generate_intro' => false]);
});

// ---- Section parsing -------------------------------------------------------

it('parses sections strictly', function (array $blocks, array $errorFragments, array $warnings = []) {
    $parsed = app(PlainLanguageSections::class)->parse($blocks, [1, 2]);

    expect(count($parsed['errors']))->toBe(count($errorFragments));

    foreach ($errorFragments as $i => $fragment) {
        expect($parsed['errors'][$i])->toContain($fragment);
    }

    expect(count($parsed['warnings']))->toBe(count($warnings));
})->with([
    'complete' => [['<h1>T</h1>', '<section data-wf-leaf="1"><p>a</p></section>', '<section data-wf-leaf="2"><p>b</p></section>'], []],
    'dropped marker' => [['<section data-wf-leaf="1"><p>a</p></section>', '<p>b</p>'], ['content outside sections', 'section [2] is missing']],
    'extra content' => [['<section data-wf-leaf="1">a</section>', 'loose', '<section data-wf-leaf="2">b</section>'], ['text outside sections']],
    'unknown section' => [['<section data-wf-leaf="1">a</section><section data-wf-leaf="2">b</section><section data-wf-leaf="9">c</section>'], ['unknown section [9]']],
    'duplicate non-adjacent' => [['<section data-wf-leaf="1">a</section><section data-wf-leaf="2">b</section><section data-wf-leaf="1">c</section>'], ['appears twice']],
    'empty section' => [['<section data-wf-leaf="1">a</section><section data-wf-leaf="2"> <p></p> </section>'], ['section [2] is empty']],
    'reordered' => [['<section data-wf-leaf="2">b</section><section data-wf-leaf="1">a</section>'], [], ['different order']],
]);

it('joins adjacent pieces of one section and reads the title', function () {
    $parsed = app(PlainLanguageSections::class)->parse(
        ['<h1>Neu &amp; klar</h1>', '<section data-wf-leaf="1"><p>a</p></section>', '<section data-wf-leaf="1"><p>ä</p></section>'],
        [1],
    );

    expect($parsed['errors'])->toBe([])
        ->and($parsed['title'])->toBe('Neu & klar')
        ->and($parsed['sections'][1])->toBe("<p>a</p>\n<p>ä</p>");
});

// ---- Writers ---------------------------------------------------------------

it('writes markdown, textarea and text leaves', function () {
    $writer = app(PlainLanguageLeafWriter::class);
    $leaf = fn (string $type, string $value) => new TextLeaf('x', $type, 'x', 'x', null, [], $value);

    expect($writer->write($leaf('markdown', ''), '<h2>Titel</h2><p>Ein <strong>neuer</strong> Absatz.</p>')['value'])
        ->toBe("## Titel\n\nEin **neuer** Absatz.");

    expect($writer->write($leaf('textarea', ''), '<p>Eins<br>Zwei &amp; drei</p><p>Vier</p>')['value'])
        ->toBe("Eins\nZwei & drei\n\nVier");

    $text = $writer->write($leaf('text', 'kurz'), '<p>Ein  <b>neuer</b></p><p>Titel, der viel zu lang geworden ist</p>');

    expect($text['value'])->toBe('Ein neuer Titel, der viel zu lang geworden ist')
        ->and($text['warnings'])->toHaveCount(2);

    expect($writer->write($leaf('text', 'x'), '<p> </p>')['error'])->toContain('no content');
});

// ---- Apply -----------------------------------------------------------------

it('writes every section back into its own leaf and keeps the structure', function () {
    $entry = $this->makeEntry('pages', plData());
    $document = app(PlainLanguageLeafDocument::class);
    $plan = $document->plan($entry, plRaw());

    $result = $document->apply($entry, plAnswer(), $plan, plRaw());

    expect($result->errors)->toBe([])
        ->and($result->changed)->toBeTrue();

    $data = $result->data;

    expect($data['title'])->toBe('Neuer Titel')
        ->and($data['intro'])->toBe([plParsed('Neue Einleitung.')]);

    // Bard: sets cloned by id (only the sent field replaced), a returned <h2>
    // promoted to a heading set, headings renumbered, the video back in place.
    $content = $data['content'];
    expect($content[0])->toBe(plParsed('Neu eins.'))
        ->and($content[1]['attrs']['id'])->toBe('h1')
        ->and($content[1]['attrs']['values'])->toBe(['type' => 'heading', 'heading' => 'Was ist das jetzt?', 'heading_index' => 1])
        ->and($content[2]['attrs']['values'])->toMatchArray(['type' => 'heading', 'heading' => 'Neue Frage', 'heading_index' => 2])
        ->and($content[3])->toBe(plSet('v1', 'video', ['url' => 'https://youtu.be/abc']))
        ->and($content[4])->toBe(plParsed('Neu zwei.'))
        ->and($content[5]['attrs']['id'])->toBe('hb1')
        ->and($content[5]['attrs']['values']['title'])->toBe('Achtung')
        ->and($content[5]['attrs']['values']['text'])->toBe([plParsed('Neuer Hinweis.')]);

    $rows = $data['sections'][0]['content'];
    expect($data['sections'][0])->toMatchArray(['id' => 's1', 'type' => 'section', 'enabled' => true, 'background' => 'blue', 'anchor' => 'start'])
        ->and($rows[0]['text'])->toBe([plParsed('Neuer Abschnittstext.')])
        ->and($rows[1]['box'])->toBe(['title' => 'Neuer Teaser', 'body' => [plParsed('Neuer Teaser Text.')]])
        ->and($rows[2]['body'])->toBe("## Neuer Titel\n\nEin **neuer** Absatz.")
        ->and($rows[3]['text'])->toBe("Eins\nZwei\n\nDrei")
        ->and($rows[4])->toBe(plData()['sections'][0]['content'][4])
        ->and($rows[5])->toBe(plData()['sections'][0]['content'][5])
        ->and($rows[6])->toMatchArray(['id' => 'r7', 'enabled' => false, 'text' => [plParsed('Weiter versteckt.')]]);

    expect($data['seo'])->toBe(['title' => 'Neuer Titel', 'description' => 'Neue Einleitung.']);

    expect($result->diff['sections']['changed'])->toBeTrue()
        ->and(collect($result->diff['sections']['leaves'])->pluck('path')->all())->toContain('sections.0.content.3.text');

    // Applying the same answer to the written data changes nothing.
    $entry->data($data);
    expect($document->apply($entry, plAnswer(), $plan, plRaw())->changed)->toBeFalse();
});

it('rejects an answer with a missing section and writes nothing', function () {
    $entry = $this->makeEntry('pages', plData());
    $document = app(PlainLanguageLeafDocument::class);
    $plan = $document->plan($entry, plRaw());

    $result = $document->apply($entry, plAnswer([7 => null]), $plan, plRaw());

    expect($result->ok())->toBeFalse()
        ->and($result->data)->toBeNull()
        ->and($result->errors)->toBe(['section [7] is missing']);
});

it('rejects a plan whose leaves no longer match the blueprint', function () {
    $entry = $this->makeEntry('pages', plData());
    $document = app(PlainLanguageLeafDocument::class);
    $plan = $document->plan($entry, plRaw());
    $plan['leaves'][6]['type'] = 'text';

    $result = $document->apply($entry, plAnswer(), $plan, plRaw());

    expect($result->errors)->toBe(['leaf [sections.0.content.3.text] no longer matches the blueprint']);
});

it('never clears the content fields it writes', function () {
    $entry = $this->makeEntry('pages', plData());
    $document = app(PlainLanguageLeafDocument::class);
    $raw = plRaw(['plain_language_clear_fields' => ['sections', 'anchor_free']]);
    $plan = $document->plan($entry, $raw);

    $data = $document->apply($entry, plAnswer(), $plan, $raw)->data;

    expect($data['sections'][0]['content'][0]['text'])->toBe([plParsed('Neuer Abschnittstext.')])
        ->and($data)->toHaveKey('anchor_free', null);
});

// ---- End to end: submit + poll through a faked service ---------------------

function plFakeService(array $answer): void
{
    config()->set('workflows.translation.plain_language.url', 'https://pl.test');
    config()->set('workflows.translation.plain_language.token', 'test-token');

    Http::fake(function ($request) use ($answer) {
        if (str_contains($request->url(), '/status')) {
            return Http::response([['id' => 'job-1', 'status' => 'completed', 'output_text' => $answer]]);
        }

        return Http::response(['text_id' => 'job-1'], 202);
    });
}

it('submits sections and writes the polled answer back', function () {
    plFakeService(plAnswer());
    $this->writeWorkflow('plain', $this->minimalWorkflow('plain', plRaw()));
    $entry = $this->makeEntry('pages', plData());

    expect(app(PlainLanguageSubmission::class)->submit($entry, $entry, 'default', 'plain'))->toBe([1, null]);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), '/status')
        && $request['generate_intro'] === true
        && count($request['text']) === 9
        && str_starts_with($request['text'][3], '<section data-wf-leaf="3"'));

    $job = PlainLanguageJob::query()->sole();
    expect($job->format)->toBe('sections')
        ->and($job->plan['leaves'])->toHaveCount(8);

    $this->artisan('workflows:plain-language-poll')->assertSuccessful();

    $fresh = Entry::find($entry->id());
    expect($fresh->get('title'))->toBe('Neuer Titel')
        ->and($fresh->get('sections')[0]['content'][3]['text'])->toBe("Eins\nZwei\n\nDrei")
        ->and($fresh->get('sections')[0]['background'])->toBe('blue')
        ->and(PlainLanguageJob::query()->count())->toBe(0);
});

it('writes nothing for a rejected answer and resubmits', function () {
    plFakeService(plAnswer([3 => null]));
    $this->writeWorkflow('plain', $this->minimalWorkflow('plain', plRaw()));
    $entry = $this->makeEntry('pages', plData());

    app(PlainLanguageSubmission::class)->submit($entry, $entry, 'default', 'plain');

    $this->artisan('workflows:plain-language-poll')->assertSuccessful();

    $fresh = Entry::find($entry->id());
    expect($fresh->get('title'))->toBe('Walker')
        ->and($fresh->get('sections'))->toBe(plData()['sections']);

    // The existing retry: a second submission is in flight.
    expect(PlainLanguageJob::query()->sole()->submission)->toBe(2);
});

it('prints a diff on a dry run without writing', function () {
    plFakeService(plAnswer());
    $this->writeWorkflow('plain', $this->minimalWorkflow('plain', plRaw()));
    $entry = $this->makeEntry('pages', plData());

    app(PlainLanguageSubmission::class)->submit($entry, $entry, 'default', 'plain');

    $this->artisan('workflows:plain-language-poll', ['--dry-run' => true])
        ->expectsOutputToContain("sections.0.content.3.text (textarea): 'Zeile eins Zeile zwei Neuer Absatz' → 'Eins Zwei Drei'")
        ->assertSuccessful();

    expect(Entry::find($entry->id())->get('title'))->toBe('Walker');
});

it('still writes legacy jobs back as one document', function () {
    plFakeService(['<h1>Alt neu</h1>', '<p>Alter Text.</p>']);
    config()->set('workflows.content_fields.intro', null);
    $this->writeWorkflow('plain', $this->minimalWorkflow('plain', plRaw()));
    $entry = $this->makeEntry('pages', plData());

    app(PlainLanguageJobStore::class)->add($entry->id(), 'plain', 'job-1', 0, ['content.0']);

    $this->artisan('workflows:plain-language-poll')->assertSuccessful();

    $content = Entry::find($entry->id())->get('content');

    expect(Entry::find($entry->id())->get('title'))->toBe('Alt neu')
        ->and($content[0])->toBe(plParsed('Alter Text.'))
        ->and(collect($content)->pluck('attrs.id')->filter()->values()->all())->toContain('v1');
});

// ---- Writer validation -------------------------------------------------------

it('validates plain_language_skip_fields in the definition writer', function () {
    $writer = app(\TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter::class);
    $codes = fn (array $metadata) => collect($writer->check($this->minimalWorkflow('plain', ['metadata' => $metadata])))
        ->pluck('code')
        ->all();

    expect($codes(['plain_language_site' => 'default', 'plain_language_skip_fields' => ['anchor.anchor_id', '*.embed_code', 'intro']]))
        ->not->toContain('skip_fields_not_list')
        ->and($codes(['plain_language_site' => 'default', 'plain_language_skip_fields' => ['a.b.c']]))->toContain('skip_fields_not_list')
        ->and($codes(['plain_language_skip_fields' => ['intro']]))->toContain('skip_fields_needs_site');
});
