import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { blockTitle, passageId } from '../contentBlocks.js';
import {
    adjacentThread,
    buildThreads,
    filterShowing,
    filterThreads,
    mentionedIds,
    openCommentCount,
    openHasNew,
    removeComments,
    sidebarRows,
    threadCounts,
    threadOf,
    upsertComment,
} from './comments.js';

const MARGIN_KEY = 'workflows.comments.margin';

function storedMargin() {
    try {
        return window.localStorage.getItem(MARGIN_KEY) !== 'hidden';
    } catch (e) {
        return true;
    }
}

function storeMargin(visible) {
    try {
        window.localStorage.setItem(MARGIN_KEY, visible ? 'shown' : 'hidden');
    } catch (e) {
        // Private window / blocked storage: the margin just opens again next time.
    }
}

/**
 * State + API calls of the Word-style comments, shared by both detail pages
 * (WorkflowInstanceDetail, TranslationInstanceDetail). The page owns one
 * instance, hands its `commentCount` / `hasNew` / `activeUuid` / `marks` to
 * ContentBlocks and the whole object to <CommentsMargin :comments="…">.
 *
 * options:
 *   comments          initial comment list (server contract, see comments.js)
 *   blocks            () => content blocks of the page (document order + labels)
 *   urls              { create, read, deleteTemplate, updateTemplate, resolveTemplate, reopenTemplate }
 *   currentUser       { id, name, avatar, initials }
 *   mentionableUsers  [{ id, name, avatar, initials }]
 *   axios             the CP's axios (fetch fallback otherwise)
 *   onAllRead         () => void — nothing unread is left (e.g. clear a version's "new" flag)
 *   margin            false = the page has no comment margin (translation view with
 *                     the source beside the text): everything opens in the popover
 */
export function useComments(options) {
    const axios = options.axios;
    const urls = options.urls || {};
    const blocks = () => (typeof options.blocks === 'function' ? options.blocks() : options.blocks) || [];

    const items = ref([...(options.comments || [])]);
    // Whether the page offers the comment margin at all.
    const hasMargin = options.margin !== false;
    // Whether the comment margin beside the content is shown. On by default,
    // remembered per browser: hiding it is a reading preference.
    const open = ref(hasMargin && (typeof window === 'undefined' ? true : storedMargin()));
    const filter = ref('open');
    const activeThreadId = ref(null);
    // Passage highlighted on the page (persistent while its thread is active).
    const activeUuid = ref(null);
    // Passage a NEW root comment is being written for: { uuid, handle, quote?, offset? } | null.
    // `uuid` null = a general comment, not about any passage.
    const composer = ref(null);
    // Resolved threads the reader expanded.
    const expanded = ref(new Set());
    // Comments read while the margin is shown — they keep their "neu" pill until
    // it is hidden, although the gutter dot clears at once.
    const readNow = ref(new Set());
    // Something the margin should scroll to / focus once its DOM exists:
    // { kind: 'thread'|'comment'|'composer', id, focusComposer?, nonce }.
    const focusRequest = ref(null);
    const pendingDelete = ref(null);
    const now = ref(Date.now());
    // While the margin is hidden, a passage's comments open in a popover at its
    // gutter button (or at a clicked mark): { uuid, handle, anchor: Element } | null.
    const popover = ref(null);
    // The popover's own "show resolved" switch (the margin's filter is not in view).
    const popoverResolved = ref(false);

    const blockOrder = computed(() => blocks().map((b) => b?.uuid).filter(Boolean));
    const blockByUuid = computed(() => new Map(blocks().filter(Boolean).map((b) => [b.uuid, b])));
    const threads = computed(() => buildThreads(items.value, blockOrder.value));
    const visibleThreads = computed(() => filterThreads(threads.value, filter.value));
    const counts = computed(() => threadCounts(threads.value));
    const rows = computed(() => sidebarRows(visibleThreads.value, blockOrder.value, composer.value?.uuid ?? null));
    // Marked text on the page: the open, visible threads that were written about
    // a selection. Resolved ones leave the text alone, as in Word.
    // The text a new comment is being written about is marked too (`composer`),
    // so it stays visible and the composer has a line to sit level with.
    // A resolved thread is marked only while it is the active one — looking
    // back at what it was about, without cluttering the text otherwise.
    const marks = computed(() => {
        const list = visibleThreads.value
            .filter((t) => t.uuid && t.root.ref?.quote && (!t.resolved || t.id === activeThreadId.value))
            .map((t) => ({
                thread: t.id,
                uuid: t.uuid,
                quote: t.root.ref.quote,
                offset: t.root.ref.offset ?? null,
                active: t.id === activeThreadId.value,
                resolved: t.resolved,
            }));
        // The marked text a new comment is being written about — in the margin's
        // composer or in the popover's.
        const c = composer.value ?? (popover.value?.quote ? popover.value : null);

        return c?.uuid && c.quote
            ? [...list, { thread: 'composer', uuid: c.uuid, quote: c.quote, offset: c.offset ?? null, active: true }]
            : list;
    });
    // The popover's threads: the passage's open ones, the resolved ones on
    // request, and a resolved one whose mark was clicked (what it was about).
    const popoverAll = computed(() => (popover.value ? threads.value.filter((t) => t.uuid === popover.value.uuid) : []));
    const popoverResolvedCount = computed(() => popoverAll.value.filter((t) => t.resolved).length);
    const popoverThreads = computed(() =>
        popoverAll.value.filter((t) => !t.resolved || popoverResolved.value || t.id === activeThreadId.value),
    );
    const activeThread = computed(() => threads.value.find((t) => t.id === activeThreadId.value) || null);
    const mentionableUsers = computed(() => options.mentionableUsers || []);
    const locale = (typeof document !== 'undefined' && document.documentElement.lang) || undefined;

    function commentCount(uuid) {
        return openCommentCount(threads.value, uuid);
    }

    // An open thread about the passage as a whole (gutter button, no marked text).
    function wholeCommented(uuid) {
        return threads.value.some((t) => t.uuid === uuid && !t.resolved && !t.root.ref?.quote);
    }

    function hasNew(uuid) {
        return openHasNew(threads.value, uuid);
    }

    function isFresh(comment) {
        return !!comment?.is_new || readNow.value.has(comment?.id);
    }

    function passageLabel(uuid, handle) {
        if (!uuid) return null;
        const block = blockByUuid.value.get(uuid);

        return block ? blockTitle(block) : handle || uuid;
    }

    function passageExists(uuid) {
        return !!uuid && blockByUuid.value.has(uuid);
    }

    // --- page passage highlight -------------------------------------------

    function revealPassage(uuid, { flash = true } = {}) {
        activeUuid.value = uuid || null;
        if (!uuid || typeof document === 'undefined') return;

        const el = document.getElementById(passageId({ uuid }));
        if (!el) return;

        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (flash) {
            el.classList.remove('fw-flash');
            void el.offsetWidth; // restart the animation
            el.classList.add('fw-flash');
            setTimeout(() => el.classList.remove('fw-flash'), 1700);
        }
    }

    // The marked text itself, once ContentBlocks has put it in (after the next
    // render): closer than the passage, which may be long.
    async function revealMark(threadId) {
        if (typeof document === 'undefined') return;
        await nextTick();
        await nextTick();
        document
            .querySelector(`mark[data-comment-thread="${CSS.escape(String(threadId))}"]`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function requestFocus(kind, id, extra = {}) {
        focusRequest.value = { kind, id, ...extra, nonce: Date.now() + Math.random() };
    }

    // --- opening ------------------------------------------------------------

    function show() {
        if (!hasMargin) return;
        popover.value = null;
        open.value = true;
    }

    function hide() {
        open.value = false;
        composer.value = null;
        activeThreadId.value = null;
        activeUuid.value = null;
        readNow.value = new Set();
    }

    function toggle() {
        open.value ? hide() : show();
    }

    watch(open, (visible) => {
        if (hasMargin) storeMargin(visible);
        // The margin takes over from a popover (selection icon, history link…).
        if (visible) popover.value = null;
    });

    /** Popover at `anchor` with a passage's comments — the margin stays hidden.
     *  `anchor` is an element or a function finding one (it may not be rendered
     *  yet, e.g. a mark); `quote` makes it a new comment on that marked text. */
    function openPopover(block, anchor, threadId = null, { quote = null, offset = null } = {}) {
        if (!block?.uuid || !anchor) return;

        composer.value = null;
        activeThreadId.value = threadId;
        activeUuid.value = block.uuid;
        popover.value = { uuid: block.uuid, handle: block.handle ?? null, anchor, quote, offset };
        markRead(block.uuid);
    }

    // Where a popover for a thread / passage sits when nothing was clicked (a
    // selection, a history link): its mark, else the passage's comment button.
    function anchorFor(uuid, markId = null) {
        return () =>
            (markId != null && document.querySelector(`mark[data-comment-thread="${CSS.escape(String(markId))}"]`)) ||
            document.querySelector(`[data-uuid="${CSS.escape(String(uuid))}"] .fw-comment-btn`);
    }

    function closePopover() {
        if (!popover.value) return;
        popover.value = null;
        activeThreadId.value = null;
        activeUuid.value = null;
        readNow.value = new Set();
    }

    /** Gutter button: show the passage's open threads and a fresh composer for it.
     *  With the margin hidden, they open in a popover at the button instead. */
    function openForPassage(block, anchor = null) {
        if (!block?.uuid) return;
        if (!open.value && anchor) {
            // The same button again closes it.
            return popover.value?.anchor === anchor ? closePopover() : openPopover(block, anchor);
        }
        if (filter.value === 'resolved') filter.value = 'open';

        composer.value = { uuid: block.uuid, handle: block.handle ?? null, quote: null, offset: null };
        const first = visibleThreads.value.find((t) => t.uuid === block.uuid);
        activeThreadId.value = first?.id ?? null;
        open.value = true;
        revealPassage(block.uuid);
        requestFocus(first ? 'thread' : 'composer', first ? first.id : block.uuid, { focusComposer: true });
        markRead(block.uuid);
    }

    /** Marked text → comment icon: a fresh composer for exactly that text. */
    function openForSelection(block, { quote, offset }) {
        if (!block?.uuid || !quote) return;
        if (!hasMargin) {
            return openPopover(block, anchorFor(block.uuid, 'composer'), null, { quote, offset: offset ?? null });
        }
        if (filter.value === 'resolved') filter.value = 'open';

        composer.value = { uuid: block.uuid, handle: block.handle ?? null, quote, offset: offset ?? null };
        activeThreadId.value = null;
        activeUuid.value = block.uuid;
        open.value = true;
        requestFocus('composer', block.uuid, { focusComposer: true });
        markRead(block.uuid);
    }

    /** History handoff, `?comment=<id>`: open at one comment. */
    function openAtComment(commentId, passageRef = null) {
        const thread = threadOf(threads.value, commentId);
        if (!hasMargin) {
            const uuid = thread?.uuid ?? passageRef;
            if (!uuid) return;
            if (thread?.resolved) expanded.value = new Set([...expanded.value, thread.id]);
            openPopover({ uuid, handle: thread?.handle ?? null }, anchorFor(uuid, thread?.root.ref?.quote ? thread.id : null), thread?.id ?? null);
            revealPassage(uuid);

            return;
        }
        if (!thread) {
            // Gone (deleted) or unknown: fall back to the passage, if any.
            open.value = true;
            if (passageRef) {
                if (filter.value === 'resolved') filter.value = 'all';
                const first = visibleThreads.value.find((t) => t.uuid === passageRef);
                activeThreadId.value = first?.id ?? null;
                revealPassage(passageRef);
                if (first) requestFocus('thread', first.id);
            }

            return;
        }

        filter.value = filterShowing(thread, filter.value);
        if (thread.resolved) expanded.value = new Set([...expanded.value, thread.id]);
        composer.value = null;
        open.value = true;
        activateThread(thread.id, { scrollPage: true, focus: false });
        requestFocus('comment', String(commentId));
    }

    function openFromQuery() {
        if (typeof window === 'undefined') return;
        const id = new URLSearchParams(window.location.search).get('comment');
        if (id) openAtComment(id);
    }

    // --- active thread / navigation ------------------------------------------

    /** Click on marked text: its thread in the margin, or in a popover at the mark. */
    function openForMark(id, anchor = null) {
        if (open.value || !anchor) return activateThread(id, { scrollPage: false });

        const thread = threads.value.find((t) => String(t.id) === String(id));
        if (thread?.uuid) openPopover({ uuid: thread.uuid, handle: thread.handle ?? null }, anchor, thread.id);
    }

    function activateThread(id, { scrollPage = true, focus = true } = {}) {
        const thread = threads.value.find((t) => String(t.id) === String(id));
        if (!thread) return;

        activeThreadId.value = thread.id;
        // In the popover the passage is right there; moving the page would only
        // pull the popover away from under the pointer.
        if (popover.value) scrollPage = false;
        if (scrollPage) revealPassage(thread.uuid);
        else activeUuid.value = thread.uuid;
        if (scrollPage && thread.root.ref?.quote) revealMark(thread.id);
        if (thread.isNew) markRead(thread.uuid);
        if (focus) requestFocus('thread', id);
    }

    function step(direction) {
        const ids = visibleThreads.value.map((t) => t.id);
        const next = adjacentThread(ids, activeThreadId.value, direction);
        if (next != null) activateThread(next);
    }

    const canStep = computed(() => {
        const ids = visibleThreads.value.map((t) => t.id);

        return {
            prev: adjacentThread(ids, activeThreadId.value, -1) != null,
            next: adjacentThread(ids, activeThreadId.value, 1) != null,
        };
    });

    function setFilter(value) {
        filter.value = value;
        if (activeThreadId.value && !visibleThreads.value.some((t) => t.id === activeThreadId.value)) {
            activeThreadId.value = null;
        }
    }

    function toggleExpanded(id) {
        const next = new Set(expanded.value);
        next.has(id) ? next.delete(id) : next.add(id);
        expanded.value = next;
    }

    function cancelComposer() {
        composer.value = null;
    }

    // --- requests -------------------------------------------------------------

    async function request(method, url, body) {
        if (axios) return (await axios.request({ method, url, data: body })).data;

        const res = await fetch(url, {
            method: method.toUpperCase(),
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (!res.ok) throw new Error('request failed');

        return res.json();
    }

    function fill(template, id) {
        return String(template || '').replace('__COMMENT__', encodeURIComponent(id));
    }

    function toast(kind, key) {
        window.Statamic?.$toast?.[kind]?.(__(`workflows::messages.${key}`));
    }

    function mentionIdsFor(body, mentions) {
        // Ids the composer tracked, kept only while their `@Name` is still in the text.
        const known = new Map(mentionableUsers.value.map((u) => [u.id, u]));
        const candidates = (mentions || []).map((m) => (typeof m === 'object' ? m : known.get(m))).filter(Boolean);

        return mentionedIds(body, candidates);
    }

    /** Mark a passage's comments as read. General (passage-less) comments are
     *  never "new" on the server, so only a passage triggers the request. */
    async function markRead(uuid) {
        const affected = items.value.filter((c) => c.is_new && (refUuidOf(c) === (uuid || null)));
        if (!affected.length) return;

        if (urls.read && uuid) {
            try {
                await request('post', urls.read, { passage_ref: uuid });
            } catch (e) {
                // Non-critical: the marker simply reappears on the next load.
            }
        }

        const ids = new Set(affected.map((c) => c.id));
        readNow.value = new Set([...readNow.value, ...ids]);
        items.value = items.value.map((c) => (ids.has(c.id) ? { ...c, is_new: false } : c));

        if (!items.value.some((c) => c.is_new)) options.onAllRead?.();
    }

    function refUuidOf(comment) {
        // Replies inherit the root's ref; fall back to the root when a reply has none.
        if (comment.ref?.uuid) return comment.ref.uuid;
        if (comment.parent_id != null) {
            const root = items.value.find((c) => c.id === comment.parent_id);

            return root?.ref?.uuid || null;
        }

        return null;
    }

    /** New root comment for a passage (or a general one with passage null). */
    async function createRoot({ body, mentions }, passage = composer.value) {
        const text = String(body ?? '').trim();
        if (!text || !urls.create) return false;

        try {
            const data = (await request('post', urls.create, {
                body: text,
                passage_ref: passage?.uuid ?? null,
                passage_handle: passage?.handle ?? null,
                quote: passage?.uuid ? passage?.quote ?? null : null,
                quote_offset: passage?.uuid && passage?.quote ? passage?.offset ?? null : null,
                mentions: mentionIdsFor(text, mentions),
            }))?.data;
            if (data) items.value = upsertComment(items.value, data);
            if (composer.value === passage) composer.value = null;
            // Written in the popover about marked text: the popover now belongs
            // to the new thread's own mark.
            if (data && popover.value?.quote) {
                popover.value = { ...popover.value, quote: null, offset: null, anchor: anchorFor(popover.value.uuid, data.id) };
            }
            if (data) {
                activeThreadId.value = data.id;
                requestFocus('thread', data.id);
            }
            toast('success', 'comment_saved');

            return true;
        } catch (e) {
            toast('error', 'comment_save_error');

            return false;
        }
    }

    async function reply(thread, { body, mentions }) {
        const text = String(body ?? '').trim();
        if (!text || !urls.create || !thread) return false;

        try {
            const res = await request('post', urls.create, {
                body: text,
                passage_ref: thread.root.ref?.uuid ?? null,
                passage_handle: thread.root.ref?.handle ?? null,
                parent_id: thread.root.id,
                mentions: mentionIdsFor(text, mentions),
            });
            if (res?.data) items.value = upsertComment(items.value, res.data);
            // A reply to a resolved thread reopens it; the server sends the fresh root.
            if (res?.root) items.value = upsertComment(items.value, res.root);
            activeThreadId.value = thread.id;
            toast('success', 'comment_saved');

            return true;
        } catch (e) {
            toast('error', 'comment_save_error');

            return false;
        }
    }

    async function update(comment, { body, mentions }) {
        const text = String(body ?? '').trim();
        if (!text || !comment?.can_edit || !urls.updateTemplate) return false;

        try {
            const data = (await request('patch', fill(urls.updateTemplate, comment.id), {
                body: text,
                mentions: mentionIdsFor(text, mentions),
            }))?.data;
            items.value = upsertComment(items.value, data ?? { ...comment, body: text });
            toast('success', 'comment_updated');

            return true;
        } catch (e) {
            toast('error', 'comment_update_error');

            return false;
        }
    }

    function askDelete(comment) {
        if (comment?.can_delete) pendingDelete.value = comment;
    }

    function cancelDelete() {
        pendingDelete.value = null;
    }

    async function confirmDelete() {
        const comment = pendingDelete.value;
        pendingDelete.value = null;
        if (!comment || !urls.deleteTemplate) return;

        try {
            const res = await request('delete', fill(urls.deleteTemplate, comment.id));
            const ids = Array.isArray(res?.deleted) && res.deleted.length
                ? res.deleted
                : [comment.id, ...(comment.parent_id == null ? items.value.filter((c) => c.parent_id === comment.id).map((c) => c.id) : [])];
            items.value = removeComments(items.value, ids);
            if (ids.includes(activeThreadId.value)) activeThreadId.value = null;
            toast('success', 'comment_deleted');
        } catch (e) {
            toast('error', 'comment_delete_error');
        }
    }

    async function setResolved(thread, resolved) {
        const template = resolved ? urls.resolveTemplate : urls.reopenTemplate;
        if (!thread?.root?.can_resolve || !template) return;

        try {
            const data = (await request('post', fill(template, thread.root.id)))?.data;
            items.value = upsertComment(items.value, data ?? { ...thread.root, resolved: resolved ? { at: new Date().toISOString(), by: options.currentUser } : null });

            const next = new Set(expanded.value);
            next.delete(thread.id);
            expanded.value = next;

            toast('success', resolved ? 'comment_resolved_toast' : 'comment_reopened_toast');
            if (!filterThreads(threads.value, filter.value).some((t) => t.id === thread.id)) {
                // Left the current filter (resolved under "open"): move on.
                if (activeThreadId.value === thread.id) activeThreadId.value = null;
            }
        } catch (e) {
            toast('error', 'comment_resolve_error');
        }
    }

    // Relative times tick while the margin is shown.
    let timer = null;
    watch(
        () => open.value || !!popover.value,
        (value) => {
            clearInterval(timer);
            now.value = Date.now();
            if (value) timer = setInterval(() => (now.value = Date.now()), 60_000);
        },
        { immediate: true },
    );
    onUnmounted(() => clearInterval(timer));
    onMounted(openFromQuery);

    return {
        // state
        items,
        hasMargin,
        open,
        filter,
        activeThreadId,
        activeThread,
        activeUuid,
        composer,
        expanded,
        focusRequest,
        pendingDelete,
        popover,
        popoverResolved,
        now,
        locale,
        currentUser: options.currentUser || null,
        mentionableUsers,
        // derived
        threads,
        visibleThreads,
        counts,
        rows,
        marks,
        popoverThreads,
        popoverResolvedCount,
        canStep,
        // helpers
        commentCount,
        hasNew,
        wholeCommented,
        isFresh,
        passageLabel,
        passageExists,
        revealPassage,
        // actions
        show,
        hide,
        toggle,
        openForPassage,
        openForSelection,
        openForMark,
        openPopover,
        closePopover,
        openAtComment,
        activateThread,
        step,
        setFilter,
        toggleExpanded,
        cancelComposer,
        markRead,
        createRoot,
        reply,
        update,
        askDelete,
        cancelDelete,
        confirmDelete,
        setResolved,
    };
}
