<?php

namespace TakepartMedia\Workflows\Fieldtypes;

use Statamic\Fields\Fieldtype;

/**
 * Display-only listing column that flags entries with an unpublished working
 * copy — i.e. the live published version is outdated. The value is computed at
 * index time from the entry itself (never stored, never shown in the publish
 * form), so the native entries listing can surface "Unveröffentlichte
 * Änderungen" without any working-copy data being persisted.
 */
class RevisionStatus extends Fieldtype
{
    protected $selectableInForms = false;

    // Listing column cell.
    public function preProcessIndex($data)
    {
        return $this->entryHasWorkingCopy();
    }

    // Read-only indicator inside the entry editor (the publish form renders
    // every non-hidden field, so the form component needs a value too). The
    // blueprint's parent is the entry being edited (EntriesController sets it),
    // so the same computation applies.
    public function preProcess($data)
    {
        return $this->entryHasWorkingCopy();
    }

    private function entryHasWorkingCopy(): bool
    {
        $entry = $this->field()?->parent();

        return $entry
            && method_exists($entry, 'revisionsEnabled')
            && $entry->revisionsEnabled()
            && $entry->hasWorkingCopy();
    }
}
