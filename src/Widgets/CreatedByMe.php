<?php

namespace TakepartMedia\Workflows\Widgets;

use Statamic\Facades\User;
use Statamic\Widgets\Widget;
use TakepartMedia\Workflows\Widgets\Concerns\PresentsPersonalEntries;
use TakepartMedia\Workflows\Workflow\PersonalWorkItems;

/**
 * CP dashboard card listing the posts whose `author` field names the current
 * user, across ALL active workflows — the cross-workflow counterpart of the
 * managers' "My posts" sections, with identical semantics (Entry::authors(),
 * see PersonalWorkItems::authoredBy()). Widget config: `limit`
 * (rows per page, default 5) and `workflows` (restrict to a subset of workflow
 * names, default all active).
 *
 * A component widget like MyQueue, and for the same reason of consistency
 * rather than interactivity: the two cards sit side by side on the dashboard
 * and share their row markup (`PersonalWidgetRow.vue`), so they have to be
 * built the same way. This one has no tabs — `component()` hands
 * `workflows-created-by-me-widget` the whole pre-sorted list, which the card
 * pages client-side with `limit` as the page size.
 *
 * Sorted by the entry's last-modified rather than by urgency: the question this
 * card answers is "what did I write, and where has it got to", so recency is
 * the useful order. Urgency belongs to the queue, where the rows are work
 * waiting on the reader.
 */
class CreatedByMe extends Widget
{
    protected static $handle = 'workflows_created_by_me';

    use PresentsPersonalEntries;

    public function component()
    {
        $user = User::current();

        // Same self-hiding mechanism as MyQueue and UserHeader: no html()
        // either, and DashboardController drops a widget only when both are
        // empty.
        if ($user === null) {
            return null;
        }

        $limit = (int) $this->config('limit', 5);

        // Newest first. `sortByDesc` is stable (PHP 8.0+), so entries sharing a
        // timestamp — a batch save — keep the title order they arrive in.
        $rows = app(PersonalWorkItems::class)
            ->authoredBy((string) $user->id(), $this->workflowsConfig())
            ->sortByDesc('last_modified')
            ->values();

        return [
            'name' => 'workflows-created-by-me-widget',
            'props' => [
                'rows' => $rows->all(),
                'limit' => $limit,
            ],
        ];
    }
}
