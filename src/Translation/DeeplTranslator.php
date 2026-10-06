<?php

namespace TakepartMedia\Workflows\Translation;

use Illuminate\Support\Facades\Http;

/**
 * The DeepL HTTP adapter — the only class in the addon that talks to DeepL.
 *
 * Deliberately narrow: texts in, texts out, same order. It knows nothing about
 * entries, workflows or Bard. That is what makes swapping the provider a matter
 * of writing a second class with this one method, rather than an edit spread
 * across the controller.
 *
 * `tag_handling: html` is not optional here. Every segment TranslatableText
 * produces is HTML (plain fields entity-escaped), which is exactly what lets one
 * request carry both prose and rich text — see that class for why. It is also what
 * carries a module-scoped block's `data-modules` wrapper through untouched:
 * DeepL treats unknown tags as markup to preserve, not as text to translate.
 */
class DeeplTranslator implements Translator
{
    /**
     * Per-request timeout. Kept well under TranslationRunner's total budget: a
     * run makes up to four of these calls, and the caller is a user waiting on
     * an HTTP request, not a queue worker. A hung connection has to fail fast
     * enough that the run can still report cleanly instead of being cut off by
     * the webserver — see TranslationRunner::BUDGET_SECONDS.
     */
    private const TIMEOUT = 30;

    public function __construct(private DeeplConfig $config) {}

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Translate a batch, preserving order: `result[i]` belongs to `texts[i]`.
     * That index alignment is the whole contract with TranslatableText —
     * see `TranslatableText::mapByIndex()`.
     *
     * $context is deliberately unused: this body is DeepL's contract, not ours, and
     * an unknown field in it is at best ignored and at worst a 400. The module
     * information a block carries still reaches DeepL — inside the `data-modules`
     * wrapper, which `tag_handling: html` preserves.
     *
     * @param  array<int, string>  $texts
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     *
     * @throws TranslationFailed
     */
    public function translate(array $texts, string $sourceLang, string $targetLang, array $context = []): array
    {
        $texts = array_values($texts);

        if ($texts === []) {
            return [];
        }

        $key = $this->config->key();
        $url = $this->config->translateUrl();

        if ($key === null || $url === null) {
            throw new TranslationFailed(__('workflows::messages.translation_not_configured'));
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'DeepL-Auth-Key '.$key])
                ->timeout(self::TIMEOUT)
                ->asJson()
                ->post($url, [
                    'text' => $texts,
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                    'tag_handling' => 'html',
                ]);
        } catch (\Throwable $e) {
            // Connection level — DNS, TLS, timeout. Report it for the log, but
            // hand the editor a sentence rather than a stack trace.
            report($e);

            throw new TranslationFailed(__('workflows::messages.translation_unreachable'));
        }

        if (! $response->successful()) {
            throw new TranslationFailed($this->messageFor($response->status()), $response->status());
        }

        $translations = $response->json('translations');

        if (! is_array($translations) || count($translations) !== count($texts)) {
            // A short or reordered batch would silently write the wrong text into
            // the wrong field, which is worse than not translating at all.
            throw new TranslationFailed(__('workflows::messages.translation_bad_response'));
        }

        return array_map(fn ($item) => (string) ($item['text'] ?? ''), $translations);
    }

    /**
     * DeepL's documented status codes, turned into something an editor can act
     * on. Anything unlisted falls through to the generic message — inventing an
     * explanation for a code we have not seen would be worse than saying so.
     */
    private function messageFor(int $status): string
    {
        return match ($status) {
            403 => __('workflows::messages.translation_error_auth'),
            429 => __('workflows::messages.translation_error_rate_limit'),
            456 => __('workflows::messages.translation_error_quota'),
            400 => __('workflows::messages.translation_error_request'),
            default => __('workflows::messages.translation_error_generic', ['status' => $status]),
        };
    }
}
