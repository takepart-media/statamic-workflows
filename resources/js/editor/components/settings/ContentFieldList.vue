<template>
    <!-- The picked content fields in reading order (`metadata.content_fields`).
         Same pattern as OrderList.vue: drag by the handle, or use the arrows.
         The list itself is the parent's — every change goes out as a new list
         through `update:modelValue`, so it lands in the editor history. -->
    <SortableList
        :model-value="modelValue"
        vertical
        :item-class="itemClass"
        :handle-class="handleClass"
        @update:model-value="(next) => $emit('update:modelValue', [...next])"
    >
        <ol class="space-y-1">
            <li
                v-for="(handle, i) in modelValue"
                :key="handle"
                :class="[itemClass, 'flex items-center gap-2 rounded-md border border-gray-200 bg-white px-2 py-1 dark:border-gray-700! dark:bg-gray-900!']"
            >
                <span :class="[handleClass, 'cursor-grab text-gray-400']" :aria-label="__('workflows::messages.editor_drag_to_reorder')">
                    <Icon name="handles" class="size-4" />
                </span>
                <span class="w-6 shrink-0 text-right font-mono text-2xs text-gray-500">{{ i + 1 }}</span>
                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 text-sm">
                    <template v-if="choiceOf(handle)">
                        <span class="truncate">{{ choiceOf(handle).label }}</span>
                        <code class="font-mono text-2xs text-gray-500">{{ handle }} · {{ choiceOf(handle).type }}</code>
                        <span v-if="choiceOf(handle).onlyIn.length" class="text-2xs text-amber-700 dark:text-amber-300">
                            {{ __('workflows::messages.content_fields_only_in', { collections: choiceOf(handle).onlyIn.join(', ') }) }}
                        </span>
                    </template>
                    <!-- Stored, but in none of the selected collections (or not
                         readable): kept visible so it can be removed. -->
                    <template v-else>
                        <Icon name="alert-warning-exclamation-mark" class="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <code class="font-mono text-2xs">{{ handle }}</code>
                        <span class="text-2xs text-amber-700 dark:text-amber-300">{{ __('workflows::messages.content_fields_stale') }}</span>
                    </template>
                </span>
                <Button
                    variant="ghost"
                    icon="chevron-up"
                    size="xs"
                    icon-only
                    :disabled="i === 0"
                    :aria-label="__('workflows::messages.move_up')"
                    @click="move(i, i - 1)"
                />
                <Button
                    variant="ghost"
                    icon="chevron-down"
                    size="xs"
                    icon-only
                    :disabled="i === modelValue.length - 1"
                    :aria-label="__('workflows::messages.move_down')"
                    @click="move(i, i + 1)"
                />
                <Button
                    variant="ghost"
                    icon="trash"
                    size="xs"
                    icon-only
                    :aria-label="__('workflows::messages.content_fields_remove', { field: handle })"
                    @click="remove(i)"
                />
            </li>
        </ol>
    </SortableList>
</template>

<script>
let instances = 0;
</script>

<script setup>
import { SortableList } from '@statamic/cms';
import { Button, Icon } from '@statamic/cms/ui';

const props = defineProps({
    // Handles in reading order.
    modelValue: { type: Array, default: () => [] },
    // contentFieldChoices() for the selected collections.
    choices: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

// Per instance: SortableList finds items and handles by class.
const uid = ++instances;
const itemClass = `wf-content-field-item-${uid}`;
const handleClass = `wf-content-field-handle-${uid}`;

const choiceOf = (handle) => props.choices.find((c) => c.handle === handle) ?? null;

function move(from, to) {
    if (to < 0 || to >= props.modelValue.length) return;
    const next = [...props.modelValue];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);
    emit('update:modelValue', next);
}

function remove(index) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));
}
</script>
