import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
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

/**
 * State + API calls of the Word-style comment sidebar, shared by both detail
 * pages (WorkflowInstanceDetail, TranslationInstanceDetail). The page owns one
 * instance, hands its `commentCount` / `hasNew` / `activeUuid` to ContentBlocks
 * and the whole object to <CommentsSidebar :comments="…">.
 *
 * options:
 *   comments          initial comment list (server contract, see comments.js)
 *   blocks            () => content blocks of the page (document order + labels)
 *   urls              { create, read, deleteTemplate, updateTemplate, resolveTemplate, reopenTemplate }
 *   currentUser       { id, name, avatar, initials }
 *   mentionableUsers  [{ id, name, avatar, initials }]
 *   axios             the CP's axios (fetch fallback otherwise)
 *   onAllRead         () => void — nothing unread is left (e.g. clear a version's "new" flag)
 */
export function useComments(options) {
    const axios = options.axios;
    const urls = options.urls || {};
    const blocks = () => (typeof options.blocks === 'function' ? options.blocks() : options.blocks) || [];

    const items = ref([...(options.comments || [])]);
    const open = ref(false);
    const filter = ref('open');
    const activeThreadId = ref(null);
    // Passage highlighted on the page (persistent while the sidebar shows it).
    const activeUuid = ref(null);
    // Passage a NEW root comment is being written for: { uuid, handle } | null.
    const composer = ref(null);
    // Resolved threads the reader expanded.
    const expanded = ref(new Set());
    // Comments read in this sidebar session — they keep their "neu" pill until
    // the sidebar closes, although the gutter dot clears at once.
    const readNow = ref(new Set());
    // Something the sidebar should scroll to / focus once its DOM exists:
    // { kind: 'thread'|'comment'|'composer', id, focusComposer?, nonce }.
    const focusRequest = ref(null);
    const pendingDelete = ref(null);
    const now = ref(Date.now());

    const blockOrder = computed(() => blocks().map((b) => b?.uuid).filter(Boolean));
    const blockByUuid = computed(() => new Map(blocks().filter(Boolean).map((b) => [b.uuid, b])));
    const threads = computed(() => buildThreads(items.value, blockOrder.value));
    const visibleThreads = computed(() => filterThreads(threads.value, filter.value));
    const counts = computed(() => threadCounts(threads.value));
    const rows = computed(() => sidebarRows(visibleThreads.value, blockOrder.value, composer.value?.uuid ?? null));
    const activeThread = computed(() => threads.value.find((t) => t.id === activeThreadId.value) || null);
    const mentionableUsers = computed(() => options.mentionableUsers || []);
    const locale = (typeof document !== 'undefined' && document.documentElement.lang) || undefined;

    function commentCount(uuid) {
        return openCommentCount(threads.value, uuid);
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

    function requestFocus(kind, id, extra = {}) {
        focusRequest.value = { kind, id, ...extra, nonce: Date.now() + Math.random() };
    }

    // --- opening ------------------------------------------------------------

    function openSidebar() {
        open.value = true;
    }

    function close() {
        open.value = false;
        composer.value = null;
        activeThreadId.value = null;
        activeUuid.value = null;
        readNow.value = new Set();
    }

    /** Gutter button: show the passage's open threads and a fresh composer for it. */
    function openForPassage(block) {
        if (!block?.uuid) return;
        if (filter.value === 'resolved') filter.value = 'open';

        composer.value = { uuid: block.uuid, handle: block.handle ?? null };
        const first = visibleThreads.value.find((t) => t.uuid === block.uuid);
        activeThreadId.value = first?.id ?? null;
        open.value = true;
        revealPassage(block.uuid);
        requestFocus(first ? 'thread' : 'composer', first ? first.id : block.uuid, { focusComposer: true });
        markRead(block.uuid);
    }

    /** History handoff, `?comment=<id>`: open at one comment. */
    function openAtComment(commentId, passageRef = null) {
        const thread = threadOf(threads.value, commentId);
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

    function activateThread(id, { scrollPage = true, focus = true } = {}) {
        const thread = threads.value.find((t) => String(t.id) === String(id));
        if (!thread) return;

        activeThreadId.value = thread.id;
        if (scrollPage) revealPassage(thread.uuid);
        else activeUuid.value = thread.uuid;
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
                mentions: mentionIdsFor(text, mentions),
            }))?.data;
            if (data) items.value = upsertComment(items.value, data);
            composer.value = null;
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
            const data = (await request('post', urls.create, {
                body: text,
                passage_ref: thread.root.ref?.uuid ?? null,
                passage_handle: thread.root.ref?.handle ?? null,
                parent_id: thread.root.id,
                mentions: mentionIdsFor(text, mentions),
            }))?.data;
            if (data) items.value = upsertComment(items.value, data);
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

    // Relative times tick while the sidebar is open.
    let timer = null;
    watch(open, (value) => {
        clearInterval(timer);
        now.value = Date.now();
        if (value) timer = setInterval(() => (now.value = Date.now()), 60_000);
    });
    onUnmounted(() => clearInterval(timer));
    onMounted(openFromQuery);

    return {
        // state
        items,
        open,
        filter,
        activeThreadId,
        activeThread,
        activeUuid,
        composer,
        expanded,
        focusRequest,
        pendingDelete,
        now,
        locale,
        currentUser: options.currentUser || null,
        mentionableUsers,
        // derived
        threads,
        visibleThreads,
        counts,
        rows,
        canStep,
        // helpers
        commentCount,
        hasNew,
        isFresh,
        passageLabel,
        passageExists,
        revealPassage,
        // actions
        openSidebar,
        close,
        openForPassage,
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
