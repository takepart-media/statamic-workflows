<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Facades\Collection;
use Statamic\Statamic;

/**
 * `metadata.revisions` — the workflow's say over Statamic revisions on the
 * collections it governs.
 *
 *   revisions: true     # on for every collection of the workflow
 *   revisions: false    # off
 *   (absent)            # the collections are left as they are
 *
 * Applied by WRITING the collection (`revisions:` in its YAML) when the workflow
 * is saved. Setting it at runtime does not work: Statamic hands out a fresh
 * collection object on every lookup, so a flag set at boot is gone by the time
 * the entry form asks.
 *
 * Revisions themselves need Statamic Pro and the global switch
 * (`statamic.revisions.enabled`); without either the collection keeps the value
 * but Statamic treats it as off — available() says so to the editor.
 */
final class CollectionRevisions
{
    public const KEY = 'revisions';

    /**
     * The workflow's choice, or null when it leaves the collections alone.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function wanted(array $raw): ?bool
    {
        $value = $raw['metadata'][self::KEY] ?? null;

        return is_bool($value) ? $value : null;
    }

    /** Whether Statamic would honour revisions at all here. */
    public static function available(): bool
    {
        return Statamic::pro() && (bool) config('statamic.revisions.enabled');
    }

    /** Whether the collection itself asks for revisions (its YAML), Pro aside. */
    public static function stored(string $handle): bool
    {
        $collection = Collection::find($handle);

        return $collection !== null && (bool) ($collection->fileData()['revisions'] ?? false);
    }

    /**
     * Write the workflow's choice into its collections. Only those whose value
     * differs are saved. Returns the handles that changed.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public static function apply(array $raw): array
    {
        $wanted = self::wanted($raw);

        if ($wanted === null) {
            return [];
        }

        $changed = [];

        foreach ((array) ($raw['collections'] ?? []) as $handle) {
            $collection = is_string($handle) ? Collection::find($handle) : null;

            if ($collection === null || self::stored($handle) === $wanted) {
                continue;
            }

            $collection->revisionsEnabled($wanted)->save();
            $changed[] = $handle;
        }

        return $changed;
    }
}
