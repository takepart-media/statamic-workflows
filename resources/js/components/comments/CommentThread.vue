<template>
    <!-- A thread card: passage context line, the root comment, its replies and a
         reply box. Resolved threads collapse to one muted line until expanded. -->
    <article
        class="fw-thread rounded-lg border bg-white text-sm shadow-xs transition-[border-color,box-shadow,opacity] dark:bg-gray-900!"
        :class="[
            active ? 'fw-thread--active border-blue-300 dark:border-blue-700!' : 'border-gray-200 dark:border-gray-700!',
            thread.resolved && !isExpanded ? 'opacity-70 hover:opacity-100' : '',
        ]"
        :data-thread-id="thread.id"
        tabindex="0"
        :aria-current="active ? 'true' : undefined"
        @click="activate"
        @focus="onFocus"
    >
        <header class="flex items-center gap-2 px-3 pt-2.5 text-xs">
            <button
                v-if="thread.uuid"
                type="button"
                class="flex min-w-0 cursor-pointer items-center gap-1 text-gray-500 hover:text-blue-600 dark:text-gray-400! dark:hover:text-blue-400!"
                :title="__('workflows::messages.comment_jump_to_passage')"
                @click.stop="jump"
            >
                <Icon name="text-formatting-quotation" class="size-3 shrink-0" />
                <span class="truncate">{{ label }}</span>
            </button>
            <span v-else class="flex items-center gap-1 text-gray-500 dark:text-gray-400!">
                <Icon name="mail-chat-bubble-text" class="size-3 shrink-0" />
                {{ __('workflows::messages.comments_general') }}
            </span>

            <span
                v-if="thread.resolved"
                class="ms-auto inline-flex shrink-0 items-center gap-1 rounded-full bg-green-100 px-1.5 py-0.5 text-2xs font-medium text-green-800 dark:bg-green-900/40! dark:text-green-300!"
            >
                <Icon name="checkmark" class="size-2.5" />
                {{ __('workflows::messages.comments_filter_resolved') }}
            </span>
        </header>

        <!-- Collapsed resolved thread: who, when, first line. -->
        <div v-if="thread.resolved && !isExpanded" class="px-3 pt-1 pb-2.5">
            <p class="truncate text-gray-600 dark:text-gray-300!">
                <span class="font-medium">{{ thread.root.author?.name }}:</span> {{ thread.root.body }}
            </p>
            <div class="mt-1 flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500!">
                <span :title="absoluteTime(thread.root.resolved?.at, store.locale)">{{ resolvedLine }}</span>
                <span v-if="thread.replies.length">· {{ __('workflows::messages.comment_replies_count', { count: thread.replies.length }) }}</span>
                <button
                    type="button"
                    class="ms-auto cursor-pointer text-blue-600 hover:underline dark:text-blue-400!"
                    @click.stop="store.toggleExpanded(thread.id)"
                >{{ __('workflows::messages.comment_expand') }}</button>
            </div>
        </div>

        <template v-else>
            <div class="space-y-3 px-3 pt-2 pb-3">
                <CommentCard
                    :comment="thread.root"
                    :store="store"
                    :resolved="thread.resolved"
                    :highlighted="highlightId != null && highlightId === String(thread.root.id)"
                    @resolve="store.setResolved(thread, true)"
                    @reopen="store.setResolved(thread, false)"
                />
                <div v-if="thread.replies.length" class="space-y-3 border-s-2 border-gray-100 ps-3 dark:border-gray-800!">
                    <CommentCard
                        v-for="r in thread.replies"
                        :key="r.id"
                        :comment="r"
                        :store="store"
                        reply
                        :highlighted="highlightId != null && highlightId === String(r.id)"
                    />
                </div>
            </div>

            <div v-if="thread.resolved" class="flex items-center gap-2 border-t border-gray-100 px-3 py-2 text-xs text-gray-500 dark:border-gray-800! dark:text-gray-400!">
                <Icon name="checkmark" class="size-3 text-green-600" />
                <span :title="absoluteTime(thread.root.resolved?.at, store.locale)">{{ resolvedLine }}</span>
                <Button
                    v-if="thread.root.can_resolve"
                    class="ms-auto"
                    size="xs"
                    variant="ghost"
                    icon="history"
                    :text="__('workflows::messages.comment_reopen')"
                    @click.stop="store.setResolved(thread, false)"
                />
                <Button
                    size="xs"
                    variant="ghost"
                    :class="{ 'ms-auto': !thread.root.can_resolve }"
                    :text="__('workflows::messages.comment_collapse')"
                    @click.stop="store.toggleExpanded(thread.id)"
                />
            </div>

            <div v-else class="border-t border-gray-100 px-3 py-2 dark:border-gray-800!" @click.stop>
                <CommentComposer
                    compact
                    :user="store.currentUser"
                    :users="store.mentionableUsers.value"
                    :placeholder="__('workflows::messages.comment_reply_placeholder')"
                    :submit-text="__('workflows::messages.comment_reply')"
                    :on-submit="(payload) => store.reply(thread, payload)"
                    @focusin="active || store.activateThread(thread.id, { focus: false })"
                />
            </div>
        </template>
    </article>
</template>

<script setup>
import { computed } from 'vue';
import { Button, Icon } from '@statamic/cms/ui';
import CommentCard from './CommentCard.vue';
import CommentComposer from './CommentComposer.vue';
import { absoluteTime, relativeTime } from './comments.js';

const props = defineProps({
    thread: { type: Object, required: true },
    store: { type: Object, required: true },
    active: { type: Boolean, default: false },
    // Comment to flash once (history handoff / ?comment=…).
    highlightId: { type: [String, Number], default: null },
});

const isExpanded = computed(() => props.store.expanded.value.has(props.thread.id));

const label = computed(() => props.store.passageLabel(props.thread.uuid, props.thread.handle));

const resolvedLine = computed(() => {
    const r = props.thread.root.resolved;
    if (!r) return '';

    return __('workflows::messages.comment_resolved_by', {
        name: r.by?.name || '—',
        time: relativeTime(r.at, props.store.now.value, props.store.locale),
    });
});

function activate(e) {
    // Clicks on controls inside the card do their own thing.
    if (e?.target?.closest?.('button, a, textarea, input, [role="menuitem"]')) return;
    props.store.activateThread(props.thread.id, { focus: false });
}

function onFocus(e) {
    // Keyboard focus on the card itself (Tab / arrow navigation) makes it active
    // without moving the page — the arrow handler in the sidebar does that.
    if (e.target === e.currentTarget && !props.active) {
        props.store.activateThread(props.thread.id, { focus: false, scrollPage: false });
    }
}

function jump() {
    props.store.activateThread(props.thread.id, { focus: false });
}
</script>

<style scoped>
.fw-thread { outline: none; position: relative; }
.fw-thread:focus-visible { box-shadow: 0 0 0 2px rgb(59 130 246 / 0.5); }
.fw-thread--active::before {
    content: '';
    position: absolute;
    inset-block: -1px;
    inset-inline-start: -1px;
    width: 3px;
    border-start-start-radius: 0.5rem;
    border-end-start-radius: 0.5rem;
    background-color: rgb(59 130 246);
}
</style>
