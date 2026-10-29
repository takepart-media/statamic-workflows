<template>
    <!-- Word-style comment margin: every thread of the page as a card in a column
         next to the content, each level with the text it is about (the marked
         text, or else its passage). Overlapping cards slide down; the active one
         sits exactly at its anchor (layoutMargin()). Too narrow for that — below
         `floatFrom` — the cards become a plain list in document order. One
         instance per page; state and requests live in useComments(). -->
    <aside ref="margin" class="fw-margin min-w-0" @keydown="onListKeydown">
        <!-- Open threads by default; resolved ones join them on request, in
             document order (`all`) — like Word's "show resolved comments". -->
        <label class="mb-3 ms-auto flex w-fit cursor-pointer items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
            <Switch
                size="xs"
                :model-value="store.filter.value !== 'open'"
                @update:model-value="(v) => store.setFilter(v ? 'all' : 'open')"
            />
            {{ __('workflows::messages.comments_show_resolved') }}
            <span
                v-if="store.counts.value.resolved"
                class="rounded-full bg-gray-200/80 px-1.5 text-2xs tabular-nums text-gray-600 dark:bg-gray-700! dark:text-gray-300!"
            >{{ store.counts.value.resolved }}</span>
        </label>

        <div
            ref="list"
            class="relative"
            :class="floating ? '' : 'space-y-3'"
            :style="floating ? { minHeight: `${height}px` } : null"
        >
            <div
                v-for="item in items"
                :key="item.key"
                :data-margin-key="item.key"
                :class="floating ? 'absolute inset-x-0 transition-[top] duration-150 ease-out' : ''"
                :style="floating ? { top: `${tops[item.key] ?? 0}px`, visibility: tops[item.key] == null ? 'hidden' : null } : null"
            >
                <CommentThread
                    v-if="item.type === 'thread'"
                    :thread="item.thread"
                    :store="store"
                    :active="store.activeThreadId.value === item.thread.id"
                    :highlight-id="highlightId"
                />

                <!-- A new comment: for a passage (gutter button), for marked text
                     (the selection icon) or for the post as a whole. -->
                <section
                    v-else
                    class="rounded-lg border border-blue-300 bg-blue-50/40 p-3 shadow-xs dark:border-blue-800! dark:bg-blue-950/20!"
                    :data-composer-uuid="item.uuid ?? ''"
                >
                    <button
                        v-if="item.uuid"
                        type="button"
                        class="mb-2 flex max-w-full cursor-pointer items-center gap-1 text-xs text-gray-500 hover:text-blue-600 dark:text-gray-400! dark:hover:text-blue-400!"
                        :title="__('workflows::messages.comment_jump_to_passage')"
                        @click="store.revealPassage(item.uuid)"
                    >
                        <Icon name="text-formatting-quotation" class="size-3 shrink-0" />
                        <span class="truncate">{{ store.passageLabel(item.uuid, store.composer.value?.handle) }}</span>
                    </button>
                    <p v-else class="mb-2 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400!">
                        <Icon name="mail-chat-bubble-text" class="size-3 shrink-0" />
                        {{ __('workflows::messages.comments_general') }}
                    </p>
                    <blockquote
                        v-if="store.composer.value?.quote"
                        class="mb-2 line-clamp-3 border-s-2 border-yellow-400 ps-2 text-xs italic text-gray-600 dark:text-gray-300!"
                    >{{ store.composer.value.quote }}</blockquote>
                    <CommentComposer
                        ref="composer"
                        :user="store.currentUser"
                        :users="store.mentionableUsers.value"
                        :placeholder="__('workflows::messages.write_comment')"
                        cancellable
                        :on-submit="(payload) => store.createRoot(payload)"
                        @cancel="store.cancelComposer()"
                    />
                </section>
            </div>

            <p v-if="!items.length" class="py-10 text-center text-sm text-gray-400 dark:text-gray-500!">
                {{ emptyText }}
            </p>
        </div>

        <ConfirmationModal
            :open="!!store.pendingDelete.value"
            :title="__('workflows::messages.delete')"
            :body-text="deleteText"
            :button-text="__('workflows::messages.delete')"
            danger
            @update:open="(v) => v || store.cancelDelete()"
            @confirm="store.confirmDelete()"
        />
    </aside>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { ConfirmationModal, Icon, Switch } from '@statamic/cms/ui';
import CommentThread from './CommentThread.vue';
import CommentComposer from './CommentComposer.vue';
import { layoutMargin } from './anchors.js';
import { passageId } from '../contentBlocks.js';

const props = defineProps({
    // The object returned by useComments().
    comments: { type: Object, required: true },
    // Media query from which the cards float next to their text; narrower
    // windows get a list. The translation page has two content columns beside
    // the margin and needs more room.
    floatFrom: { type: String, default: '(min-width: 1024px)' },
});

const store = props.comments;
const margin = ref(null);
const list = ref(null);
const composer = ref(null);
const highlightId = ref(null);
const floating = ref(false);
const tops = ref({});
const height = ref(0);

const GAP = 12;

// Thread cards plus the composer row. A general composer (no passage) goes
// first, like the general threads.
const items = computed(() => {
    const rows = store.rows.value.map((row) =>
        row.type === 'thread'
            ? { type: 'thread', key: row.key, thread: row.thread, uuid: row.thread.uuid }
            : { type: 'composer', key: row.key, uuid: row.uuid },
    );
    const c = store.composer.value;

    return c && !c.uuid ? [{ type: 'composer', key: 'c-general', uuid: null }, ...rows] : rows;
});

const activeKey = computed(() => {
    if (store.composer.value) return store.composer.value.uuid ? `c-${store.composer.value.uuid}` : 'c-general';

    return store.activeThreadId.value != null ? `t-${store.activeThreadId.value}` : null;
});

const emptyText = computed(() => {
    if (store.filter.value === 'open') return __('workflows::messages.comments_empty_open');
    if (store.filter.value === 'resolved') return __('workflows::messages.comments_empty_resolved');

    return __('workflows::messages.no_comments');
});

const deleteText = computed(() => {
    const c = store.pendingDelete.value;
    if (!c) return '';
    const hasReplies = c.parent_id == null && store.items.value.some((x) => x.parent_id === c.id);

    return hasReplies
        ? __('workflows::messages.comment_delete_thread_confirm')
        : __('workflows::messages.delete_comment_confirm');
});

// --- layout ---------------------------------------------------------------

// Where an item's text is: its marked text, else its passage, else the top.
function anchorTop(item, base) {
    const markId = item.type === 'thread' ? String(item.thread.id) : 'composer';
    let el = document.querySelector(`mark[data-comment-thread="${CSS.escape(markId)}"]`);
    if (!el && item.uuid) el = document.getElementById(passageId({ uuid: item.uuid }));

    return el ? el.getBoundingClientRect().top - base : 0;
}

let frame = null;
let waiting = [];

// Positions are measured in the next frame. Every caller waits for the LAST
// scheduled measurement — a newer layout() cancels the older frame but must
// not release its waiters before the cards are placed.
function layout() {
    cancelAnimationFrame(frame);

    return new Promise((resolve) => {
        waiting.push(resolve);
        frame = requestAnimationFrame(async () => {
            place();
            await nextTick();
            followReveal();
            const done = waiting;
            waiting = [];
            done.forEach((fn) => fn());
        });
    });
}

// A new comment box the reader was just sent to. Its card may still move for a
// moment (marks are applied, neighbours measured, images load), so every layout
// within that moment jumps to it again — the reader lands on the input, not on
// where it was a frame ago.
let reveal = null;

function followReveal() {
    if (!reveal || Date.now() > reveal.until) {
        reveal = null;

        return;
    }

    const el = list.value?.querySelector(`[data-margin-key="${CSS.escape(reveal.key)}"]`);
    if (!el) return;

    el.scrollIntoView({ block: 'center' });
    const c = Array.isArray(composer.value) ? composer.value[0] : composer.value;
    c?.focus({ preventScroll: true });
}

function place() {
    const root = list.value;
    if (!root || !floating.value) return;

    const base = root.getBoundingClientRect().top;
    const measured = items.value.map((item) => {
        const el = root.querySelector(`[data-margin-key="${CSS.escape(item.key)}"]`);

        return { id: item.key, top: anchorTop(item, base), height: el?.offsetHeight ?? 0 };
    });

    const next = layoutMargin(measured, activeKey.value, GAP);
    tops.value = next;
    height.value = measured.reduce((max, m) => Math.max(max, (next[m.id] ?? 0) + m.height), 0);
}

let query = null;
let resize = null;

function onMedia() {
    floating.value = !!query?.matches;
    nextTick(layout);
}

onMounted(() => {
    query = window.matchMedia(props.floatFrom);
    query.addEventListener('change', onMedia);
    onMedia();

    // Cards grow (replies, expanded threads, the composer) and the content
    // reflows (images loading, the window narrowing): both move anchors.
    resize = new ResizeObserver(() => layout());
    resize.observe(list.value);
    if (margin.value?.parentElement) resize.observe(margin.value.parentElement);
    window.addEventListener('resize', layout);

    applyFocusRequest();
});

onUnmounted(() => {
    cancelAnimationFrame(frame);
    query?.removeEventListener('change', onMedia);
    resize?.disconnect();
    window.removeEventListener('resize', layout);
});

watch(
    () => [items.value.map((i) => i.key).join(), activeKey.value, store.expanded.value, store.marks.value],
    async () => {
        await nextTick();
        // New cards need observing so their height changes re-lay the margin.
        list.value?.querySelectorAll('[data-margin-key]').forEach((el) => resize?.observe(el));
        layout();
    },
    { flush: 'post' },
);

// --- focus ----------------------------------------------------------------

watch(() => store.focusRequest.value, applyFocusRequest);

async function applyFocusRequest() {
    const req = store.focusRequest.value;
    if (!req) return;
    store.focusRequest.value = null;

    // Wait for the new card (and the composer's mark) to exist and be placed.
    await nextTick();
    await nextTick();
    await layout();
    const root = list.value;
    if (!root) return;

    const byAttr = (attr, value) =>
        [...root.querySelectorAll(`[${attr}]`)].find((el) => el.getAttribute(attr) === String(value ?? '')) || null;
    // Floating, the card is already level with the text the page scrolled to;
    // only a card pushed out of view needs to be brought in.
    const reveal = (el, block) => el?.scrollIntoView({ block: floating.value ? 'nearest' : block, behavior: 'smooth' });

    if (req.kind === 'comment') {
        const el = byAttr('data-comment-id', req.id);
        reveal(el, 'center');
        highlightId.value = null;
        await nextTick();
        highlightId.value = req.id;
        setTimeout(() => {
            if (highlightId.value === req.id) highlightId.value = null;
        }, 2100);
        el?.closest('[data-thread-id]')?.focus({ preventScroll: true });

        return;
    }

    if (req.kind === 'thread') {
        const el = byAttr('data-thread-id', req.id);
        reveal(el, req.focusComposer ? 'start' : 'nearest');
        if (!req.focusComposer) el?.focus({ preventScroll: true });
    }

    // A new comment always comes into view, whatever the layout: that is
    // where the reader is about to type. A jump, not a smooth scroll — a smooth
    // one would be aimed at a card that may still move.
    if (req.kind === 'composer') {
        reveal = { key: req.id ? `c-${req.id}` : 'c-general', until: Date.now() + 1000 };
        followReveal();
    }

    if (req.focusComposer) {
        const c = Array.isArray(composer.value) ? composer.value[0] : composer.value;
        c?.focus({ preventScroll: true });
    }
}

// Arrow keys on a focused thread card move between threads (like Word's
// previous / next comment). Text fields keep their arrows.
function onListKeydown(e) {
    const target = e.target;
    if (!target?.matches?.('[data-thread-id]')) return;

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        store.step(e.key === 'ArrowDown' ? 1 : -1);
    } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        store.activateThread(target.getAttribute('data-thread-id'), { focus: false });
    }
}
</script>
