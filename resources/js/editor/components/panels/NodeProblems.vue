<template>
    <!-- Problems of the node as a whole (not of one of its fields, which show
         their own): unreachable, not connected … — above the tabs, so they show
         whatever tab is open. -->
    <ul v-if="problems.length" class="space-y-1 border-b border-gray-200 px-4 py-3 dark:border-gray-700!" :data-path="path">
        <li v-for="(problem, i) in problems" :key="i" class="flex items-start gap-2 text-sm">
            <Icon
                :name="problem.level === 'error' ? 'warning-diamond' : 'alert-warning-exclamation-mark'"
                class="mt-0.5 size-4 shrink-0"
                :class="problem.level === 'error' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'"
            />
            <span>{{ problem.message }}</span>
        </li>
    </ul>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Icon } from '@statamic/cms/ui';
import { EDITOR_CONTEXT } from '../context.js';

const props = defineProps({
    // The node's own path (`place:<id>` / `transition:<id>`).
    path: { type: String, required: true },
});

const editor = inject(EDITOR_CONTEXT);

const problems = computed(() => editor.validation?.problemsExactly(props.path) ?? []);
</script>
