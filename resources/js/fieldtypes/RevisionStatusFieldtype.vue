<template>
    <div class="flex items-center">
        <Badge
            v-if="value"
            :text="__('workflows::messages.state_pending_revision')"
            color="orange"
            size="sm"
        />
        <span v-else class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('workflows::messages.revision_status_current') }}
        </span>
    </div>
</template>

<script setup>
// Read-only publish-form display for the `revision_status` fieldtype. The value
// is computed server-side (Fieldtype::preProcess) from the entry's working-copy
// state, so the editor shows whether unpublished changes exist. Display-only —
// it never emits updates and nothing is stored (the field is visibility:computed).
import { Badge } from '@statamic/cms/ui';

defineProps({
    value: { default: null },
    config: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({}) },
    handle: { type: String, default: '' },
    readOnly: { type: Boolean, default: false },
});
</script>
