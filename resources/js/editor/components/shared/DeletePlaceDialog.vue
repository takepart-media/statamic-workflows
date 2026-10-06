<template>
    <!-- Deleting from the editor — panel button, Delete key, context menu. One
         dialog for everything that goes in one step (a node, or a selection):
         places still holding entries need a target first; what loses its link
         to the deleted places is listed. Nothing is written until the save, and
         Ctrl+Z brings it all back. -->
    <ConfirmationModal
        :open="open"
        :title="title"
        :button-text="__('workflows::messages.confirm_delete')"
        :disabled="missingTarget"
        danger
        @update:open="(v) => $emit('update:open', v)"
        @confirm="confirm"
    >
        <div class="space-y-4">
            <!-- One target per place that still holds entries. -->
            <Field
                v-for="place in withUsage"
                :key="place.id"
                :label="withUsage.length > 1 || places.length > 1 ? `${__('workflows::messages.migrate_target')}: ${place.name}` : __('workflows::messages.migrate_target')"
                :instructions="__('workflows::messages.migrate_place_prompt', { place: place.name, count: place.usage })"
            >
                <Select
                    adaptive-width
                    :model-value="targets[place.id] ?? null"
                    :options="targetOptions"
                    :placeholder="__('workflows::messages.select_place')"
                    @update:model-value="(v) => (targets[place.id] = v ?? null)"
                />
            </Field>

            <div v-if="refs.length" class="space-y-1.5">
                <p class="text-sm font-medium">{{ __('workflows::messages.editor_delete_refs') }}</p>
                <ul class="max-h-48 space-y-1 overflow-y-auto text-sm">
                    <li v-for="ref in refs" :key="ref.key" class="flex items-center gap-1.5">
                        <Icon :name="ref.icon || 'link'" class="size-3.5 shrink-0 text-gray-400" />
                        <span class="truncate">{{ ref.text }}</span>
                    </li>
                </ul>
            </div>

            <p v-if="withUsage.length" class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-700! dark:bg-amber-950/40! dark:text-amber-100!">
                <Icon name="warning-diamond" class="mt-0.5 size-4 shrink-0" />
                <span>{{ __('workflows::messages.editor_delete_timer_note') }}</span>
            </p>
            <p v-if="hasSaved" class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('workflows::messages.editor_delete_on_save') }}
            </p>
        </div>
    </ConfirmationModal>
</template>

<script setup>
import { computed, reactive, watch } from 'vue';
import { ConfirmationModal, Field, Icon, Select } from '@statamic/cms/ui';

const props = defineProps({
    open: { type: Boolean, default: false },
    // The places to delete: [{id, name, handle, usage, isNew}].
    places: { type: Array, default: () => [] },
    // Transitions deleted in the same step: [{id, name}].
    transitions: { type: Array, default: () => [] },
    // What loses its link to the places: [{key, text, icon?}].
    refs: { type: Array, default: () => [] },
    // Where entries may go: every place that stays, new ones included.
    targetOptions: { type: Array, default: () => [] },
});

// confirm: {targets: {placeId: targetPlaceId}}
const emit = defineEmits(['update:open', 'confirm']);

const targets = reactive({});

// Fresh choices every time it opens.
watch(() => props.open, (open) => {
    if (!open) return;
    for (const key of Object.keys(targets)) delete targets[key];
});

const withUsage = computed(() => props.places.filter((p) => !p.isNew && p.usage > 0));
const hasSaved = computed(() => props.places.some((p) => !p.isNew));
const missingTarget = computed(() => withUsage.value.some((p) => !targets[p.id]));

const title = computed(() => {
    const count = props.places.length + props.transitions.length;
    if (count > 1) return __('workflows::messages.editor_confirm_delete_nodes', { count });
    if (props.transitions.length) return __('workflows::messages.confirm_delete_transition', { handle: props.transitions[0].name });
    return __('workflows::messages.confirm_delete_place', { handle: props.places[0]?.name ?? '' });
});

function confirm() {
    if (missingTarget.value) return;
    emit('confirm', { targets: { ...targets } });
}
</script>
