<?php

namespace TakepartMedia\Workflows\Content;

use Statamic\Fields\Field;
use TakepartMedia\Workflows\Support\ContentFields;
use TakepartMedia\Workflows\Translation\TranslatableText;

/**
 * Finds the prose in an entry's content fields — the pieces the plain-language
 * service rewrites — as a flat list of TextLeaf objects with their RAW data paths.
 *
 * Reads raw data against the blueprint (like TranslatableText), not augmented
 * values: the plain-language write-back sets values at exactly these paths, so the
 * paths have to be the stored structure's own.
 *
 *   - Replicator rows, Grid rows and Groups are recursed into; every text field
 *     below them is a leaf of its own.
 *   - A Bard field is ONE leaf, its sets included: Bard text is a document, and
 *     its sets (a hint box, a heading set) belong to that document's flow. Nothing
 *     below a Bard field is walked further.
 *   - Markdown, Textarea and Text fields are leaves when they hold prose.
 *
 * Not prose, and therefore skipped (and reported through skipped()):
 *   - empty values, hidden or computed fields;
 *   - a Text field whose `input_type` is not `text` (url, email, number …);
 *   - a value that is a single URL, or starts with `<iframe`, `<script` or
 *     `<blockquote` (an embed code);
 *   - fields named in the workflow's `metadata.plain_language_skip_fields`:
 *       `handle`        the top-level field `handle`
 *       `set.handle`    field `handle` inside a set / row of type `set` (or inside
 *                       the grid / group called `set`)
 *       `*.handle`      field `handle` at any nested level
 *   - disabled Replicator rows and Bard sets, when
 *     `workflows.translation.plain_language.include_disabled` is false (default
 *     true: a disabled row is still content an editor may switch back on, and a
 *     plain-language version should not switch it back on in German).
 */
class TextLeafWalker
{
    /** Fieldtypes a leaf can be. */
    public const TEXT_TYPES = ['bard', 'markdown', 'textarea', 'text'];

    /** Placeholder prefix in skeleton(); a NUL byte cannot occur in stored YAML text. */
    private const PLACEHOLDER = "\0leaf:";

    /** @var array<int, TextLeaf> */
    private array $leaves = [];

    /** @var array<int, array{path: string, reason: string}> */
    private array $skipped = [];

    /** @var array<int, string> */
    private array $skip = [];

    private string $topField = '';

    /**
     * @param  \Statamic\Contracts\Entries\Entry  $entry  supplies the blueprint
     * @param  array<string, mixed>  $data  the raw data to walk (the entry's own, or a skeleton)
     * @param  array<int, string>  $fieldHandles  top-level content fields, in order
     * @param  array<int, string>  $skip  `metadata.plain_language_skip_fields`
     * @return array<int, TextLeaf>
     */
    public function leaves($entry, array $data, array $fieldHandles, array $skip = []): array
    {
        $this->leaves = [];
        $this->skipped = [];
        $this->skip = array_values(array_filter($skip, fn ($rule) => is_string($rule) && trim($rule) !== ''));

        $fields = $entry?->blueprint()?->fields()->all();

        if ($fields === null) {
            return [];
        }

        foreach ($fieldHandles as $handle) {
            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $field = $fields->get($handle);

            if (! $field instanceof Field || ! array_key_exists($handle, $data)) {
                continue;
            }

            if (in_array($handle, $this->skip, true)) {
                $this->addSkipped($handle, 'configured');

                continue;
            }

            $this->topField = $handle;
            $this->walk($field, $data[$handle], $handle, null, []);
        }

        return $this->leaves;
    }

    /**
     * What the last leaves() call left out, and why.
     *
     * @return array<int, array{path: string, reason: string}>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * The structure of $data with every leaf value replaced by a placeholder naming
     * its type. Two data sets with the same skeleton differ only in their prose —
     * which is what the plain-language write-back must guarantee before it saves.
     *
     * @param  array<string, mixed>  $data  keyed like the entry's data
     * @param  array<int, TextLeaf|array{path: string, type: string}>  $leaves
     * @return array<string, mixed>
     */
    public static function skeleton(array $data, array $leaves): array
    {
        foreach ($leaves as $leaf) {
            $path = $leaf instanceof TextLeaf ? $leaf->path : (string) ($leaf['path'] ?? '');
            $type = $leaf instanceof TextLeaf ? $leaf->type : (string) ($leaf['type'] ?? '');

            if ($path === '' || ! \Illuminate\Support\Arr::has($data, $path)) {
                continue;
            }

            \Illuminate\Support\Arr::set($data, $path, self::PLACEHOLDER.$type);
        }

        return $data;
    }

    // ---- Walking -----------------------------------------------------------

    /**
     * @param  array<int, string>  $modules
     */
    private function walk(Field $field, $value, string $path, ?string $context, array $modules): void
    {
        if (in_array($field->visibility(), ['hidden', 'computed'], true)) {
            if (! $this->isEmpty($value)) {
                $this->addSkipped($path, 'hidden');
            }

            return;
        }

        match ($field->type()) {
            'bard' => $this->bard($field, $value, $path, $context, $modules),
            'markdown', 'textarea', 'text' => $this->scalar($field, $value, $path, $context, $modules),
            'replicator' => $this->replicator($field, $value, $path, $modules),
            'grid' => $this->grid($field, $value, $path, $modules),
            'group' => $this->group($field, $value, $path, $modules),
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $modules
     */
    private function scalar(Field $field, $value, string $path, ?string $context, array $modules): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $reason = $this->notProse($field, $value);

        if ($reason !== null) {
            $this->addSkipped($path, $reason);

            return;
        }

        $this->leaves[] = new TextLeaf($path, $field->type(), $field->handle(), $this->topField, $context, $modules, $value, $field);
    }

    /**
     * @param  array<int, string>  $modules
     */
    private function bard(Field $field, $value, string $path, ?string $context, array $modules): void
    {
        if (is_string($value)) {
            if (trim(strip_tags($value)) === '' && ! str_contains($value, '<img')) {
                return;
            }

            $this->leaves[] = new TextLeaf($path, 'bard', $field->handle(), $this->topField, $context, $modules, $value, $field);

            return;
        }

        if (! is_array($value) || $value === []) {
            return;
        }

        $sets = [];
        $hasProse = false;

        foreach ($value as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) !== 'set') {
                $hasProse = $hasProse || $this->nodeHasContent($node);

                continue;
            }

            $id = (string) ($node['attrs']['id'] ?? '');
            $values = is_array($node['attrs']['values'] ?? null) ? $node['attrs']['values'] : [];
            $setHandle = (string) ($values['type'] ?? '');

            if (! $this->includeDisabled() && ($node['attrs']['enabled'] ?? true) === false) {
                continue;
            }

            $textField = $this->setTextField($field, $setHandle, $values, "{$path}.{$i}.attrs.values");

            // A set without an id cannot be found again in the answer; its text
            // stays as it is and the set goes back by position like a text-free one.
            if ($textField !== null && $id !== '') {
                $sets[$id] = $textField;
            }
        }

        if (! $hasProse && $sets === []) {
            $this->addSkipped($path, 'no_text');

            return;
        }

        $this->leaves[] = new TextLeaf($path, 'bard', $field->handle(), $this->topField, $context, $modules, $value, $field, $sets);
    }

    /**
     * The handle of the set field whose text travels inside the set's wrapper: the
     * first text-capable, visible, not-skipped field holding prose. Null when the
     * set carries no text (a video, a PDF) — such a set is not sent at all.
     *
     * @param  array<string, mixed>  $values
     */
    public function setTextField(Field $field, string $setHandle, array $values, string $path = ''): ?string
    {
        $fields = $this->setFields($field, $setHandle);

        if ($fields === null) {
            return null;
        }

        foreach ($fields as $handle => $candidate) {
            $handle = (string) $handle;

            if (! in_array($candidate->type(), self::TEXT_TYPES, true)
                || in_array($candidate->visibility(), ['hidden', 'computed'], true)) {
                continue;
            }

            $value = $values[$handle] ?? null;

            if ($this->isEmpty($value) || ($candidate->type() === 'bard' && is_array($value) && ! $this->bardHasText($value))) {
                continue;
            }

            if ($this->isSkipped($handle, $setHandle)) {
                $this->addSkipped($path.'.'.$handle, 'configured');

                continue;
            }

            if (is_string($value) && $candidate->type() !== 'bard' && ($reason = $this->notProse($candidate, $value)) !== null) {
                $this->addSkipped($path.'.'.$handle, $reason);

                continue;
            }

            return $handle;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $modules
     */
    private function replicator(Field $field, $value, string $path, array $modules): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (! $this->includeDisabled() && ($row['enabled'] ?? true) === false) {
                continue;
            }

            $setHandle = (string) ($row['type'] ?? '');
            $fields = $this->setFields($field, $setHandle);

            if ($fields === null) {
                continue;
            }

            $this->fields($fields, $row, "{$path}.{$i}", $setHandle, $this->scope($modules, $row));
        }
    }

    /**
     * @param  array<int, string>  $modules
     */
    private function grid(Field $field, $value, string $path, array $modules): void
    {
        $fields = is_array($value) ? $this->setFields($field, '') : null;

        if ($fields === null) {
            return;
        }

        foreach ($value as $i => $row) {
            if (is_array($row)) {
                $this->fields($fields, $row, "{$path}.{$i}", $field->handle(), $this->scope($modules, $row));
            }
        }
    }

    /**
     * @param  array<int, string>  $modules
     */
    private function group(Field $field, $value, string $path, array $modules): void
    {
        $fields = is_array($value) ? $this->setFields($field, '') : null;

        if ($fields !== null) {
            $this->fields($fields, $value, $path, $field->handle(), $modules);
        }
    }

    /**
     * @param  iterable<string, Field>  $fields
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $modules
     */
    private function fields(iterable $fields, array $data, string $prefix, string $context, array $modules): void
    {
        foreach ($fields as $handle => $field) {
            $handle = (string) $handle;

            if (! array_key_exists($handle, $data)) {
                continue;
            }

            if ($this->isSkipped($handle, $context)) {
                if (! $this->isEmpty($data[$handle])) {
                    $this->addSkipped("{$prefix}.{$handle}", 'configured');
                }

                continue;
            }

            $this->walk($field, $data[$handle], "{$prefix}.{$handle}", $context, $modules);
        }
    }

    // ---- Rules -------------------------------------------------------------

    /** Why a string value is not prose, or null when it is. */
    private function notProse(Field $field, string $value): ?string
    {
        if ($field->type() === 'text') {
            $inputType = (string) ($field->get('input_type') ?: 'text');

            if ($inputType !== 'text') {
                return 'input_type:'.$inputType;
            }
        }

        $trimmed = trim($value);

        if (preg_match('~^(https?://|mailto:|tel:|www\.)\S+$~i', $trimmed) === 1) {
            return 'url';
        }

        if (preg_match('~^<(iframe|script|blockquote)\b~i', $trimmed) === 1) {
            return 'embed';
        }

        return null;
    }

    /** Whether a nested field is named in `plain_language_skip_fields`. */
    private function isSkipped(string $handle, ?string $context): bool
    {
        return in_array('*.'.$handle, $this->skip, true)
            || ($context !== null && in_array($context.'.'.$handle, $this->skip, true));
    }

    private function includeDisabled(): bool
    {
        return filter_var(config('workflows.translation.plain_language.include_disabled', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * The entity scope inside a row: its own `content_fields.scope` list intersected
     * with the surrounding one — the same rule TranslatableText::enterScope() applies.
     *
     * @param  array<int, string>  $outer
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    private function scope(array $outer, array $values): array
    {
        $field = ContentFields::scope();
        $inner = TranslatableText::handles($field === null ? null : ($values[$field] ?? null));

        if ($inner === []) {
            return $outer;
        }

        return $outer === [] ? $inner : array_values(array_intersect($outer, $inner));
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * The fields of a set (or of a grid / group with an empty $setHandle), imports
     * resolved, or null when the field does not declare that set.
     *
     * @return \Illuminate\Support\Collection<string, Field>|null
     */
    private function setFields(Field $field, string $setHandle)
    {
        try {
            $fieldtype = $field->fieldtype();

            if ($setHandle === '') {
                return $fieldtype->fields()->all();
            }

            if (! method_exists($fieldtype, 'flattenedSetsConfig') || ! $fieldtype->flattenedSetsConfig()->has($setHandle)) {
                return null;
            }

            return $fieldtype->fields($setHandle)->all();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** A Bard node that would put something on the page: text, or an image. */
    private function nodeHasContent(array $node): bool
    {
        if (in_array($node['type'] ?? null, ['image'], true)) {
            return true;
        }

        if (isset($node['text']) && is_string($node['text'])) {
            return trim($node['text']) !== '';
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child) && $this->nodeHasContent($child)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, mixed>  $nodes */
    private function bardHasText(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (is_array($node) && ($node['type'] ?? null) !== 'set' && $this->nodeHasContent($node)) {
                return true;
            }
        }

        return false;
    }

    private function isEmpty($value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');
    }

    private function addSkipped(string $path, string $reason): void
    {
        $this->skipped[] = ['path' => $path, 'reason' => $reason];
    }
}
