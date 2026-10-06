<?php

namespace TakepartMedia\Workflows\Content;

use TakepartMedia\Workflows\Support\ContentFields;
use Illuminate\Support\Str;
use Statamic\Fields\Value;
use Statamic\Fields\Values;

/**
 * Builds a read-only, flattened preview of an entry's editorial content for the
 * workflow detail view: text/headings/lists and inline images are rendered as
 * HTML (via Bard augmentation, which resolves localized values and asset URLs),
 * videos become embeds, and every other set is reduced to a short textual line —
 * no full field/fieldset UI.
 *
 * Each block carries its source `handle` (the fieldset/set handle) and a `uuid`
 * that identifies the passage so comments can be attached and reliably re-found.
 * The uuid is STABLE across requests: Bard sets use their stored set id
 * (`attrs.id`); other passages use the field handle (+ position), unique per entry.
 *
 * Block shapes (plus `handle` + `uuid` on every block):
 *   ['kind' => 'html',    'html' => '…', 'note' => bool?]
 *   ['kind' => 'video',   'url' => '…', 'embedUrl' => '…'|null, 'provider' => '…'|null]
 *   ['kind' => 'heading', 'text' => '…']
 *   ['kind' => 'text',    'text' => '…', 'label' => '…'?]
 */
class EntryContentPreview
{
    public function blocks($entry): array
    {
        $blocks = [];

        if (ContentFields::intro() !== null) {
            $this->appendBard($blocks, $entry, ContentFields::intro());
        }

        $this->appendBard($blocks, $entry, ContentFields::body());
        $this->appendSources($blocks, $entry);
        $this->appendPodcast($blocks, $entry);

        return $blocks;
    }

    /**
     * Stable content hash for the Bard-field container the passage sits in.
     * Complements `contentHashForPassage()`: while that one flips only when
     * the specific passage-uuid's payload changes, this one flips whenever
     * anything in the enclosing field changes — sibling sets added, removed,
     * reordered, or edited. Used to render "something else in this area was
     * changed" alongside the per-passage stale badge.
     *
     * Returns null when the passage no longer exists (orphan) or no block in
     * the container is present.
     */
    public function containerHashForPassage($entry, string $passageRef): ?string
    {
        $blocks = $this->blocks($entry);

        $container = null;
        foreach ($blocks as $block) {
            if (($block['uuid'] ?? null) === $passageRef) {
                $container = $block['container'] ?? null;
                break;
            }
        }

        if ($container === null) {
            return null;
        }

        return $this->hashContainer($blocks, $container);
    }

    /**
     * Batch variant: resolves current container hashes for a set of passage
     * refs on one entry with a single `blocks()` walk and a per-container
     * memo, so a comment drawer with N comments in the same container hashes
     * that container exactly once.
     *
     * @param  array<int, string>  $passageRefs
     * @return array<string, string|null>  keyed by passage_ref
     */
    public function containerHashesForPassages($entry, array $passageRefs): array
    {
        $wanted = array_flip(array_unique(array_filter($passageRefs, 'is_string')));
        $result = array_fill_keys(array_keys($wanted), null);

        if ($wanted === []) {
            return $result;
        }

        $blocks = $this->blocks($entry);

        // Map passage_ref → container so we know which container to hash for
        // each ref. Refs that don't match any block stay null (orphan).
        $refToContainer = [];
        foreach ($blocks as $block) {
            $uuid = $block['uuid'] ?? null;
            if (is_string($uuid) && isset($wanted[$uuid])) {
                $refToContainer[$uuid] = $block['container'] ?? null;
            }
        }

        // Hash each container at most once.
        $containerHashes = [];
        foreach ($refToContainer as $ref => $container) {
            if ($container === null) {
                continue;
            }
            if (! isset($containerHashes[$container])) {
                $containerHashes[$container] = $this->hashContainer($blocks, $container);
            }
            $result[$ref] = $containerHashes[$container];
        }

        return $result;
    }

    private function hashContainer(array $blocks, string $container): ?string
    {
        $filtered = [];
        foreach ($blocks as $block) {
            if (($block['container'] ?? null) !== $container) {
                continue;
            }
            $payload = $block;
            // handle/uuid stay stable across content edits; container is what
            // we group by, and hashing it is redundant. Content-carrying keys
            // are all that matter for change detection.
            unset($payload['handle'], $payload['uuid'], $payload['container']);
            $filtered[] = $payload;
        }

        if ($filtered === []) {
            return null;
        }

        return hash('sha256', json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Stable content hash for a single passage, identified by its `uuid`
     * (`passage_ref` in the comments table). Returns null when the passage no
     * longer exists in the entry — callers can treat that as an orphan.
     *
     * Used at comment-write time to record what the passage looked like, and
     * at render time to detect that the passage has since been edited (the
     * "stale" badge in the comment drawer). Only the content-carrying fields
     * of the block are hashed; `handle` and `uuid` are metadata and stay
     * stable across edits.
     */
    public function contentHashForPassage($entry, string $passageRef): ?string
    {
        foreach ($this->blocks($entry) as $block) {
            if (($block['uuid'] ?? null) !== $passageRef) {
                continue;
            }

            $payload = $block;
            // Strip metadata keys that don't represent editorial content, so
            // renaming/regrouping under the same passage id keeps the hash
            // stable. Content-carrying keys are all that matter.
            unset($payload['handle'], $payload['uuid'], $payload['container']);

            return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return null;
    }

    /**
     * Batch variant: pre-compute the current hash for a set of passage refs on
     * one entry. Cheaper than hashing per comment when a drawer renders many.
     *
     * @param  array<int, string>  $passageRefs
     * @return array<string, string|null>  keyed by passage_ref
     */
    public function contentHashesForPassages($entry, array $passageRefs): array
    {
        $wanted = array_flip(array_unique(array_filter($passageRefs, 'is_string')));
        $result = array_fill_keys(array_keys($wanted), null);

        foreach ($this->blocks($entry) as $block) {
            $ref = $block['uuid'] ?? null;
            if (! is_string($ref) || ! isset($wanted[$ref])) {
                continue;
            }

            $payload = $block;
            unset($payload['handle'], $payload['uuid'], $payload['container']);

            $result[$ref] = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $result;
    }

    private function appendBard(array &$blocks, $entry, string $handle): void
    {
        $value = $this->augmented($entry, $handle);

        // Plain bard (no sets) augments to an HTML string. Stable id = field handle.
        if (is_string($value)) {
            $this->pushHtml($blocks, $handle, $handle, $handle, $value);

            return;
        }

        if (! is_iterable($value)) {
            return;
        }

        foreach ($value as $i => $item) {
            $data = $item instanceof Values ? $item->all() : (array) $item;
            $type = (string) $this->scalar($data['type'] ?? '');
            $setId = $this->scalar($data['id'] ?? null);
            // Stable passage id: Bard set id for sets, else the field handle + index.
            // (Only needs to be unique per entry, since comments are scoped by subject.)
            $uuid = $setId ? (string) $setId : $handle.':'.$i;

            // $handle here is the enclosing Bard field (intro / content_sets).
            // It becomes each block's `container` — used to group siblings for
            // the container-hash. `pushXxx` still receives the set-type as its
            // display handle, so filters that key off handle keep working.
            // Plain Bard text between sets — its handle is the bard field itself.
            if ($type === 'text') {
                $this->pushHtml($blocks, $handle, $handle, $uuid, (string) $this->scalar($data['text'] ?? ''));

                continue;
            }

            // Sets — labelled with their own set handle, read as `content_fields.sets` says.
            $this->pushSet($blocks, $handle, $type, $uuid, $data);
        }
    }

    /**
     * One Bard set, read the way `content_fields.sets` describes its type. A type the
     * project has not described is left out.
     */
    private function pushSet(array &$blocks, string $container, string $type, string $uuid, array $data): void
    {
        [$kind, $field, $extra] = array_pad(ContentFields::sets()[$type] ?? [], 3, null);

        if ($kind === null || $field === null) {
            return;
        }

        $value = $this->scalar($data[$field] ?? null);

        match ($kind) {
            'html' => $this->pushHtml($blocks, $container, $type, $uuid, (string) $value),
            'heading' => $this->pushHeading($blocks, $container, $type, $uuid, (string) $value, (string) $this->scalar($data[$extra] ?? '')),
            'video' => $this->pushVideo($blocks, $container, $type, $uuid, (string) $value),
            'asset' => $this->pushText($blocks, $container, $type, $uuid, $extra, $this->assetName($value)),
            'code' => $this->pushText($blocks, $container, $type, $uuid, $extra, $this->codeText($value)),
            default => null,
        };
    }

    private function appendSources(array &$blocks, $entry): void
    {
        $textField = ContentFields::sourcesText();

        if ($textField === null) {
            return;
        }

        $html = $this->augmented($entry, $textField);

        if (! is_string($html) || trim(strip_tags($html)) === '') {
            return;
        }

        // dd_title + dd_text form one editorial "sources" block group — the
        // heading is part of the same container so a comment on either flags
        // when the group is edited as a whole.
        $titleField = ContentFields::sourcesTitle();
        $title = $titleField === null ? '' : trim((string) $this->scalar($this->augmented($entry, $titleField)));
        if ($title !== '') {
            $this->pushHeading($blocks, $textField, $titleField, $titleField, $title, '');
        }

        $this->pushHtml($blocks, $textField, $textField, $textField, $html);
    }

    private function appendPodcast(array &$blocks, $entry): void
    {
        $linkField = ContentFields::podcastLink();
        $codeField = ContentFields::podcastCode();
        $link = $linkField === null ? '' : trim((string) $this->scalar($this->augmented($entry, $linkField)));
        $code = $codeField === null ? '' : trim((string) $this->scalar($this->augmented($entry, $codeField)));

        if ($link !== '') {
            $this->pushText($blocks, 'podcast', 'podcast', 'podcast', 'Podcast', $link);
        } elseif ($code !== '') {
            $this->pushText($blocks, 'podcast', 'podcast', 'podcast', 'Podcast', __('workflows::messages.embedded_podcast'));
        }
    }

    // ---- Block builders ----------------------------------------------------

    // Signature: (container, handle, uuid, ...content). `container` is the
    // enclosing Bard-field name (used to group siblings for the container
    // hash). `handle` stays the display key (set type for bard sets, field
    // handle for plain bard text) that filters key off.

    private function pushHtml(array &$blocks, string $container, string $handle, string $uuid, string $html, bool $note = false): void
    {
        if (trim(strip_tags($html)) === '' && ! str_contains($html, '<img')) {
            return;
        }

        $blocks[] = array_filter([
            'kind' => 'html',
            'container' => $container,
            'handle' => $handle,
            'uuid' => $uuid,
            'html' => $html,
            'note' => $note ?: null,
        ], fn ($v) => $v !== null);
    }

    private function pushHeading(array &$blocks, string $container, string $handle, string $uuid, string $headline, string $subheadline): void
    {
        if (trim($headline) !== '') {
            $blocks[] = ['kind' => 'heading', 'container' => $container, 'handle' => $handle, 'uuid' => $uuid, 'text' => $headline];
        }
        if (trim($subheadline) !== '') {
            $blocks[] = ['kind' => 'text', 'container' => $container, 'handle' => $handle, 'uuid' => $uuid, 'text' => $subheadline];
        }
    }

    private function pushVideo(array &$blocks, string $container, string $handle, string $uuid, string $url): void
    {
        if ($url === '') {
            return;
        }

        [$embedUrl, $provider] = $this->videoEmbed($url);

        $blocks[] = ['kind' => 'video', 'container' => $container, 'handle' => $handle, 'uuid' => $uuid, 'url' => $url, 'embedUrl' => $embedUrl, 'provider' => $provider];
    }

    private function pushText(array &$blocks, string $container, string $handle, string $uuid, ?string $label, string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        $blocks[] = array_filter([
            'kind' => 'text',
            'container' => $container,
            'handle' => $handle,
            'uuid' => $uuid,
            'label' => $label,
            'text' => $text,
        ], fn ($v) => $v !== null);
    }

    // ---- Helpers -----------------------------------------------------------

    private function augmented($entry, string $handle)
    {
        return $entry->augmentedValue($handle)->value();
    }

    private function scalar($value)
    {
        return $value instanceof Value ? $value->value() : $value;
    }

    private function assetName($file): string
    {
        if (is_string($file)) {
            return $file;
        }

        if (is_object($file) && method_exists($file, 'basename')) {
            return (string) $file->basename();
        }

        return (string) (data_get($file, 'basename') ?? data_get($file, 'title') ?? '');
    }

    private function codeText($code): string
    {
        $code = is_array($code) ? ($code['code'] ?? '') : $code;

        return Str::limit(trim((string) $code), 200);
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
}
