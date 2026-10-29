<template>
    <!-- A passage's comments while the margin is hidden: a popover at its gutter
         button (or at the clicked mark) with the open threads and a box for a new
         comment. Fixed and teleported to <body>, like the selection icon, so it
         follows its anchor while the page scrolls. -->
    <Teleport to="body">
        <section
            v-if="popover"
            ref="panel"
            role="dialog"
            :aria-label="label"
            class="fw-popover fixed z-[55] flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700! dark:bg-gray-900!"
            :style="style"
            @keydown.esc="store.closePopover()"
        >
            <header class="flex items-center gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-800!">
                <Icon name="text-formatting-quotation" class="size-3 shrink-0 text-gray-400" />
                <span class="min-w-0 flex-1 truncate text-xs font-medium text-gray-600 dark:text-gray-300!">{{ label }}</span>
                <Button icon="x" variant="ghost" size="xs" :aria-label="__('Close')" @click="store.closePopover()" />
            </header>

            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto p-3">
                <!-- Resolved threads of this passage, on request — like the
                     margin's switch. Only where there are any. -->
                <label
                    v-if="store.popoverResolvedCount.value"
                    class="ms-auto flex w-fit cursor-pointer items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"
                >
                    <Switch size="xs" v-model="store.popoverResolved.value" />
                    {{ __('workflows::messages.comments_show_resolved') }}
                    <span class="rounded-full bg-gray-200/80 px-1.5 text-2xs tabular-nums text-gray-600 dark:bg-gray-700! dark:text-gray-300!">{{ store.popoverResolvedCount.value }}</span>
                </label>

                <CommentThread
                    v-for="thread in store.popoverThreads.value"
                    :key="thread.id"
                    :thread="thread"
                    :store="store"
                    :active="store.activeThreadId.value === thread.id"
                />

                <div :class="{ 'border-t border-gray-100 pt-3 dark:border-gray-800!': store.popoverThreads.value.length }">
                    <!-- A new comment on marked text (no margin on this page). -->
                    <blockquote
                        v-if="popover.quote"
                        class="mb-2 line-clamp-3 border-s-2 border-yellow-400 ps-2 text-xs italic text-gray-600 dark:text-gray-300!"
                    >{{ popover.quote }}</blockquote>
                    <CommentComposer
                        ref="composer"
                        :user="store.currentUser"
                        :users="store.mentionableUsers.value"
                        :placeholder="__('workflows::messages.write_comment')"
                        :rows="2"
                        :on-submit="(payload) => store.createRoot(payload, { uuid: popover.uuid, handle: popover.handle, quote: popover.quote, offset: popover.offset })"
                    />
                </div>
            </div>

        </section>
    </Teleport>

    <!-- The margin, which normally holds it, is not there. -->
    <ConfirmationModal
        v-if="!store.open.value"
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
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { Button, ConfirmationModal, Icon, Switch } from '@statamic/cms/ui';
import CommentThread from './CommentThread.vue';
import CommentComposer from './CommentComposer.vue';

const props = defineProps({
    // The object returned by useComments().
    comments: { type: Object, required: true },
});

const store = props.comments;
const popover = computed(() => store.popover.value);
const label = computed(() => (popover.value ? store.passageLabel(popover.value.uuid, popover.value.handle) : ''));

const deleteText = computed(() => {
    const c = store.pendingDelete.value;
    if (!c) return '';
    const hasReplies = c.parent_id == null && store.items.value.some((x) => x.parent_id === c.id);

    return hasReplies
        ? __('workflows::messages.comment_delete_thread_confirm')
        : __('workflows::messages.delete_comment_confirm');
});

const panel = ref(null);
const composer = ref(null);
// null until the anchor has been found: the popover is not shown at 0,0 meanwhile.
const position = ref(null);

const WIDTH = 352;
const GAP = 8;

const style = computed(() => ({
    top: `${position.value?.top ?? 0}px`,
    left: `${position.value?.left ?? 0}px`,
    visibility: position.value ? null : 'hidden',
    width: `${Math.min(WIDTH, window.innerWidth - 2 * GAP)}px`,
    maxHeight: `${Math.min(560, window.innerHeight - 2 * GAP)}px`,
}));

let frame = null;
let observer = null;

// The anchor is an element, or a function finding one — a mark that is only
// put in after the next render, say.
function anchorEl() {
    const anchor = popover.value?.anchor;

    return typeof anchor === 'function' ? anchor() : anchor;
}

// Left of the anchor (the gutter sits right of the text), level with its top;
// below it where there is no room on the left. Kept inside the viewport.
function place() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => {
        if (!popover.value) return;
        const anchor = anchorEl();
        if (!anchor) return;
        if (!anchor.isConnected) return typeof popover.value.anchor === 'function' ? null : store.closePopover();

        const rect = anchor.getBoundingClientRect();
        const width = Math.min(WIDTH, window.innerWidth - 2 * GAP);
        const height = panel.value?.offsetHeight || 0;

        let left = rect.left - width - GAP;
        let top = rect.top;
        if (left < GAP) {
            left = Math.min(Math.max(GAP, rect.right - width), window.innerWidth - width - GAP);
            top = rect.bottom + GAP;
        }
        top = Math.max(GAP, Math.min(top, window.innerHeight - height - GAP));

        position.value = { top, left };
    });
}

// Clicks outside close it — except on its anchor (the button toggles it) and in
// the menus / dialogs it opens itself, which the kit teleports elsewhere.
function onPointerDown(e) {
    const target = e.target;
    if (!popover.value || store.pendingDelete.value) return;
    if (panel.value?.contains(target) || anchorEl()?.contains?.(target)) return;
    if (target?.closest?.('[role="menu"], [role="dialog"], [data-reka-popper-content-wrapper]')) return;

    store.closePopover();
}

function listen(on) {
    const method = on ? 'addEventListener' : 'removeEventListener';
    document[method]('mousedown', onPointerDown, true);
    window[method]('scroll', place, true);
    window[method]('resize', place);
}

watch(
    () => popover.value?.anchor,
    async (anchor, previous) => {
        observer?.disconnect();
        if (!anchor) {
            position.value = null;
            if (previous) listen(false);

            return;
        }
        if (!previous) listen(true);

        await nextTick();
        await nextTick(); // marks go in a tick after the render
        place();
        // Threads coming and going change the height — re-clamp to the viewport.
        if (panel.value && typeof ResizeObserver !== 'undefined') {
            observer = new ResizeObserver(place);
            observer.observe(panel.value);
        }
        // Nothing to read yet, or marked text to comment on: straight into writing.
        if (!store.popoverThreads.value.length || popover.value?.quote) composer.value?.focus({ preventScroll: true });
    },
);

onUnmounted(() => {
    cancelAnimationFrame(frame);
    observer?.disconnect();
    listen(false);
});
</script>
