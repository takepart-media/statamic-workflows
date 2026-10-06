<?php

namespace TakepartMedia\Workflows\Content;

/**
 * Captures the state of an entry for the workflow event log, so the History diff
 * can show what actually changed between two events.
 *
 * `$entry->data()` alone is not enough: Statamic keeps `slug`, `date` and
 * `published` OUTSIDE the data collection — the Stache pulls them out of the
 * front matter and derives them from the filename (see
 * Stache\Stores\CollectionEntriesStore::makeItemFromFile). Without them a slug
 * rename or a date change is invisible in the History, which is exactly what this
 * class exists to prevent. Statamic has the same problem for revisions and solves
 * it the same way in Entry::revisionAttributes().
 *
 * The shape stays FLAT (no nested `data` key like revisionAttributes uses) because
 * WorkflowController::historyDiff() diffs the union of the snapshot's top-level
 * keys and looks each one up as a blueprint field handle.
 */
class EntrySnapshot
{
    /**
     * Field handles that change on every single save and would therefore report a
     * change on every event. Statamic excludes the same two from revisions.
     */
    private const NOISE = ['updated_by', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public function take($entry): array
    {
        if (! is_object($entry) || ! method_exists($entry, 'data')) {
            return [];
        }

        $snapshot = $entry->data()->except(self::NOISE)->all();

        if (method_exists($entry, 'slug')) {
            $snapshot['slug'] = $entry->slug();
        }

        if (method_exists($entry, 'published')) {
            $snapshot['published'] = (bool) $entry->published();
        }

        // Only dated collections have an entry date; date() returns null otherwise
        // and would add a permanently empty row to every diff.
        if (method_exists($entry, 'collection') && $entry->collection()?->dated()) {
            $snapshot['date'] = optional($entry->date())->toIso8601String();
        }

        return $snapshot;
    }
}
