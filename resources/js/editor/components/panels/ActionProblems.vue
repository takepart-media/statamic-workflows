<template>
    <!-- The errors of one action row, or of the action list as a whole. -->
    <p v-if="message" class="flex items-start gap-1.5 text-xs text-red-600 dark:text-red-400">
        <Icon name="warning-diamond" class="mt-0.5 size-3.5 shrink-0" />
        <span>{{ message }}</span>
    </p>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Icon } from '@statamic/cms/ui';
import { EDITOR_CONTEXT } from '../context.js';

const props = defineProps({
    path: { type: String, required: true },
    // Only problems exactly at `path`, not inside it (the list vs. its rows).
    exact: { type: Boolean, default: false },
});

const editor = inject(EDITOR_CONTEXT);

const message = computed(() => {
    const v = editor.validation;
    if (!v) return null;
    if (!props.exact) return v.errorFor(props.path) ?? null;
    const found = v.problemsExactly(props.path).filter((p) => p.level === 'error');
    return found.length ? [...new Set(found.map((p) => p.message))].join(' ') : null;
});
</script>
