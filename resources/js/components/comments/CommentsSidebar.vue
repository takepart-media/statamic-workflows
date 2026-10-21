<template>
    <!-- Word-style comment sidebar: ALL comments of the page as thread cards in
         document order, filtered by open / resolved / all, synced with the
         passages (active thread ↔ highlighted passage). One instance per page;
         state and requests live in useComments(). -->
    <Stack
        :open="store.open.value"
        size="half"
        inset
        icon="mail-chat-bubble-text"
        :title="__('workflows::messages.comments')"
        @update:open="(v) => v || store.close()"
        @opened="onOpened"
        @closed="visible = false"
    >
        <template #header-actions>
            <Button
                icon="chevron-up"
                variant="ghost"
                size="sm"
                :disabled="!store.canStep.value.prev"
                :aria-label="__('workflows::messages.comment_prev')"
                :title="__('workflows::messages.comment_prev')"
                @click="navigate(-1)"
            />
            <Button
                icon="chevron-down"
                variant="ghost"
                size="sm"
                :disabled="!store.canStep.value.next"
                :aria-label="__('workflows::messages.comment_next')"
                :title="__('workflows::messages.comment_next')"
                @click="navigate(1)"
            />
        </template>

        <div class="sticky top-0 z-10 flex items-center gap-3 border-b border-gray-200 bg-white px-6 py-3 dark:border-gray-700! dark:bg-gray-900!">
            <ToggleGroup :model-value="store.filter.value" size="sm" @update:model-value="(v) => v && store.setFilter(v)">
                <ToggleItem v-for="f in FILTERS" :key="f" :value="f">
                    {{ __(`workflows::messages.comments_filter_${f}`) }}
                    <span class="ms-1 rounded-full bg-gray-200/80 px-1.5 text-2xs tabular-nums text-gray-600 dark:bg-gray-700! dark:text-gray-300!">{{ store.counts.value[f] }}</span>
                </ToggleItem>
            </ToggleGroup>
        </div>

        <div ref="list" class="space-y-3 px-6 py-4" @keydown="onListKeydown">
            <template v-for="row in store.rows.value" :key="row.key">
                <CommentThread
                    v-if="row.type === 'thread'"
                    :thread="row.thread"
                    :store="store"
                    :active="store.activeThreadId.value === row.thread.id"
                    :highlight-id="highlightId"
                />

                <!-- New comment for the passage whose gutter button was clicked,
                     at that passage's place in document order. -->
                <section
                    v-else
                    class="rounded-lg border border-blue-300 bg-blue-50/40 p-3 shadow-xs dark:border-blue-800! dark:bg-blue-950/20!"
                    :data-composer-uuid="row.uuid"
                >
                    <button
                        type="button"
                        class="mb-2 flex max-w-full cursor-pointer items-center gap-1 text-xs text-gray-500 hover:text-blue-600 dark:text-gray-400! dark:hover:text-blue-400!"
                        :title="__('workflows::messages.comment_jump_to_passage')"
                        @click="store.revealPassage(row.uuid)"
                    >
                        <Icon name="text-formatting-quotation" class="size-3 shrink-0" />
                        <span class="truncate">{{ store.passageLabel(row.uuid, store.composer.value?.handle) }}</span>
                    </button>
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
            </template>

            <p v-if="!store.rows.value.length" class="py-10 text-center text-sm text-gray-400 dark:text-gray-500!">
                {{ emptyText }}
            </p>
        </div>
    </Stack>

    <ConfirmationModal
        :open="!!store.pendingDelete.value"
        :title="__('workflows::messages.delete')"
        :body-text="deleteText"
        :button-text="__('workflows::messages.delete')"
        danger
        @update:open="(v) => v || store.cancelDelete()"
        @confirm="store.confirmDelete()"
    />
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Button, ConfirmationModal, Icon, Stack, ToggleGroup, ToggleItem } from '@statamic/cms/ui';
import CommentThread from './CommentThread.vue';
import CommentComposer from './CommentComposer.vue';
import { FILTERS } from './comments.js';

const props = defineProps({
    // The object returned by useComments().
    comments: { type: Object, required: true },
});

const store = props.comments;
const list = ref(null);
const composer = ref(null);
const visible = ref(false);
const highlightId = ref(null);

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

function onOpened() {
    visible.value = true;
    applyFocusRequest();
}

watch(
    () => store.focusRequest.value,
    () => {
        if (visible.value) applyFocusRequest();
    },
);

async function applyFocusRequest() {
    const req = store.focusRequest.value;
    if (!req) return;
    store.focusRequest.value = null;

    await nextTick();
    const root = list.value;
    if (!root) return;

    const byAttr = (attr, value) =>
        [...root.querySelectorAll(`[${attr}]`)].find((el) => el.getAttribute(attr) === String(value)) || null;

    if (req.kind === 'comment') {
        const el = byAttr('data-comment-id', req.id);
        el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
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
        el?.scrollIntoView({ block: req.focusComposer ? 'start' : 'nearest', behavior: 'smooth' });
        if (!req.focusComposer) el?.focus({ preventScroll: true });
    }

    if (req.kind === 'composer') {
        byAttr('data-composer-uuid', req.id)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    if (req.focusComposer) {
        const c = Array.isArray(composer.value) ? composer.value[0] : composer.value;
        c?.focus({ preventScroll: req.kind === 'thread' });
    }
}

function navigate(direction) {
    store.step(direction);
}

// Arrow keys on a focused thread card move between threads (like Word's
// previous / next comment). Text fields keep their arrows.
function onListKeydown(e) {
    const target = e.target;
    if (!target?.matches?.('[data-thread-id]')) return;

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        navigate(e.key === 'ArrowDown' ? 1 : -1);
    } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        store.activateThread(target.getAttribute('data-thread-id'), { focus: false });
    }
}
</script>
