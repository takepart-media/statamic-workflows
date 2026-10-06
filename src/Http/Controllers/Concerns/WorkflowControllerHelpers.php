<?php

namespace TakepartMedia\Workflows\Http\Controllers\Concerns;

use Statamic\Facades\Site;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * Helpers shared by WorkflowController (editorial side) and
 * WorkflowSettingsController (super-admin settings). Kept private: they are
 * implementation details of the two controllers, not an API.
 */
trait WorkflowControllerHelpers
{
    /** Addon-namespaced translation. */
    private function t(string $key, array $replace = []): string
    {
        return (string) __("workflows::messages.{$key}", $replace);
    }

    /**
     * True Super-Admin only (ignores `privileged_groups`). The workflow
     * settings editor uses this strict guard because renaming/deleting places
     * is destructive and should never be delegated by a group flag.
     */
    private function isSuperAdmin(): bool
    {
        $user = User::current();

        return $user !== null && $user->isSuper();
    }

    /**
     * Resolve a `metadata.nav.slug` back to the workflow name it belongs to.
     * Mirrors the lookup in `workflow()` — extracted for reuse by the settings
     * routes.
     */
    private function workflowBySlug(string $slug, DefinitionLoader $loader): ?string
    {
        // Inactive workflows included on purpose: this resolves the settings
        // routes, and a deactivated workflow still has to be editable. The
        // manager page (`workflow()`) does its own lookup over the active list.
        return collect($loader->allIncludingInactive())->first(
            fn (string $name) => ($loader->raw($name)['metadata']['nav']['slug'] ?? null) === $slug
        );
    }

    /**
     * Ordered site handles the workflow listing spans. Sourced from all
     * configured Statamic sites so newly created sites appear automatically
     * (the columns/rows/site-picker are all driven by this), independent of
     * any single collection's site availability. Order follows sites.yaml.
     *
     * @return array<int, string>
     */
    private function siteHandles(): array
    {
        return Site::all()->map->handle()->values()->all();
    }

    private function siteName(string $handle): string
    {
        return (string) (optional(Site::get($handle))->name() ?? $handle);
    }

    /**
     * The first entry-backed workflow whose collections include the given
     * collection handle.
     */
    private function workflowForCollection(string $collection): ?string
    {
        return app(WorkflowResolver::class)->forCollection($collection);
    }
}
