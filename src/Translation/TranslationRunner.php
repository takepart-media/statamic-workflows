<?php

namespace TakepartMedia\Workflows\Translation;

use TakepartMedia\Workflows\Support\ContentFields;
/**
 * One machine translation, start to finish, in memory.
 *
 * Returns the entry data as it should be saved — and writes nothing itself.
 * That separation is the whole point: the caller creates the localization only
 * after this returns, so a failed API call leaves no half-translated entry
 * behind. Anything that goes wrong throws, and the caller has nothing to undo.
 *
 * An earlier design created the localization first (as a copy of the source) and
 * translated over it in separate chunk requests, on the assumption that a long
 * post would otherwise hold an HTTP request open past its timeout. Measuring the
 * real content disproved it: of 388 entries, 303 need a single DeepL call and
 * the largest needs four. Seconds, not minutes — so the entry can simply wait
 * until there is something worth creating.
 */
class TranslationRunner
{
    /**
     * Wall-clock budget for the whole run, in seconds.
     *
     * Not a performance knob — a correctness one. nginx and PHP-FPM impose their
     * own limits, and a run cut off by THEM could still have finished the
     * translation and written the entry while the browser already saw an error:
     * exactly the "failed, but there is an entry anyway" outcome this class
     * exists to prevent. Stopping first, on our terms, keeps the promise. The
     * budget is checked between chunks, so it bounds the run at roughly one
     * chunk's overshoot.
     */
    private const BUDGET_SECONDS = 45;

    public function __construct(
        private TranslatableText $text,
        private TranslationRun $chunker,
        private LanguageCodes $codes,
    ) {}

    /**
     * Translate $source into $targetSite and return the data to save.
     *
     * The service is passed in, not resolved here: which one applies depends on the
     * workflow's `plain_language_site`, and that is workflow metadata the caller
     * already reads (alongside `translation_exclude_fields`). The runner only has to
     * know that texts go out and come back in the same order.
     *
     * @param  array<int, string>  $excludeFields  `metadata.translation_exclude_fields`
     * @return array{data: array<string, mixed>, segments: int, warnings: array<int, string>}
     *
     * @throws TranslationFailed
     */
    public function run($source, string $targetSite, Translator $translator, array $excludeFields = []): array
    {
        $extracted = $this->text->extract($source, $excludeFields);
        $segments = $extracted['segments'];

        if ($segments === []) {
            // Nothing translatable — the copy of the source IS the correct
            // result, and saying so beats failing on an empty request.
            return [
                'data' => $source->data()->all(),
                'segments' => 0,
                'warnings' => $extracted['warnings'],
            ];
        }

        $from = $this->codes->source((string) $source->locale());
        $to = $this->codes->target($targetSite);
        $context = $this->contextOf($source);

        $translations = [];
        $startedAt = microtime(true);

        foreach ($this->chunker->chunk($segments) as $i => $chunk) {
            if ($i > 0 && microtime(true) - $startedAt > self::BUDGET_SECONDS) {
                throw new TranslationFailed(__('workflows::messages.translation_error_timeout'));
            }

            $translated = $translator->translate(array_column($chunk, 'text'), $from, $to, $context);

            // Segment keys are unique across chunks, so merging cannot collide.
            $translations = array_merge($translations, TranslatableText::mapByIndex($chunk, $translated));
        }

        // One write-back at the end, over the source data. `inject()` rebuilds
        // the same traversal `extract()` used, so every key lands where it came
        // from — see TranslatableText. Safe to reuse the instance: inject()
        // resets its state, and the extraction warnings are already copied out.
        $data = $this->text->inject($source, $translations, $excludeFields);

        return [
            'data' => $data,
            'segments' => count($segments),
            'warnings' => array_values(array_unique(array_merge($extracted['warnings'], $this->text->warnings()))),
        ];
    }

    /**
     * Document-level facts that apply to the whole entry, for the services that can
     * use them (see Translator::translate()).
     *
     * The entry's own entity is a DIFFERENT thing from a block's `in_entities`: the
     * former says what the article is about, the latter which portals a single block
     * appears on. A post on `tma` whose blocks carry no restriction has every segment
     * at scope `[]` — correct, and still worth telling the service that the subject
     * is TMA, which is exactly what this adds. Sent as `modules` — see
     * TranslatableText::SCOPE_ATTR for the same rename on the per-block wrapper.
     *
     * Empty entities produce an empty context, so a project without an entity
     * taxonomy sends nothing extra.
     *
     * Public because the dry-run preview has to show the same context the run would
     * send. Two implementations of that would be two answers to "what goes out",
     * which is the one thing that endpoint exists to answer.
     *
     * @return array<string, mixed>
     */
    public function contextOf($source): array
    {
        $field = ContentFields::entity();

        $entities = TranslatableText::handles(
            $field !== null && is_object($source) && method_exists($source, 'get') ? $source->get($field) : null
        );

        return $entities === [] ? [] : ['modules' => $entities];
    }
}
