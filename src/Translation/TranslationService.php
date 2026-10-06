<?php

namespace TakepartMedia\Workflows\Translation;

/**
 * The one thing every translation service has in common: whether it can be called
 * at all. Everything else differs — DeepL answers in the same request (see
 * Translator), the plain-language service accepts a job and finishes later (see
 * PlainLanguageTranslator).
 *
 * Exists so the parts that only need to ASK about a service — the settings read-out,
 * the payload preview's service descriptor — can hold either without pretending the
 * two are interchangeable.
 */
interface TranslationService
{
    public function isConfigured(): bool;
}
