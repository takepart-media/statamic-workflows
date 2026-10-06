<?php

namespace TakepartMedia\Workflows\Query\Scopes\Filters;

use Statamic\Contracts\Revisions\RevisionRepository;
use Statamic\Facades\Collection;
use Statamic\Query\Scopes\Filter;
use Statamic\Support\Str;

/**
 * Native entries-listing filter for the working-copy state. Because the
 * indicator is computed (not stored), it can't be queried by field value — so
 * this filter resolves the set of entries that currently have a working copy
 * from the revision repository and constrains the listing query by id.
 *
 * Only shown on listings of revision-enabled collections (companion to the
 * `revision_status` column).
 */
class RevisionStatus extends Filter
{
    public static function title()
    {
        return __('workflows::messages.revision_status');
    }

    public function fieldItems()
    {
        return [
            'revision_status' => [
                'type' => 'radio',
                'options' => [
                    'pending' => __('workflows::messages.state_pending_revision'),
                    'current' => __('workflows::messages.revision_status_current'),
                ],
            ],
        ];
    }

    public function apply($query, $values)
    {
        // Empty set: use a sentinel so whereIn matches nothing and whereNotIn
        // matches everything, rather than relying on empty-array edge cases.
        $ids = $this->workingCopyEntryIds() ?: ['__none__'];

        if (($values['revision_status'] ?? null) === 'pending') {
            $query->whereIn('id', $ids);
        } elseif (($values['revision_status'] ?? null) === 'current') {
            $query->whereNotIn('id', $ids);
        }
    }

    public function badge($values)
    {
        return ($values['revision_status'] ?? null) === 'pending'
            ? __('workflows::messages.state_pending_revision')
            : __('workflows::messages.revision_status_current');
    }

    public function visibleTo($key)
    {
        return in_array($key, ['entries', 'entries-fieldtype'], true)
            && (bool) $this->collection()?->revisionsEnabled();
    }

    /**
     * Entry ids that currently have an unpublished working copy. Revision keys
     * are paths (collections/<collection>/<locale>/<id>); the id is the last
     * segment. Ids from other collections are harmless — they simply won't
     * match the (already collection-scoped) listing query.
     *
     * @return array<int, string>
     */
    private function workingCopyEntryIds(): array
    {
        return app(RevisionRepository::class)
            ->query()
            ->where('action', 'working')
            ->get()
            ->map(fn ($revision) => Str::afterLast($revision->key(), '/'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function collection()
    {
        return Collection::findByHandle($this->context['collection'] ?? null);
    }
}
