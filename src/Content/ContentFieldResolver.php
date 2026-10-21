<?php

namespace TakepartMedia\Workflows\Content;

/**
 * The top-level fields that ARE an entry's content under one workflow — the
 * single answer both the readers (preview, comments) and the writers (plain
 * language) go by.
 *
 * Three steps:
 *
 *   1. `metadata.content_fields`, filtered to the fields the entry's own
 *      blueprint has (one workflow may govern collections with different
 *      blueprints). Order kept, duplicates dropped.
 *   2. otherwise auto-detection on the entry's blueprint (see
 *      ContentFieldCatalog::autoDetectAll()): the configured intro + body if
 *      present, else the first Bard / Markdown / Replicator field.
 *   3. otherwise nothing — the entry simply has no content to show.
 *
 * Step 1 falling through to step 2 is intentional: a workflow listing only
 * fields this blueprint lacks reads the blueprint's natural content instead
 * of an empty page. The linter warns about exactly that mismatch.
 */
class ContentFieldResolver
{
    /**
     * @param  \Statamic\Contracts\Entries\Entry  $entry
     * @param  array<string, mixed>  $raw  the workflow definition
     * @return array<int, string>
     */
    public static function for($entry, array $raw): array
    {
        $blueprint = $entry?->blueprint();

        if ($blueprint === null) {
            return [];
        }

        $present = $blueprint->fields()->all();

        $configured = collect((array) ($raw['metadata']['content_fields'] ?? []))
            ->filter(fn ($handle) => is_string($handle) && $handle !== '' && $present->has($handle))
            ->unique()
            ->values()
            ->all();

        if ($configured !== []) {
            return $configured;
        }

        return ContentFieldCatalog::autoDetectAll($blueprint);
    }
}
