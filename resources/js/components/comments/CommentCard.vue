<template>
    <!-- One comment, Word-style: avatar, name, relative time, body with
         highlighted @mentions, freshness signals, and the actions menu. -->
    <div
        class="fw-comment group/comment flex gap-2.5 rounded-md transition-colors"
        :class="{ 'fw-comment--focus': highlighted }"
        :data-comment-id="comment.id"
    >
        <Avatar :user="author" :class="reply ? 'size-6 text-2xs' : 'size-7 text-xs'" class="mt-0.5 shrink-0" />

        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5 text-xs">
                <span class="truncate font-semibold text-gray-800 dark:text-gray-100!">{{ author.name }}</span>
                <time
                    class="shrink-0 text-gray-400 dark:text-gray-500!"
                    :datetime="comment.at"
                    :title="absoluteTime(comment.at, store.locale)"
                >{{ relativeTime(comment.at, store.now.value, store.locale) }}</time>
                <span
                    v-if="comment.edited_at"
                    class="shrink-0 text-gray-400 italic dark:text-gray-500!"
                    :title="absoluteTime(comment.edited_at, store.locale)"
                >({{ __('workflows::messages.comment_edited') }})</span>
                <span
                    v-if="store.isFresh(comment)"
                    class="shrink-0 rounded-full bg-amber-500 px-1.5 text-2xs font-semibold text-white"
                >{{ __('workflows::messages.new') }}</span>

                <!-- Resolve and edit right on the card, Word-style; only delete,
                     the destructive one, stays behind the "…" menu. Reopening a
                     resolved thread is the button in its footer. -->
                <div v-if="hasActions && !editing" class="ms-auto -my-1 flex shrink-0 items-center" @click.stop>
                    <Button
                        v-if="canResolve"
                        icon="checkmark"
                        variant="ghost"
                        size="xs"
                        :aria-label="__('workflows::messages.comment_resolve')"
                        :title="__('workflows::messages.comment_resolve')"
                        class="text-green-700! dark:text-green-400!"
                        @click="$emit('resolve')"
                    />
                    <Button
                        v-if="comment.can_edit"
                        icon="pencil"
                        variant="ghost"
                        size="xs"
                        :aria-label="__('workflows::messages.edit')"
                        :title="__('workflows::messages.edit')"
                        @click="startEdit"
                    />
                    <Dropdown v-if="comment.can_delete" align="end">
                        <template #trigger>
                            <Button
                                icon="dots"
                                variant="ghost"
                                size="xs"
                                :aria-label="__('workflows::messages.comment_actions')"
                                :title="__('workflows::messages.comment_actions')"
                                class="opacity-60 group-hover/comment:opacity-100 focus-visible:opacity-100"
                            />
                        </template>
                        <DropdownMenu>
                            <DropdownItem
                                icon="trash"
                                variant="destructive"
                                :text="__('workflows::messages.delete')"
                                @click="store.askDelete(comment)"
                            />
                        </DropdownMenu>
                    </Dropdown>
                </div>
            </div>

            <CommentComposer
                v-if="editing"
                ref="editor"
                class="mt-1.5"
                :users="store.mentionableUsers.value"
                :initial-body="comment.body"
                :initial-mentions="comment.mentions || []"
                :submit-text="__('workflows::messages.save')"
                :show-avatar="false"
                :rows="3"
                cancellable
                :on-submit="saveEdit"
                @cancel="editing = false"
                @click.stop
            />

            <p v-else class="mt-0.5 text-sm leading-relaxed whitespace-pre-line break-words text-gray-700 dark:text-gray-200!"><template
                v-for="(seg, i) in segments"
                :key="i"
            ><span
                v-if="seg.type === 'mention'"
                class="fw-mention"
                :class="{ 'fw-mention--me': isMe(seg.id) }"
            >{{ seg.text }}</span><template v-else>{{ seg.text }}</template></template></p>

            <!-- Freshness signals: metadata about relevance, not alarms. -->
            <div v-if="comment.content_stale || comment.container_changed" class="mt-1 flex flex-wrap items-center gap-3 text-xs">
                <span v-if="comment.content_stale" class="inline-flex items-center gap-1 text-amber-700 dark:text-amber-300!">
                    <Icon name="warning-diamond" class="size-3" />
                    {{ __('workflows::messages.comment_content_stale') }}
                </span>
                <span v-if="comment.container_changed" class="inline-flex items-center gap-1 text-blue-700 dark:text-blue-300!">
                    <Icon name="pencil" class="size-3" />
                    {{ __('workflows::messages.comment_container_changed') }}
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue';
import { Avatar, Button, Dropdown, DropdownItem, DropdownMenu, Icon } from '@statamic/cms/ui';
import CommentComposer from './CommentComposer.vue';
import { absoluteTime, relativeTime, tokenizeBody } from './comments.js';

const props = defineProps({
    comment: { type: Object, required: true },
    store: { type: Object, required: true },
    reply: { type: Boolean, default: false },
    resolved: { type: Boolean, default: false },
    highlighted: { type: Boolean, default: false },
});

defineEmits(['resolve', 'reopen']);

const editing = ref(false);
const editor = ref(null);

const author = computed(() => ({
    id: props.comment.author?.id ?? null,
    name: props.comment.author?.name || '—',
    avatar: props.comment.author?.avatar || undefined,
    initials: props.comment.author?.initials || undefined,
}));

const segments = computed(() => tokenizeBody(props.comment.body, props.comment.mentions));

// Resolving belongs to the thread, so only its root offers it — and only
// while it is open.
const canResolve = computed(() => !props.reply && props.comment.can_resolve && !props.resolved);

const hasActions = computed(() => props.comment.can_edit || props.comment.can_delete || canResolve.value);

function isMe(id) {
    return id != null && props.store.currentUser && String(id) === String(props.store.currentUser.id);
}

function startEdit() {
    editing.value = true;
    // The dropdown returns focus to its trigger on close; focus the editor after that.
    setTimeout(() => nextTick(() => editor.value?.focus()), 50);
}

async function saveEdit(payload) {
    const ok = await props.store.update(props.comment, payload);
    if (ok) editing.value = false;

    return ok;
}
</script>

<style scoped>
.fw-mention {
    border-radius: 0.25rem;
    padding-inline: 0.15rem;
    font-weight: 500;
    color: rgb(29 78 216);
    background-color: rgb(219 234 254 / 0.8);
}
:where(.dark) .fw-mention { color: rgb(147 197 253); background-color: rgb(30 58 138 / 0.4); }
.fw-mention--me { color: rgb(146 64 14); background-color: rgb(254 243 199); }
:where(.dark) .fw-mention--me { color: rgb(252 211 77); background-color: rgb(120 53 15 / 0.4); }
.fw-comment--focus { animation: fw-comment-focus 2s ease-out; }
@keyframes fw-comment-focus {
    0%, 40% { background-color: rgb(254 240 138 / 0.6); box-shadow: 0 0 0 4px rgb(254 240 138 / 0.6); }
    100% { background-color: transparent; box-shadow: 0 0 0 4px transparent; }
}
</style>
