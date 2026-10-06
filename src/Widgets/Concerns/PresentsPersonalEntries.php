<?php

namespace TakepartMedia\Workflows\Widgets\Concerns;

/**
 * The config plumbing the cross-workflow dashboard widgets (MyQueue, Watched,
 * CreatedByMe, QueueStats, WorkflowsOverview) share on top of
 * PersonalWorkItems. A trait rather than a base class: the widgets already
 * extend Statamic's Widget, and the only thing they have in common besides it
 * is exactly this.
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
}
