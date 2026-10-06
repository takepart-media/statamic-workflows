<?php

namespace TakepartMedia\Workflows\Widgets;

use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\InstanceUrl;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * CP dashboard card listing the current user's assigned posts under whichever
 * workflow governs the `posts` collection. The workflow handle is resolved at
 * runtime (via WorkflowResolver) instead of hard-coded so renaming the
 * workflow YAML — or pointing the widget at a different collection — does not
 * break the widget. Override the `collection` widget config to switch focus.
 */
class AssignedPosts extends Widget
{
    protected static $handle = 'workflows_assigned_posts';

    public function html()
    {
        $user = User::current();

        if ($user === null) {
            return null;
        }

        $collection = (string) $this->config('collection', 'posts');
        $resolver = app(WorkflowResolver::class);
        $workflow = $resolver->forCollection($collection);

        if ($workflow === null) {
            return view('workflows::widgets.assigned-posts', [
                'posts' => collect(),
                'remaining' => 0,
                'managerUrl' => cp_route('workflows.queue'),
            ]);
        }

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $limit = (int) $this->config('limit', 5);

        $assignedIds = collect(app(WorkflowAssigneeStore::class)->byWorkflow($workflow))
            ->filter(fn ($userIds) => in_array((string) $user->id(), $userIds, true))
            ->keys();

        // One InstanceUrl for the whole card so its slug lookup is memoised
        // across the rows rather than re-reading the YAML per post.
        $urls = app(InstanceUrl::class);

        $posts = $assignedIds
            ->map(fn ($id) => Entry::find($id))
            ->filter()
            ->map(function ($entry) use ($raw, $resolver, $workflow, $urls) {
                $state = $resolver->stateFor($entry, $workflow, $raw);

                return [
                    'title' => (string) $entry->value('title'),
                    // Empty where the version is not part of the workflow yet — the
                    // card then names the post without a state. Only reachable if an
                    // assignment outlives the state it was made in.
                    'state_label' => $state === null ? '' : $this->stateLabel($state),
                    'url' => $urls->for($entry->id(), $workflow),
                ];
            })
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $total = $posts->count();

        return view('workflows::widgets.assigned-posts', [
            'posts' => $posts->take($limit),
            'remaining' => max(0, $total - $limit),
            'managerUrl' => $this->managerUrl($raw),
        ]);
    }

    private function managerUrl(array $raw): string
    {
        $slug = $raw['metadata']['nav']['slug'] ?? null;

        return $slug
            ? cp_route('workflows.workflow', ['slug' => $slug])
            : cp_route('workflows.queue');
    }

    private function stateLabel(string $state): string
    {
        return app(DefinitionLoader::class)->stateLabel($state);
    }
}
