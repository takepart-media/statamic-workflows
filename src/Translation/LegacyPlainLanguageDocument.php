<?php

namespace TakepartMedia\Workflows\Translation;

use TakepartMedia\Workflows\Support\ContentFields;
use DOMNode;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard\Augmentor;
use Statamic\Fieldtypes\RowId;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * LEGACY. Only still used to write back jobs submitted before the leaf format
 * existed (`workflow_plain_language_jobs.format` = null). New submissions go through
 * PlainLanguageLeafDocument. Kept unchanged so a rewrite in flight during the update
 * lands exactly as it would have before; delete once no such job can exist any more.
 *
 * How a plain-language result maps onto an entry — both directions, in one class.
 *
 * This is where the plain-language path parts ways with DeepL. DeepL returns one
 * translation per text, so TranslatableText can write each answer back into the exact
 * field it came from — the entry's structure is never rebuilt, only its leaf strings
 * are replaced. The plain-language service returns a DOCUMENT: it merges, splits,
 * reorders and omits, and answers 38 sent texts with 24 blocks. There is no block that
 * corresponds to `content_sets.2`, so a per-field write-back is not merely
 * unimplemented here — it has nothing to attach to.
 *
 * So the result replaces the entry's content wholesale, and this class owns both halves
 * of that decision, because they only make sense together:
 *
 *   `texts()`  marks structure on the way OUT. The title gets an `<h1>`, the intro a
 *              `<div class="intro">`, and every segment that belongs to a set a
 *              `<div class="<set handle>">` wrapper. Without that the service receives
 *              a heading as bare prose — it cannot know that "Was ist eine
 *              Nierenschwäche?" is a `heading` set with its own numbering, and the
 *              answer comes back as flat text.
 *   `apply()`  reads those markers back. A wrapper becomes the set it names, an `<h1>`
 *              becomes the title FIELD (leaving it in the content would print the
 *              heading twice), the intro wrapper becomes the intro FIELD (same reason:
 *              left in the content it would print the introduction twice, once in
 *              German above it), and the sets the service never saw — video, PDF,
 *              code — go back at the index they had in the source. See INTRO_FIELD.
 *
 * What a set may become is never guessed: the Bard field's own set config decides
 * whether a handle exists and which of its fields carries the text (via
 * TranslatableText::setFields). An unknown class keeps its content as plain prose
 * rather than inventing a set.
 *
 * The service is not obliged to return the wrappers. If it drops them the result is
 * simply the flat document it was before this markup existed — headings are still
 * recovered from `<h2>`, everything else lands as paragraphs. Degrading, not breaking.
 *
 * `content_sets` is named outright, the same way EntryContentPreview names it: the
 * addon already treats it as this project's content field, and deriving "the main
 * Bard field" would be a guess dressed up as a rule.
 */
class LegacyPlainLanguageDocument
{
    /** The entry field the rewritten document replaces: `content_fields.body`. */
    public static function contentField(): string
    {
        return ContentFields::body();
    }

    /** The entry field the rewritten heading lands in: `content_fields.title`. */
    public static function titleField(): string
    {
        return ContentFields::title();
    }

    /**
     * The entry field that travels as its own block instead of as loose prose.
     *
     * Marked for exactly the reason a set is marked: without a wrapper the intro
     * arrives as one paragraph among thirty, and the service cannot tell that this
     * sentence introduces the article rather than continuing it. The class reuses
     * the field handle, so on the wire the marker reads like any other set handle —
     * the endpoint is told about structure, not about our field names.
     *
     * A multi-paragraph intro travels as ONE such block, not one per paragraph — see
     * texts(), where the reason lives next to the code that does it.
     *
     * THE MARKER ROUND-TRIPS. When the answer still carries the wrapper, document()
     * takes that chunk out of the content document and parses it with THIS field's own
     * Bard config instead, so the rewritten introduction lands in the intro FIELD and
     * nowhere else. `intro` is deliberately not looked up as a set of `content_sets` —
     * it is not one, and the unknown-class rule that used to catch it (wrapper
     * ignored, content stays prose) is exactly the behaviour being replaced. Leaving
     * it in the body was visible on the page: the host template renders `intro` as its
     * own block above the content, so a reader of a plain-language version got the
     * German introduction first and the rewritten one again as the first paragraph
     * below it.
     *
     * DEGRADATION IS THE OLD BEHAVIOUR. The service is not obliged to return the
     * wrapper. Without it there is no intro chunk, nothing is written to the field,
     * and the rewritten introduction stays what it was before: the document's first
     * prose block inside `content_sets`, with the intro field keeping the German value
     * the copy started with. The same fallback covers a blueprint with no `intro`
     * field at all, and a wrapper whose content parses to nothing. Worse than the
     * round-trip, no worse than yesterday.
     *
     * Existing versions are NOT backfilled. Their intro is already merged into
     * `content_sets` as prose and cannot be told apart from the paragraphs around it
     * after the fact; a resubmit rewrites such a post from its source and fixes it on
     * the way through.
     */
    public static function introField(): ?string
    {
        return ContentFields::intro();
    }

    /**
     * Fields this path never sends, on top of whatever a workflow excludes itself.
     *
     * The rewriting service answers with a DOCUMENT, and everything it is given comes
     * back inside that document. A field the write-back has no slot for therefore does
     * not merely stay untranslated — its text reappears in the article body. For the
     * SEO field that would put the meta description into the running text of a page in
     * plain language, which is why it is excluded here rather than left to each
     * project's `translation_exclude_fields`.
     *
     * The translating path has the opposite need: DeepL answers per text, so the SEO
     * fields land back exactly where they came from and are translated normally.
     *
     * Not sending it does not mean leaving it German. `seo.title` and
     * `seo.description` are DERIVED from the answer once it is in — from the rewritten
     * heading and the rewritten first paragraph — so the two texts a search result
     * shows are in plain language without either of them ever going on the wire. See
     * PlainLanguageSeo, and apply() for where that happens.
     */
    public static function neverSent(): array
    {
        return [ContentFields::seo()];
    }

    /**
     * Field types that can carry a set's text. Everything else (assets, terms,
     * toggles, the integer of a heading's numbering) is structure the service never
     * sees and can never fill.
     */
    private const TEXT_FIELD_TYPES = ['bard', 'markdown', 'textarea', 'text'];

    /**
     * Fieldtypes whose stored value is a LIST. Their empty is `[]`; everything
     * else empties to `''` — see emptyValueFor(), which is the only reader.
     */
    private const LIST_FIELD_TYPES = [
        'bard', 'replicator', 'grid', 'assets', 'entries', 'terms', 'taxonomy',
        'users', 'sites', 'table', 'array', 'checkboxes',
    ];

    /** Bard node types that hold prose rather than a nested structure. */
    private const BARD_HEADING = 'heading';

    public function __construct(
        private TranslatableText $text,
        private PlainLanguageSeo $seo,
        private WorkflowResolver $workflows,
    ) {}

    /**
     * The exclude list to extract with on this path: what the workflow declares plus
     * NEVER_SENT.
     *
     * One method rather than two `array_merge()` calls, because the submission and the
     * payload preview have to agree — a preview that shows a field the run does not
     * send is worse than no preview.
     *
     * @param  array<int, string>  $configured  `metadata.translation_exclude_fields`
     * @return array<int, string>
     */
    public static function excludeFields(array $configured): array
    {
        return array_values(array_unique(array_merge($configured, self::neverSent())));
    }

    /**
     * The texts to send, in segment order.
     *
     * Everything travels as extracted — already HTML, already entity-escaped where it
     * was plain — with three markers added:
     *
     * - the title gets an `<h1>`. That wrapper marks the heading as ours rather than
     *   letting the service decide which of the texts was one, and it is what makes
     *   the answer identifiable again in apply();
     * - the intro gets ONE `<div class="intro">`, however many paragraphs it has —
     *   see INTRO_FIELD and the merge below;
     * - a segment inside a set gets `<div class="<set handle>">`. Same idea, one level
     *   down: it says "this text is a hintbox", so the answer can be a hintbox again.
     *
     * One wrapper per SEGMENT for sets, not per set: a set with a Bard field is
     * extracted node by node (`…values.content.0`, `.content.1`), and each node keeps
     * its own wrapper. apply() merges neighbouring wrappers of the same set back into
     * one.
     *
     * THE INTRO IS THE EXCEPTION, and it is the only one. `intro` is a Bard too, so a
     * two-paragraph introduction extracts as `intro.0` and `intro.1` — and wrapping
     * each on its own sent two blocks that both claimed to BE the introduction, while
     * `generate_intro` in the request body tells the service to write that
     * introduction itself. One instruction, two candidates: the endpoint had to guess,
     * and what it guessed was its own business. Merged, the question does not arise.
     *
     * This is why the returned list is NOT index-aligned with $segments any more. That
     * costs nothing here: the service answers with a document, not with one reply per
     * text, so no caller maps an answer back by position — apply() reads the markers
     * instead, and the `segment_keys` stored with the job are diagnostics. It would be
     * a breaking change on the DeepL path, which is why that path never comes through
     * this class.
     *
     * @param  array<int, array{key: string, field?: string, text: string}>  $segments
     * @return array<int, string>
     */
    public function texts($source, array $segments): array
    {
        $handles = $this->setHandlesByIndex($source);

        $texts = [];
        $intro = [];
        $introAt = null;

        foreach (array_values($segments) as $segment) {
            $key = (string) ($segment['key'] ?? '');
            $text = (string) ($segment['text'] ?? '');

            if ($key === self::titleField()) {
                $texts[] = '<h1>'.$text.'</h1>';

                continue;
            }

            // Matched on the FIELD, not on the key: intro is a Bard field, so its
            // segments are `intro.0`, `intro.1`, … They collect here and leave a
            // placeholder, so the merged block lands where the FIRST of them was
            // rather than at the end. Written to survive intro segments that are not
            // adjacent — they always are today, but a rule that holds either way is
            // cheaper than one that has to be rechecked after every extractor change.
            if (self::introField() !== null && ($segment['field'] ?? null) === self::introField()) {
                if ($introAt === null) {
                    $introAt = count($texts);
                    $texts[] = '';
                }

                $intro[] = $text;

                continue;
            }

            $index = $this->setIndexOf($key);
            $handle = $index === null ? null : ($handles[$index] ?? null);

            $texts[] = $handle === null ? $text : '<div class="'.$handle.'">'.$text.'</div>';
        }

        // Concatenated bare: every part is already a block element (`<p>…</p>` for a
        // paragraph node), so the result is one wrapper around the paragraphs the
        // editor actually wrote — no separator to invent and none to strip back out.
        if ($introAt !== null) {
            $texts[$introAt] = '<div class="'.self::introField().'">'.implode('', $intro).'</div>';
        }

        return $texts;
    }

    /**
     * The entry's data with the rewritten document in it.
     *
     * Returns a fresh array — the entry is not touched, so the caller decides whether
     * and when to save. Fields the document says nothing about (assets, the taxonomies)
     * keep their values: they were copied from the source and are not this service's
     * business — unless the workflow names them in `plain_language_clear_fields`, which
     * is the one way a project can say "this one must not survive the rewrite" (see
     * clear() and WorkflowResolver::plainLanguageClearFields).
     *
     * Three fields the document DOES say something about: the leading `<h1>` becomes
     * `title`, the intro wrapper becomes `intro` (and is then absent from the content
     * nodes — see INTRO_FIELD), and the rest becomes `content_sets`. The intro is only
     * written when the answer actually carried the wrapper; when it did not, the field
     * keeps the copied value and the introduction stays the first paragraph of the
     * content, which is what this path did before the marker round-tripped.
     *
     * `seo` is the one exception, and it is not the service's business either: its
     * title and description are DERIVED here from what the answer produced, because
     * the field is never sent (NEVER_SENT) and would otherwise stay a German copy for
     * good. PlainLanguageSeo owns every rule about that — see there.
     *
     * A resubmit re-derives both texts, deliberately: the content this derives FROM is
     * being replaced wholesale in the same call, so keeping meta texts that describe
     * the previous rewrite would leave the entry describing something it no longer
     * says. An editor's manual correction is the price, and it is only paid on an
     * explicit "rewrite again" — the one-off backfill protects such edits instead
     * (BackfillPlainLanguageCommand).
     *
     * `$raw` is the workflow definition this rewrite belongs to, handed down by
     * PollPlainLanguageCommand::settle() the same way it hands the loader's raw array
     * to its place lookups. It is optional because everything above works without it;
     * only the clearing step needs settings, and a caller with none simply clears
     * nothing.
     *
     * @param  array<int, string>  $blocks  HTML blocks, in document order
     * @param  array<string, mixed>  $raw  The workflow definition, for the settings
     * @return array<string, mixed>
     */
    public function apply($entry, array $blocks, array $raw = []): array
    {
        $data = $entry->data()->all();
        $blocks = array_values(array_filter(array_map('trim', $blocks), fn ($block) => $block !== ''));

        [$heading, $body] = $this->splitHeading($blocks);

        if ($heading !== null) {
            $data[self::titleField()] = $heading;
        }

        ['intro' => $intro, 'nodes' => $nodes] = $this->document($entry, $body);

        // Only on a non-empty result: an answer without the wrapper must leave the
        // copied intro alone rather than emptying the field, and an emptied intro
        // would be indistinguishable from a deliberate one on the next resubmit.
        if ($intro !== [] && self::introField() !== null) {
            $data[self::introField()] = $intro;
        }

        // Always attempted, even when $nodes is empty: an empty BODY is not the same
        // as an empty RESULT. A post whose only translatable text is its title (a
        // video-only article, say) sends a single segment, gets a single rewritten
        // `<h1>` back, and correctly has nothing left over for $nodes — but its
        // video is still there in the source and belongs back in the result. Only
        // when there is truly nothing to restore does this still come back empty;
        // PollPlainLanguageCommand is what decides whether THAT counts as failure,
        // by comparing against what the source itself had.
        $data[self::contentField()] = $this->withUntranslatableSets($entry, $nodes);

        // The description prefers the intro that was just written back — that IS the
        // introduction the service was asked to write, and it is now a field of its
        // own rather than the document's first paragraph. `$intro` is passed, not
        // `$data[self::introField()]`: the copied German value must never become a
        // meta description, so an answer without the wrapper has to fall through to
        // the content's first paragraph exactly as before.
        //
        // That fallback is derived from the FINAL content field, not from $nodes: the
        // sets restored above can sit in front of the first paragraph. They are never
        // paragraphs, so the result is the same today — reading the value that is
        // actually stored keeps it the same if that ever changes.
        //
        // The title falls back to value(), which follows the origin. apply() only sets
        // titleField() when the answer had a leading `<h1>`, and a version without one
        // shows its origin's heading — so that is the heading its SEO title has to
        // match. `seo` itself is read from $data rather than through value(), because
        // an OWN array and an inherited one are two different merge bases.
        $seo = $this->seo->merged(
            (string) ($data[self::titleField()] ?? $entry->value(self::titleField()) ?? ''),
            (array) $data[self::contentField()],
            $data[PlainLanguageSeo::field()] ?? null,
            $this->originSeo($entry),
            intro: $intro,
        );

        if ($seo !== null) {
            $data[PlainLanguageSeo::field()] = $seo;
        }

        // Last, so a misconfiguration that named one of the fields above cannot be
        // undone by it — clear() refuses those handles outright, and doing this after
        // the writes makes that refusal observable rather than order-dependent.
        return $this->clear($entry, $data, $this->workflows->plainLanguageClearFields($raw));
    }

    /**
     * The empty own values that shadow what the copy brought along.
     *
     * WHY SHADOW INSTEAD OF REMOVE. Removing a key from a localization's own data
     * does not empty the field, it RESTORES it: Statamic resolves a value as
     * `$this->has($key) ? $this->get($key) : $origin->value($key)` (Data\HasOrigin),
     * and `has()` is `array_key_exists` on the version's own array. Unset the key and
     * every reader — the page, the CP form, the next rewrite's extractor — falls
     * through to the German original again, which is the opposite of what was asked
     * for. An explicit empty value keeps the key present and answers "empty" to all of
     * them. Nulls survive the save for a localization, too: Entry::fileData() strips
     * them only `if ($this->isRoot())`.
     *
     * WHY NOT `null`, THEN. Because a blueprint `default:` refills it. This project's
     * source headline declares `default: Quellen`, and a null value augments straight
     * back to "Quellen" — shadowed in the data, visible on the page. `''` does not,
     * which is why the empty is taken from the FIELD's shape rather than being one
     * constant: a list-shaped fieldtype (bard and friends) empties to `[]`, everything
     * else to `''`, and only a handle no blueprint declares falls back to `null` —
     * there is nothing to infer from then, and a field nobody declares has no default
     * to be refilled from either.
     *
     * WHY SETTINGS-DRIVEN. The addon does not know which of a project's fields are
     * unwanted in plain language; it would have to hardcode `dd_text` to guess. The
     * workflow says so instead, which also makes the scope exact: the key only has
     * meaning on the plain-language manager (the resolver returns nothing for any
     * other workflow, and the writer refuses the combination). THE TRANSLATION PATH IS
     * NOT TOUCHED — it never comes through this class at all: DeepL answers per field
     * and TranslatableText writes each answer home, so an English version keeps its
     * sources, translated.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $handles
     * @return array<string, mixed>
     */
    private function clear($entry, array $data, array $handles): array
    {
        foreach ($handles as $handle) {
            // The three fields this class WRITES are never clearable. A workflow that
            // named `content_sets` here would otherwise wipe the rewrite it just
            // received — a configuration mistake that destroys the only copy of the
            // answer, which is too expensive to allow for the sake of consistency.
            if (in_array($handle, [self::contentField(), self::titleField(), self::introField()], true)) {
                continue;
            }

            $data[$handle] = $this->emptyValueFor($entry, $handle);
        }

        return $data;
    }

    /**
     * The empty value that shadows a field: `[]` for a list-shaped fieldtype, `''` for
     * everything else, `null` for a handle the blueprint does not declare.
     *
     * Public because the backfill writes the same values into existing versions, and
     * a backfill that emptied a field "almost like" the live path would leave two
     * shapes of empty in one project — the same argument PlainLanguageSeo makes about
     * sharing plan() with BackfillPlainLanguageCommand.
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
     * The `seo` value of the version this one was copied from, or null when there is
     * no origin to ask.
     *
     * `root()` rather than a walk up the chain: localizations are created off the root
     * (WorkflowController::localizeInto), so the chain is one level deep by
     * construction. Reading the root's OWN data keeps the answer honest — the root has
     * no origin of its own, so there is nothing for a fallthrough to add.
     */
    private function originSeo($entry)
    {
        $root = method_exists($entry, 'root') ? $entry->root() : null;

        if ($root === null || $root->id() === $entry->id()) {
            return null;
        }

        return $root->data()->get(PlainLanguageSeo::field());
    }

    /**
     * The returned document, split into the two fields it feeds: `nodes` for the
     * content field — sets where the answer named one, prose everywhere else — and
     * `intro` for what the intro wrapper contained.
     *
     * The intro is separated HERE rather than fished back out of the finished nodes,
     * because by then it is indistinguishable from the prose around it: both are
     * paragraphs, and the wrapper that told them apart is gone. `intro` comes back
     * empty whenever the answer had no such wrapper, when the blueprint has no intro
     * field, or when the wrapper held nothing that parses — all three mean the same
     * to the caller: leave the field alone (see INTRO_FIELD).
     *
     * Parsed with the INTRO field's own Bard rather than the content field's, so the
     * nodes are shaped by the field that stores them — its buttons, its sets, its
     * `remove_empty_nodes`. Through prose(), so the two corrections that apply to
     * loose prose anywhere apply here too: a naked text node becomes a paragraph
     * instead of being dropped by Bard's schema, and a heading only becomes a
     * `heading` set where THAT field declares one (the intro field does not, so a
     * heading stays a plain node instead of borrowing the content field's numbering).
     *
     * @param  array<int, string>  $blocks
     * @return array{intro: array<int, mixed>, nodes: array<int, mixed>}
     */
    private function document($entry, array $blocks): array
    {
        if ($blocks === []) {
            return ['intro' => [], 'nodes' => []];
        }

        $fields = $entry->blueprint()?->fields()->all();
        $field = $fields?->get(self::contentField());

        if (! $field instanceof Field) {
            return ['intro' => [], 'nodes' => []];
        }

        $introField = self::introField() === null ? null : $fields?->get(self::introField());
        $introField = $introField instanceof Field ? $introField : null;

        $nodes = [];
        $intro = [];

        foreach ($this->chunks(implode("\n", $blocks)) as $chunk) {
            // Appended rather than assigned: chunks() merges only ADJACENT wrappers of
            // the same class, and a service that answers with two intro blocks around
            // something else has still written one introduction in two parts.
            if ($introField !== null && $chunk['set'] === self::introField()) {
                foreach ($this->prose($introField, $chunk['html']) as $node) {
                    $intro[] = $node;
                }

                continue;
            }

            $set = $chunk['set'] === null
                ? null
                : $this->setNode($field, $chunk['set'], $chunk['html']);

            // Unknown class, or a set with nothing that could hold text: the wrapper
            // is ignored and its content stays prose. Ignoring beats inventing a set
            // the blueprint does not have. An intro wrapper with no intro field to
            // write into arrives here too, which is what makes the fallback the old
            // behaviour rather than a loss.
            foreach ($set !== null ? [$set] : $this->prose($field, $chunk['html']) as $node) {
                $nodes[] = $node;
            }
        }

        return ['intro' => $intro, 'nodes' => $this->numberHeadings($field, $nodes)];
    }

    /**
     * The document split into set-wrappers and the prose between them.
     *
     * Parsed as a DOM rather than with a regex: a wrapper may contain block elements
     * of its own, and Bard's parser would drop the `<div>` before anyone could read
     * its class. Neighbouring wrappers of the same set are merged — a set whose Bard
     * field held three paragraphs was sent as three segments and comes back as three
     * wrappers, and it is one set.
     *
     * @return array<int, array{set: string|null, html: string}>
     */
    private function chunks(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // Two things this line has to get right, both learned the hard way:
        //
        // - the XML declaration is what tells loadHTML the input is UTF-8. Without it
        //   libxml assumes ISO-8859-1 and every umlaut breaks. It survives as a
        //   comment node, which is why the loop below reads the CONTAINER's children
        //   rather than the document's;
        // - the container is not cosmetic either. With NOIMPLIED and no element around
        //   the fragment, libxml drops top-level TEXT — a sentence the answer left
        //   without a block element would vanish without a trace.
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="wf-plain-language-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;

        foreach ($document->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $root = $child;

                break;
            }
        }

        // Nothing parseable at all — hand the document on as one prose chunk rather
        // than losing it.
        if ($root === null) {
            return [['set' => null, 'html' => $html]];
        }

        $chunks = [];

        foreach ($root->childNodes as $node) {
            $set = $this->wrapperClass($node);

            // `$last` is read by index, never with `??`: a plain chunk's `set` IS null,
            // and `?? $fallback` would treat that as "no chunk yet" — which is how
            // consecutive prose ended up in separate chunks, one parse each.
            $last = $chunks === [] ? null : count($chunks) - 1;

            if ($set !== null) {
                $inner = $this->innerHtml($document, $node);

                // Same set as the chunk before it → one set, not two.
                if ($last !== null && $chunks[$last]['set'] === $set) {
                    $chunks[$last]['html'] .= "\n".$inner;

                    continue;
                }

                $chunks[] = ['set' => $set, 'html' => $inner];

                continue;
            }

            $outer = (string) $document->saveHTML($node);

            if (trim($outer) === '') {
                continue;
            }

            // A sentence the answer left without a block element around it. Wrapped
            // HERE, before parsing: Bard's schema has no place for text at the top
            // level, and its parser drops such a fragment silently rather than
            // complaining — the sentence would simply be gone from the article.
            if ($node->nodeType === XML_TEXT_NODE) {
                $outer = '<p>'.$outer.'</p>';
            }

            // Prose accumulates into one chunk, so Bard's parser sees a document and
            // can resolve structure that spans blocks — a list whose items arrived as
            // separate strings, for instance.
            if ($last !== null && $chunks[$last]['set'] === null) {
                $chunks[$last]['html'] .= "\n".$outer;

                continue;
            }

            $chunks[] = ['set' => null, 'html' => $outer];
        }

        return $chunks;
    }

    /**
     * The set handle a node's wrapper names, or null when it is not a wrapper.
     *
     * Only a `div`, and only its first class: the marker `texts()` writes carries
     * exactly one, and reading more would invite a stray utility class to decide what
     * a block is.
     */
    private function wrapperClass(DOMNode $node): ?string
    {
        if ($node->nodeType !== XML_ELEMENT_NODE || strtolower($node->nodeName) !== 'div') {
            return null;
        }

        $class = trim((string) $node->attributes?->getNamedItem('class')?->nodeValue);

        if ($class === '') {
            return null;
        }

        return explode(' ', $class)[0];
    }

    private function innerHtml(\DOMDocument $document, DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= (string) $document->saveHTML($child);
        }

        return $html;
    }

    /**
     * A set node built from what the answer put inside its wrapper, or null when this
     * field declares no such set — or none of its fields could hold the text.
     *
     * @return array<string, mixed>|null
     */
    private function setNode(Field $field, string $handle, string $html): ?array
    {
        $target = $this->textFieldOf($field, $handle);

        if ($target === null) {
            return null;
        }

        $value = $this->valueFor($target, $html);

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return [
            'type' => 'set',
            // Statamic's own generator, so the id is shaped like every other set's.
            'attrs' => [
                'id' => app(RowId::class)->generate(),
                'values' => [
                    'type' => $handle,
                    $target->handle() => $value,
                ],
            ],
        ];
    }

    /**
     * The field of a set that carries its text: the first one whose type can hold
     * prose. Null when the set is unknown to this field or holds no such field —
     * a video's URL and a PDF's asset are structure, not text.
     */
    private function textFieldOf(Field $field, string $handle): ?Field
    {
        $fields = $this->text->setFields($field, $handle);

        if ($fields === null) {
            return null;
        }

        foreach ($fields as $candidate) {
            if (in_array($candidate->type(), self::TEXT_FIELD_TYPES, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The HTML in the shape the target field stores: Bard keeps nodes, everything
     * else takes text.
     *
     * @return array<int, mixed>|string|null
     */
    private function valueFor(Field $target, string $html)
    {
        if ($target->type() === 'bard') {
            return $this->parse($target, $html);
        }

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // A single-line field must not carry the paragraph breaks the answer had.
        return $target->type() === 'text' ? (string) preg_replace('~\s+~u', ' ', $text) : $text;
    }

    /**
     * Prose between the wrappers, as Bard nodes.
     *
     * Two corrections on the way, both for shapes the service produces and Bard
     * cannot store:
     *
     * - a `<h2>`/`<h3>` becomes this project's `heading` SET where the field declares
     *   one. That is how a heading is expressed here — with its own numbering, read by
     *   the table of contents — and a bare Bard heading would leave the article
     *   without one;
     * - a naked text node is wrapped in a paragraph. Text at the top level of a
     *   ProseMirror document is invalid; it arrives when the answer ends in a sentence
     *   with no block element around it.
     *
     * @return array<int, mixed>
     */
    private function prose(Field $field, string $html): array
    {
        $nodes = [];

        foreach ($this->parse($field, $html) as $node) {
            $type = is_array($node) ? ($node['type'] ?? null) : null;

            if ($type === self::BARD_HEADING) {
                $heading = $this->headingSet($field, $node);

                $nodes[] = $heading ?? $node;

                continue;
            }

            $nodes[] = $type === 'text'
                ? ['type' => 'paragraph', 'content' => [$node]]
                : $node;
        }

        return $nodes;
    }

    /**
     * A Bard heading node as this project's `heading` set, or null when the field
     * declares no such set (then the plain node is kept).
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function headingSet(Field $field, array $node): ?array
    {
        $text = $this->text->plainTextOf($node);

        return $text === '' ? null : $this->setNode($field, self::BARD_HEADING, htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Give every heading set a running number.
     *
     * `heading_index` is the numbering the table of contents prints (1, 2, 3 …), not a
     * level — so it is assigned in document order rather than derived from `<h2>` vs
     * `<h3>`. Skipped where the set has no such field.
     *
     * @param  array<int, mixed>  $nodes
     * @return array<int, mixed>
     */
    private function numberHeadings(Field $field, array $nodes): array
    {
        $fields = $this->text->setFields($field, self::BARD_HEADING);

        if ($fields === null) {
            return $nodes;
        }

        $indexField = collect($fields)->first(fn (Field $candidate) => $candidate->handle() === 'heading_index');

        if ($indexField === null) {
            return $nodes;
        }

        $number = 0;

        foreach ($nodes as $i => $node) {
            if (! is_array($node) || ($node['attrs']['values']['type'] ?? null) !== self::BARD_HEADING) {
                continue;
            }

            $nodes[$i]['attrs']['values'][$indexField->handle()] = ++$number;
        }

        return $nodes;
    }

    /**
     * HTML to Bard nodes, through the field's own augmentor.
     *
     * Parsed in ONE pass per chunk rather than block by block: Bard's parser is a
     * document parser, and handing it a whole chunk lets it resolve structure that
     * spans blocks — a list whose items arrived as separate strings, for instance.
     *
     * An unparseable chunk yields no nodes rather than a half one; an empty document
     * overall is what the caller treats as failure. Silently saving an emptied content
     * field would be the worst outcome available.
     *
     * @return array<int, mixed>
     */
    private function parse(Field $field, string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        // Same augmentor mode TranslatableText uses: StatamicLinkMark keeps
        // `statamic://entry::…` hrefs raw instead of resolving them to public URLs.
        $augmentor = (new Augmentor($field->fieldtype()))->withStatamicImageUrls();

        try {
            $document = $augmentor->renderHtmlToProsemirror($html);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return array_values($document['content'] ?? []);
    }

    /**
     * Put the sets back that the service never saw.
     *
     * A video, a PDF or a code block carries no translatable text, so it is not in what
     * we sent and cannot be in what comes back — and replacing the content field
     * wholesale would drop it. They are re-inserted at the index they had in the entry
     * being written into (the copy of the source), which is the only ordering
     * information that exists: the rewritten document has its own structure, and there
     * is nothing in it that says "the video went here".
     *
     * That anchoring is approximate on purpose. A set near the top of the source stays
     * near the top; one deep inside a long article drifts, because the source's 3 nodes
     * may have become 18. An index past the end simply appends. The alternative —
     * guessing a position from the new structure — would look precise and be arbitrary.
     *
     * Sets that DID have text are not restored: their content is already in the
     * rewritten document, and bringing the German original back would duplicate it.
     *
     * @param  array<int, mixed>  $nodes
     * @return array<int, mixed>
     */
    private function withUntranslatableSets($entry, array $nodes): array
    {
        $existing = (array) $entry->get(self::contentField());

        if ($existing === []) {
            return $nodes;
        }

        // Which top-level nodes produced a segment: the blueprint decides what counts as
        // text, so this asks the extractor rather than re-deciding it here. A set whose
        // every field was skipped (video URL, embed code, terms) yields no key.
        $translated = collect($this->text->extract($entry)['segments'])
            ->map(fn (array $segment) => (string) $segment['key'])
            ->filter(fn (string $key) => str_starts_with($key, self::contentField().'.'))
            ->map(fn (string $key) => explode('.', $key)[1] ?? null)
            ->filter(fn ($index) => $index !== null)
            ->unique()
            ->all();

        foreach ($existing as $index => $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'set') {
                continue;
            }

            if (in_array((string) $index, $translated, true)) {
                continue;
            }

            array_splice($nodes, min((int) $index, count($nodes)), 0, [$node]);
        }

        return $nodes;
    }

    /**
     * Peel the leading `<h1>` off the block list.
     *
     * Only a LEADING one, and only the first: an `<h1>` further down is a heading the
     * service chose to put in the body, and hoisting it into the title field would
     * reorder the document. Returns `[null, $blocks]` when the first block is not a
     * heading, which leaves the title exactly as the copy had it.
     *
     * @param  array<int, string>  $blocks
     * @return array{0: string|null, 1: array<int, string>}
     */
    private function splitHeading(array $blocks): array
    {
        if ($blocks === [] || ! preg_match('~^<h1\b[^>]*>(.*)</h1>$~is', $blocks[0], $match)) {
            return [null, $blocks];
        }

        // The title is a plain text field: tags the service put inside the heading go,
        // and entities decode, so the value reads the way it will be displayed.
        $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $title === '' ? [null, $blocks] : [$title, array_slice($blocks, 1)];
    }

    /**
     * `content_sets` index => set handle, for the entry the texts come from.
     *
     * @return array<int, string>
     */
    private function setHandlesByIndex($entry): array
    {
        $handles = [];

        foreach ((array) $entry->get(self::contentField()) as $index => $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'set') {
                continue;
            }

            $handle = $node['attrs']['values']['type'] ?? null;

            if (is_string($handle) && $handle !== '') {
                $handles[(int) $index] = $handle;
            }
        }

        return $handles;
    }

    /**
     * The `content_sets` index a segment key points into, or null for a key that is
     * not a set's field (the field's own prose, or another field entirely).
     */
    private function setIndexOf(string $key): ?int
    {
        return preg_match('~^'.preg_quote(self::contentField(), '~').'\.(\d+)\.attrs\.values\.~', $key, $match) === 1
            ? (int) $match[1]
            : null;
    }
}
