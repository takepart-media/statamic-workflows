<?php

namespace TakepartMedia\Workflows\Fieldtypes;

use TakepartMedia\Workflows\Support\Privileges;
use Statamic\Facades\User;
use Statamic\Fields\Fieldtype;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\InstanceUrl;
use TakepartMedia\Workflows\Workflow\RevisionPublishers;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * Display-only panel inside the entry editor that surfaces the workflow
 * transitions for this entry as clickable buttons.
 *
 * Where the workflow governs publishing, the panel replaces the native "Publish"
 * flow: the Vue side hides the header's publish buttons while the current place
 * does not allow publishing. Three conditions have to hold for that, and all
 * three ship from here — see `gates_publishing`, `allow_publish` and
 * `is_privileged` below. Super admins and members of the `admins` group keep the
 * native button either way.
 *
 * The value returned here is the initial payload the Vue component reads on
 * mount; the actual actions list is (re)fetched from the workflows API so it
 * stays live after a transition without a full page reload.
 */
class WorkflowActions extends Fieldtype
{
    protected $selectableInForms = false;

    public function preProcess($data)
    {
        $entry = $this->field()?->parent();

        // Also runs on the "create entry" form, before the first save — Statamic
        // gives that in-memory entry no id yet, so `method_exists` alone is not
        // enough to guard against it. Nothing here means anything for content
        // that does not exist yet: no state, no transitions, no history.
        if ($entry === null || ! method_exists($entry, 'id') || $entry->id() === null) {
            return null;
        }

        $collection = method_exists($entry, 'collectionHandle')
            ? $entry->collectionHandle()
            : null;

        $resolver = app(WorkflowResolver::class);

        // Per entry VERSION, not per collection: a localization belongs to the
        // localization workflow, its origin to the authoring one. Otherwise the
        // editor of an English translation showed the post manager's actions.
        $workflow = $collection ? $resolver->forEntry($entry) : null;

        if ($workflow === null) {
            return null;
        }

        $user = User::current();
        $isPrivileged = Privileges::isPrivileged($user);

        $id = (string) $entry->id();

        $revisionsEnabled = method_exists($entry, 'revisionsEnabled')
            && (bool) $entry->revisionsEnabled();

        $revisionsUrl = $revisionsEnabled
            ? cp_route('collections.entries.revisions.store', [$collection, $id])
            : null;

        // Whether the current workflow place allows the entry to be published
        // right now. The Vue component uses this to decide whether Statamic's
        // native Publish button stays visible; without it we can't distinguish
        // "hide Publish" from "keep Save" — the DOM structure is ambiguous.
        $raw = app(DefinitionLoader::class)->raw($workflow);
        $current = $resolver->stateFor($entry, $workflow, $raw);
        // A null state means this version is not part of the workflow yet (an
        // unpublished post in a localization manager). No place, so no publish flag
        // to read — and the panel's own fetch returns no actions either.
        $allowPublish = $current !== null
            && ($raw['places'][$current]['metadata']['allow_publish'] ?? false) === true;

        return [
            'entry_id' => $id,
            'workflow' => $workflow,
            'is_privileged' => $isPrivileged,
            // While a working copy exists and the workflow names who may publish
            // it (RevisionPublishers): whether this user may — overrides the
            // three-way publish-button rule on the client. Null otherwise.
            'revision_publish' => RevisionPublishers::allows($user, $entry),
            'allow_publish' => $allowPublish,
            // Whether this workflow governs publishing AT ALL, i.e. declares any
            // place with `allow_publish`. Without it the client cannot tell "you may
            // not publish in this state" from "publishing is none of this workflow's
            // business" — and it hid the native button in both cases. A localization
            // workflow is the second case: it has no publish place,
            // WorkflowTransitioner::syncPublishedFlag() and
            // WorkflowResolver::canPublish() both bow out for exactly that reason,
            // and hiding the button left a finished translation with no way to go
            // live at all.
            'gates_publishing' => $resolver->publishPlaces($raw) !== [],
            // The place colours from the definitions, so the header badge resolves
            // its colour exactly like every other state badge in the CP: configured
            // `metadata.color` first, then the built-in per-handle map in
            // states.js, then neutral. It used to be a hardcoded switch over five
            // handle names in the component, which meant a place the author
            // coloured amber in the settings still rendered grey here — and a place
            // the author invented rendered grey no matter what.
            'state_colors' => app(DefinitionLoader::class)->placeColors(),
            'actions_url' => cp_route('workflows.api.actions', ['id' => $id, 'workflow' => $workflow]),
            'apply_url_template' => cp_route('workflows.api.apply', ['id' => $id, 'name' => '__NAME__', 'workflow' => $workflow]),
            // Populated only when the entry's collection has revisions on
            // (`revisions: true` in the collection; the editor warns about a
            // governed collection without — DefinitionLinter). The
            // client best-effort POSTs here after a successful save to record
            // each transition as a revision.
            'revisions_enabled' => $revisionsEnabled,
            'revisions_url' => $revisionsUrl,
            // Where the Vue component sends the browser after a successful
            // transition — the workflow instance summary. Chosen over a plain
            // page reload so the redaktion sees the full History + Actions
            // rather than a stale entry editor.
            'workflow_url' => app(InstanceUrl::class)->for($id, $workflow),
            // A localization workflow owns which sites an entry lives in — that
            // runs through transfer(), not through Statamic's Sites card. The
            // card is a locally-registered component behind a `localizations.length
            // > 1` computed, so there is no server-side seam to close: the client
            // hides it. The blueprint itself does NOT lock the site fields — that was
            // tried via ensureFieldHasConfig() and reverted because blueprints are
            // cached (see InjectWorkflowFields). This is the piece that can be
            // decided per render.
            'lock_sites' => ! $isPrivileged && $resolver->localizationWorkflowFor((string) $collection) !== null,
            // Statamic's own heading for that card, resolved server-side so the
            // client matches whatever locale the CP runs in instead of guessing.
            'sites_label' => (string) __('Sites'),
        ];
    }
}
