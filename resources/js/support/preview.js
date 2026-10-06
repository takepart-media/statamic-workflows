// Opens an entry's frontend preview in its own window — the "Live preview"
// button of the workflow views.
//
// `url` is the addon's preview route (WorkflowController::previewUrlFor); it
// tokenises the entry and redirects to the rendered page, so the window shows
// the real site, unpublished or not. Named per entry so a second click reloads
// the same window (each click is a fresh token) instead of stacking windows.
// Size and position follow Statamic's own live-preview popout: the right side
// of the screen, flush with the top.
export function openPreviewWindow(url, id) {
    if (!url) return;

    const width = Math.min(1440, Math.round(screen.availWidth * 0.75));
    const height = Math.round(screen.availHeight * 0.9);
    const left = Math.max(0, screen.availWidth - width);
    const name = `workflows-preview-${String(id).replace(/[^A-Za-z0-9_-]/g, '_')}`;

    const win = window.open(url, name, `width=${width},height=${height},top=0,left=${left}`);

    // A popup blocker returns null; fall back to a tab so the click still does
    // something visible rather than nothing.
    if (!win) {
        window.open(url, '_blank');
        return;
    }

    win.focus();
}

// Label, icon and tooltip for a preview button, by the server's `preview_mode`
// (EntryPreviewController::modeFor). A published post opens its public page and
// says so; anything pending or unpublished is a tokenised preview.
export function previewPresentation(mode) {
    // Statamic's translation helper is a Vue global property; in a plain module
    // it is only reachable as a window global. Same guard as states.js.
    const t = (key) => (typeof __ === 'function' ? __(key) : key);

    if (mode === 'live') {
        return {
            icon: 'external-link',
            text: t('workflows::messages.live_page'),
            title: t('workflows::messages.live_page_hint'),
        };
    }

    return {
        icon: 'eye',
        text: t('workflows::messages.live_preview'),
        title: mode === 'working_copy'
            ? t('workflows::messages.live_preview_pending_hint')
            : t('workflows::messages.live_preview_hint'),
    };
}
