<?php

namespace TakepartMedia\Workflows\Translation;

/**
 * What PlainLanguageLeafDocument::apply() made of an answer.
 *
 *   data      the entry data to store, or null when the answer was rejected
 *   diff      per content field: which leaves changed, from what to what (plain
 *             text excerpts) — plus `title`, `seo` and cleared fields
 *   errors    why the answer was rejected; non-empty means nothing may be written
 *   warnings  oddities that did not stop the write (reordered sections, a
 *             single-line field that came back as several paragraphs …)
 *   changed   whether data differs from what the entry holds now — false makes
 *             the write-back a no-op (idempotent re-polls)
 */
final class PlainLanguageApplyResult
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, mixed>  $diff
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly ?array $data,
        public readonly array $diff = [],
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly bool $changed = false,
    ) {}

    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    public static function rejected(array $errors, array $warnings = []): self
    {
        return new self(null, [], array_values($errors), array_values($warnings), false);
    }

    public function ok(): bool
    {
        return $this->errors === [] && $this->data !== null;
    }
}
