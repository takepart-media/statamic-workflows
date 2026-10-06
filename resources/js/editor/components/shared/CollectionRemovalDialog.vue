<template>
    <!-- Removing a collection drops its entries out of the workflow and erases
         their states, assignments, history and comments. The server refuses that
         without `confirm_collection_removal` and answers 422 with the affected
         count — this asks, and `confirm` replays the save with the flag. -->
    <Modal :open="open" @update:open="(v) => v || $emit('update:open', false)">
        <ModalTitle class="wf-modal-head">
            <Icon name="warning-diamond" class="size-4" />
            {{ __('workflows::messages.collection_removal_title') }}
        </ModalTitle>
        <div class="space-y-3 py-3">
            <p class="text-sm">
                {{ __('workflows::messages.collection_removal_prompt', { collections: labels, count: affectedCount }) }}
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('workflows::messages.collection_removal_warning') }}
            </p>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2 px-2 pt-3">
                <Button variant="ghost" :text="__('workflows::messages.cancel')" @click="$emit('update:open', false)" />
                <Button
                    variant="destructive"
                    icon="trash"
                    :text="__('workflows::messages.confirm_collection_removal')"
                    :loading="busy"
                    :disabled="busy"
                    @click="$emit('confirm')"
                />
            </div>
        </template>
    </Modal>
</template>

<script setup>
import { computed } from 'vue';
import { Button, Icon, Modal, ModalTitle } from '@statamic/cms/ui';

const props = defineProps({
    open: { type: Boolean, default: false },
    // Handles of the removed collections, as the server reports them.
    collections: { type: Array, default: () => [] },
    affectedCount: { type: Number, default: 0 },
    // [{value: handle, label: title}] — to show titles instead of handles.
    collectionOptions: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
});

defineEmits(['update:open', 'confirm']);

const labels = computed(() =>
    props.collections
        .map((handle) => props.collectionOptions.find((o) => o.value === handle)?.label ?? handle)
        .join(', '),
);
</script>
