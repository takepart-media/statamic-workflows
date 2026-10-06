<?php

use Illuminate\Support\Facades\Route;
use TakepartMedia\Workflows\Http\Controllers\EntryPreviewController;
use TakepartMedia\Workflows\Http\Controllers\WorkflowController;

// Auto-mounted by Statamic under the CP prefix (config('statamic.cp.route'),
// default /cp) with CP auth + Inertia middleware. Route names get the
// `statamic.cp.` prefix, so reference them via cp_route('workflows.*').

// Inertia pages (full-page Vue, wrapped in the CP chrome).
Route::get('workflows', [WorkflowController::class, 'queue'])->name('workflows.queue');

// Instance summary. The canonical form nests under the manager's own slug, and
// that nesting is load-bearing rather than cosmetic: Statamic derives a nav
// item's active state purely from its URL (NavItem::generateActivePatternForCpUrl
// builds `<slug>(/(.*)?|$)`) and offers no way to set the pattern by hand. With
// the workflow in the query string instead, no nav child ever matched on a
// detail page, the section collapsed, and the manager links disappeared.
//
// The id-only form stays for bookmarks and older stored links; it resolves the
// workflow and redirects to the canonical URL.
Route::get('workflows/{slug}/instances/{id}', [WorkflowController::class, 'showInWorkflow'])
    ->where('slug', '(?!instances|api|settings)[a-z0-9-]+')
    ->name('workflows.workflow.show');
Route::get('workflows/instances/{id}', [WorkflowController::class, 'show'])->name('workflows.show');

// Per-workflow queue, filtered to a single definition. The {slug} maps to a
// workflow's `metadata.nav.slug` (see DefinitionLoader). Constrained so it never
// shadows the `instances`/`api`/`settings` sub-paths above/below.
Route::get('workflows/{slug}', [WorkflowController::class, 'workflow'])
    ->where('slug', '(?!instances|api|settings)[a-z0-9-]+')
    ->name('workflows.workflow');

// Super-admin-only settings index: one card per workflow, linking to its
// settings editor. Landing page for "Settings > Workflows" in the CP nav.
Route::get('workflows/settings', [WorkflowController::class, 'settingsIndex'])
    ->name('workflows.settings.index');

// CP-side "workflows:install" — copy a shipped workflow example into the
// project, set its collections, optionally seed states. Same primitives the
// CLI command uses (see WorkflowInstaller). Only types the project does not
// have yet; an installed workflow is managed through the two routes below.
Route::post('workflows/api/settings/install', [WorkflowController::class, 'installWorkflow'])
    ->name('workflows.settings.install');

// Switch an installed workflow on/off, or remove its definition. Keyed by the
// workflow HANDLE (the YAML basename), not the nav slug: an inactive workflow
// has no manager page, and a workflow without `metadata.nav` has no slug at all.
Route::post('workflows/api/settings/{workflow}/active', [WorkflowController::class, 'setWorkflowActive'])
    ->where('workflow', '[A-Za-z0-9_-]+')
    ->name('workflows.settings.active');

// CP-side "workflows:seed-states" for one workflow — backfill the state rows of
// entries that predate it. Idempotent, so it needs no confirmation. The `--redo`
// variant of the command stays CLI-only: re-deriving states that already exist
// is the repair case, not the everyday one.
Route::post('workflows/api/settings/{workflow}/seed', [WorkflowController::class, 'seedWorkflowStates'])
    ->where('workflow', '[A-Za-z0-9_-]+')
    ->name('workflows.settings.seed');
Route::delete('workflows/api/settings/{workflow}', [WorkflowController::class, 'destroyWorkflow'])
    ->where('workflow', '(?!install)[A-Za-z0-9_-]+')
    ->name('workflows.settings.destroy');

// Super-admin-only settings editor for a workflow's places, transitions and
// metadata. Nested under `workflows/settings`, NOT under `workflows/{slug}` —
// the queue route above shares that nesting trick for its own nav item (see the
// comment there), and Statamic derives active state purely from a nav item's
// URL prefix. Nesting the editor under the queue's own slug would make the
// QUEUE's nav item (under the manager's own section) match this URL too, since
// its auto-generated pattern is `<slug>(/(.*)?|$)` — exactly the bug this
// avoids: the editor should only ever activate "Settings > Workflows".
Route::get('workflows/settings/{slug}', [WorkflowController::class, 'editSettings'])
    ->where('slug', '(?!instances|api|settings)[a-z0-9-]+')
    ->name('workflows.settings.edit');
Route::post('workflows/api/{slug}/settings', [WorkflowController::class, 'saveSettings'])
    ->name('workflows.api.settings.save');
Route::post('workflows/api/{slug}/places/{place}/delete', [WorkflowController::class, 'deletePlace'])
    ->name('workflows.api.settings.place.delete');

// JSON API.
Route::get('workflows/api/listing', [WorkflowController::class, 'listing'])->name('workflows.listing');
Route::get('workflows/api/instances/{id}/actions', [WorkflowController::class, 'actionsJson'])->name('workflows.api.actions');
// Cheap fingerprint (current place + the entry's own last-modified timestamp) —
// what a PlaceActions::REFRESH_INTERVAL timer polls instead of the whole payload.
Route::get('workflows/api/instances/{id}/status', [WorkflowController::class, 'instanceStatus'])->name('workflows.api.instance.status');
// The "Live preview" button: tokenises the entry (working copy first) with
// Statamic's own live-preview mechanism and redirects to the frontend URL, so a
// plain window.open on this route lands on the rendered page. A GET on purpose —
// the button is navigation, and nothing here needs a request body; core's own
// popout route is a GET too. Under `api/` so the `{slug}` routes above never
// match it. See EntryPreviewController for why this is not core's popout.
Route::get('workflows/api/instances/{id}/preview', EntryPreviewController::class)->name('workflows.api.preview');
Route::get('workflows/api/instances/{id}/history/{event}/diff', [WorkflowController::class, 'historyDiff'])->name('workflows.api.history.diff');
// Dry run for a `needs_site_pick` translation start: the source content, split
// into translatable segments with the paths they get written back to. Read-only
// — it creates nothing, so it can be inspected before any content is touched.
Route::get('workflows/api/instances/{id}/translation-payload', [WorkflowController::class, 'translationPayload'])->name('workflows.api.translation.payload');
Route::post('workflows/api/instances/{id}/transitions/{name}', [WorkflowController::class, 'applyTransition'])->name('workflows.api.apply');
Route::post('workflows/api/instances/{id}/assignees', [WorkflowController::class, 'setAssignees'])->name('workflows.api.assignees');
Route::post('workflows/api/instances/{id}/transfer', [WorkflowController::class, 'transfer'])->name('workflows.api.transfer');
Route::post('workflows/api/instances/{id}/state', [WorkflowController::class, 'setState'])->name('workflows.api.state');
Route::post('workflows/api/posts', [WorkflowController::class, 'storePost'])->name('workflows.api.posts');
Route::post('workflows/api/instances/{id}/comments', [WorkflowController::class, 'storeComment'])->name('workflows.api.comments');
Route::post('workflows/api/instances/{id}/comments/read', [WorkflowController::class, 'markCommentsRead'])->name('workflows.api.comments.read');
Route::delete('workflows/api/instances/{id}/comments/{comment}', [WorkflowController::class, 'deleteComment'])->name('workflows.api.comments.destroy');
// Watching: a personal bookmark for the "Watched" dashboard card. One URL, the
// verb says which way.
Route::post('workflows/api/instances/{id}/watch', [WorkflowController::class, 'watch'])->name('workflows.api.watch');
Route::delete('workflows/api/instances/{id}/watch', [WorkflowController::class, 'unwatch'])->name('workflows.api.unwatch');
Route::get('workflows/api/definitions', [WorkflowController::class, 'definitions'])->name('workflows.api.definitions');

// The dashboard's one write: restart every parked failed translation the caller
// may restart (QueueStats card 3). Takes no subject — the set IS "everything
// that failed", which is why it hangs off `stats` rather than off an instance.
// Per-subject permission is the ordinary transition gate, applied inside.
Route::post('workflows/api/stats/retry-failed', [WorkflowController::class, 'retryFailedTranslations'])
    ->name('workflows.api.stats.retry');

// The posts in one (workflow, state) pair — what a segment of the status row's
// doughnut opens (WorkflowStatus). Lazy on purpose: shipping every state's posts
// with the dashboard payload would be hundreds of entry lookups per load for a
// list nobody may click. Read-only, `?workflow=&state=`, both validated against
// the definitions server-side.
Route::get('workflows/api/stats/state-subjects', [WorkflowController::class, 'stateSubjects'])
    ->name('workflows.api.stats.state-subjects');
