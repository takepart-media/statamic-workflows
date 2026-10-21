<template>
    <!-- No heading of its own: both hosts render this inside a Stack whose own
         title already says "history", and a second one right below it read as a
         duplicate. -->
    <ol class="m-0 list-none p-0">
        <!-- Save rows sit between the workflow steps, so they are indented and
             muted: the steps have to stay the thing you read first. -->
        <li
            v-for="event in history"
            :key="event.id"
            :class="[
                rowClass,
                isSave(event) ? 'ps-3 border-s-2 border-s-gray-200 dark:border-s-gray-700!' : '',
                isComment(event) ? 'ps-3 border-s-2 border-s-blue-200 dark:border-s-blue-800!' : '',
                isReminder(event) ? 'ps-3 border-s-2 border-s-amber-200 dark:border-s-amber-800!' : '',
            ]"
        >
            <div class="text-xs text-gray-400">{{ formatDate(event.at) }}</div>
            <div :class="isSave(event) || isReminder(event) ? 'text-gray-500 dark:text-gray-400!' : 'dark:text-gray-300'">
                <strong>{{ event.actor.name }}</strong>
                <template v-if="event.transition">
                    — {{ event.from }} &rarr; {{ event.to }}
                    (<code>{{ event.transition }}</code>)
                </template>
                <!-- A save is not a state change: no from/to, the note says it all. -->
                <template v-else-if="isSave(event)">
                    — {{ event.note }}
                </template>
                <!-- A comment isn't a state change either — the timeline entry
                     records "so-and-so said this while the entry was in state X".
                     The click opens the passage drawer for the full thread. -->
                <template v-else-if="isComment(event)">
                    — {{ __('workflows::messages.history_event_comment_created') }}
                    <template v-if="event.to">
                        (<em>{{ event.to }}</em>)
                    </template>
                    <template v-if="event.comment?.passage_handle">
                        · <code>{{ event.comment.passage_handle }}</code>
                    </template>
                </template>
                <!-- Sent by the scheduler while the entry sat in `from` — not a
                     state change, so no arrow. -->
                <template v-else-if="isReminder(event)">
                    — {{ __('workflows::messages.history_event_reminder_sent') }}
                    <template v-if="event.from">
                        (<em>{{ event.from }}</em>)
                    </template>
                </template>
                <template v-else>
                    — {{ event.to }}
                </template>
            </div>

            <!-- Comment body preview (or deleted placeholder). Click opens
                 the passage drawer via emit, unless the comment is gone. -->
            <div
                v-if="isComment(event)"
                :class="[
                    'mt-1 rounded border-l-2 border-blue-300 bg-blue-50 px-2 py-1 text-sm italic dark:border-blue-700! dark:bg-blue-950/40!',
                    commentClickable(event) ? 'cursor-pointer hover:bg-blue-100 dark:hover:bg-blue-900/40!' : 'opacity-70',
                ]"
                :role="commentClickable(event) ? 'button' : undefined"
                :tabindex="commentClickable(event) ? 0 : undefined"
                @click="onCommentClick(event)"
                @keydown.enter="onCommentClick(event)"
                @keydown.space.prevent="onCommentClick(event)"
            >
                <template v-if="event.comment?.deleted">
                    {{ __('workflows::messages.history_comment_deleted') }}
                </template>
                <template v-else>
                    „{{ event.comment?.body_preview }}"
                </template>
            </div>

            <div v-if="event.note && !isSave(event) && !isComment(event)" class="mt-0.5 italic text-gray-500">{{ event.note }}</div>

            <div v-if="historyDiffUrlTemplate && hasDiffButton(event)" class="mt-1">
                <Button
                    variant="ghost"
                    size="xs"
                    :text="diffs[event.id]?.open
                        ? __('workflows::messages.history_hide_diff')
                        : __('workflows::messages.history_show_diff')"
                    @click="toggleDiff(event.id)"
                />
            </div>

            <div v-if="diffs[event.id]?.open" class="mt-2 rounded-md border border-gray-200 bg-gray-50 p-3 dark:border-gray-700! dark:bg-gray-900!">
                <div v-if="diffs[event.id].loading" class="text-xs text-gray-400">
                    {{ __('workflows::messages.loading') }}
                </div>
                <div v-else-if="diffs[event.id].note === 'no_diff_data'" class="text-xs text-gray-400">
                    {{ __('workflows::messages.history_no_diff_data') }}
                </div>
                <div v-else-if="diffs[event.id].note === 'no_comparison'" class="text-xs text-gray-400">
                    {{ __('workflows::messages.history_no_comparison') }}
                </div>
                <div v-else-if="diffs[event.id].changes?.length === 0" class="text-xs text-gray-400">
                    {{ __('workflows::messages.history_no_changes') }}
                </div>
                <ul v-else class="space-y-2">
                    <li v-for="change in diffs[event.id].changes" :key="change.field" class="text-xs">
                        <div class="font-medium text-gray-700 dark:text-gray-200">
                            {{ change.label }}
                            <span class="ml-1 text-[10px] uppercase tracking-wide text-gray-400">{{ change.kind }}</span>
                        </div>
                        <!-- Scalar / choice / date / bool / rich / assets / relationships:
                             left = before, right = after, with word-level red/green
                             highlighting on the differing tokens. -->
                        <div v-if="change.showValues" class="mt-0.5 grid grid-cols-1 gap-x-4 gap-y-0.5 text-[11px] text-gray-600 dark:text-gray-300 sm:grid-cols-2">
                            <div class="whitespace-pre-wrap break-words">
                                <template v-for="(chunk, i) in wordDiff(change.from, change.to).before" :key="'b'+i">
                                    <span
                                        v-if="chunk.kind === 'removed'"
                                        class="rounded bg-red-100 px-0.5 text-red-800 line-through dark:bg-red-950! dark:text-red-100!"
                                    >{{ chunk.text }}</span>
                                    <template v-else>{{ chunk.text }}</template>
                                </template>
                            </div>
                            <div class="whitespace-pre-wrap break-words">
                                <template v-for="(chunk, i) in wordDiff(change.from, change.to).after" :key="'a'+i">
                                    <span
                                        v-if="chunk.kind === 'added'"
                                        class="rounded bg-green-100 px-0.5 text-green-800 dark:bg-green-950! dark:text-green-100!"
                                    >{{ chunk.text }}</span>
                                    <template v-else>{{ chunk.text }}</template>
                                </template>
                            </div>
                        </div>
                        <!-- Values that can't be shown side by side: one-line summary. -->
                        <div v-else class="mt-0.5 text-[11px] italic text-gray-500 dark:text-gray-400">
                            {{ change.summary }}
                        </div>
                    </li>
                </ul>
            </div>
        </li>
        <li v-if="history.length === 0" class="py-2.5 text-sm text-gray-400">{{ __('workflows::messages.no_history') }}</li>
    </ol>
</template>

<script setup>
import { ref, getCurrentInstance } from 'vue';
import { Button } from '@statamic/cms/ui';

/**
 * The workflow History list with its per-event diff panel. Rendered inside the
 * history drawer of both detail pages (WorkflowInstanceDetail and
 * TranslationInstanceDetail), which would otherwise each need their own copy of the
 * LCS word diff below. The drawer supplies the heading and the scroll container.
 */
const props = defineProps({
    history: { type: Array, default: () => [] },
    // URL template with an `__EVENT__` placeholder. Empty disables the diff
    // buttons entirely (the list still renders).
    historyDiffUrlTemplate: { type: String, default: '' },
    // Row separator classes, so each host page keeps its own list rhythm.
    rowClass: { type: String, default: 'border-b border-gray-100 py-2.5 text-sm last:border-0 dark:border-gray-800!' },
});

// `open-comment` fires when the user clicks a comment_created row. The parent
// page (WorkflowInstanceDetail / TranslationInstanceDetail) opens the comment
// sidebar at that comment (falling back to its passage_ref if it is gone).
const emit = defineEmits(['open-comment']);

// Event types that record a plain save rather than a workflow step. Kept in sync
// with WorkflowController::SAVE_TYPES, which is what makes the diff of a step span
// all the saves that happened during it.
const SAVE_TYPES = ['draft_save'];

function isSave(event) {
    return SAVE_TYPES.includes(event.type);
}

function isComment(event) {
    return event.type === 'comment_created';
}

function isReminder(event) {
    return event.type === 'reminder_sent';
}

// A comment event has no content diff to show — its "content" is the body
// itself. Also true when the comment has been deleted since (no target to
// open the drawer against).
function hasDiffButton(event) {
    return !isComment(event) && !isReminder(event);
}

function commentClickable(event) {
    // Passage-less comments (QA notes, general ones) open in the sidebar too —
    // it shows every comment of the page, not only one passage's.
    return isComment(event) && !event.comment?.deleted && (event.comment?.passage_ref || event.comment?.id);
}

function onCommentClick(event) {
    if (!commentClickable(event)) return;
    emit('open-comment', {
        passageRef: event.comment.passage_ref ?? null,
        commentId: event.comment.id,
    });
}

// Statamic exposes axios on Vue's globals rather than as an importable module.
const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

// Per-history-event diff state: { open, loading, vs, changes, note }.
const diffs = ref({});

function formatDate(iso) {
    try {
        return new Date(iso).toLocaleString();
    } catch (e) {
        return iso;
    }
}

// Word-level diff for the History side-by-side view. Splits both strings on
// whitespace boundaries, runs an LCS (O(m·n) DP), and returns two token lists
// annotated with `kind: 'same' | 'removed' | 'added'`. The template styles
// removed tokens red on the left column and added tokens green on the right —
// matches the visual pattern of Statamic's own revision preview.
//
// Cached by input pair — Vue would otherwise re-run this on every render.
const wordDiffCache = new Map();
function wordDiff(oldText, newText) {
    const oldStr = String(oldText ?? '');
    const newStr = String(newText ?? '');
    const cacheKey = oldStr + ' ' + newStr;
    if (wordDiffCache.has(cacheKey)) return wordDiffCache.get(cacheKey);

    const tokenise = (s) => s.split(/(\s+)/).filter((t) => t.length > 0);
    const oldTokens = tokenise(oldStr);
    const newTokens = tokenise(newStr);
    const m = oldTokens.length, n = newTokens.length;

    // Cap runaway inputs — pathological strings would blow the DP table.
    if (m * n > 500_000) {
        const result = {
            before: [{ text: oldStr, kind: 'removed' }],
            after: [{ text: newStr, kind: 'added' }],
        };
        wordDiffCache.set(cacheKey, result);
        return result;
    }

    // LCS length table.
    const dp = Array.from({ length: m + 1 }, () => new Uint16Array(n + 1));
    for (let i = 1; i <= m; i++) {
        for (let j = 1; j <= n; j++) {
            dp[i][j] = oldTokens[i - 1] === newTokens[j - 1]
                ? dp[i - 1][j - 1] + 1
                : Math.max(dp[i - 1][j], dp[i][j - 1]);
        }
    }

    // Backtrace to build per-column token annotations.
    const before = [], after = [];
    let i = m, j = n;
    while (i > 0 && j > 0) {
        if (oldTokens[i - 1] === newTokens[j - 1]) {
            before.unshift({ text: oldTokens[i - 1], kind: 'same' });
            after.unshift({ text: newTokens[j - 1], kind: 'same' });
            i--; j--;
        } else if (dp[i - 1][j] >= dp[i][j - 1]) {
            before.unshift({ text: oldTokens[i - 1], kind: 'removed' });
            i--;
        } else {
            after.unshift({ text: newTokens[j - 1], kind: 'added' });
            j--;
        }
    }
    while (i > 0) { i--; before.unshift({ text: oldTokens[i], kind: 'removed' }); }
    while (j > 0) { j--; after.unshift({ text: newTokens[j], kind: 'added' }); }

    const result = { before, after };
    wordDiffCache.set(cacheKey, result);
    return result;
}

// Toggle the diff panel for a single history event. Always compares against
// the preceding event (there is no "vs. original" mode); the server returns
// `note: 'no_comparison'` for the very first event, and the UI shows the
// no-comparison message instead of empty content.
function toggleDiff(eventId) {
    const current = diffs.value[eventId];
    if (current?.open) {
        diffs.value = { ...diffs.value, [eventId]: { ...current, open: false } };
        return;
    }
    const initial = current ?? {
        open: true,
        loading: true,
        vs: 'previous',
        changes: null,
        note: null,
    };
    diffs.value = { ...diffs.value, [eventId]: { ...initial, open: true } };
    loadDiff(eventId);
}

async function loadDiff(eventId) {
    if (!props.historyDiffUrlTemplate) return;

    const state = diffs.value[eventId];
    if (!state) return;

    diffs.value = { ...diffs.value, [eventId]: { ...state, loading: true } };

    const url = props.historyDiffUrlTemplate.replace('__EVENT__', encodeURIComponent(eventId.replace(/^evt_/, '')));

    try {
        const res = axios
            ? (await axios.get(url, { params: { vs: state.vs } })).data
            : await (await fetch(`${url}?vs=${state.vs}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();

        diffs.value = { ...diffs.value, [eventId]: {
            open: true,
            loading: false,
            vs: state.vs,
            changes: res.changes ?? [],
            note: res.note ?? null,
        }};
    } catch (e) {
        diffs.value = { ...diffs.value, [eventId]: {
            open: true,
            loading: false,
            vs: state.vs,
            changes: [],
            note: 'no_diff_data',
        }};
    }
}
</script>
