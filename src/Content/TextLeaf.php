<?php

namespace TakepartMedia\Workflows\Content;

use Statamic\Fields\Field;

/**
 * One piece of prose inside an entry's content fields, as TextLeafWalker finds it.
 *
 *   path      dotted path into the entry's RAW data (`sections.0.content.2.text`)
 *   type      bard | markdown | textarea | text — decides how the leaf is sent and
 *             written back
 *   handle    the field's own handle (`text`)
 *   field     the top-level content field it lives in (`sections`)
 *   context   the set / row type around it (`text_block`), the grid or group handle,
 *             or null directly in a top-level field
 *   modules   the entity scope the enclosing rows limit it to ([] = every entity)
 *   value     the raw stored value (ProseMirror nodes, an HTML string, or text)
 *   fieldObject  the blueprint Field — needed to render and parse Bard; never
 *             serialised into a plan
 *   sets      Bard only: set id => handle of the set field whose text is sent
 */
final class TextLeaf
{
    /**
     * @param  array<int, string>  $modules
     * @param  array<string, string>  $sets
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly string $handle,
        public readonly string $field,
        public readonly ?string $context,
        public readonly array $modules,
        public readonly mixed $value,
        public readonly ?Field $fieldObject = null,
        public readonly array $sets = [],
    ) {}

    /** Directly a top-level content field, not nested inside rows or groups. */
    public function isTopLevel(): bool
    {
        return $this->path === $this->field;
    }

    /** A short, stable fingerprint of the value — for diagnostics and the backfill. */
    public function hash(): string
    {
        return substr(sha1((string) json_encode($this->value)), 0, 12);
    }

    /**
     * @return array{path: string, type: string, handle: string, field: string, context: string|null, modules: array<int, string>, hash: string, sets: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type,
            'handle' => $this->handle,
            'field' => $this->field,
            'context' => $this->context,
            'modules' => $this->modules,
            'hash' => $this->hash(),
            'sets' => $this->sets,
        ];
    }
}
