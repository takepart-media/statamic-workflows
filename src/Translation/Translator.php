<?php

namespace TakepartMedia\Workflows\Translation;

/**
 * What TranslationRunner needs from a translation service, and nothing more.
 *
 * Texts in, texts out, same order — `result[i]` belongs to `texts[i]`. That index
 * alignment is the contract with TranslatableText: `mapByIndex()` turns the
 * positional answer back into the `key => text` map `inject()` writes from, so an
 * implementation that reorders or shortens a batch would put translated text into
 * the wrong field. Implementations therefore verify the count themselves rather
 * than trusting the service.
 *
 * Every text is HTML (plain fields entity-escaped, see TranslatableText), and a
 * block limited to certain modules arrives inside a `data-modules` wrapper. An
 * implementation must pass unknown markup through untouched — it is the receiving
 * service's context, not ours.
 */
interface Translator extends TranslationService
{
    /**
     * Translate a batch, preserving order.
     *
     * $context carries document-level facts that apply to every text in the batch —
     * today the entity the entry belongs to. It is advisory: an implementation whose
     * request body is a vendor's contract (DeepL) ignores it, because adding unknown
     * fields to someone else's API is not ours to do. An implementation talking to
     * the project's own endpoint passes it on.
     *
     * @param  array<int, string>  $texts
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     *
     * @throws TranslationFailed
     */
    public function translate(array $texts, string $sourceLang, string $targetLang, array $context = []): array;
}
