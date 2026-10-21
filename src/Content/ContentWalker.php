<?php

namespace TakepartMedia\Workflows\Content;

use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Fields\Field;
use Statamic\Fields\Value;
use Statamic\Fields\Values;
use Statamic\Support\Arr;

/**
 * Walks an entry's content fields into a flat, read-only list of blocks for the
 * workflow detail view and the comment anchors. The structure of the content
 * does not matter: whatever the workflow names as content (a Bard field, a
 * Replicator of sections holding Replicators of Bard, a Markdown field …) is
 * read through Statamic's own augmentation, so localized values, asset URLs
 * and disabled rows/sets are already settled when the walker sees them. Every
 * nested Value knows its fieldtype — and through it its blueprint Field — so no
 * project-specific configuration is needed.
 *
 * Two kinds of block:
 *
 *   - `html`   Bard text between sets, a Bard field without sets, Markdown.
 *   - `fields` a structural node — a Bard set, a Replicator or Grid row, a
 *              Group — with its scalar children as `items` (text, code, video,
 *              media). Its rich children (Bard, Markdown, Replicator, Grid,
 *              Group) follow as their own blocks one `depth` deeper.
 *
 * Every block carries:
 *
 *   uuid       stable passage id comments are anchored to (see below)
 *   container  the nearest enclosing structural node (its uuid), or the
 *              top-level field handle — groups siblings for the container hash
 *   path       dotted data path inside the entry, for orientation only
 *   depth      nesting level, 0 = directly in a top-level content field
 *   handle     the set type for sets/rows, else the field handle
 *   label      what an editor calls it (set display / field display)
 *
 * UUIDs are stable across requests and unique per entry:
 *   - Bard set / Replicator or Grid row: its stored row id, else "$fieldPath:$i"
 *   - top-level Bard text chunk: "$handle:$i" (as before the walker existed)
 *   - nested Bard text chunk: "$parentUuid.$field:$i"
 *   - top-level Bard/Markdown/text field read as one block: "$handle"
 *   - nested Bard/Markdown field read as one block: "$parentUuid.$field"
 *   - Group: "$parentUuid.$handle" (top level: "$handle")
 * A collision (a row duplicated with its id, say) gets a "~2", "~3" suffix.
 */
class ContentWalker
{
    /** Fieldtypes whose value is a structure or prose and gets blocks of its own. */
    private const RICH = ['bard', 'markdown', 'replicator', 'grid', 'group'];

    /** Fieldtypes shown as items inside their structural node's block. */
    private const SCALAR = ['text', 'textarea', 'code', 'video', 'assets'];

    /** @var array<int, array<string, mixed>> */
    private array $blocks = [];

    /** @var array<string, int> */
    private array $seen = [];

    /**
     * @param  \Statamic\Contracts\Entries\Entry  $entry
     * @param  array<int, string>  $handles  top-level content fields, in order
     * @return array<int, array<string, mixed>>
     */
    public function walk($entry, array $handles): array
    {
        $this->blocks = [];
        $this->seen = [];

        foreach ($handles as $handle) {
            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $value = $entry->augmentedValue($handle);

            if (! $value instanceof Value || ! $value->fieldtype()) {
                continue;
            }

            $this->topLevel($handle, $value);
        }

        $blocks = $this->blocks;
        $this->blocks = [];
        $this->seen = [];

        return $blocks;
    }

    // ---- Top level ---------------------------------------------------------

    private function topLevel(string $handle, Value $value): void
    {
        $type = $value->fieldtype()->handle();
        $label = $this->label($value, $handle);

        match ($type) {
            'bard' => $this->bard($value, $handle, $handle, $handle, null, $handle, 0, $label),
            'markdown' => $this->html($handle, $handle, $handle, 0, $handle, $label, (string) $value->value()),
            'replicator', 'grid' => $this->rows($value, $handle, $handle, $handle, 0),
            'group' => $this->node($this->groupValues($value), $handle, $handle, $handle, 0, $handle, $label),
            // A lone scalar field named as content: one block carrying just that item.
            default => in_array($type, self::SCALAR, true)
                ? $this->fieldsBlock($handle, $handle, $handle, 0, $handle, $label, array_filter([$this->item($value, $handle)]))
                : null,
        };
    }

    // ---- Rich fields -------------------------------------------------------

    /**
     * A Bard field. Without sets it augments to one HTML string; with sets to a
     * list of text chunks and sets.
     *
     * @param  string  $chunkPrefix  uuid prefix of its text chunks ("intro" or "<parent>.content")
     * @param  string|null  $wholeUuid  uuid when the field reads as a single HTML block
     */
    private function bard(Value $value, string $field, string $path, string $container, ?string $parentUuid, string $chunkPrefix, int $depth, string $label): void
    {
        $augmented = $value->value();
        $wholeUuid = $parentUuid === null ? $field : $parentUuid.'.'.$field;

        if (is_string($augmented)) {
            $this->html($wholeUuid, $container, $path, $depth, $field, $label, $augmented);

            return;
        }

        if (! is_iterable($augmented)) {
            return;
        }

        $sets = $this->setsConfig($value);

        foreach (array_values($this->arrayOf($augmented)) as $i => $item) {
            $data = $this->arrayOf($item);
            $itemPath = $path.'.'.$i;

            // Text chunks between sets carry no row id — that tells them apart
            // from a set whose handle happens to be `text`.
            if (! array_key_exists('id', $data) && ($data['type'] ?? null) === 'text') {
                $this->html($chunkPrefix.':'.$i, $container, $itemPath, $depth, $field, $label, (string) $this->plain($data['text'] ?? ''));

                continue;
            }

            $setType = (string) $this->plain($data['type'] ?? '');
            $uuid = $this->plain($data['id'] ?? null) ?: $path.':'.$i;

            $this->node($data, (string) $uuid, $container, $itemPath, $depth, $setType, $this->setLabel($sets, $setType));
        }
    }

    /**
     * Replicator or Grid rows — each one a structural node.
     */
    private function rows(Value $value, string $field, string $path, string $container, int $depth): void
    {
        $augmented = $value->value();

        if (! is_iterable($augmented)) {
            return;
        }

        $isGrid = $value->fieldtype()->handle() === 'grid';
        $sets = $isGrid ? [] : $this->setsConfig($value);
        $fieldLabel = $this->label($value, $field);

        foreach (array_values($this->arrayOf($augmented)) as $i => $row) {
            $data = $this->arrayOf($row);
            $setType = $isGrid ? $field : (string) $this->plain($data['type'] ?? '');
            $uuid = $this->plain($data['id'] ?? null) ?: $path.':'.$i;
            $label = $isGrid ? $fieldLabel : $this->setLabel($sets, $setType);

            $this->node($data, (string) $uuid, $container, $path.'.'.$i, $depth, $setType, $label);
        }
    }

    /**
     * One structural node: a `fields` block with its scalar children, followed
     * by its rich children one level deeper. Left out entirely when it has
     * neither — an empty spacer row is not content.
     *
     * @param  array<string, mixed>  $data  the node's augmented values (Value objects + id/type)
     */
    private function node(array $data, string $uuid, string $container, string $path, int $depth, string $handle, string $label): void
    {
        $uuid = $this->unique($uuid);
        $items = [];
        $children = [];

        foreach ($data as $key => $child) {
            if (! $child instanceof Value || ! $child->fieldtype() || ! $this->visible($child)) {
                continue;
            }

            $type = $child->fieldtype()->handle();

            if (in_array($type, self::SCALAR, true)) {
                if ($item = $this->item($child, (string) $key)) {
                    $items[] = $item;
                }
            } elseif (in_array($type, self::RICH, true)) {
                $children[] = [(string) $key, $child, $type];
            }
        }

        // Header first, so the children render under it; dropped again below if
        // it turns out to frame nothing.
        $index = count($this->blocks);
        $this->fieldsBlock($uuid, $container, $path, $depth, $handle, $label, $items, force: true);

        foreach ($children as [$key, $child, $type]) {
            $childPath = $path.'.'.$key;
            $childLabel = $this->label($child, $key);

            match ($type) {
                'bard' => $this->bard($child, $key, $childPath, $uuid, $uuid, $uuid.'.'.$key, $depth + 1, $childLabel),
                'markdown' => $this->html($uuid.'.'.$key, $uuid, $childPath, $depth + 1, $key, $childLabel, (string) $child->value()),
                'replicator', 'grid' => $this->rows($child, $key, $childPath, $uuid, $depth + 1),
                'group' => $this->node($this->groupValues($child), $uuid.'.'.$key, $uuid, $childPath, $depth + 1, $key, $childLabel),
            };
        }

        if ($items === [] && count($this->blocks) === $index + 1) {
            array_pop($this->blocks);
            unset($this->seen[$uuid]);
        }
    }

    // ---- Scalar items ------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function item(Value $value, string $handle): ?array
    {
        $label = $this->label($value, $handle);
        $augmented = $value->value();

        switch ($value->fieldtype()->handle()) {
            case 'text':
            case 'textarea':
                $text = trim((string) $this->plain($augmented));

                return $text === '' ? null : ['label' => $label, 'kind' => 'text', 'text' => $text];

            case 'code':
                $code = is_object($augmented) && method_exists($augmented, 'toArray')
                    ? ($augmented->toArray()['code'] ?? '')
                    : (is_array($augmented) ? ($augmented['code'] ?? '') : $augmented);
                $code = rtrim((string) $code);

                return trim($code) === '' ? null : ['label' => $label, 'kind' => 'code', 'code' => $code];

            case 'video':
                $url = trim((string) $this->plain($augmented));

                if ($url === '') {
                    return null;
                }

                [$embedUrl, $provider] = $this->videoEmbed($url);

                return ['label' => $label, 'kind' => 'video', 'url' => $url, 'embedUrl' => $embedUrl, 'provider' => $provider];

            case 'assets':
                $assets = $this->assets($augmented);

                return $assets === [] ? null : ['label' => $label, 'kind' => 'media', 'assets' => $assets];
        }

        return null;
    }

    /**
     * @return array<int, array{basename: string, url: ?string, isImage: bool, thumb?: ?string}>
     */
    private function assets($augmented): array
    {
        if ($augmented === null || $augmented === '') {
            return [];
        }

        if (is_object($augmented) && method_exists($augmented, 'get') && ! method_exists($augmented, 'basename')) {
            $augmented = $augmented->get(); // query builder → collection
        }

        $list = is_object($augmented) && method_exists($augmented, 'basename')
            ? [$augmented]
            : ($augmented instanceof LaravelCollection ? $augmented->all() : (array) $augmented);

        $result = [];

        foreach ($list as $asset) {
            if (is_string($asset)) {
                $result[] = ['basename' => basename($asset), 'url' => null, 'isImage' => false];

                continue;
            }

            if (! is_object($asset) || ! method_exists($asset, 'basename')) {
                continue;
            }

            $isImage = method_exists($asset, 'isImage') && (bool) $asset->isImage();

            $result[] = array_filter([
                'basename' => (string) $asset->basename(),
                'url' => $asset->url(),
                'isImage' => $isImage,
                'thumb' => $isImage && method_exists($asset, 'thumbnailUrl') ? $this->thumb($asset) : null,
            ], fn ($v) => $v !== null);
        }

        return $result;
    }

    private function thumb($asset): ?string
    {
        try {
            return $asset->thumbnailUrl('small');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build an embeddable player URL for Vimeo/YouTube. Returns [embedUrl, provider]
     * with embedUrl null when the host isn't recognised (the view falls back to a link).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function videoEmbed(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        if (str_contains($host, 'vimeo.com')) {
            if (str_contains($host, 'player.vimeo.com')) {
                return [$url, 'vimeo'];
            }
            if (preg_match('#vimeo\.com/(\d+)(?:/([a-zA-Z0-9]+))?#', $url, $m)) {
                $embed = 'https://player.vimeo.com/video/'.$m[1];
                if (! empty($m[2])) {
                    $embed .= '?h='.$m[2];
                }

                return [$embed, 'vimeo'];
            }
        }

        if (str_contains($host, 'youtu')) {
            $id = null;
            if (str_contains($host, 'youtu.be')) {
                $id = trim(parse_url($url, PHP_URL_PATH) ?? '', '/');
            } else {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
                $id = $q['v'] ?? null;
                if (! $id && preg_match('#/embed/([a-zA-Z0-9_-]+)#', $url, $m)) {
                    $id = $m[1];
                }
            }

            if ($id) {
                return ['https://www.youtube-nocookie.com/embed/'.$id, 'youtube'];
            }
        }

        return [null, null];
    }

    // ---- Block builders ----------------------------------------------------

    private function html(string $uuid, string $container, string $path, int $depth, string $handle, string $label, string $html): void
    {
        if (trim(strip_tags($html)) === '' && ! str_contains($html, '<img')) {
            return;
        }

        $this->blocks[] = [
            'uuid' => $this->unique($uuid),
            'container' => $container,
            'path' => $path,
            'depth' => $depth,
            'handle' => $handle,
            'label' => $label,
            'kind' => 'html',
            'html' => $html,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function fieldsBlock(string $uuid, string $container, string $path, int $depth, string $handle, string $label, array $items, bool $force = false): void
    {
        if ($items === [] && ! $force) {
            return;
        }

        $this->blocks[] = [
            'uuid' => $force ? $uuid : $this->unique($uuid),
            'container' => $container,
            'path' => $path,
            'depth' => $depth,
            'handle' => $handle,
            'label' => $label,
            'kind' => 'fields',
            'items' => array_values($items),
        ];
    }

    // ---- Helpers -----------------------------------------------------------

    private function unique(string $uuid): string
    {
        if (! isset($this->seen[$uuid])) {
            $this->seen[$uuid] = 1;

            return $uuid;
        }

        $n = ++$this->seen[$uuid];
        $candidate = $uuid.'~'.$n;

        while (isset($this->seen[$candidate])) {
            $candidate = $uuid.'~'.(++$n);
            $this->seen[$uuid] = $n;
        }

        $this->seen[$candidate] = 1;

        return $candidate;
    }

    /** Hidden and computed fields are not editorial content. */
    private function visible(Value $value): bool
    {
        $field = $value->fieldtype()->field();

        return ! $field instanceof Field || ! in_array($field->visibility(), ['hidden', 'computed'], true);
    }

    private function label(Value $value, string $fallback): string
    {
        $field = $value->fieldtype()?->field();
        $display = $field instanceof Field ? $field->display() : null;

        return (string) ($display ?: $fallback);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function setsConfig(Value $value): array
    {
        $fieldtype = $value->fieldtype();

        if (! method_exists($fieldtype, 'flattenedSetsConfig')) {
            return [];
        }

        $config = $fieldtype->flattenedSetsConfig();

        return $config instanceof LaravelCollection ? $config->all() : (array) $config;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sets
     */
    private function setLabel(array $sets, string $type): string
    {
        $display = Arr::get($sets, $type.'.display');

        return (string) ($display ? __($display) : $type);
    }

    /**
     * @return array<string, mixed>
     */
    private function groupValues(Value $value): array
    {
        return $this->arrayOf($value->value());
    }

    /**
     * The raw entries of a Values bag (Value objects kept), or an array as is.
     *
     * @return array<int|string, mixed>
     */
    private function arrayOf($value): array
    {
        if ($value instanceof Values) {
            return $value->getProxiedInstance()->all();
        }

        if ($value instanceof LaravelCollection) {
            return $value->all();
        }

        return is_array($value) ? $value : (is_iterable($value) ? iterator_to_array($value) : []);
    }

    private function plain($value)
    {
        return $value instanceof Value ? $value->value() : $value;
    }
}
