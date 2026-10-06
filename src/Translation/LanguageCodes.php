<?php

namespace TakepartMedia\Workflows\Translation;

use Statamic\Facades\Site;

/**
 * Statamic site handle → the language codes a translation API expects.
 *
 * DeepL is the first consumer and shapes the rules, but the split is the same
 * everywhere: the SOURCE is a bare language (DeepL rejects regional variants
 * there — it detects the variant itself), the TARGET wants the variant where
 * one exists, because `EN` alone is deprecated and picking between EN-US and
 * EN-GB is exactly the kind of decision an editor made when they configured the
 * site.
 *
 * The site's locale is the input, not the handle: a site handled `en` can be
 * `en_GB`, and that difference is the whole point.
 */
class LanguageCodes
{
    /**
     * Locales DeepL knows a regional target for. Anything else falls back to the
     * bare language, which DeepL accepts for every language it supports.
     */
    private const TARGET_VARIANTS = [
        'en' => 'EN-US',
        'en_us' => 'EN-US',
        'en_gb' => 'EN-GB',
        'pt' => 'PT-PT',
        'pt_pt' => 'PT-PT',
        'pt_br' => 'PT-BR',
        'zh' => 'ZH-HANS',
        'zh_cn' => 'ZH-HANS',
        'zh_hans' => 'ZH-HANS',
        'zh_tw' => 'ZH-HANT',
        'zh_hant' => 'ZH-HANT',
    ];

    /** `source_lang` — bare language, uppercased. */
    public function source(string $siteHandle): string
    {
        return strtoupper($this->language($this->locale($siteHandle)));
    }

    /** `target_lang` — regional variant where the API has one. */
    public function target(string $siteHandle): string
    {
        $locale = $this->normalise($this->locale($siteHandle));

        return self::TARGET_VARIANTS[$locale]
            ?? self::TARGET_VARIANTS[$this->language($locale)]
            ?? strtoupper($this->language($locale));
    }

    /**
     * The site's configured locale (`de_DE`), falling back to the handle when a
     * site is unknown — a missing site should not be the thing that breaks the
     * payload.
     */
    public function locale(string $siteHandle): string
    {
        return (string) (Site::get($siteHandle)?->locale() ?? $siteHandle);
    }

    /** Both descriptors at once, for the payload's `source`/`target` blocks. */
    public function describe(string $siteHandle, bool $asTarget): array
    {
        return [
            'site' => $siteHandle,
            'locale' => $this->locale($siteHandle),
            'deepl' => $asTarget ? $this->target($siteHandle) : $this->source($siteHandle),
        ];
    }

    private function normalise(string $locale): string
    {
        return strtolower(str_replace('-', '_', $locale));
    }

    private function language(string $locale): string
    {
        return strtok($this->normalise($locale), '_') ?: $locale;
    }
}
