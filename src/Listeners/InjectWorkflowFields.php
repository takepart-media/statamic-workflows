<?php

namespace TakepartMedia\Workflows\Listeners;

use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * The addon's two blueprint fields, added to the entry blueprints of governed
 * collections: the `workflow_actions` panel and the `content_updated_at` clock.
 *
 * Keeps both a plugin-owned concern — no app-side blueprint YAML needs to know
 * about them, and disabling the addon removes them cleanly.
 *
 * NOTE: do not make blueprint changes here depend on the current user.
 * Blueprints are cached, so whichever request resolves one first decides its
 * shape for everyone afterwards — a per-user `visibility` was tried for the
 * localization site-field lock and handed a super admin the read-only variant.
 * User-dependent restrictions belong in the fieldtype payload (per render) or on
 * save, not in the blueprint.
 */
class InjectWorkflowFields
{
    public function handle(EntryBlueprintFound $event): void
    {
        $entry = $event->entry;
        $blueprint = $event->blueprint;

        if ($entry === null || $blueprint === null) {
            return;
        }

        $collection = method_exists($entry, 'collectionHandle')
            ? $entry->collectionHandle()
            : null;

        if ($collection === null) {
            return;
        }

        $resolver = app(WorkflowResolver::class);
        // Collection level on purpose, NOT WorkflowResolver::forEntry(): this only
        // answers "is this collection governed at all", i.e. whether the panel
        // exists. Which workflow it then shows is the fieldtype's call, per render
        // — see the cache note above.
        $workflow = $resolver->forCollection($collection);

        if ($workflow === null) {
            return;
        }

        $tab = $blueprint->tabs()->keys()->first() ?? 'main';

        $blueprint->ensureFieldPrepended('workflow_actions', [
            'type' => 'workflow_actions',
            'display' => 'Workflow',
            'localizable' => false,
            'hide_display' => true,
            'visibility' => 'computed',
        ], $tab);

        if ($this->governsContent($resolver, $workflow)) {
            $blueprint->ensureField(ContentTimestamp::FIELD, [
                // A DATE, no time. The deadline counts in days, and a time would
                // suggest a precision the comparison deliberately does not use —
                // isStale() levels both sides to the day.
                'type' => 'date',
                'display' => (string) __('workflows::messages.column_content_updated_at'),
                'instructions' => (string) __('workflows::messages.content_updated_at_instructions'),
                // Every version carries its OWN value — that is what lets a
                // localization be behind the post it belongs to. Without this the
                // origin's value would be inherited and every translation would
                // report itself as current.
                'localizable' => true,
                // Visible to everyone, editable by a super admin only. NOT
                // `computed`: computed values are rejected by Fields::values() on
                // save, which is right for the actions panel (it has nothing to
                // store) and fatal here.
                //
                // This is the one place the blueprint depends on the current user,
                // against the warning above — and it is allowed to be, because it
                // is no longer what enforces anything. ProtectContentTimestamp
                // rejects a foreign hand on the way IN, so a wrongly shaped form
                // can at worst inconvenience somebody. Read that class before
                // touching this line.
                'visibility' => $this->mayEditTimestamp() ? 'visible' : 'read_only',
                'listable' => 'hidden',
            ], $tab);
        }
    }

    /**
     * Whether the current user may type into the clock.
     *
     * Super admin only. The field is the SLA's whole input — moving it forward
     * postpones an ageing deadline for a year, moving it back can take a post
     * offline at the next scheduler tick — so it is not an editorial field, it is
     * an administrative one that happens to live on the entry.
     */
    private function mayEditTimestamp(): bool
    {
        return User::current()?->isSuper() ?? false;
    }

    /**
     * Does this workflow author the content itself?
     *
     * The clock belongs to the collections a POST MANAGER governs — the ageing
     * measures against the post, and a localization workflow only ever reads that
     * number. Localizations are entries of the same collection, so they receive
     * the field anyway, which is exactly what the per-version comparison needs.
     *
     * A collection whose only workflow is a localization one gets no clock: there
     * is no authoring step in it that could ever set one.
     */
    private function governsContent(WorkflowResolver $resolver, string $workflow): bool
    {
        return ! $resolver->isLocalizationWorkflow(
            app(DefinitionLoader::class)->raw($workflow)
        );
    }
}
