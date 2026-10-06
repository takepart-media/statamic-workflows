<?php

namespace TakepartMedia\Workflows\Translation;

use TakepartMedia\Workflows\Support\ContentFields;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard\Augmentor;
use Statamic\Support\Str;

/**
 * Turns an entry's raw content into a flat, ordered list of translatable
 * segments — and writes translated segments back to exactly the same places.
 *
 * Both directions live in ONE class walking ONE traversal, for the same reason
 * SnapshotDiffer shares `collect()` between `changes()` and `hasChanges()`: the
 * moment extraction and write-back are two separate walkers, their paths drift
 * apart and the translation lands in the wrong field. There is no stored plan
 * either — `inject()` rebuilds it from the same source data, so nothing has to
 * survive between requests.
 *
 * Everything the caller needs to know about the shape:
 *
 *   ['key' => 'content_sets.4.attrs.values.text.0',   // path into $entry->data()
 *    'field' => 'content_sets',                       // top-level handle, for grouping
 *    'kind' => 'html',                                // how inject() writes it back
 *    'modules' => ['fabry'],                          // block scope; [] = every module
 *    'text' => '<div data-modules="fabry">Ich bin <b>eine</b> Maus</div>']
 *
 * EVERY segment's `text` is HTML — plain fields are entity-escaped, rich text is
 * serialised through Statamic's own Bard renderer. That is deliberate: it means
 * the whole list goes out in a single API request with one `tag_handling: "html"`,
 * instead of being split into a plain batch and a rich batch. `kind` survives only
 * so `inject()` knows whether to decode entities or parse ProseMirror back.
 *
 * A block can be limited to certain modules (`in_entities`, imported into every
 * set — the blueprint field's own handle, unrelated to the wire rename below).
 * Where that list is non-empty the text ships inside a `data-modules`
 * wrapper so the receiving service can read the scope — see `envelope()` for why
 * an absent wrapper means "every module" and why nothing has to strip it again.
 *
 * Which fields are text at all is decided by the BLUEPRINT, never by guessing at
 * string leaves: a set's fields are resolved through the Statamic Fields API
 * (which also expands `import:` fieldsets), so an asset path, a URL or a term
 * handle is never mistaken for a sentence.
 *
 * One field breaks that rule for a documented reason: an SEO Pro field declares every
 * one of its subkeys as the same wrapper type, so its own config cannot tell the SEO
 * title from the canonical URL. That field alone is walked against a named list of
 * subkeys — see SEO_TEXT_KEYS.
 */
class TranslatableText
{
    /** Scalar fieldtypes whose value is a translatable string. */
    private const SCALAR_TYPES = ['text', 'textarea', 'markdown'];

    /**
     * The subkeys of an SEO Pro field that hold prose.
     *
     * A NAMED list, which is the one place this class does not let the field config
     * decide — and the exception is deliberate. SEO Pro wraps every subkey in the same
     * `seo_pro_source` fieldtype, and the inner types it declares do not separate
     * sentences from tokens: `canonical_url`, `og_type`, `twitter_handle`,
     * `site_name_separator` and `priority` are all `text` as well. Asking the fieldtype
     * would therefore translate a URL into "https://ein-Beispiel.de" and `article`
     * into `Artikel`. These five are the ones a human writes and a reader sees.
     *
     * Everything else in the field keeps its value untouched. A key that holds
     * something is still reported as skipped, so the payload preview says so rather
     * than staying quiet about it.
     */
    private const SEO_TEXT_KEYS = ['title', 'description', 'og_title', 'twitter_title', 'twitter_description'];

    /**
     * The prefix of an SEO Pro value that POINTS AT another field instead of holding
     * text of its own (`@seo:intro` = "use the intro"). Translating it would turn the
     * reference into prose and silently break the inheritance the editor set up.
     *
     * Public because the plain-language path needs the same protection for the
     * opposite reason: it DERIVES `seo.title`/`seo.description` from the rewritten
     * document (PlainLanguageSeo) instead of translating them, and overwriting a
     * reference there breaks the inheritance just as thoroughly. One spelling of the
     * prefix, two readers — a second copy of `'@seo:'` is a second thing to get wrong.
     */
    public const SEO_REFERENCE = '@seo:';

    /**
     * Entry attributes that are never translated. `title` deliberately is NOT in
     * here; the slug is derived from it downstream and is a separate decision.
     */
    private const ALWAYS_SKIP = ['slug', 'date', 'published'];

    /**
     * Bard nodes that carry no prose. Both the camelCase (ProseMirror) and the
     * snake_case spellings appear in the wild depending on the Bard version that
     * wrote the entry.
     */
    private const SKIPPED_NODES = ['image', 'horizontalRule', 'horizontal_rule', 'codeBlock', 'code_block'];

    /**
     * The set field that limits a block to certain modules is the project's own
     * handle (`content_fields.scope`) — a project without it simply never opens a
     * scope. Deliberately separate from SCOPE_ATTR below: that is part of the wire
     * contract the rewriting service reads, this is the blueprint's field.
     */

    /** The HTML attribute a scoped block's text travels in — part of the wire contract. */
    private const SCOPE_ATTR = 'data-modules';

    /** @var array<int, array{key: string, field: string, kind: string, modules: array<int, string>, text: string}> */
    private array $segments = [];

    /** @var array<int, array{key: string, reason: string}> */
    private array $skipped = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /** @var array<string, string> `key => translated text`; empty while extracting. */
    private array $translations = [];

    /**
     * Entity handles the block currently being walked is limited to. Empty means
     * "no restriction" — the same rule the front end applies when it decides
     * whether to render a set at all.
     *
     * @var array<int, string>
     */
    private array $entityScope = [];

    private bool $writing = false;

    /**
     * Every translatable segment of $entry, in document order.
     *
     * @param  array<int, string>  $excludeFields  top-level handles to leave alone (metadata.translation_exclude_fields)
     * @return array{segments: array<int, array<string, mixed>>, skipped: array<int, array<string, string>>, warnings: array<int, string>}
     */
    public function extract($entry, array $excludeFields = []): array
    {
        $this->reset(false, []);

        $data = $entry->data()->all();
        $this->walkEntry($entry, $data, $excludeFields);

        return [
            'segments' => $this->segments,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * The entry's data with every translated segment written back into its
     * original place. Returns a fresh array — the entry itself is not touched,
     * so the caller decides whether and when to save.
     *
     * Keys that are missing from $translations simply keep the source text, so a
     * partial result is safe to apply.
     *
     * @param  array<string, string>  $translations  `segment key => translated text`
     * @param  array<int, string>  $excludeFields
     * @return array<string, mixed>
     */
    public function inject($entry, array $translations, array $excludeFields = []): array
    {
        $this->reset(true, $translations);

        $data = $entry->data()->all();
        $this->walkEntry($entry, $data, $excludeFields);

        return $data;
    }

    /**
     * Anything that went sideways during the last extract()/inject() — an
     * unresolvable set, a translation that came back as a different node type.
     * Never fatal, always worth surfacing.
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Pair a translation API's positional result with the segments it came from.
     * DeepL (and every API shaped like it) returns `translations[i]` for the
     * `text[i]` it was given, so index alignment is the contract — this turns it
     * into the `key => text` map inject() wants.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<int, string>  $texts
     * @return array<string, string>
     */
    public static function mapByIndex(array $segments, array $texts): array
    {
        $map = [];

        foreach (array_values($segments) as $i => $segment) {
            if (! array_key_exists($i, $texts)) {
                continue;
            }

            $map[(string) $segment['key']] = (string) $texts[$i];
        }

        return $map;
    }

    private function reset(bool $writing, array $translations): void
    {
        $this->segments = [];
        $this->skipped = [];
        $this->warnings = [];
        $this->entityScope = [];
        $this->writing = $writing;
        $this->translations = $translations;
    }

    /**
     * Top level: the blueprint decides which handles exist and what type they
     * are. Fields the blueprint does not know are ignored entirely — an orphan
     * key in the YAML has no fieldtype and therefore no safe interpretation.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $excludeFields
     */
    private function walkEntry($entry, array &$data, array $excludeFields): void
    {
        $blueprint = method_exists($entry, 'blueprint') ? $entry->blueprint() : null;

        if ($blueprint === null) {
            $this->warn(__('workflows::messages.translation_warning_no_blueprint'));

            return;
        }

        foreach ($blueprint->fields()->all() as $handle => $field) {
            if (in_array($handle, self::ALWAYS_SKIP, true) || in_array($handle, $excludeFields, true)) {
                continue;
            }

            if (! array_key_exists($handle, $data)) {
                continue;
            }

            $before = count($this->segments);

            $this->walkField($field, $data[$handle], (string) $handle);

            // A field that is not localizable renders from the ORIGIN in the
            // publish form. Writing a translation into it succeeds but stays
            // invisible in the editor and may be overwritten on the next save.
            // Only worth saying when the field actually produced segments.
            if (! $this->writing
                && count($this->segments) > $before
                && $field->get('localizable', false) !== true) {
                $this->warn(__('workflows::messages.translation_warning_not_localizable', ['field' => $handle]));
            }
        }
    }

    /**
     * Walk a resolved set of fields (a replicator row, a grid row, a group, a
     * Bard set) against the data that belongs to it.
     *
     * @param  iterable<string, Field>  $fields
     * @param  array<string, mixed>  $data
     */
    private function walkFields(iterable $fields, array &$data, string $prefix): void
    {
        foreach ($fields as $handle => $field) {
            if (! array_key_exists($handle, $data)) {
                continue;
            }

            $this->walkField($field, $data[$handle], $prefix.$handle);
        }
    }

    private function walkField(Field $field, &$value, string $path): void
    {
        $type = $field->type();

        if (in_array($type, self::SCALAR_TYPES, true)) {
            $this->scalar($value, $path);

            return;
        }

        match ($type) {
            'bard' => $this->bard($field, $value, $path),
            'replicator' => $this->replicator($field, $value, $path),
            'grid' => $this->grid($field, $value, $path),
            'group' => $this->group($field, $value, $path),
            'seo_pro' => $this->seo($value, $path),
            default => $this->skip($path, $type),
        };
    }

    /**
     * text / textarea / markdown. Escaped on the way out so it can ride along in
     * the same `tag_handling: "html"` request as the rich text, decoded on the
     * way back. `double_encode: false` keeps an already-escaped `&amp;` intact
     * instead of turning it into `&amp;amp;`.
     *
     * The way back strips tags BEFORE decoding, and that order is the whole
     * trick: the value went out as escaped text, so HTML that genuinely belongs to
     * a markdown field arrives as `&lt;div&gt;` and `strip_tags()` cannot see it.
     * What it does remove is markup the service added — a `data-modules` wrapper,
     * or a `<strong>` an LLM decided a plain headline needed. Unlike Bard, a
     * scalar field has no parser that would drop those for us.
     */
    private function scalar(&$value, string $path): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if ($this->writing) {
            if (isset($this->translations[$path])) {
                $value = html_entity_decode(
                    strip_tags((string) $this->translations[$path]),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            }

            return;
        }

        $this->push($path, 'plain', htmlspecialchars($value, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false));
    }

    /**
     * An SEO Pro field: the handful of subkeys that carry prose, each as its own
     * segment, keyed `seo.title`, `seo.description`, …
     *
     * They go through scalar(), so they are escaped on the way out and stripped and
     * decoded on the way back like any other plain field — the SEO title of a
     * translated page is text, not markup, and a `<strong>` a service decided to add
     * has no business in a `<title>` tag.
     *
     * A non-array value means the field is switched off (`false`) or was never
     * touched (`null`); there is nothing to walk and nothing to skip.
     *
     * Which subkeys count is SEO_TEXT_KEYS — see there for why the field config
     * cannot answer that question.
     */
    private function seo(&$value, string $path): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $subPath = $path.'.'.$key;

            if (! in_array($key, self::SEO_TEXT_KEYS, true)) {
                // Only when it actually holds something: reporting two dozen empty
                // robots and sitemap keys would bury the rows worth reading.
                if ($item !== null && $item !== '' && $item !== false) {
                    $this->skip($subPath, 'seo_pro_source');
                }

                continue;
            }

            if (is_string($item) && str_starts_with($item, self::SEO_REFERENCE)) {
                $this->skip($subPath, 'seo_pro_reference');

                continue;
            }

            $this->scalar($value[$key], $subPath);
        }
    }

    /**
     * Bard comes in two storage shapes. With `save_html` it is one HTML string
     * and the whole field is a single segment. Otherwise it is a ProseMirror
     * node list, and each top-level node becomes its own segment — that keeps a
     * full sentence (with its bold/link marks) together as translation context,
     * which per-text-leaf extraction would shred.
     */
    private function bard(Field $field, &$value, string $path): void
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                return;
            }

            if ($this->writing) {
                if (isset($this->translations[$path])) {
                    $value = (string) $this->translations[$path];
                }

                return;
            }

            $this->push($path, 'html', $value);

            return;
        }

        if (! is_array($value)) {
            return;
        }

        $augmentor = $this->augmentor($field);
        $nodes = [];

        foreach ($value as $i => $node) {
            foreach ($this->bardNode($field, $augmentor, $node, "{$path}.{$i}") as $result) {
                $nodes[] = $result;
            }
        }

        // Always rebuilt, in both directions. While extracting the result is
        // identical to the input; while writing it lets a translation that came
        // back as several nodes splice in without shifting the indices of the
        // segments still to be processed.
        $value = $nodes;
    }

    /**
     * One Bard node in, the node(s) that replace it out.
     *
     * @return array<int, mixed>
     */
    private function bardNode(Field $field, Augmentor $augmentor, $node, string $path): array
    {
        if (! is_array($node)) {
            return [$node];
        }

        $type = $node['type'] ?? null;

        // A set is structure, not prose — it never goes through HTML. Its fields
        // are resolved from the set definition and walked like any other field,
        // which is what keeps `attrs.id` and the set's non-text fields intact.
        if ($type === 'set' && is_array($node['attrs']['values'] ?? null)) {
            $values = $node['attrs']['values'];
            $setHandle = (string) ($values['type'] ?? '');
            $fields = $this->setFields($field, $setHandle);

            if ($fields === null) {
                $this->skip("{$path}.attrs.values", "set:{$setHandle}");

                return [$node];
            }

            $restore = $this->enterScope($values);
            $this->walkFields($fields, $values, "{$path}.attrs.values.");
            $this->entityScope = $restore;

            $node['attrs']['values'] = $values;

            return [$node];
        }

        if ($type === null || in_array($type, self::SKIPPED_NODES, true)) {
            $this->skip($path, 'bard:'.((string) $type ?: 'unknown'));

            return [$node];
        }

        if ($this->writing) {
            $translated = $this->translations[$path] ?? null;

            if ($translated === null || trim((string) $translated) === '') {
                return [$node];
            }

            return $this->nodesFromHtml($augmentor, (string) $translated, $node, $path);
        }

        // An empty paragraph or a node holding nothing but a hard break has
        // nothing to translate and would only cost an API segment.
        if ($this->plainTextOf($node) === '') {
            return [$node];
        }

        $html = $this->renderNode($augmentor, $node, $path);

        if ($html === null || trim($html) === '') {
            return [$node];
        }

        $this->push($path, 'html', $html);

        return [$node];
    }

    private function renderNode(Augmentor $augmentor, array $node, string $path): ?string
    {
        try {
            return $augmentor->renderProsemirrorToHtml(['type' => 'doc', 'content' => [$node]]);
        } catch (\Throwable $e) {
            report($e);
            $this->warn(__('workflows::messages.translation_warning_render_failed', ['path' => $path]));

            return null;
        }
    }

    /**
     * Translated HTML back into Bard nodes.
     *
     * The expected case is one node of the same type as the original. Then only
     * its inline `content` is swapped and the ORIGINAL node object is kept, so
     * its `attrs` (heading level, text alignment, ids) survive untouched. Any
     * other outcome means the translation changed the block structure — the
     * returned nodes are used as-is and the caller gets a warning.
     *
     * Measured drift of the render→parse round trip, all of it inert and all of
     * it confined to blocks that were actually translated: nested paragraphs
     * inside list items come back with an explicit `textAlign: left`, mark attrs
     * that were `null` (a link's `target`/`title`/`rel`) lose the key, and a
     * trailing space in a text node is dropped. Statamic writes the first two
     * itself on every CP save, and HTML collapses the third — so none of it is
     * worth a scrubber that would become a second opinion on what valid Bard
     * data looks like.
     *
     * @return array<int, mixed>
     */
    private function nodesFromHtml(Augmentor $augmentor, string $html, array $original, string $path): array
    {
        try {
            $document = $augmentor->renderHtmlToProsemirror($html);
        } catch (\Throwable $e) {
            report($e);
            $this->warn(__('workflows::messages.translation_warning_parse_failed', ['path' => $path]));

            return [$original];
        }

        $nodes = array_values($document['content'] ?? []);

        if ($nodes === []) {
            $this->warn(__('workflows::messages.translation_warning_empty_result', ['path' => $path]));

            return [$original];
        }

        if (count($nodes) === 1 && ($nodes[0]['type'] ?? null) === ($original['type'] ?? null)) {
            $original['content'] = $nodes[0]['content'] ?? [];

            return [$original];
        }

        $this->warn(__('workflows::messages.translation_warning_structure_changed', ['path' => $path]));

        return $nodes;
    }

    /**
     * Replicator rows are `['type' => '<set handle>', ...fields]`. The row's own
     * set definition supplies the fieldtypes, so a `link` or `assets` field
     * inside a set is skipped for the same reason it is skipped at top level.
     */
    private function replicator(Field $field, &$value, string $path): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $setHandle = (string) ($row['type'] ?? '');
            $fields = $this->setFields($field, $setHandle);

            if ($fields === null) {
                $this->skip("{$path}.{$i}", "set:{$setHandle}");

                continue;
            }

            $restore = $this->enterScope($row);
            $this->walkFields($fields, $row, "{$path}.{$i}.");
            $this->entityScope = $restore;

            $value[$i] = $row;
        }
    }

    /** Grid rows share one field list — no set handle involved. */
    private function grid(Field $field, &$value, string $path): void
    {
        if (! is_array($value)) {
            return;
        }

        $fields = $this->setFields($field, '');

        if ($fields === null) {
            $this->skip($path, 'grid');

            return;
        }

        foreach ($value as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $this->walkFields($fields, $row, "{$path}.{$i}.");
            $value[$i] = $row;
        }
    }

    private function group(Field $field, &$value, string $path): void
    {
        if (! is_array($value)) {
            return;
        }

        $fields = $this->setFields($field, '');

        if ($fields === null) {
            $this->skip($path, 'group');

            return;
        }

        $this->walkFields($fields, $value, "{$path}.");
    }

    /**
     * The Field objects behind a set (or behind a grid/group, with an empty
     * $setHandle). Goes through the Statamic Fields API rather than reading the
     * raw config, because that is what expands `import: set_text` into actual
     * fields with actual types.
     *
     * @return \Illuminate\Support\Collection<string, Field>|null
     */
    /**
     * The fields a Bard/Replicator set declares, imports resolved — or null when the
     * field does not know that set at all.
     *
     * Public because the plain-language write-back asks the same question in the
     * other direction: a returned `<div class="hintbox">` may only become a set if
     * the field declares one, and which of its fields carries the text is decided
     * here, not guessed there (see PlainLanguageDocument).
     */
    public function setFields(Field $field, string $setHandle)
    {
        try {
            $fieldtype = $field->fieldtype();

            if ($setHandle === '') {
                return $fieldtype->fields()->all();
            }

            // Asking a replicator for an unknown set builds a Fields object from
            // a null config, so check first rather than catch afterwards.
            if (! $fieldtype->flattenedSetsConfig()->has($setHandle)) {
                return null;
            }

            return $fieldtype->fields($setHandle)->all();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * A Bard augmentor for this field, in the mode that keeps references raw:
     * `withStatamicImageUrls()` swaps in StatamicLinkMark, which passes an
     * `statamic://entry::…` href straight through. Without it the default
     * LinkMark resolves such links to a public URL — the HTML would look right
     * and the round-trip would silently turn every internal link into a hard
     * URL.
     */
    private function augmentor(Field $field): Augmentor
    {
        return (new Augmentor($field->fieldtype()))->withStatamicImageUrls();
    }

    /** Concatenated text of a ProseMirror node — used only to spot empty blocks. */
    public function plainTextOf($node): string
    {
        if (! is_array($node)) {
            return '';
        }

        if (isset($node['text']) && is_string($node['text'])) {
            return trim($node['text']);
        }

        $out = '';

        foreach ($node['content'] ?? [] as $child) {
            $out .= $this->plainTextOf($child);
        }

        return trim($out);
    }

    /**
     * Record one segment, in the shape the rest of the pipeline agrees on.
     *
     * The single place that stamps the block's module scope and applies the wire
     * envelope. Three call sites used to repeat the array literal, and a fourth
     * would have been the one that forgot the scope.
     */
    private function push(string $key, string $kind, string $text): void
    {
        $this->segments[] = [
            'key' => $key,
            'field' => $this->rootHandle($key),
            'kind' => $kind,
            'modules' => $this->entityScope,
            'text' => $this->envelope($text),
        ];
    }

    /**
     * The text as it goes on the wire.
     *
     * A block with no module restriction is sent exactly as before: the ABSENCE of
     * the wrapper is the "applies everywhere" case. That mirrors the front end's
     * own rule (no selection = render for every module) and leaves the payload —
     * and the provider's character count — untouched for the vast majority of
     * blocks, which carry no restriction at all.
     *
     * Nothing has to remove the wrapper on the way back. Bard's ProseMirror parser
     * drops an unknown `div` and hoists its children, so `nodesFromHtml()` still
     * compares the original node type against the same type and keeps the original
     * node's `attrs`. Scalar fields have no such parser, which is exactly why
     * `scalar()` strips tags before decoding.
     */
    private function envelope(string $text): string
    {
        if ($this->entityScope === []) {
            return $text;
        }

        // Comma-separated, without spaces: the receiving service splits on `,` and
        // should not have to trim. Handles never contain a comma — they are taxonomy
        // slugs.
        $scope = htmlspecialchars(implode(',', $this->entityScope), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<div '.self::SCOPE_ATTR.'="'.$scope.'">'.$text.'</div>';
    }

    /**
     * Narrow the entity scope to the block held in $values, and return the scope
     * the caller has to restore once it is done with that block.
     *
     * Empty means "every entity" and is therefore the neutral element: a nested
     * block without its own list keeps the surrounding one, and a surrounding
     * block without one imposes nothing. Two non-empty lists intersect — a block
     * for `fabry` inside a block for `fabry, nephrs` is reachable under `fabry`
     * alone, so that is what the service should be told.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string>  the previous scope
     */
    private function enterScope(array $values): array
    {
        $restore = $this->entityScope;
        $inner = $this->scopeOf($values);

        if ($inner !== []) {
            $this->entityScope = $restore === []
                ? $inner
                : array_values(array_intersect($restore, $inner));
        }

        return $restore;
    }

    /**
     * The entity handles a set's stored values are limited to.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    private function scopeOf(array $values): array
    {
        $field = ContentFields::scope();

        return self::handles($field === null ? null : ($values[$field] ?? null));
    }

    /**
     * A stored taxonomy value as a clean list of handles. A `terms` field with
     * `max_items: 1` stores a bare string instead of a list, so both shapes have to
     * be accepted — which is true of the entry's own entity field just as much as of
     * a set's `in_entities`, hence public and static.
     *
     * @return array<int, string>
     */
    public static function handles($value): array
    {
        if ($value === null) {
            return [];
        }

        return collect(is_array($value) ? $value : [$value])
            ->map(fn ($handle) => is_string($handle) ? trim($handle) : '')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function rootHandle(string $path): string
    {
        return Str::before($path, '.');
    }

    private function skip(string $path, string $reason): void
    {
        if ($this->writing) {
            return;
        }

        $this->skipped[] = ['key' => $path, 'reason' => $reason];
    }

    private function warn(string $message): void
    {
        if (! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }
}
