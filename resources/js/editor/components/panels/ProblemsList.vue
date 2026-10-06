<template>
    <!-- What is wrong with the workflow right now: live validation (client
         rules + the server's last check, useValidation.js). An entry with a node
         opens it, one with a settings section opens that. -->
    <div>
        <ul v-if="problems.length" class="space-y-1">
            <li v-for="(problem, i) in problems" :key="`${problem.path ?? ''}:${i}`">
                <button
                    type="button"
                    class="flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-gray-100 disabled:cursor-default disabled:hover:bg-transparent dark:hover:bg-gray-800"
                    :disabled="!problem.nodeId && !problem.section"
                    @click="$emit('select', problem)"
                >
                    <Icon :name="LEVELS[problem.level]?.icon ?? LEVELS.error.icon" class="mt-0.5 size-4 shrink-0" :class="LEVELS[problem.level]?.color ?? LEVELS.error.color" />
                    <span class="min-w-0 flex-1">
                        <span class="block">{{ problem.message }}</span>
                        <code v-if="problem.displayPath ?? problem.path" class="block truncate font-mono text-2xs text-gray-500 dark:text-gray-400">{{ problem.displayPath ?? problem.path }}</code>
                    </span>
                </button>
            </li>
        </ul>
        <p v-else class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <Icon name="checkmark" class="size-4 shrink-0" />
            {{ __('workflows::messages.editor_problems_none') }}
        </p>
    </div>
</template>

<script setup>
import { Icon } from '@statamic/cms/ui';

defineProps({
    // [{ level: 'error'|'warning'|'info', message, nodeId?: string, path?: string,
    //    section?: string, displayPath?: string }]
    problems: { type: Array, default: () => [] },
});

// select: a problem with a node or a settings section was clicked.
defineEmits(['select']);

const LEVELS = {
    error: { icon: 'warning-diamond', color: 'text-red-600 dark:text-red-400' },
    warning: { icon: 'alert-warning-exclamation-mark', color: 'text-amber-600 dark:text-amber-400' },
    info: { icon: 'info', color: 'text-gray-500 dark:text-gray-400' },
};
</script>
