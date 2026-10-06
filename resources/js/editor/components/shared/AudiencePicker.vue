<template>
    <!-- "Who": a type (group / role / user, or the caller's empty choice) and the
         handles of that type. One component for the transition gate, the SLA
         take-over and the source-view rules — the same question three times. -->
    <div class="flex flex-wrap items-start gap-2">
        <Select
            adaptive-width
            :model-value="by"
            :options="byOptions"
            size="sm"
            class="w-40 shrink-0"
            :aria-label="byLabel || undefined"
            @update:model-value="onBy"
        />
        <Combobox
            v-if="showHandles"
            adaptive-width
            :model-value="handles"
            :options="handleOptions"
            multiple
            close-on-select
            clearable
            class="min-w-48 flex-1"
            :placeholder="placeholder"
            @update:model-value="$emit('update:handles', $event ?? [])"
        />
    </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Combobox, Select } from '@statamic/cms/ui';
import { EDITOR_CONTEXT } from '../context.js';

const props = defineProps({
    // 'group' | 'role' | 'user' — or whatever the caller's "nobody in
    // particular" value is (null, 'none'), for which no handle list is shown.
    by: { type: String, default: null },
    handles: { type: Array, default: () => [] },
    // [{value, label}] — which types this use offers, the empty choice included.
    byOptions: { type: Array, required: true },
    placeholder: { type: String, default: '' },
    byLabel: { type: String, default: '' },
});

const emit = defineEmits(['update:by', 'update:handles']);

const editor = inject(EDITOR_CONTEXT);

const showHandles = computed(() => ['group', 'role', 'user'].includes(props.by));

const handleOptions = computed(() => ({
    group: editor.options.groupOptions,
    role: editor.options.roleOptions,
    user: editor.options.userOptions,
}[props.by] ?? []));

// Group, role and user handles are not interchangeable: switching the type
// drops the list rather than keep values that mean nothing in the new one.
function onBy(value) {
    emit('update:by', value ?? null);
    emit('update:handles', []);
}
</script>
