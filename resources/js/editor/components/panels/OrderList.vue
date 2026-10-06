<template>
    <!-- The YAML order of places or transitions — kept apart from the canvas
         positions because it MEANS something (progress bar, final place,
         filters; the first transition is the start). Drag by the handle, or use
         the arrows. -->
    <SortableList
        :model-value="ids"
        vertical
        :item-class="itemClass"
        :handle-class="handleClass"
        @update:model-value="(next) => editor.api.reorder(kind, next)"
    >
        <ol class="space-y-1">
            <li
                v-for="(entry, i) in entries"
                :key="entry.id"
                :class="[itemClass, 'flex items-center gap-2 rounded-md border border-gray-200 bg-white px-2 py-1 dark:border-gray-700! dark:bg-gray-900!']"
            >
                <span :class="[handleClass, 'cursor-grab text-gray-400']" :aria-label="__('workflows::messages.editor_drag_to_reorder')">
                    <Icon name="handles" class="size-4" />
                </span>
                <span class="w-6 shrink-0 text-right font-mono text-2xs text-gray-500">{{ i + 1 }}</span>
                <button
                    type="button"
                    class="min-w-0 flex-1 truncate text-left text-sm hover:underline"
                    @click="editor.select(entry.id)"
                >
                    {{ labelOf(entry) }}
                    <code class="ml-1 font-mono text-2xs text-gray-500">{{ keyOf(entry) }}</code>
                </button>
                <Button
                    variant="ghost"
                    icon="chevron-up"
                    size="xs"
                    icon-only
                    :disabled="i === 0"
                    :aria-label="__('workflows::messages.move_up')"
                    @click="move(entry, i - 1)"
                />
                <Button
                    variant="ghost"
                    icon="chevron-down"
                    size="xs"
                    icon-only
                    :disabled="i === entries.length - 1"
                    :aria-label="__('workflows::messages.move_down')"
                    @click="move(entry, i + 1)"
                />
            </li>
        </ol>
    </SortableList>
</template>

<script setup>
import { computed, inject } from 'vue';
import { SortableList } from '@statamic/cms';
import { Button, Icon } from '@statamic/cms/ui';
import { keyOf } from '../../model/editorModel.js';
import { placeDisplayName } from '../../model/labels.js';
import { EDITOR_CONTEXT } from '../context.js';

const props = defineProps({
    kind: { type: String, required: true }, // 'place' | 'transition'
});

const editor = inject(EDITOR_CONTEXT);

const itemClass = `wf-order-item-${props.kind}`;
const handleClass = `wf-order-handle-${props.kind}`;

const entries = computed(() => (props.kind === 'place' ? editor.api.model.places : editor.api.model.transitions));
const ids = computed(() => entries.value.map((e) => e.id));

function labelOf(entry) {
    return props.kind === 'place'
        ? placeDisplayName(editor.api.view.value, keyOf(entry), editor.labelCtx)
        : (entry.metadata?.label || keyOf(entry));
}

function move(entry, toIndex) {
    if (props.kind === 'place') {
        editor.api.movePlace(entry.id, toIndex);
    } else {
        editor.api.moveTransition(entry.id, toIndex);
    }
}
</script>
