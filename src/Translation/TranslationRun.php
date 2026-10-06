<?php

namespace TakepartMedia\Workflows\Translation;

/**
 * Splits a segment list into request-sized chunks — and nothing else.
 *
 * One rule in one place, because two callers depend on it agreeing with itself:
 * `applyTransition()` tells the client how many chunks there will be, and each
 * later `translateChunk()` request works out which segments chunk N holds. Those
 * are separate HTTP requests, so the split has to be reproducible rather than
 * remembered — which it is, as long as it is computed from the SOURCE entry.
 * The source does not change while a translation runs; the target does.
 */
class TranslationRun
{
    /** DeepL accepts 50 texts per request; leaving headroom costs one request. */
    public const MAX_SEGMENTS = 40;

    /**
     * DeepL's request-body limit is 128 KiB. The budget here counts segment
     * text only, so the slack covers the JSON envelope and multi-byte
     * characters — a German text is well over one byte per character.
     */
    public const MAX_BYTES = 90_000;

    /**
     * Group segments into chunks, in order.
     *
     * A single segment larger than the byte budget still gets its own chunk
     * rather than being dropped or split mid-sentence: DeepL will either accept
     * it or reject it with a message, and both beat losing the text silently.
     *
     * @param  array<int, array{text: string}>  $segments
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function chunk(array $segments): array
    {
        $chunks = [];
        $current = [];
        $bytes = 0;

        foreach (array_values($segments) as $segment) {
            $size = strlen((string) ($segment['text'] ?? ''));

            if ($current !== [] && (count($current) >= self::MAX_SEGMENTS || $bytes + $size > self::MAX_BYTES)) {
                $chunks[] = $current;
                $current = [];
                $bytes = 0;
            }

            $current[] = $segment;
            $bytes += $size;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
