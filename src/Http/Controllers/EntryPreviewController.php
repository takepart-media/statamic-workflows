<?php

namespace TakepartMedia\Workflows\Http\Controllers;

use Facades\Statamic\CP\LivePreview;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\URL;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The "Live preview" button of the workflow views: opens the entry's frontend
 * page in its own window, unpublished or not.
 *
 * Statamic's own live preview is two things. One is a token mechanism: the
 * entry is parked in the cache under a token (CP\LivePreview::tokenize), and the
 * HandleToken middleware on the web routes swaps it into the repository for any
 * request carrying `?token=`, which is what lets drafts and working copies
 * render (DataResponse::handleDraft). The other is the popout window — a blank
 * CP page with a <LivePreviewPopout> that shows nothing on its own: it waits on
 * a BroadcastChannel for the publish form in the opener tab to post fresh token
 * URLs as the editor types. Nothing is edited on a workflow page, so there is
 * nothing to stream; reusing the popout would open an empty frame. This
 * endpoint keeps the first half and replaces the second with a plain redirect
 * to the tokenised frontend URL, which the button opens via window.open.
 *
 * What gets opened depends on the entry, see modeFor():
 *  - `live`: published and nothing pending — the public page itself, no token.
 *    That is the `published` place of a workflow, and a reviewer there wants
 *    to see what visitors see, not a tokenised copy of it.
 *  - `working_copy`: the pending revision of a published entry. That is the
 *    version a reviewer judges in a `pending_revision` place, and what
 *    Statamic's live preview renders in the editor too.
 *  - `draft`: an unpublished entry, made visible by the token.
 *
 * Who may use it: any CP user with access to the entry's site — not only those
 * with the entry ability, see __invoke(). Anyone with the tokenised URL can
 * view the page for an hour, which is what live preview has always meant.
 *
 * The token is always freshly generated. A predictable name (say, derived from
 * the entry id) would make every unpublished page readable to anyone who can
 * guess it; core's token GC (a lottery in HandleToken) clears expired ones.
 */
class EntryPreviewController extends CpController
{
    /**
     * Which page the preview button opens for an entry: `live`, `working_copy`
     * or `draft` (see the class docblock). Keyed on the entry rather than on the
     * workflow place — PublishedFlag keeps the two in step, and the entry is
     * what actually decides whether a public page exists.
     */
    public static function modeFor($entry): string
    {
        $pending = method_exists($entry, 'hasWorkingCopy') && $entry->hasWorkingCopy();

        if ($pending) {
            return 'working_copy';
        }

        return $entry->published() ? 'live' : 'draft';
    }

    public function __invoke(Request $request, string $id)
    {
        $entry = Entry::find($id);
        abort_if($entry === null, 404);

        // Site access only — the same gate as the button that leads here
        // (WorkflowController::previewUrlFor()). NOT the entry ability: reviewers
        // who hold no `view <collection> entries` are exactly who the workflow
        // asks to look at the page, and the detail page shows them the content
        // as blocks already. CP authentication is the route group's job.
        $user = User::current();
        $site = Site::get($entry->locale());
        abort_unless($user !== null && $site !== null && $user->can('view', $site), 403);

        // No route in this site means no frontend page to preview — the same
        // condition under which Entry::livePreviewUrl() returns null.
        abort_if($entry->collection()->route($entry->locale()) === null, 404);

        $mode = self::modeFor($entry);

        if ($mode === 'live') {
            return redirect()->away($entry->absoluteUrl());
        }

        $item = $mode === 'working_copy' ? $entry->fromWorkingCopy() : $entry;

        // What PreviewController::tokenizeAndReturn() sets: templates may branch
        // on `live_preview` (e.g. to hide analytics), and the cache tag skips
        // caching for it.
        $item->setSupplement('live_preview', true);

        $token = LivePreview::tokenize(null, $item)->token();

        $url = URL::makeAbsolute($item->previewTargets()->first()['url']);

        // Same shape as core's preview URL. `live-preview` is a random cache
        // buster, not a flag; `token` is what the middleware reads.
        return redirect()->away(vsprintf('%s%slive-preview=%s&token=%s', [
            $url,
            str_contains($url, '?') ? '&' : '?',
            Str::random(),
            $token,
        ]));
    }
}
