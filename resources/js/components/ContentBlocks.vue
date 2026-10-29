<template>
    <!-- Read-only content preview of an entry, as a reading flow: it should look
         like the page, not like the blueprint. No boxes, no set headers, no
         indentation — passages follow each other separated by space only, and
         `depth` is ignored for display (readingBlocks() drops structural nodes
         that frame nothing). Every passage keeps `id="passage-<uuid>"` and
         `data-ref`: comment links and the history handoff jump there. -->
    <!-- Technische Baumansicht – testweise (User-Entscheidung ausstehend).
         With `technical` on, the structure tree replaces the reading flow: every
         block (structural ones without own items too), indented by `depth`,
         set/row headers with handle + label + uuid, items under their field
         handle, child blocks nested below their parent with their path tail.
         Remove this branch + technicalTree() in contentBlocks.js to drop it. -->
    <div v-if="technical" ref="root" class="fw-flow space-y-2" :class="{ 'fw-flow--gutter': commentable }" @click="onMarkClick">
        <div
            v-for="(node, i) in treeNodes"
            :key="blockKey(node.block, i, keyPrefix)"
            :id="anchors ? passageId(node.block) : undefined"
            :data-ref="anchors ? node.block.uuid : undefined"
            :data-uuid="node.block.uuid"
            class="fw-passage group relative flex items-start"
            :class="{ 'fw-passage--active': isActive(node.block), 'fw-passage--commented': isWholeCommented(node.block) }"
            :style="{ marginInlineStart: `${node.indent * 1.25}rem` }"
        >
            <div class="fw-passage-body min-w-0 flex-1 rounded-e-md border-s-2 border-gray-200 ps-3 dark:border-gray-700!">
                <div class="flex flex-wrap items-baseline gap-2">
                    <code class="rounded bg-gray-100 px-1.5 py-0.5 text-2xs font-semibold text-gray-700 dark:bg-gray-800! dark:text-gray-300!">{{ node.block.handle }}</code>
                    <span v-if="node.pathTail && node.pathTail !== node.block.handle" class="font-mono text-2xs text-gray-500 dark:text-gray-400">{{ node.pathTail }}</span>
                    <span class="text-xs text-gray-600 dark:text-gray-300">{{ node.block.label }}</span>
                    <span class="select-all font-mono text-2xs text-gray-400 dark:text-gray-500">{{ node.block.uuid }}</span>
                </div>

                <div
                    v-if="node.block.kind === 'html'"
                    class="fw-prose mt-1.5 text-sm text-gray-800 dark:text-gray-200"
                    v-html="node.block.html"
                />

                <dl v-else-if="blockItems(node.block).length" class="mt-1.5 space-y-1.5 ps-[1.25rem]">
                    <div v-for="(item, j) in blockItems(node.block)" :key="j" class="flex items-baseline gap-2 text-sm">
                        <dt class="shrink-0 font-mono text-2xs text-gray-500 dark:text-gray-400">{{ item.handle || item.label }}</dt>
                        <dd class="min-w-0 flex-1 text-gray-800 dark:text-gray-200">
                            <span v-if="item.kind === 'text'" class="whitespace-pre-line">{{ item.text }}</span>
                            <pre
                                v-else-if="item.kind === 'code'"
                                class="max-h-60 overflow-auto rounded-md bg-gray-50 p-2 font-mono text-xs text-gray-700 dark:bg-gray-900! dark:text-gray-300!"
                            ><code>{{ item.code }}</code></pre>
                            <a v-else-if="item.kind === 'video'" :href="item.url" :class="link" target="_blank" rel="noopener">{{ item.url }}</a>
                            <span v-else-if="item.kind === 'media'" class="text-xs">{{ (item.assets || []).map((a) => a.basename).join(', ') }}</span>
                        </dd>
                    </div>
                </dl>
            </div>

            <div v-if="commentable" class="fw-gutter">
                <slot name="comment" :block="node.block">
                    <CommentAction :block="node.block" :count="count(node.block)" :fresh="fresh(node.block)" @comment="(b, el) => $emit('comment', b, el)" />
                </slot>
            </div>
        </div>

        <slot v-if="!treeNodes.length" name="empty" />
    </div>

    <div v-else ref="root" class="fw-flow space-y-6" :class="{ 'fw-flow--gutter': commentable }" @click="onMarkClick">
        <div
            v-for="(block, i) in visibleBlocks"
            :key="blockKey(block, i, keyPrefix)"
            :id="anchors ? passageId(block) : undefined"
            :data-ref="anchors ? block.uuid : undefined"
            :data-uuid="block.uuid"
            class="fw-passage group relative flex items-start"
            :class="{ 'fw-passage--active': isActive(block), 'fw-passage--commented': isWholeCommented(block) }"
        >
            <div class="fw-passage-body min-w-0 flex-1 rounded-e-md border-s-2 border-transparent ps-3">
                <!-- Developer metadata behind the "technical details" switch: the
                     handle (set type / field) and the passage ref. -->
                <div v-if="technical" class="mb-1.5 flex flex-wrap items-center gap-2">
                    <code class="rounded bg-gray-100 px-1.5 py-0.5 text-2xs text-gray-500 dark:bg-gray-800! dark:text-gray-400!">{{ block.handle }}</code>
                    <span class="select-all font-mono text-2xs text-gray-400 dark:text-gray-500">{{ block.uuid }}</span>
                </div>

                <!-- A structural node without own values, kept only because it
                     carries comments: a quiet anchor so they stay reachable. -->
                <p v-if="isHeaderOnly(block)" class="text-xs italic text-gray-400 dark:text-gray-500">
                    {{ __('workflows::messages.content_structure_comments', { label: blockTitle(block) }) }}
                </p>

                <div
                    v-else-if="block.kind === 'html'"
                    class="fw-prose text-sm text-gray-800 dark:text-gray-200"
                    v-html="block.html"
                />

                <div v-else-if="block.kind === 'fields'" class="space-y-3">
                    <div v-for="(item, j) in blockItems(block)" :key="j" class="text-sm">
                        <h3
                            v-if="itemDisplay(item) === 'heading'"
                            class="text-base font-semibold leading-snug text-gray-900 dark:text-gray-100"
                        >
                            <span v-if="technical" class="me-1.5 font-mono text-2xs font-normal text-gray-400">{{ item.handle }}</span>{{ item.text }}
                        </h3>

                        <p
                            v-else-if="itemDisplay(item) === 'paragraph'"
                            class="whitespace-pre-line leading-relaxed text-gray-800 dark:text-gray-200"
                        >
                            <span v-if="technical" class="me-1.5 font-mono text-2xs text-gray-400">{{ item.handle }}</span>{{ item.text }}
                        </p>

                        <p v-else-if="itemDisplay(item) === 'labelled'" class="text-gray-800 dark:text-gray-200">
                            <span class="text-xs text-gray-400 dark:text-gray-500">{{ itemLabel(item) }}:</span>
                            <span v-if="technical" class="ms-1 font-mono text-2xs text-gray-400">{{ item.handle }}</span>
                            {{ ' ' }}<span class="whitespace-pre-line">{{ item.text }}</span>
                        </p>

                        <template v-else>
                            <div v-if="itemLabel(item) || technical" class="mb-1 text-xs text-gray-400 dark:text-gray-500">
                                {{ itemLabel(item) }}
                                <span v-if="technical" class="font-mono text-2xs">{{ item.handle }}</span>
                            </div>

                            <pre
                                v-if="item.kind === 'code'"
                                class="max-h-60 overflow-auto rounded-md bg-gray-50 p-2 font-mono text-xs text-gray-700 dark:bg-gray-900! dark:text-gray-300!"
                            ><code>{{ item.code }}</code></pre>

                            <div v-else-if="item.kind === 'video'">
                                <div v-if="item.embedUrl" class="relative aspect-video overflow-hidden rounded-lg bg-black">
                                    <iframe
                                        :src="item.embedUrl"
                                        class="absolute inset-0 h-full w-full"
                                        frameborder="0"
                                        allow="autoplay; fullscreen; picture-in-picture"
                                        allowfullscreen
                                    />
                                </div>
                                <a v-else :href="item.url" :class="link" target="_blank" rel="noopener">{{ item.url }}</a>
                            </div>

                            <div v-else-if="item.kind === 'media'" class="flex flex-wrap gap-2">
                                <template v-for="(asset, k) in item.assets || []" :key="k">
                                    <a
                                        v-if="assetThumb(asset)"
                                        :href="asset.url || undefined"
                                        target="_blank"
                                        rel="noopener"
                                        class="block max-w-xs overflow-hidden rounded-md"
                                        :title="asset.basename"
                                    >
                                        <img :src="assetThumb(asset)" :alt="asset.basename" class="max-h-48 w-auto rounded-md object-cover" loading="lazy" />
                                    </a>
                                    <component
                                        :is="asset.url ? 'a' : 'span'"
                                        v-else
                                        :href="asset.url || undefined"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 px-2 py-0.5 text-xs text-gray-600 dark:border-gray-700! dark:text-gray-300!"
                                    >
                                        <Icon name="file-content-list" class="size-3.5" />
                                        {{ asset.basename }}
                                    </component>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Comment gutter, like Word's margin: a column right of the
                 reading flow with a button on the passage's first line, shown
                 while the passage is hovered. Hovering / focusing it highlights the passage it belongs to.
                 On narrow containers it collapses to the passage's top-right. -->
            <div v-if="commentable" class="fw-gutter">
                <slot name="comment" :block="block">
                    <CommentAction :block="block" :count="count(block)" :fresh="fresh(block)" @comment="(b, el) => $emit('comment', b, el)" />
                </slot>
            </div>
        </div>

        <slot v-if="!visibleBlocks.length" name="empty" />
    </div>

    <!-- Marked text → comment icon. Only where passages take comments. -->
    <SelectionCommentButton v-if="commentable" :scope="root" @comment="onSelection" />
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onMounted, ref, watch } from 'vue';
import { Button, Icon } from '@statamic/cms/ui';
import SelectionCommentButton from './comments/SelectionCommentButton.vue';
import { clearMarks, locateQuote, wrapRange } from './comments/anchors.js';
import { assetThumb, blockItems, blockKey, blockTitle, isHeaderOnly, itemDisplay, itemLabel, passageId, readingBlocks, technicalTree } from './contentBlocks.js';

const props = defineProps({
    blocks: { type: Array, default: () => [] },
    // Show the comment action (+ count and "new" pill) on every passage.
    commentable: { type: Boolean, default: false },
    // Render `id="passage-<uuid>"` / `data-ref` — off for a second column
    // showing another version, whose uuids would collide with this one's.
    anchors: { type: Boolean, default: true },
    // Super-admin "technical details": field/set handles and passage uuids.
    technical: { type: Boolean, default: false },
    // (uuid) => number / boolean — the page owns the comment list.
    commentCount: { type: Function, default: () => 0 },
    hasNew: { type: Function, default: () => false },
    // Passage whose comment thread is active in the sidebar — kept highlighted
    // (accent + tint) for as long as the thread is, unlike the brief fw-flash.
    activeUuid: { type: String, default: null },
    // (uuid) => boolean — an open comment is about the whole passage (no marked
    // text): the passage gets a light yellow tint, as marks do for their text.
    wholeCommented: { type: Function, default: () => false },
    // Marked text of comment threads: [{ thread, uuid, quote, offset, active, resolved? }].
    marks: { type: Array, default: () => [] },
    keyPrefix: { type: String, default: '' },
});

const emit = defineEmits(['comment', 'comment-selection', 'mark']);

const root = ref(null);

const link = 'text-blue-600 no-underline hover:underline';

// Structural nodes only survive with comments — and only where comments are
// shown at all; the source column has none.
const visibleBlocks = computed(() =>
    readingBlocks(props.blocks, props.commentable ? (uuid) => props.commentCount(uuid) : () => 0),
);

// Technische Baumansicht – testweise (User-Entscheidung ausstehend).
const treeNodes = computed(() => (props.technical ? technicalTree(props.blocks) : []));

function count(block) {
    return Number(props.commentCount(block.uuid)) || 0;
}
function isWholeCommented(block) {
    return props.commentable && !!block?.uuid && !!props.wholeCommented(block.uuid);
}

function isActive(block) {
    return props.commentable && !!props.activeUuid && block?.uuid === props.activeUuid;
}
function fresh(block) {
    return !!props.hasNew(block.uuid);
}

// --- marked text ---------------------------------------------------------------

function onSelection({ prose, quote, offset }) {
    const uuid = prose.closest('[data-uuid]')?.dataset.uuid;
    const block = props.blocks.find((b) => b?.uuid === uuid);
    if (block) emit('comment-selection', block, { quote, offset });
}

function onMarkClick(e) {
    const mark = e.target?.closest?.('mark[data-comment-thread]');
    if (mark && !window.getSelection()?.toString()) emit('mark', mark.dataset.commentThread, mark);
}

// The prose is v-html, so the marks are put into the DOM by hand after every
// render: cleared, then each thread's quote looked up again in its passage's
// current text. A quote that is gone simply gets no mark — its comment stays on
// the passage.
function applyMarks() {
    const el = root.value;
    if (!el) return;

    for (const prose of el.querySelectorAll('.fw-prose')) clearMarks(prose);
    if (!props.commentable) return;

    for (const mark of props.marks) {
        const passage = [...el.querySelectorAll('[data-uuid]')].find((p) => p.dataset.uuid === mark.uuid);
        const prose = passage?.querySelector('.fw-prose');
        if (!prose) continue;

        const range = locateQuote(prose.textContent, mark.quote, mark.offset);
        if (!range) continue;

        wrapRange(prose, range[0], range[1], {
            thread: mark.thread,
            className: ['fw-comment-mark', mark.active && 'fw-comment-mark--active', mark.resolved && 'fw-comment-mark--resolved'].filter(Boolean).join(' '),
        });
    }
}

watch(
    () => [props.marks, props.blocks, props.technical],
    () => nextTick(applyMarks),
    { deep: true, flush: 'post' },
);
onMounted(applyMarks);

// The default comment affordance, Word-style: an always visible button in the
// gutter. Without comments a quiet speech bubble with a small "+"; with comments
// the count in a tinted pill-button, plus the "new" pill for unread ones.
const CommentAction = defineComponent({
    props: { block: Object, count: Number, fresh: Boolean },
    emits: ['comment'],
    setup(p, { emit }) {
        return () => {
            const label = blockTitle(p.block);
            const add = __('workflows::messages.comment_add');
            const aria = p.count
                ? __('workflows::messages.comments_open_for', { label, count: p.count })
                : __('workflows::messages.comment_add_for', { label });

            return [
                h(
                    'button',
                    {
                        type: 'button',
                        class: [
                            'fw-comment-btn cursor-pointer',
                            p.count
                                ? 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 dark:border-blue-800! dark:bg-blue-950! dark:text-blue-300! dark:hover:bg-blue-900!'
                                : 'border-transparent text-gray-400 hover:border-gray-200 hover:bg-gray-50 hover:text-gray-700 dark:text-gray-500! dark:hover:border-gray-700! dark:hover:bg-gray-800! dark:hover:text-gray-200!',
                        ],
                        'aria-label': aria,
                        title: p.count ? aria : add,
                        // The button goes along: with the margin hidden, the
                        // passage's comments open in a popover right there.
                        onClick: (e) => emit('comment', p.block, e.currentTarget),
                    },
                    [
                        h('span', { class: 'relative inline-flex' }, [
                            h(Icon, { name: 'mail-chat-bubble-text', class: 'size-4' }),
                            p.count
                                ? null
                                : h(
                                      'span',
                                      { class: 'fw-comment-plus bg-white text-gray-500 dark:bg-gray-900! dark:text-gray-400!', 'aria-hidden': 'true' },
                                      [h(Icon, { name: 'plus', class: 'size-2' })],
                                  ),
                        ]),
                        p.count ? h('span', { class: 'text-xs font-semibold tabular-nums' }, String(p.count)) : null,
                    ],
                ),
                p.fresh
                    ? h('span', { class: 'rounded-full bg-amber-500 px-1.5 text-2xs font-semibold text-white' }, __('workflows::messages.new'))
                    : null,
            ];
        };
    },
});
</script>

<style scoped>
/* Comment gutter (Word-style margin). The flow is a size container so the
   gutter reacts to the column it sits in (the translation view is two columns),
   not to the viewport. No rule, no permanent buttons: a passage's comment
   button shows while the passage is hovered, focused or active (its thread or
   popover is open) — and always on touch screens, which cannot hover. */
.fw-flow--gutter { container-type: inline-size; position: relative; }
.fw-gutter > * { opacity: 0; transition: opacity 120ms ease-out; }
.fw-passage:hover > .fw-gutter > *,
.fw-gutter:focus-within > *,
.fw-passage--active > .fw-gutter > * { opacity: 1; }
@media (hover: none) {
    .fw-gutter > * { opacity: 1; }
}
.fw-gutter {
    flex-shrink: 0;
    width: 4rem;
    padding-inline-start: 0.75rem;
    padding-block: 0.25rem; /* keep the button off the passage's top and bottom edge */
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.25rem;
}
.fw-gutter :deep(.fw-comment-btn) {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
    min-width: 2rem;
    min-height: 2rem;
    padding-inline: 0.4rem;
    border-width: 1px;
    border-style: solid;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: background-color 120ms ease-out, color 120ms ease-out, border-color 120ms ease-out;
}
.fw-gutter :deep(.fw-comment-btn:focus-visible) { outline: 2px solid rgb(59 130 246); outline-offset: 2px; }
.fw-gutter :deep(.fw-comment-plus) {
    position: absolute;
    inset-block-end: -0.2rem;
    inset-inline-end: -0.25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 0.65rem;
    height: 0.65rem;
    border-radius: 9999px;
}

/* A commentable passage lights up faintly while hovered: the set its comment
   button belongs to. One commented as a whole stays light yellow. */
.fw-flow--gutter .fw-passage:not(.fw-passage--active):hover > .fw-passage-body { background-color: rgb(249 250 251); }
:where(.dark) .fw-flow--gutter .fw-passage:not(.fw-passage--active):hover > .fw-passage-body { background-color: rgb(31 41 55 / 0.5); }
.fw-passage--commented > .fw-passage-body,
.fw-flow--gutter .fw-passage--commented:not(.fw-passage--active):hover > .fw-passage-body {
    background-color: rgb(254 249 195 / 0.55);
    border-inline-start-color: rgb(250 204 21) !important;
}
:where(.dark) .fw-passage--commented > .fw-passage-body,
:where(.dark) .fw-flow--gutter .fw-passage--commented:not(.fw-passage--active):hover > .fw-passage-body {
    background-color: rgb(161 98 7 / 0.2);
    border-inline-start-color: rgb(202 138 4) !important;
}

/* Anchoring: while the button is hovered / focused, its passage lights up with a
   light background and a left accent, so it is clear which text is meant. */
.fw-passage-body { padding-block: 0.5rem; transition: background-color 120ms ease-out, border-color 120ms ease-out; }
.fw-passage:has(.fw-gutter :is(button, a):hover, .fw-gutter :is(button, a):focus-visible) > .fw-passage-body {
    background-color: rgb(239 246 255);
    border-inline-start-color: rgb(96 165 250) !important;
}
:where(.dark) .fw-passage:has(.fw-gutter :is(button, a):hover, .fw-gutter :is(button, a):focus-visible) > .fw-passage-body {
    background-color: rgb(30 58 138 / 0.25);
    border-inline-start-color: rgb(96 165 250) !important;
}

/* The passage of the thread active in the comment sidebar: a persistent accent,
   same language as the hover anchor above but a touch stronger. */
.fw-passage--active > .fw-passage-body {
    background-color: rgb(239 246 255);
    border-inline-start-color: rgb(59 130 246) !important;
    box-shadow: inset 0 0 0 1px rgb(191 219 254);
}
:where(.dark) .fw-passage--active > .fw-passage-body {
    background-color: rgb(30 58 138 / 0.3);
    border-inline-start-color: rgb(96 165 250) !important;
    box-shadow: inset 0 0 0 1px rgb(30 64 175 / 0.6);
}

/* Narrow column: no gutter; the button sits at the passage's top-right and the
   text keeps clear of it. */
@container (max-width: 30rem) {
    .fw-gutter {
        position: absolute;
        inset-block-start: 0;
        inset-inline-end: 0;
        width: auto;
        margin-top: 0;
        padding-inline-start: 0;
        align-items: flex-end;
    }
    .fw-flow--gutter .fw-passage-body { padding-inline-end: 2.75rem; }
}

/* Jumping to a passage (comment link, history handoff): keep it clear of the
   sticky header and flash it briefly. */
.fw-passage { scroll-margin-top: 6rem; }
.fw-passage:target,
.fw-passage.fw-flash { animation: fw-passage-flash 1.6s ease-out; }
@keyframes fw-passage-flash {
    0% { background-color: rgb(254 240 138 / 0.7); }
    100% { background-color: transparent; }
}

/* Marked text of a comment thread, Word-style: a light highlight, stronger while
   its thread is active in the margin. */
.fw-prose :deep(mark.fw-comment-mark) {
    background-color: rgb(254 240 138 / 0.55);
    color: inherit;
    border-bottom: 2px solid rgb(250 204 21);
    cursor: pointer;
    transition: background-color 120ms ease-out;
}
.fw-prose :deep(mark.fw-comment-mark--active) { background-color: rgb(253 224 71 / 0.85); border-bottom-color: rgb(234 179 8); }
:where(.dark) .fw-prose :deep(mark.fw-comment-mark) { background-color: rgb(161 98 7 / 0.35); border-bottom-color: rgb(202 138 4); }
:where(.dark) .fw-prose :deep(mark.fw-comment-mark--active) { background-color: rgb(161 98 7 / 0.6); }
/* A resolved thread looked at again: the same place, in a quiet "done" green. */
.fw-prose :deep(mark.fw-comment-mark--resolved) { background-color: rgb(187 247 208 / 0.7); border-bottom-color: rgb(34 197 94); }
:where(.dark) .fw-prose :deep(mark.fw-comment-mark--resolved) { background-color: rgb(21 128 61 / 0.4); border-bottom-color: rgb(34 197 94); }

/* Minimal prose styling for the augmented Bard / Markdown HTML rendered via v-html. */
.fw-prose :deep(p) { margin: 0 0 0.75rem; line-height: 1.65; }
.fw-prose :deep(p:last-child) { margin-bottom: 0; }
.fw-prose :deep(h1),
.fw-prose :deep(h2),
.fw-prose :deep(h3),
.fw-prose :deep(h4) { margin: 1rem 0 0.5rem; font-weight: 600; line-height: 1.3; }
.fw-prose :deep(h1:first-child),
.fw-prose :deep(h2:first-child),
.fw-prose :deep(h3:first-child),
.fw-prose :deep(h4:first-child) { margin-top: 0; }
.fw-prose :deep(h1) { font-size: 1.25rem; }
.fw-prose :deep(h2) { font-size: 1.125rem; }
.fw-prose :deep(h3) { font-size: 1rem; }
.fw-prose :deep(ul),
.fw-prose :deep(ol) { margin: 0 0 0.75rem; padding-inline-start: 1.25rem; }
.fw-prose :deep(ul) { list-style: disc; }
.fw-prose :deep(ol) { list-style: decimal; }
.fw-prose :deep(li) { margin: 0.15rem 0; }
.fw-prose :deep(a) { color: #2563eb; text-decoration: underline; }
.fw-prose :deep(img) { max-width: 100%; height: auto; border-radius: 0.5rem; margin: 0.5rem 0; }
.fw-prose :deep(table) { width: 100%; border-collapse: collapse; margin: 0.75rem 0; }
.fw-prose :deep(th),
.fw-prose :deep(td) { border: 1px solid #e5e7eb; padding: 0.4rem 0.6rem; text-align: start; }
</style>
