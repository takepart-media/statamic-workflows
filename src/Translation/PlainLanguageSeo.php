<?php

namespace TakepartMedia\Workflows\Translation;

use TakepartMedia\Workflows\Support\ContentFields;
/**
 * The SEO texts of a plain-language version, derived from the rewrite itself.
 *
 * This class exists because of a gap the two translation paths do not share. DeepL
 * answers per text, so `seo.title` and `seo.description` come back as themselves and
 * TranslatableText writes them into the field they left (see SEO_TEXT_KEYS). The
 * rewriting service answers with a DOCUMENT, and everything sent to it reappears
 * inside that document — which is why `seo` is excluded on that path
 * (PlainLanguageDocument::NEVER_SENT) and why a plain-language version used to carry
 * the German meta texts of its origin, word for word, forever.
 *
 * The fix is deliberately NOT a second call to the service. The two texts a reader
 * of a search result sees are already in the answer:
 *
 *   - the TITLE is the rewritten `<h1>` — the heading the version now displays, so an
 *     SEO title derived from it says exactly what the page says;
 *   - the DESCRIPTION is the rewritten INTRODUCTION, and it is looked for in two
 *     places in this order: the intro nodes the caller just wrote back into the
 *     `intro` field, then the first non-empty top-level `paragraph` of the finished
 *     `content_sets`. Both are the same text; which one holds it depends on whether
 *     the service returned the intro wrapper it was sent (see
 *     PlainLanguageDocument::INTRO_FIELD). With the wrapper the introduction is a
 *     field of its own and no longer the document's first paragraph — that paragraph
 *     is then the article's opening, which describes the page less well. Without it
 *     nothing is passed and the paragraph rule answers, exactly as before.
 *
 *     What is NEVER read is the entry's STORED `intro` value. A version that got no
 *     wrapper back still carries the German intro of the copy it started from, and
 *     deriving a meta description from that is the very bug this class exists for.
 *     Only intro nodes that came out of THIS answer are offered here, which is why
 *     they are a parameter rather than something this class fetches.
 *
 * Everything here is pure: values in, values out, no entry and no save. That is what
 * lets the live path (PlainLanguageDocument::apply()) and the one-off backfill
 * (BackfillPlainLanguageCommand) share one set of rules instead of agreeing on them twice.
 *
 * THE MERGE IS WHOLE-ARRAY, and that is not a shortcut. Statamic's origin
 * fallthrough works per top-level key: a localization either has its own `seo` array
 * or inherits the origin's entirely — there is no per-subkey inheritance. Writing
 * `{title, description}` alone would therefore not "add two keys", it would REPLACE
 * an inherited `seo` and drop the canonical URL, the OG image and the robots flags
 * with it. So the base is the version's own array where it has one, the origin's
 * where it does not, and the two derived keys are set on top of that copy.
 *
 * Two values are never touched:
 *
 *   - `seo: false` means SEO Pro is switched off for this entry. Deriving texts into
 *     a disabled field would switch it back on as a side effect of a rewrite;
 *   - a `@seo:<handle>` value POINTS at another field ("use the intro") instead of
 *     holding text. Overwriting it would turn a piece of inheritance the editor set
 *     up into a literal string — the same reason the DeepL path refuses to translate
 *     one (TranslatableText::SEO_REFERENCE, read here rather than re-spelled).
 */
class PlainLanguageSeo
{
    /** The entry field the derived texts live in: `content_fields.seo`. */
    public static function field(): string
    {
        return ContentFields::seo();
    }

    /** The subkey that mirrors the rewritten heading. */
    public const TITLE_KEY = 'title';

    /** The subkey that mirrors the rewritten introduction. */
    public const DESCRIPTION_KEY = 'description';

    /**
     * SEO Pro's own limit for a meta description, and the reason truncate() exists.
     *
     * A rewritten introduction is prose written for a reader, not for a `<meta>` tag,
     * so it regularly runs past this. The DeepL path leaves such a value long and
     * lets the publish form complain on the next save — acceptable for a text a human
     * wrote and can shorten. Here nobody wrote it, so nobody would shorten it.
     */
    public const DESCRIPTION_LIMIT = 160;

    /** A derived value that differs from what is stored: this one gets written. */
    public const WRITE = 'write';

    /** Stored value already equals the derived one — the idempotent case. */
    public const UNCHANGED = 'unchanged';

    /** Stored value is a `@seo:` reference and stays one. */
    public const REFERENCE = 'reference';

    /** Somebody changed this text after the rewrite; only `--force` overrides that. */
    public const EDITED = 'edited';

    /** Nothing to derive from — a document with no paragraph, or an empty title. */
    public const NO_SOURCE = 'no source';

    /**
     * The complete `seo` array to store, or null when nothing should be written.
     *
     * Null covers three cases that are all "leave the entry alone": the field is
     * switched off, both texts are already what they would be derived to (the second
     * run of the backfill, and every resubmit that changes nothing), or every
     * candidate key is protected.
     *
     * @param  string  $title  The version's effective title — the rewritten heading
     * @param  array<int, mixed>  $nodes  The finished `content_sets` nodes
     * @param  mixed  $own  The version's OWN `seo` value (no origin fallthrough)
     * @param  mixed  $origin  The origin's `seo` value
     * @param  bool  $keepEdited  Leave texts a human changed since the rewrite
     * @param  array<int, mixed>  $intro  Intro nodes from THIS answer, if it had any
     * @return array<string, mixed>|null
     */
    public function merged(string $title, array $nodes, $own, $origin, bool $keepEdited = false, array $intro = []): ?array
    {
        $plan = $this->plan($title, $nodes, $own, $origin, $keepEdited, $intro);

        return $plan === null ? null : $this->withPlan($plan, $own, $origin);
    }

    /**
     * What would happen to each derived subkey, and why.
     *
     * Split off from merged() for the backfill's sake: a dry run has to say
     * `description: edited` rather than reporting the whole entry as unchanged and
     * leaving the operator to guess which of the two texts was protected. The live
     * path never reads it — it calls merged(), which is this plus withPlan().
     *
     * Null means the field is switched off, which is the one outcome that is about
     * the FIELD rather than about a subkey.
     *
     * `$intro` defaults to empty, and the backfill leaves it that way on purpose: it
     * runs over versions written before the intro round-tripped, whose intro field
     * still holds the German copy. There is nothing there for it to prefer.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $intro
     * @return array<string, array{from: mixed, to: string|null, reason: string}>|null
     */
    public function plan(string $title, array $nodes, $own, $origin, bool $keepEdited = false, array $intro = []): ?array
    {
        if ($this->disabled($own, $origin)) {
            return null;
        }

        $base = $this->base($own, $origin);
        $inherited = is_array($origin) ? $origin : [];

        $derived = [
            self::TITLE_KEY => trim($title) === '' ? null : trim($title),
            self::DESCRIPTION_KEY => $this->description($nodes, $intro),
        ];

        $plan = [];

        foreach ($derived as $key => $value) {
            $current = $base[$key] ?? null;

            $plan[$key] = [
                'from' => $current,
                'to' => $value,
                'reason' => $this->reasonFor($current, $value, $inherited[$key] ?? null, $keepEdited),
            ];
        }

        return $plan;
    }

    /**
     * A plan applied to the merge base — the array a caller stores.
     *
     * Returns null when the plan writes nothing, so "no change" never reaches a
     * `save()`. That matters beyond tidiness: materialising the origin's inherited
     * array onto the localization would make every later origin edit stop reaching
     * it, and doing that for a no-op write would be a silent change of behaviour.
     *
     * @param  array<string, array{from: mixed, to: string|null, reason: string}>  $plan
     * @return array<string, mixed>|null
     */
    public function withPlan(array $plan, $own, $origin): ?array
    {
        $seo = $this->base($own, $origin);
        $changed = false;

        foreach ($plan as $key => $decision) {
            if (($decision['reason'] ?? null) !== self::WRITE) {
                continue;
            }

            $seo[$key] = $decision['to'];
            $changed = true;
        }

        return $changed ? $seo : null;
    }

    /** Whether SEO Pro is switched off for this version — see the class docblock. */
    public function disabled($own, $origin): bool
    {
        return $own === false || ($own === null && $origin === false);
    }

    /**
     * The array the derived keys are set on: the version's own, or the origin's when
     * it has none of its own. Anything else (a string, `null`) starts from empty.
     *
     * @return array<string, mixed>
     */
    public function base($own, $origin): array
    {
        if (is_array($own)) {
            return $own;
        }

        return is_array($origin) ? $origin : [];
    }

    /** Whether a stored value points at another field instead of holding text. */
    public function isReference($value): bool
    {
        return is_string($value) && str_starts_with($value, TranslatableText::SEO_REFERENCE);
    }

    /**
     * The meta description for a rewritten document: the rewritten introduction where
     * the answer produced one as its own field, otherwise the document's first
     * non-empty top-level paragraph. Shortened to the field's limit either way.
     *
     * The intro is asked FIRST and the content is the fallback, not the other way
     * round — see the class docblock for why both exist. One rule serves both:
     * only TOP-LEVEL paragraphs, and only paragraphs. A `heading` set's text is a
     * question, not a summary, and prose nested inside a hintbox or an accordion is a
     * detail the article gets to later. An intro made of sets rather than prose (a
     * per-entity introduction) therefore yields nothing here and falls through to the
     * content, which is right: there is no single introduction to quote.
     *
     * Null when NEITHER has one — a video-only post, say — and then the stored
     * description keeps whatever it had: an inherited German sentence is a poor
     * description, an empty one is worse.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $intro
     */
    public function description(array $nodes, array $intro = []): ?string
    {
        $text = $this->firstParagraph($intro) ?? $this->firstParagraph($nodes);

        return $text === null ? null : $this->truncate($text);
    }

    /**
     * The text of the first non-empty top-level paragraph, whitespace collapsed, or
     * null when there is none.
     *
     * @param  array<int, mixed>  $nodes
     */
    private function firstParagraph(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'paragraph') {
                continue;
            }

            $text = trim((string) preg_replace('~\s+~u', ' ', $this->sentences($node)));

            if ($text === '') {
                continue;
            }

            return $text;
        }

        return null;
    }

    /**
     * Text shortened to $limit characters at a word boundary, with an ellipsis.
     *
     * Counted with mb_* throughout, because SEO Pro counts characters and a German
     * description is full of umlauts — a byte-based cut would shorten the same
     * sentence differently depending on how many of them it contains, and could halve
     * a multi-byte character on the way out.
     *
     * The ellipsis is part of the budget (`$limit - 1`), so the stored value fits the
     * field rather than exceeding it by one on every truncation. A single word longer
     * than the whole limit has no boundary to fall back to and is cut hard; trailing
     * punctuation goes, so the result reads "… und dann…" instead of "… und dann,…".
     */
    public function truncate(string $text, int $limit = self::DESCRIPTION_LIMIT): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit - 1);
        $boundary = mb_strrpos($cut, ' ');

        if ($boundary !== false && $boundary > 0) {
            $cut = mb_substr($cut, 0, $boundary);
        }

        return rtrim($cut, " \t\n\r\0\x0B,;:.-–—").'…';
    }

    /**
     * Which of the two texts wins, in the order the reasons have to be asked.
     *
     * The order is the whole content of this method:
     *
     *   1. a reference is never touched, whatever else is true of it;
     *   2. nothing derived means nothing to compare — the stored value stays;
     *   3. ALREADY EQUAL comes before "edited". After the backfill has run once, the
     *      stored title no longer matches the origin's copy, which is exactly what
     *      the edited-check looks for — asking that question first would report every
     *      second run as `edited` and the whole feature as non-idempotent;
     *   4. edited: the stored text differs from the copy the localization started
     *      with, so a human wrote it. `--force` is what says otherwise.
     *
     * @param  mixed  $current  What is stored now (own value, or inherited)
     * @param  mixed  $inherited  The origin's value for the same subkey
     */
    private function reasonFor($current, ?string $value, $inherited, bool $keepEdited): string
    {
        if ($this->isReference($current)) {
            return self::REFERENCE;
        }

        if ($value === null) {
            return self::NO_SOURCE;
        }

        if (is_string($current) && $current === $value) {
            return self::UNCHANGED;
        }

        if ($keepEdited && is_string($current) && trim($current) !== '' && $current !== $inherited) {
            return self::EDITED;
        }

        return self::WRITE;
    }

    /**
     * The prose of one ProseMirror node, spacing intact.
     *
     * Deliberately NOT TranslatableText::plainTextOf(), which trims every text leaf
     * because it only ever answers "is this block empty?". A paragraph with a link or
     * a bold word in the middle is three leaves — `'Mehr dazu '`, `'hier'`, `'.'` —
     * and trimming each of them concatenates to "Mehr dazuhier.". Harmless for the
     * emptiness question, wrong for a sentence a reader sees in a search result.
     *
     * A hard break becomes a space for the same reason: it separates words, and the
     * caller collapses runs of whitespace afterwards.
     *
     * @param  mixed  $node
     */
    private function sentences($node): string
    {
        if (! is_array($node)) {
            return '';
        }

        if (isset($node['text']) && is_string($node['text'])) {
            return $node['text'];
        }

        if (in_array($node['type'] ?? null, ['hardBreak', 'hard_break'], true)) {
            return ' ';
        }

        $out = '';

        foreach ($node['content'] ?? [] as $child) {
            $out .= $this->sentences($child);
        }

        return $out;
    }
}
