<template>
    <!-- Rows of type + entries, e.g. group X and users Y, Z (AudienceRows.php). -->
    <div class="space-y-2">
        <div v-for="(row, i) in rows" :key="i" class="flex items-start gap-2">
            <AudiencePicker
                class="flex-1"
                :by="row.by"
                :handles="row.handles"
                :by-options="byOptions"
                :placeholder="placeholder"
                @update:by="(v) => setRow(i, { by: v, handles: [] })"
                @update:handles="(v) => setRow(i, { handles: v })"
            />
            <Button
                icon="trash"
                variant="ghost"
                size="sm"
                icon-only
                :aria-label="__('workflows::messages.audience_rows_remove')"
                @click="emit('update:rows', rows.filter((_, j) => j !== i))"
            />
        </div>
        <Button
            variant="secondary"
            size="sm"
            icon="plus"
            :text="__('workflows::messages.audience_rows_add')"
            @click="emit('update:rows', [...rows, { by: 'group', handles: [] }])"
        />
    </div>
</template>

<script setup>
import { Button } from '@statamic/cms/ui';
import AudiencePicker from './AudiencePicker.vue';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    byOptions: { type: Array, required: true },
    placeholder: { type: String, default: '' },
});

const emit = defineEmits(['update:rows']);

const setRow = (i, patch) => emit('update:rows', props.rows.map((row, j) => (j === i ? { ...row, ...patch } : row)));
</script>
