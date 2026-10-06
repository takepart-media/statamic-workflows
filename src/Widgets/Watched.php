<?php

namespace TakepartMedia\Workflows\Widgets;

use Statamic\Facades\User;
use Statamic\Widgets\Widget;
use TakepartMedia\Workflows\Widgets\Concerns\PresentsPersonalEntries;
use TakepartMedia\Workflows\Workflow\PersonalWorkItems;

/**
 * CP dashboard card listing the posts the current user watches — the bookmark
 * button on the detail pages — across ALL active workflows. Widget config:
 * `limit` (rows shown, default 5) and `workflows` (restrict to a subset of
 * workflow names, default all active).
 *
 * Built like CreatedByMe and sharing its row component, because it answers the
 * same kind of question ("where has it got to") about a different set of posts.
 * Sorted by last-modified for the same reason: what moved most recently is what
 * the reader wants to see first. Each row carries the URL to stop watching it,
 * which is the one thing this card does that the other two do not.
 */
class Watched extends Widget
{
    protected static $handle = 'workflows_watched';

    use PresentsPersonalEntries;

    public function component()
    {
        $user = User::current();

        // Same self-hiding mechanism as CreatedByMe.
        if ($user === null) {
            return null;
        }

        $limit = (int) $this->config('limit', 5);

        $rows = app(PersonalWorkItems::class)
            ->watchedBy((string) $user->id(), $this->workflowsConfig())
            ->sortByDesc('last_modified')
            ->values();

        return [
            'name' => 'workflows-watched-widget',
            'props' => [
                'rows' => $rows->take($limit)
                    ->map(fn (array $row) => $row + [
                        'unwatch_url' => cp_route('workflows.api.unwatch', ['id' => $row['id'], 'workflow' => $row['workflow']]),
                    ])
                    ->values()
                    ->all(),
                'more' => $this->overflow($rows, $limit)->all(),
            ],
        ];
    }
}
