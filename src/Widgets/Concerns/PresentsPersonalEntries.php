<?php

namespace TakepartMedia\Workflows\Widgets\Concerns;

use Illuminate\Support\Collection;

/**
 * The config- and overflow-plumbing the two cross-workflow list widgets
 * (MyQueue, CreatedByMe) share on top of PersonalWorkItems. A trait rather
 * than a base class: the widgets already extend Statamic's Widget, and the
 * only thing they have in common besides it is exactly this.
 */
trait PresentsPersonalEntries
{
    /**
     * The `workflows` widget config as a clean list of names, or null for
     * "all active". Unknown names are dropped later by the row builder.
     *
     * @return array<int, string>|null
     */
    private function workflowsConfig(): ?array
    {
        $raw = $this->config('workflows');

        if (! is_array($raw)) {
            return null;
        }

        return collect($raw)
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->values()
            ->all();
    }

    /**
     * The rows the limit hides, grouped per workflow so the card's footer can
     * say "+3 Beiträge · +1 Übersetzungen" and link each part to its manager
     * page — a bare "+N" has nowhere to go, since the list mixes workflows.
     * Workflow order is preserved: the groups come out in the order the rows
     * first mention them. `url` is null for a workflow without a manager page
     * (no `metadata.nav`), and the footer renders that part unlinked.
     *
     * Only CreatedByMe uses this. MyQueue's footer has to follow the active tab,
     * so it subtracts client-side from the per-workflow counts in `tabs` — which
     * carry the same `url` for the same purpose.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return \Illuminate\Support\Collection<int, array{count: int, label: string, color: string, url: string|null}>
     */
    private function overflow(Collection $rows, int $limit): Collection
    {
        return $rows->slice($limit)
            ->groupBy('workflow')
            ->map(fn (Collection $hidden) => [
                'count' => $hidden->count(),
                'label' => $hidden->first()['workflow_label'],
                'color' => $hidden->first()['workflow_color'],
                'url' => $hidden->first()['manager_url'],
            ])
            ->values();
    }
}
