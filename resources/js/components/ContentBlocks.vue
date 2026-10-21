<template>
    <!-- Read-only content preview of an entry: the flat block list from
         EntryContentPreview, nested by `depth`. A `fields` block is a structural
         node (Bard set, Replicator/Grid row, Group) with its own short values;
         its rich children (Bard text, Markdown, nested rows) follow as blocks one
         level deeper, indented with a rule on the left — the tree reads at a
         glance without boxes in boxes. Every block keeps `id="passage-<uuid>"`
         and `data-ref`: comment links and the history handoff jump there. -->
    <div class="space-y-3">
        <div
            v-for="(block, i) in blocks"
            :key="blockKey(block, i, keyPrefix)"
            :id="anchors ? passageId(block) : undefined"
            :data-ref="anchors ? block.uuid : undefined"
            :style="indentStyle(block)"
            class="rounded-lg border border-gray-100 dark:border-gray-800"
            :class="[
                blockDepth(block) > 0 ? 'border-s-2 border-s-gray-300 dark:border-s-gray-600' : '',
                isHeaderOnly(block) ? 'px-3 py-2' : 'p-3',
            ]"
        >
            <!-- Developer metadata: the handle (set type / field) and the passage
                 ref. Only helps when mapping a passage back to the blueprint or a
                 comment, so the parent decides who sees it. -->
            <div v-if="showHandle || showUuid" class="mb-2 flex items-center gap-2">
                <code v-if="showHandle" class="rounded bg-gray-100 px-1.5 py-0.5 text-2xs text-gray-500 dark:bg-gray-800! dark:text-gray-400!">{{ block.handle }}</code>
                <span v-if="showUuid" class="select-all font-mono text-2xs text-gray-300 dark:text-gray-600">{{ block.uuid }}</span>
            </div>

            <!-- Structural node: its label heads the block (and, without own
                 values, is all there is — the children below carry the content). -->
            <div v-if="block.kind === 'fields'" class="flex items-center gap-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ blockTitle(block) }}</h3>
                <div v-if="commentable && isHeaderOnly(block)" class="ml-auto">
                    <slot name="comment" :block="block">
                        <CommentAction :block="block" :count="count(block)" :fresh="fresh(block)" @comment="$emit('comment', block)" />
                    </slot>
                </div>
            </div>

            <div
                v-if="block.kind === 'html'"
                class="fw-prose text-sm text-gray-700 dark:text-gray-300"
                v-html="block.html"
            />

            <div v-else-if="block.kind === 'fields' && !isHeaderOnly(block)" class="mt-2 space-y-2.5">
                <div v-for="(item, j) in blockItems(block)" :key="j" class="text-sm">
                    <p v-if="item.kind === 'text'" class="text-gray-600 dark:text-gray-400">
                        <span v-if="item.label" class="font-medium text-gray-700 dark:text-gray-300">{{ item.label }}: </span>
                        <span class="whitespace-pre-line">{{ item.text }}</span>
                    </p>

                    <template v-else>
                        <div v-if="item.label" class="mb-1 font-medium text-gray-700 dark:text-gray-300">{{ item.label }}</div>

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
                                    class="block w-32 overflow-hidden rounded-md border border-gray-100 dark:border-gray-800"
                                    :title="asset.basename"
                                >
                                    <img :src="assetThumb(asset)" :alt="asset.basename" class="h-20 w-full object-cover" loading="lazy" />
                                    <span class="block truncate px-1.5 py-0.5 text-2xs text-gray-500 dark:text-gray-400">{{ asset.basename }}</span>
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

            <!-- Comment button below the passage, not above it: you read first
                 and comment second. Right-aligned, out of the reading column. A
                 header-only node has it in its header row instead. -->
            <div v-if="commentable && !isHeaderOnly(block)" class="mt-2 flex items-center justify-end gap-1.5">
                <slot name="comment" :block="block">
                    <CommentAction :block="block" :count="count(block)" :fresh="fresh(block)" @comment="$emit('comment', block)" />
                </slot>
            </div>
        </div>

        <slot v-if="!blocks.length" name="empty" />
    </div>
</template>

<script setup>
import { defineComponent, h } from 'vue';
import { Button, Icon } from '@statamic/cms/ui';
import { assetThumb, blockDepth, blockItems, blockKey, blockTitle, indentStyle, isHeaderOnly, passageId } from './contentBlocks.js';

const props = defineProps({
    blocks: { type: Array, default: () => [] },
    // Show the comment button (+ count and "new" pill) on every block.
    commentable: { type: Boolean, default: false },
    // Render `id="passage-<uuid>"` / `data-ref` — off for a second column
    // showing another version, whose uuids would collide with this one's.
    anchors: { type: Boolean, default: true },
    showHandle: { type: Boolean, default: false },
    showUuid: { type: Boolean, default: false },
    // (uuid) => number / boolean — the page owns the comment list.
    commentCount: { type: Function, default: () => 0 },
    hasNew: { type: Function, default: () => false },
    keyPrefix: { type: String, default: '' },
});

defineEmits(['comment']);

const link = 'text-blue-600 no-underline hover:underline';

function count(block) {
    return props.commentCount(block.uuid) || 0;
}
function fresh(block) {
    return !!props.hasNew(block.uuid);
}

// The default comment affordance: button, count, "new" pill.
const CommentAction = defineComponent({
    props: { block: Object, count: Number, fresh: Boolean },
    emits: ['comment'],
    setup(p, { emit }) {
        return () => [
            h(Button, {
                variant: 'default',
                size: 'xs',
                icon: 'mail-chat-bubble-text',
                text: __('workflows::messages.comments'),
                title: __('workflows::messages.comments'),
                onClick: () => emit('comment', p.block),
            }),
            p.count
                ? h('span', { class: 'rounded-full bg-gray-200 px-1.5 text-2xs font-semibold text-gray-700 dark:bg-gray-700! dark:text-gray-200!' }, String(p.count))
                : null,
            p.fresh
                ? h('span', { class: 'rounded-full bg-amber-500 px-1.5 text-2xs font-semibold text-white' }, __('workflows::messages.new'))
                : null,
        ];
    },
});
</script>

<style scoped>
/* Minimal prose styling for the augmented Bard / Markdown HTML rendered via v-html. */
.fw-prose :deep(p) { margin: 0 0 0.75rem; line-height: 1.6; }
.fw-prose :deep(p:last-child) { margin-bottom: 0; }
.fw-prose :deep(h2),
.fw-prose :deep(h3),
.fw-prose :deep(h4) { margin: 1rem 0 0.5rem; font-weight: 600; line-height: 1.3; }
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
