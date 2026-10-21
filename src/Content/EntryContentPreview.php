<?php

namespace TakepartMedia\Workflows\Content;

use Statamic\Facades\Blink;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * The read-only content preview of an entry for the workflow detail view and
 * the comment anchors — a facade over ContentWalker that decides WHICH fields
 * are content (per workflow, see ContentFieldResolver) and hashes passages for
 * the comment drawer's "text changed" badges.
 *
 * Block shape: see ContentWalker. Every block has a stable `uuid` (the
 * comment's `passage_ref`) and a `container` (what the container hash groups by).
 *
 * The block list is memoized per request (Blink) by entry id, locale, last
 * modification and the resolved field list: a detail page asks for it for the
 * content panel, the comment hashes and the unread count, and walking a deep
 * Replicator three times for the same answer is wasted work.
 */
class EntryContentPreview
{
    /**
     * @param  \Statamic\Contracts\Entries\Entry  $entry
     * @param  string|null  $workflow  the workflow in scope; null = auto-detect the fields
     * @return array<int, array<string, mixed>>
     */
    public function blocks($entry, ?string $workflow = null): array
    {
        $fields = $this->fields($entry, $workflow);

        if ($fields === []) {
            return [];
        }

        $key = implode('|', [
            $entry->id(),
            $entry->locale(),
            $entry->lastModified()?->timestamp,
            implode(',', $fields),
        ]);

        return Blink::store('workflows-content-blocks')
            ->once($key, fn () => (new ContentWalker)->walk($entry, $fields));
    }

    /**
     * The top-level content fields of this entry under the workflow.
     *
     * @return array<int, string>
     */
    public function fields($entry, ?string $workflow = null): array
    {
        $raw = [];

        if ($workflow !== null && $workflow !== '') {
            try {
                $raw = app(DefinitionLoader::class)->raw($workflow);
            } catch (\InvalidArgumentException) {
                $raw = [];
            }
        }

        return app(WorkflowResolver::class)->contentFields($raw, $entry);
    }

    /**
     * Stable content hash for the container the passage sits in.
     * Complements `contentHashForPassage()`: while that one flips only when
     * the specific passage-uuid's payload changes, this one flips whenever
     * anything in the enclosing container changes — siblings added, removed,
     * reordered, or edited. Used to render "something else in this area was
     * changed" alongside the per-passage stale badge.
     *
     * Returns null when the passage no longer exists (orphan).
     */
    public function containerHashForPassage($entry, string $passageRef, ?string $workflow = null): ?string
    {
        return $this->containerHashesForPassages($entry, [$passageRef], $workflow)[$passageRef] ?? null;
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
    public function containerHashesForPassages($entry, array $passageRefs, ?string $workflow = null): array
    {
        $wanted = array_flip(array_unique(array_filter($passageRefs, 'is_string')));
        $result = array_fill_keys(array_keys($wanted), null);

        if ($wanted === []) {
            return $result;
        }

        $blocks = $this->blocks($entry, $workflow);

        // Map passage_ref → container so we know which container to hash for
        // each ref. Refs that don't match any block stay null (orphan).
        $refToContainer = [];
        foreach ($blocks as $block) {
            $uuid = $block['uuid'] ?? null;
            if (is_string($uuid) && isset($wanted[$uuid])) {
                $refToContainer[$uuid] = $block['container'] ?? null;
            }
        }

        $containerHashes = [];
        foreach ($refToContainer as $ref => $container) {
            if ($container === null) {
                continue;
            }
            $containerHashes[$container] ??= $this->hashContainer($blocks, $container);
            $result[$ref] = $containerHashes[$container];
        }

        return $result;
    }

    /**
     * Stable content hash for a single passage, identified by its `uuid`
     * (`passage_ref` in the comments table). Returns null when the passage no
     * longer exists in the entry — callers can treat that as an orphan.
     *
     * Used at comment-write time to record what the passage looked like, and
     * at render time to detect that the passage has since been edited (the
     * "stale" badge in the comment drawer).
     */
    public function contentHashForPassage($entry, string $passageRef, ?string $workflow = null): ?string
    {
        return $this->contentHashesForPassages($entry, [$passageRef], $workflow)[$passageRef] ?? null;
    }

    /**
     * Batch variant: pre-compute the current hash for a set of passage refs on
     * one entry. Cheaper than hashing per comment when a drawer renders many.
     *
     * @param  array<int, string>  $passageRefs
     * @return array<string, string|null>  keyed by passage_ref
     */
    public function contentHashesForPassages($entry, array $passageRefs, ?string $workflow = null): array
    {
        $wanted = array_flip(array_unique(array_filter($passageRefs, 'is_string')));
        $result = array_fill_keys(array_keys($wanted), null);

        if ($wanted === []) {
            return $result;
        }

        foreach ($this->blocks($entry, $workflow) as $block) {
            $ref = $block['uuid'] ?? null;
            if (is_string($ref) && isset($wanted[$ref])) {
                $result[$ref] = $this->hash($this->hashPayload($block));
            }
        }

        return $result;
    }

    /**
     * The content-carrying part of a block. Position and naming (uuid,
     * container, path, depth, handle, labels) stay out, so moving a passage or
     * renaming a field in the blueprint keeps the hash; so do values derived
     * from the content (embed URLs, thumbnails), which can change with the
     * addon's own code.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public function hashPayload(array $block): array
    {
        unset($block['uuid'], $block['container'], $block['path'], $block['depth'], $block['handle'], $block['label']);

        if (isset($block['items']) && is_array($block['items'])) {
            $block['items'] = array_map(function ($item) {
                unset($item['label'], $item['embedUrl'], $item['provider'], $item['thumb']);

                if (isset($item['assets']) && is_array($item['assets'])) {
                    $item['assets'] = array_map(function ($asset) {
                        unset($asset['thumb']);

                        return $asset;
                    }, $item['assets']);
                }

                return $item;
            }, $block['items']);
        }

        return $block;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function hashContainer(array $blocks, string $container): ?string
    {
        $filtered = [];
        foreach ($blocks as $block) {
            if (($block['container'] ?? null) === $container) {
                $filtered[] = $this->hashPayload($block);
            }
        }

        return $filtered === [] ? null : $this->hash($filtered);
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
