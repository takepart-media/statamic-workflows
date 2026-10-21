<template>
    <!-- Comment input with @mention autocomplete. Used for new comments, replies
         (collapsed to one line until focused) and inline edits. Ctrl/Cmd+Enter
         sends, Esc closes the mention list first and cancels second. -->
    <div class="fw-composer relative" :class="{ 'fw-composer--collapsed': collapsed }">
        <div class="flex items-start gap-2">
            <Avatar v-if="user && !collapsed && showAvatar" :user="user" class="mt-0.5 size-6 shrink-0 text-2xs" />
            <div class="relative min-w-0 flex-1">
                <textarea
                    ref="input"
                    v-model="body"
                    :rows="collapsed ? 1 : rows"
                    :placeholder="placeholder"
                    :aria-label="placeholder"
                    :disabled="busy"
                    role="combobox"
                    aria-autocomplete="list"
                    :aria-expanded="mention.open ? 'true' : 'false'"
                    :aria-controls="listId"
                    :aria-activedescendant="mention.open && suggestions.length ? `${listId}-${mention.index}` : undefined"
                    class="block w-full resize-y rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-600! dark:bg-gray-900! dark:text-gray-200!"
                    :class="{ 'resize-none overflow-hidden': collapsed }"
                    @focus="expanded = true"
                    @input="updateMention"
                    @click="updateMention"
                    @keyup="onKeyup"
                    @keydown="onKeydown"
                    @blur="onBlur"
                />

                <!-- Mention suggestions: a listbox under the textarea rather than a
                     Popover — the kit's Popover moves focus into its content, and the
                     caret has to stay in the text while you pick a name. -->
                <ul
                    v-if="mention.open"
                    :id="listId"
                    role="listbox"
                    class="absolute inset-x-0 top-full z-20 mt-1 max-h-56 overflow-auto rounded-lg border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700! dark:bg-gray-800!"
                >
                    <li
                        v-for="(u, i) in suggestions"
                        :id="`${listId}-${i}`"
                        :key="u.id"
                        role="option"
                        :aria-selected="i === mention.index ? 'true' : 'false'"
                        class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 dark:text-gray-200!"
                        :class="i === mention.index ? 'bg-blue-50 dark:bg-blue-950/50!' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50!'"
                        @mousedown.prevent="pick(u)"
                        @mousemove="mention.index = i"
                    >
                        <Avatar :user="u" class="size-5 shrink-0 text-2xs" />
                        <span class="truncate">{{ u.name }}</span>
                    </li>
                    <li v-if="!suggestions.length" class="px-2 py-1.5 text-sm text-gray-400">
                        {{ __('workflows::messages.comment_mention_none') }}
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="!collapsed" class="mt-2 flex items-center gap-2" :class="{ 'ps-8': user && showAvatar }">
            <span class="me-auto hidden text-2xs text-gray-400 sm:inline dark:text-gray-500!">{{ __('workflows::messages.comment_shortcut_hint') }}</span>
            <Button
                v-if="cancellable || compact"
                variant="ghost"
                size="sm"
                :text="__('workflows::messages.cancel')"
                :disabled="busy"
                @click="cancel"
            />
            <Button
                variant="primary"
                size="sm"
                :text="submitText || __('workflows::messages.comment_submit')"
                :loading="busy"
                :disabled="busy || !body.trim()"
                @click="submit"
            />
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { Avatar, Button } from '@statamic/cms/ui';
import { filterMentionables, insertMention, mentionQueryAt } from './comments.js';

const props = defineProps({
    users: { type: Array, default: () => [] },
    user: { type: Object, default: null },
    placeholder: { type: String, default: '' },
    submitText: { type: String, default: '' },
    initialBody: { type: String, default: '' },
    // [{ id, name }] already in the body (edit mode).
    initialMentions: { type: Array, default: () => [] },
    // Reply box: one line until focused, collapses again when left empty.
    compact: { type: Boolean, default: false },
    cancellable: { type: Boolean, default: false },
    showAvatar: { type: Boolean, default: true },
    rows: { type: Number, default: 3 },
    // async ({ body, mentions }) => boolean — true clears the composer.
    onSubmit: { type: Function, required: true },
});

const emit = defineEmits(['cancel']);

const input = ref(null);
const body = ref(props.initialBody);
const tracked = ref([...(props.initialMentions || [])]);
const expanded = ref(!props.compact);
const busy = ref(false);
const mention = reactive({ open: false, start: 0, query: '', index: 0 });
const listId = `fw-mentions-${Math.random().toString(36).slice(2, 9)}`;

const collapsed = computed(() => props.compact && !expanded.value && !body.value);
const suggestions = computed(() => filterMentionables(props.users, mention.query));

function updateMention() {
    const el = input.value;
    if (!el || !props.users.length) return closeMention();

    const hit = mentionQueryAt(body.value, el.selectionStart);
    if (!hit) return closeMention();

    // A query with a space that matches nobody is ordinary text after all.
    if (hit.query.includes(' ') && !filterMentionables(props.users, hit.query).length) return closeMention();

    if (!mention.open || mention.start !== hit.start) mention.index = 0;
    mention.open = true;
    mention.start = hit.start;
    mention.query = hit.query;
    if (mention.index >= suggestions.value.length) mention.index = 0;
}

function closeMention() {
    mention.open = false;
    mention.query = '';
    mention.index = 0;
}

async function pick(u) {
    const el = input.value;
    const caret = el ? el.selectionStart : body.value.length;
    const next = insertMention(body.value, mention.start, caret, u);
    body.value = next.text;
    if (!tracked.value.some((m) => m.id === u.id)) tracked.value.push({ id: u.id, name: u.name });
    closeMention();

    await nextTick();
    el?.focus();
    el?.setSelectionRange(next.caret, next.caret);
}

function onKeydown(e) {
    if (mention.open) {
        const n = suggestions.value.length;
        if (e.key === 'ArrowDown' && n) {
            e.preventDefault();
            mention.index = (mention.index + 1) % n;
            return;
        }
        if (e.key === 'ArrowUp' && n) {
            e.preventDefault();
            mention.index = (mention.index - 1 + n) % n;
            return;
        }
        if ((e.key === 'Enter' || e.key === 'Tab') && n && !e.metaKey && !e.ctrlKey) {
            e.preventDefault();
            pick(suggestions.value[mention.index]);
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            closeMention();
            return;
        }
    }

    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        submit();
        return;
    }

    if (e.key === 'Escape') {
        // Keep the Stack open: Esc here means "leave the composer".
        e.preventDefault();
        e.stopPropagation();
        cancel();
    }
}

function onKeyup(e) {
    if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) updateMention();
}

function onBlur() {
    // Let a mousedown on a suggestion land first.
    setTimeout(closeMention, 120);
    if (props.compact && !body.value.trim()) expanded.value = false;
}

function reset() {
    body.value = '';
    tracked.value = [];
    closeMention();
    if (props.compact) expanded.value = false;
}

function cancel() {
    if (busy.value) return;
    reset();
    input.value?.blur();
    emit('cancel');
}

async function submit() {
    const text = body.value.trim();
    if (!text || busy.value) return;

    busy.value = true;
    try {
        const ok = await props.onSubmit({ body: text, mentions: tracked.value.slice() });
        if (ok) reset();
    } finally {
        busy.value = false;
    }
}

function focus(options = {}) {
    expanded.value = true;
    nextTick(() => {
        const el = input.value;
        if (!el) return;
        el.focus({ preventScroll: !!options.preventScroll });
        const end = el.value.length;
        el.setSelectionRange(end, end);
    });
}

defineExpose({ focus, reset });
</script>
