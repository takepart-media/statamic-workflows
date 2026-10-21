<?php

namespace TakepartMedia\Workflows\Translation;

use DOMElement;
use Illuminate\Support\Arr;
use Statamic\Facades\Markdown;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard\Augmentor;
use TakepartMedia\Workflows\Content\ContentFieldResolver;
use TakepartMedia\Workflows\Content\TextLeaf;
use TakepartMedia\Workflows\Content\TextLeafWalker;
use TakepartMedia\Workflows\Support\ContentFields;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * The plain-language round trip, per text leaf.
 *
 * The service rewrites a DOCUMENT: it merges, splits and shortens, so there is no
 * reply per sent text. What it does keep is structure we mark — so every piece of
 * prose in the workflow's content fields (a TextLeaf: a Bard field, a Markdown,
 * Textarea or Text field, wherever it sits in Replicator / Grid / Group rows) goes
 * out as its own `<section data-wf-leaf="N">`, and the answer for section N is
 * written back into exactly that leaf. Everything that is not a leaf — row ids,
 * set types, assets, toggles, a background colour — is never sent and never
 * touched: it is restored from the source's raw values (the plan's skeleton).
 *
 *   plan()   what is sent: content fields, leaves (path, type, hash) and the
 *            skeleton (raw values of the content fields). Stored with the job.
 *   texts()  the wire texts: `<h1>title</h1>`, then one section per leaf.
 *   apply()  the answer back into the entry data. STRICT: a missing, unknown,
 *            duplicated or empty section, content outside the sections, or a
 *            structure that no longer matches the skeleton rejects the answer —
 *            nothing is written, and PollPlainLanguageCommand's retry takes over.
 *
 * Jobs submitted before this format (`format` = null) are written back by
 * LegacyPlainLanguageDocument.
 */
class PlainLanguageLeafDocument
{
    public const FORMAT = PlainLanguageJob::FORMAT_SECTIONS;

    /** Fieldtypes whose empty value is `[]` rather than `''` — see emptyValueFor(). */
    private const LIST_FIELD_TYPES = [
        'bard', 'replicator', 'grid', 'assets', 'entries', 'terms', 'taxonomy',
        'users', 'sites', 'table', 'array', 'checkboxes',
    ];

    public function __construct(
        private TextLeafWalker $walker,
        private PlainLanguageLeafWriter $writer,
        private PlainLanguageSections $sections,
        private PlainLanguageSeo $seo,
        private WorkflowResolver $workflows,
    ) {}

    /** The entry field the rewritten `<h1>` lands in: `content_fields.title`. */
    public static function titleField(): string
    {
        return ContentFields::title();
    }

    /**
     * Fields the rewrite never sends, whatever else is configured: the SEO field
     * (its texts are DERIVED from the answer — see PlainLanguageSeo) plus the
     * workflow's `translation_exclude_fields`.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public function excludeFields(array $raw): array
    {
        return array_values(array_unique(array_merge(
            $this->workflows->translationExcludeFields($raw),
            [ContentFields::seo(), self::titleField()],
        )));
    }

    /**
     * The workflow's content fields for this entry (ContentFieldResolver), minus
     * the excluded ones and top-level skip fields.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public function contentFields($entry, array $raw): array
    {
        $exclude = $this->excludeFields($raw);

        return array_values(array_filter(
            ContentFieldResolver::for($entry, $raw),
            fn (string $handle) => ! in_array($handle, $exclude, true),
        ));
    }

    /**
     * What a rewrite of $source sends, and what its answer is written into.
     *
     * @param  array<string, mixed>  $raw
     * @return array{mode: string, fields: array<int, string>, skip: array<int, string>, title: bool, leaves: array<int, array<string, mixed>>, skeleton: array<string, mixed>, skipped: array<int, array{path: string, reason: string}>}
     */
    public function plan($source, array $raw): array
    {
        $fields = $this->contentFields($source, $raw);
        $skip = $this->workflows->plainLanguageSkipFields($raw);
        $data = $source->data()->all();

        $leaves = $this->walker->leaves($source, $data, $fields, $skip);
        $skipped = $this->walker->skipped();
        $intro = ContentFields::intro();

        $planned = [];

        foreach (array_values($leaves) as $i => $leaf) {
            $planned[] = ['n' => $i + 1] + $leaf->toArray() + [
                'intro' => $intro !== null && $leaf->path === $intro,
            ];
        }

        return [
            'mode' => self::FORMAT,
            'fields' => $fields,
            'skip' => $skip,
            'title' => trim((string) $source->value(self::titleField())) !== '',
            'leaves' => $planned,
            'skeleton' => array_intersect_key($data, array_flip($fields)),
            'skipped' => $skipped,
        ];
    }

    /**
     * Request fields that depend on the plan: `generate_intro` only when an intro
     * leaf actually goes out — otherwise the flag would ask the service to write an
     * introduction into a section that does not exist.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function requestExtras(array $plan): array
    {
        return [
            'generate_intro' => collect($plan['leaves'] ?? [])->contains(fn ($leaf) => ($leaf['intro'] ?? false) === true),
        ];
    }

    /**
     * The wire texts: the title as `<h1>`, then one section per leaf.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, string>
     */
    public function texts(array $plan, $source): array
    {
        $texts = [];

        if ($plan['title'] ?? false) {
            $texts[] = '<h1>'.$this->escape((string) $source->value(self::titleField())).'</h1>';
        }

        [$leaves] = $this->leavesFor($source, $plan, $source->data()->all());

        foreach ($plan['leaves'] ?? [] as $planned) {
            $leaf = $leaves[(int) $planned['n']] ?? null;

            if ($leaf === null) {
                continue;
            }

            $inner = $this->render($leaf);

            if (($planned['intro'] ?? false) && ($intro = ContentFields::intro()) !== null) {
                $inner = '<div class="'.$this->escape($intro).'">'.$inner.'</div>';
            }

            $texts[] = '<section data-wf-leaf="'.(int) $planned['n'].'" data-wf-type="'.$leaf->type.'">'
                .$this->envelope($leaf->modules, $inner)
                .'</section>';
        }

        return $texts;
    }

    /**
     * The answer written into $entry's data — or the reasons it cannot be.
     *
     * The content fields are first reset to the skeleton (the source's raw values at
     * submit time), then every leaf gets its section. The entry itself is not
     * touched; the caller saves `data` when the result is ok() and changed.
     *
     * @param  array<int, string>  $blocks  the answer's HTML blocks
     * @param  array<string, mixed>  $plan  what was sent (plan())
     * @param  array<string, mixed>  $raw  the workflow definition
     */
    public function apply($entry, array $blocks, array $plan, array $raw = []): PlainLanguageApplyResult
    {
        if (($plan['mode'] ?? null) !== self::FORMAT || ! is_array($plan['leaves'] ?? null)) {
            return PlainLanguageApplyResult::rejected(['job has no usable plan']);
        }

        $planned = collect($plan['leaves'])->keyBy(fn ($leaf) => (int) $leaf['n'])->all();
        $parsed = $this->sections->parse($blocks, array_keys($planned));
        $warnings = $parsed['warnings'];

        if ($parsed['errors'] !== []) {
            return PlainLanguageApplyResult::rejected($parsed['errors'], $warnings);
        }

        $skeleton = (array) ($plan['skeleton'] ?? []);
        [$leaves, $missing] = $this->leavesFor($entry, $plan, $skeleton);

        if ($missing !== []) {
            return PlainLanguageApplyResult::rejected(
                array_map(fn ($path) => "leaf [{$path}] no longer matches the blueprint", $missing),
                $warnings,
            );
        }

        $current = $entry->data()->all();
        $data = array_replace($current, $skeleton);
        $errors = [];
        $written = [];

        foreach ($planned as $n => $leafPlan) {
            $leaf = $leaves[$n];
            $result = $this->writer->write($leaf, $this->prepare($parsed['sections'][$n], (bool) ($leafPlan['intro'] ?? false)));

            array_push($warnings, ...$result['warnings']);

            if ($result['error'] !== null) {
                $errors[] = $result['error'];

                continue;
            }

            Arr::set($data, $leaf->path, $result['value']);
            $written[] = ['type' => $leaf->type, 'value' => $result['value'], 'intro' => (bool) ($leafPlan['intro'] ?? false)];
        }

        $errors = array_merge($errors, $this->structureErrors($skeleton, $data, array_values($planned)));

        if ($errors !== []) {
            return PlainLanguageApplyResult::rejected($errors, $warnings);
        }

        if ($parsed['title'] !== null && ($plan['title'] ?? false)) {
            $data[self::titleField()] = $parsed['title'];
        } elseif ($plan['title'] ?? false) {
            $warnings[] = 'answer carried no leading <h1>; title kept';
        }

        $seo = $this->seo->mergedWith(
            (string) ($data[self::titleField()] ?? $entry->value(self::titleField()) ?? ''),
            $this->seo->descriptionFromLeaves($written),
            $data[PlainLanguageSeo::field()] ?? null,
            $this->originSeo($entry),
        );

        if ($seo !== null) {
            $data[PlainLanguageSeo::field()] = $seo;
        }

        $data = $this->clear($entry, $data, $this->workflows->plainLanguageClearFields($raw), $this->protectedFields($plan));

        return new PlainLanguageApplyResult(
            $data,
            $this->diff($current, $data, $plan),
            [],
            $warnings,
            $data != $current,
        );
    }

    /**
     * The fields a clear list may never empty: the title and every content field
     * the rewrite writes — a misconfiguration there would destroy the answer.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, string>
     */
    public function protectedFields(array $plan): array
    {
        return array_values(array_unique(array_merge([self::titleField()], (array) ($plan['fields'] ?? []))));
    }

    /**
     * The empty own value that shadows a field: `[]` for list-shaped fieldtypes,
     * `''` otherwise, `null` for a handle no blueprint declares. See
     * LegacyPlainLanguageDocument::clear() for why shadowing beats removing.
     *
     * @return array<int, mixed>|string|null
     */
    public function emptyValueFor($entry, string $handle)
    {
        $field = $entry->blueprint()?->fields()->all()->get($handle);

        if (! $field instanceof Field) {
            return null;
        }

        return in_array($field->type(), self::LIST_FIELD_TYPES, true) ? [] : '';
    }

    /**
     * The leaves of $data matched to the plan by path and type, keyed by section
     * number — plus the paths that no longer match.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $data
     * @return array{0: array<int, TextLeaf>, 1: array<int, string>}
     */
    public function leavesFor($entry, array $plan, array $data): array
    {
        $walked = collect($this->walker->leaves($entry, $data, (array) ($plan['fields'] ?? []), (array) ($plan['skip'] ?? [])))
            ->keyBy(fn (TextLeaf $leaf) => $leaf->path);

        $leaves = [];
        $missing = [];

        foreach ($plan['leaves'] ?? [] as $planned) {
            $leaf = $walked->get((string) $planned['path']);

            if (! $leaf instanceof TextLeaf || $leaf->type !== $planned['type']) {
                $missing[] = (string) $planned['path'];

                continue;
            }

            // The plan's set mapping wins: it records which set field was SENT.
            if ($leaf->type === 'bard' && is_array($planned['sets'] ?? null)) {
                $leaf = new TextLeaf($leaf->path, $leaf->type, $leaf->handle, $leaf->field, $leaf->context, $leaf->modules, $leaf->value, $leaf->fieldObject, $planned['sets']);
            }

            $leaves[(int) $planned['n']] = $leaf;
        }

        return [$leaves, $missing];
    }

    // ---- Sending -----------------------------------------------------------

    /** One leaf as HTML. */
    private function render(TextLeaf $leaf): string
    {
        if ($leaf->type !== 'bard' || is_string($leaf->value)) {
            return $this->renderValue($leaf->type, $leaf->fieldObject, $leaf->value);
        }

        $html = '';
        $prose = [];
        $flush = function () use (&$prose, &$html, $leaf) {
            if ($prose !== []) {
                $html .= $this->renderBard($leaf->fieldObject, $prose);
                $prose = [];
            }
        };

        foreach ((array) $leaf->value as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) !== 'set') {
                $prose[] = $node;

                continue;
            }

            $flush();

            $id = (string) ($node['attrs']['id'] ?? '');

            // Text-free sets are not sent; apply() puts them back by position.
            if ($id === '' || ! isset($leaf->sets[$id])) {
                continue;
            }

            $values = (array) ($node['attrs']['values'] ?? []);
            $handle = (string) ($values['type'] ?? '');
            $textField = $leaf->sets[$id];
            $target = $leaf->fieldObject === null ? null : app(TranslatableText::class)->setFields($leaf->fieldObject, $handle)?->get($textField);
            $scope = ContentFields::scope();

            $html .= '<div class="'.$this->escape($handle).'" data-wf-set="'.$this->escape($id).'">'
                .$this->envelope(
                    TranslatableText::handles($scope === null ? null : ($values[$scope] ?? null)),
                    $this->renderValue($target?->type() ?? 'text', $target, $values[$textField] ?? null),
                )
                .'</div>';
        }

        $flush();

        return $html;
    }

    private function renderValue(string $type, ?Field $field, $value): string
    {
        return match ($type) {
            'bard' => is_string($value) ? $value : ($field === null ? '' : $this->renderBard($field, (array) $value)),
            'markdown' => $this->renderMarkdown($field, (string) $value),
            'textarea' => $this->renderTextarea((string) $value),
            default => $this->escape((string) $value),
        };
    }

    /** @param  array<int, mixed>  $nodes */
    private function renderBard(?Field $field, array $nodes): string
    {
        if ($field === null || $nodes === []) {
            return '';
        }

        try {
            // StatamicLinkMark / statamic:// image urls: references stay raw, so the
            // round trip does not turn internal links into hard URLs.
            return (new Augmentor($field->fieldtype()))
                ->withStatamicImageUrls()
                ->renderProsemirrorToHtml(['type' => 'doc', 'content' => array_values($nodes)]);
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    private function renderMarkdown(?Field $field, string $value): string
    {
        $parser = $field?->get('parser') ?: 'default';

        return trim(Markdown::parser($parser)->parse($value));
    }

    private function renderTextarea(string $value): string
    {
        $paragraphs = preg_split('~\R\s*\R~u', trim($value)) ?: [];

        return implode('', array_map(
            fn (string $paragraph) => '<p>'.implode('<br>', array_map(fn ($line) => $this->escape(trim($line)), preg_split('~\R~u', trim($paragraph)) ?: [])).'</p>',
            array_filter($paragraphs, fn ($p) => trim($p) !== ''),
        ));
    }

    /**
     * A scoped block's `data-modules` wrapper — see TranslatableText::envelope().
     *
     * @param  array<int, string>  $modules
     */
    private function envelope(array $modules, string $html): string
    {
        if ($modules === []) {
            return $html;
        }

        return '<div data-modules="'.$this->escape(implode(',', $modules)).'">'.$html.'</div>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false);
    }

    // ---- Writing back ------------------------------------------------------

    /**
     * A section's inner HTML without the markers that only travel: every
     * `data-modules` envelope and, in the intro section, the intro wrapper.
     */
    private function prepare(string $html, bool $intro): string
    {
        [$document, $root] = PlainLanguageSections::load($html);

        if ($root === null) {
            return $html;
        }

        $introClass = ContentFields::intro();
        $xpath = new \DOMXPath($document);

        foreach (iterator_to_array($xpath->query('.//div[@data-modules]', $root)) as $wrapper) {
            $this->unwrap($wrapper);
        }

        if ($intro && $introClass !== null) {
            foreach (iterator_to_array($root->childNodes) as $node) {
                if ($node instanceof DOMElement && strtolower($node->nodeName) === 'div'
                    && explode(' ', trim($node->getAttribute('class')))[0] === $introClass) {
                    $this->unwrap($node);
                }
            }
        }

        return PlainLanguageSections::innerHtml($document, $root);
    }

    private function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;

        if ($parent === null) {
            return;
        }

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }

    /**
     * Whether the new content fields still have the skeleton's shape: outside the
     * leaf paths nothing may differ, and every leaf path holds a value of its type.
     *
     * @param  array<string, mixed>  $skeleton
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $planned
     * @return array<int, string>
     */
    private function structureErrors(array $skeleton, array $data, array $planned): array
    {
        $after = array_intersect_key($data, $skeleton);

        if (TextLeafWalker::skeleton($skeleton, $planned) != TextLeafWalker::skeleton($after, $planned)) {
            return ['content structure changed outside the text leaves'];
        }

        $errors = [];

        foreach ($planned as $leaf) {
            $path = (string) $leaf['path'];

            if (! Arr::has($data, $path)) {
                $errors[] = "leaf [{$path}] is missing after the write";

                continue;
            }

            $before = Arr::get($skeleton, $path);
            $value = Arr::get($data, $path);
            $sameShape = $leaf['type'] === 'bard' ? is_array($before) === is_array($value) && (is_array($value) || is_string($value)) : is_string($value);

            if (! $sameShape) {
                $errors[] = "leaf [{$path}] changed its value type";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $handles
     * @param  array<int, string>  $protected
     * @return array<string, mixed>
     */
    private function clear($entry, array $data, array $handles, array $protected): array
    {
        foreach ($handles as $handle) {
            if (! in_array($handle, $protected, true)) {
                $data[$handle] = $this->emptyValueFor($entry, $handle);
            }
        }

        return $data;
    }

    /** The root's own `seo` value, or null when $entry is the root. */
    private function originSeo($entry)
    {
        $root = method_exists($entry, 'root') ? $entry->root() : null;

        if ($root === null || $root->id() === $entry->id()) {
            return null;
        }

        return $root->data()->get(PlainLanguageSeo::field());
    }

    // ---- Diff --------------------------------------------------------------

    /**
     * What the write changes, per field, as short plain-text excerpts.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function diff(array $before, array $after, array $plan): array
    {
        $diff = [];

        foreach ((array) ($plan['fields'] ?? []) as $field) {
            $leaves = [];

            foreach ($plan['leaves'] ?? [] as $leaf) {
                if (($leaf['field'] ?? null) !== $field) {
                    continue;
                }

                $from = $this->excerpt(Arr::get($before, $leaf['path']));
                $to = $this->excerpt(Arr::get($after, $leaf['path']));

                if ($from !== $to) {
                    $leaves[] = ['path' => $leaf['path'], 'type' => $leaf['type'], 'from' => $from, 'to' => $to];
                }
            }

            $diff[$field] = [
                'changed' => ($before[$field] ?? null) != ($after[$field] ?? null),
                'leaves' => $leaves,
            ];
        }

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (isset($diff[$key]) || ($before[$key] ?? null) == ($after[$key] ?? null)) {
                continue;
            }

            $diff[$key] = [
                'changed' => true,
                'from' => $this->excerpt($before[$key] ?? null),
                'to' => $this->excerpt($after[$key] ?? null),
            ];
        }

        return $diff;
    }

    private function excerpt($value): string
    {
        if ($value === null) {
            return '—';
        }

        $text = trim((string) preg_replace('~\s+~u', ' ', $this->plainOf($value)));

        if ($text === '' && is_array($value)) {
            return $value === [] ? '[empty]' : '['.count($value).' item(s)]';
        }

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79).'…' : $text;
    }

    private function plainOf($value): string
    {
        if (is_string($value)) {
            return html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], [' ', ' '], $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (! is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (in_array($key, ['id', 'type', 'marks', 'attrs'], true) && ! is_array($item)) {
                continue;
            }

            if ($key === 'attrs' && is_array($item)) {
                $out[] = $this->plainOf($item['values'] ?? []);

                continue;
            }

            $out[] = $this->plainOf($item);
        }

        return implode(' ', array_filter($out, fn ($part) => trim($part) !== ''));
    }
}
