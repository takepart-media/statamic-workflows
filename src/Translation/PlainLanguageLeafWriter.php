<?php

namespace TakepartMedia\Workflows\Translation;

use DOMElement;
use League\HTMLToMarkdown\HtmlConverter;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard\Augmentor;
use TakepartMedia\Workflows\Content\TextLeaf;

/**
 * Turns the HTML of one returned section into the value its leaf stores.
 *
 *   bard      ProseMirror nodes through the field's own augmentor. Set wrappers
 *             (`<div class="<set>" data-wf-set="<id>">`) become CLONES of the
 *             source set with that id — only the field whose text was sent is
 *             replaced, so assets, toggles and the entity scope survive. A wrapper
 *             without a known id but naming a set the field declares becomes a new
 *             set; anything else stays prose. Sets that were never sent (no text:
 *             a video, a PDF) go back at the index they had. In a TOP-LEVEL Bard
 *             field a returned `<h2>`/`<h3>` becomes the configured heading set
 *             (`plain_language.heading_set`) and heading sets are numbered in
 *             `plain_language.heading_index_field` — both only where the field
 *             declares that set. A Bard stored as HTML (`save_html`) stays HTML.
 *   markdown  league/html-to-markdown, ATX headings, line breaks kept.
 *   textarea  paragraphs separated by a blank line, `<br>` as a line break.
 *   text      one line: tags stripped, entities decoded, whitespace collapsed.
 *
 * Every method answers `['value' => …, 'warnings' => […], 'error' => ?string]`; an
 * error means the leaf would end up empty, and the caller fails the whole answer.
 */
class PlainLanguageLeafWriter
{
    /** Seed and counter for set ids — see newId(). */
    private string $seed = '';

    private int $counter = 0;

    public function __construct(private TranslatableText $text) {}

    /**
     * @return array{value: mixed, warnings: array<int, string>, error: string|null}
     */
    public function write(TextLeaf $leaf, string $html): array
    {
        $html = trim($html);

        return match ($leaf->type) {
            'bard' => is_string($leaf->value)
                ? $this->result(trim($html), $leaf)
                : $this->bard($leaf, $html),
            'markdown' => $this->result($this->markdown($html), $leaf),
            'textarea' => $this->result($this->textarea($html), $leaf),
            default => $this->text($leaf, $html),
        };
    }

    // ---- Scalar types ------------------------------------------------------

    public function markdown(string $html): string
    {
        $converter = new HtmlConverter([
            'header_style' => 'atx',
            'strip_tags' => false,
            'hard_break' => true,
            'remove_nodes' => 'script style',
        ]);

        return trim($converter->convert($html));
    }

    public function textarea(string $html): string
    {
        $html = (string) preg_replace('~<br\s*/?>~i', "\n", $html);
        $html = (string) preg_replace('~</(p|div|li|h[1-6]|blockquote|ul|ol|section)>~i', "\n\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = array_map(fn ($line) => trim((string) preg_replace('~[ \t]+~u', ' ', $line)), explode("\n", $text));
        $text = implode("\n", $lines);

        return trim((string) preg_replace("~\n{3,}~", "\n\n", $text));
    }

    public function plain(string $html): string
    {
        // Block ends and line breaks separate words; without the space two
        // paragraphs would run together ("neuerTitel").
        $html = (string) preg_replace('~</(p|div|li|h[1-6]|blockquote)>|<br\s*/?>~i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('~\s+~u', ' ', $text));
    }

    /**
     * @return array{value: mixed, warnings: array<int, string>, error: string|null}
     */
    private function text(TextLeaf $leaf, string $html): array
    {
        $value = $this->plain($html);
        $result = $this->result($value, $leaf);

        $blocks = preg_match_all('~<(p|div|li|h[1-6])\b~i', $html);

        if ($blocks > 1) {
            $result['warnings'][] = "{$leaf->path}: single-line field came back as {$blocks} blocks, joined";
        }

        $before = mb_strlen(trim((string) $leaf->value));

        if ($before > 0 && mb_strlen($value) > 3 * $before) {
            $result['warnings'][] = "{$leaf->path}: single-line field grew from {$before} to ".mb_strlen($value).' characters';
        }

        return $result;
    }

    /**
     * @return array{value: mixed, warnings: array<int, string>, error: string|null}
     */
    private function result($value, TextLeaf $leaf, array $warnings = []): array
    {
        $empty = $value === null || $value === [] || (is_string($value) && trim(strip_tags($value)) === '' && ! str_contains($value, '<img'));

        return [
            'value' => $value,
            'warnings' => $warnings,
            'error' => $empty ? "{$leaf->path}: section produced no content" : null,
        ];
    }

    // ---- Bard --------------------------------------------------------------

    /**
     * @return array{value: mixed, warnings: array<int, string>, error: string|null}
     */
    private function bard(TextLeaf $leaf, string $html): array
    {
        $field = $leaf->fieldObject;

        if (! $field instanceof Field) {
            return ['value' => null, 'warnings' => [], 'error' => "{$leaf->path}: no blueprint field"];
        }

        $warnings = [];
        $this->seed = $leaf->path;
        $this->counter = 0;
        $source = $this->setsById((array) $leaf->value);
        $used = [];
        $nodes = [];
        $topLevel = $leaf->isTopLevel();

        foreach ($this->chunks($html) as $chunk) {
            if ($chunk['set'] !== null) {
                $node = $this->setFromWrapper($field, $leaf, $chunk, $source, $used, $warnings);

                // [] = a known set that came back empty: dropped, with a warning.
                if ($node !== null) {
                    if ($node !== []) {
                        $nodes[] = $node;
                    }

                    continue;
                }
            }

            foreach ($this->prose($field, $chunk['html'], $topLevel) as $node) {
                $nodes[] = $node;
            }
        }

        if ($nodes === []) {
            return ['value' => [], 'warnings' => $warnings, 'error' => "{$leaf->path}: section produced no content"];
        }

        $nodes = $this->withUnsentSets((array) $leaf->value, $leaf->sets, $nodes);

        if ($topLevel) {
            $nodes = $this->numberHeadings($field, $nodes);
        }

        return ['value' => array_values($nodes), 'warnings' => $warnings, 'error' => null];
    }

    /**
     * The set a wrapper stands for, or null to treat its content as prose.
     *
     * @param  array{set: string|null, id: string|null, html: string}  $chunk
     * @param  array<string, array<string, mixed>>  $source
     * @param  array<string, bool>  $used
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>|null
     */
    private function setFromWrapper(Field $field, TextLeaf $leaf, array $chunk, array $source, array &$used, array &$warnings): ?array
    {
        $id = $chunk['id'];

        if ($id !== null && isset($source[$id], $leaf->sets[$id])) {
            $node = $source[$id];
            $handle = (string) ($node['attrs']['values']['type'] ?? '');
            $target = $this->setField($field, $handle, $leaf->sets[$id]);

            if ($target === null) {
                return null;
            }

            $value = $this->valueFor($target, $chunk['html'], $node['attrs']['values'][$target->handle()] ?? null);

            if ($this->isEmpty($value)) {
                $warnings[] = "{$leaf->path}: set [{$id}] came back empty and was dropped";

                return [];
            }

            $node['attrs']['values'][$target->handle()] = $value;

            // The service split one set into two: the second is a copy, so it needs
            // an id of its own.
            if (isset($used[$id])) {
                $node['attrs']['id'] = $this->newId($chunk['html']);
            }

            $used[$id] = true;

            return $node;
        }

        if ($chunk['set'] === null) {
            return null;
        }

        return $this->newSet($field, $chunk['set'], $chunk['html']);
    }

    /**
     * A new set of type $handle with $html in its first text field, or null when
     * the field declares no such set or it has no text field.
     *
     * @return array<string, mixed>|null
     */
    private function newSet(Field $field, string $handle, string $html): ?array
    {
        $target = $this->setField($field, $handle, null);

        if ($target === null) {
            return null;
        }

        $value = $this->valueFor($target, $html, null);

        if ($this->isEmpty($value)) {
            return null;
        }

        return [
            'type' => 'set',
            'attrs' => [
                'id' => $this->newId($handle.$html),
                'values' => ['type' => $handle, $target->handle() => $value],
            ],
        ];
    }

    /**
     * The field of set $handle named $preferred, or its first text-capable field.
     */
    private function setField(Field $field, string $handle, ?string $preferred): ?Field
    {
        $fields = $this->text->setFields($field, $handle);

        if ($fields === null) {
            return null;
        }

        if ($preferred !== null && $fields->has($preferred)) {
            return $fields->get($preferred);
        }

        foreach ($fields as $candidate) {
            if (in_array($candidate->type(), ['bard', 'markdown', 'textarea', 'text'], true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * HTML in the shape $target stores. $original decides the Bard shape: a Bard
     * stored as HTML stays HTML.
     *
     * @return array<int, mixed>|string|null
     */
    private function valueFor(Field $target, string $html, $original)
    {
        return match ($target->type()) {
            'bard' => is_string($original) ? trim($html) : $this->paragraphs($this->parse($target, $html)),
            'markdown' => $this->markdown($html),
            'textarea' => $this->textarea($html),
            default => $this->plain($html),
        };
    }

    /**
     * Prose between the wrappers, as Bard nodes. A loose text node becomes a
     * paragraph; in a top-level field a heading becomes the heading set.
     *
     * @return array<int, mixed>
     */
    private function prose(Field $field, string $html, bool $topLevel): array
    {
        $nodes = [];
        $headingSet = $topLevel ? $this->headingSetHandle() : null;

        foreach ($this->paragraphs($this->parse($field, $html)) as $node) {
            if ($headingSet !== null && is_array($node) && ($node['type'] ?? null) === 'heading') {
                $text = $this->text->plainTextOf($node);
                $set = $text === '' ? null : $this->newSet($field, $headingSet, htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                $nodes[] = $set ?? $node;

                continue;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<int, mixed>
     */
    private function paragraphs(array $nodes): array
    {
        return array_map(
            fn ($node) => is_array($node) && ($node['type'] ?? null) === 'text'
                ? ['type' => 'paragraph', 'content' => [$node]]
                : $node,
            $nodes,
        );
    }

    /**
     * Sets the service never saw — they had no text — back at their source index.
     * See LegacyPlainLanguageDocument::withUntranslatableSets() for why the index
     * is the only anchor there is.
     *
     * @param  array<int, mixed>  $original
     * @param  array<string, string>  $sent
     * @param  array<int, mixed>  $nodes
     * @return array<int, mixed>
     */
    private function withUnsentSets(array $original, array $sent, array $nodes): array
    {
        foreach (array_values($original) as $index => $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'set') {
                continue;
            }

            $id = (string) ($node['attrs']['id'] ?? '');

            if ($id !== '' && isset($sent[$id])) {
                continue;
            }

            array_splice($nodes, min($index, count($nodes)), 0, [$node]);
        }

        return $nodes;
    }

    /**
     * Number the heading sets 1, 2, 3 … in document order — the numbering the table
     * of contents prints. Only where the field declares the heading set and that set
     * has the index field.
     *
     * @param  array<int, mixed>  $nodes
     * @return array<int, mixed>
     */
    private function numberHeadings(Field $field, array $nodes): array
    {
        $set = $this->headingSetHandle();
        $indexField = $this->headingIndexField();

        if ($set === null || $indexField === null) {
            return $nodes;
        }

        $fields = $this->text->setFields($field, $set);

        if ($fields === null || ! $fields->has($indexField)) {
            return $nodes;
        }

        $number = 0;

        foreach ($nodes as $i => $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'set' && ($node['attrs']['values']['type'] ?? null) === $set) {
                $nodes[$i]['attrs']['values'][$indexField] = ++$number;
            }
        }

        return $nodes;
    }

    /**
     * An id for a set the answer created. Derived from the leaf, the position and
     * the content rather than random, so writing the same answer twice yields the
     * same data — a re-poll is then recognised as "unchanged" and not saved again.
     * Same length as Statamic's own RowId.
     */
    private function newId(string $salt): string
    {
        return substr(sha1($this->seed.'|'.(++$this->counter).'|'.$salt), 0, 8);
    }

    private function headingSetHandle(): ?string
    {
        $handle = config('workflows.translation.plain_language.heading_set', 'heading');

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    private function headingIndexField(): ?string
    {
        $handle = config('workflows.translation.plain_language.heading_index_field', 'heading_index');

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * The section split into set wrappers and the prose between them. Adjacent
     * wrappers with the same id (or the same class, when there is no id) are one
     * set the service answered in two parts.
     *
     * @return array<int, array{set: string|null, id: string|null, html: string}>
     */
    private function chunks(string $html): array
    {
        [$document, $root] = PlainLanguageSections::load($html);

        if ($root === null) {
            return [['set' => null, 'id' => null, 'html' => $html]];
        }

        $chunks = [];

        foreach ($root->childNodes as $node) {
            $last = $chunks === [] ? null : count($chunks) - 1;
            [$set, $id] = $this->wrapper($node);

            if ($set !== null || $id !== null) {
                $inner = PlainLanguageSections::innerHtml($document, $node);

                if ($last !== null && $chunks[$last]['set'] === $set && $chunks[$last]['id'] === $id) {
                    $chunks[$last]['html'] .= "\n".$inner;

                    continue;
                }

                $chunks[] = ['set' => $set, 'id' => $id, 'html' => $inner];

                continue;
            }

            $outer = (string) $document->saveHTML($node);

            if (trim($outer) === '') {
                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE) {
                $outer = '<p>'.$outer.'</p>';
            }

            if ($last !== null && $chunks[$last]['set'] === null && $chunks[$last]['id'] === null) {
                $chunks[$last]['html'] .= "\n".$outer;

                continue;
            }

            $chunks[] = ['set' => null, 'id' => null, 'html' => $outer];
        }

        return $chunks;
    }

    /**
     * [set handle, set id] of a wrapper `div`, or [null, null] for anything else.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function wrapper($node): array
    {
        if (! $node instanceof DOMElement || strtolower($node->nodeName) !== 'div') {
            return [null, null];
        }

        $class = trim($node->getAttribute('class'));
        $id = trim($node->getAttribute('data-wf-set'));

        return [
            $class === '' ? null : explode(' ', $class)[0],
            $id === '' ? null : $id,
        ];
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<string, array<string, mixed>>
     */
    private function setsById(array $nodes): array
    {
        $sets = [];

        foreach ($nodes as $node) {
            $id = is_array($node) && ($node['type'] ?? null) === 'set' ? (string) ($node['attrs']['id'] ?? '') : '';

            if ($id !== '') {
                $sets[$id] = $node;
            }
        }

        return $sets;
    }

    /**
     * @return array<int, mixed>
     */
    private function parse(Field $field, string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        try {
            $document = (new Augmentor($field->fieldtype()))->withStatamicImageUrls()->renderHtmlToProsemirror($html);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return array_values($document['content'] ?? []);
    }

    private function isEmpty($value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }
}
