<template>
    <Badge
        v-if="value"
        :text="__('workflows::messages.state_pending_revision')"
        color="orange"
        size="sm"
    />
</template>

<script setup>
// Native entries-listing cell for the `revision_status` fieldtype. Renders an
// orange "Unveröffentlichte Änderungen" badge when the entry has an unpublished
// working copy, matching the badge used in the workflow dashboard.
import { Badge } from '@statamic/cms/ui';

defineProps({
    handle: { type: String, required: false, default: '' },
    value: { required: false, default: null },
    values: { required: false, default: () => ({}) },
});
</script>
