<template>
    <!-- The side panel with nothing selected: how to start, the workflow in
         numbers, the way to its settings and what is wrong with it. -->
    <div class="space-y-6 p-4">
        <div class="space-y-1.5 text-sm">
            <p class="flex items-start gap-2 font-medium">
                <Icon name="cursor-click" class="mt-0.5 size-4 shrink-0 text-gray-500 dark:text-gray-400" />
                {{ __('workflows::messages.editor_overview_hint') }}
            </p>
            <p class="ps-6 text-gray-500 dark:text-gray-400">{{ __('workflows::messages.editor_overview_add_hint') }}</p>
        </div>

        <dl class="grid grid-cols-3 gap-2">
            <div v-for="stat in stats" :key="stat.key" class="rounded-md border border-gray-200 px-3 py-2 dark:border-gray-700!">
                <dt class="truncate text-2xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ stat.label }}</dt>
                <dd class="text-lg font-semibold tabular-nums">{{ stat.value }}</dd>
            </div>
        </dl>

        <!-- Places marked for deletion — gone from the canvas, still reachable
             here until the save. -->
        <div v-if="model.deletions.length" class="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-700! dark:bg-amber-950/40!">
            <p class="text-sm font-medium text-amber-950 dark:text-amber-100">
                {{ __('workflows::messages.editor_pending_deletions') }} ({{ model.deletions.length }})
            </p>
            <ul class="space-y-1">
                <li v-for="d in model.deletions" :key="d.id" class="flex items-center gap-2 text-sm">
                    <Icon
                        v-if="editor.validation?.countsFor(d.id).errors"
                        name="warning-diamond"
                        class="size-3.5 shrink-0 text-red-600 dark:text-red-400"
                        :aria-label="editor.validation.errorsFor(d.id).map((p) => p.message).join(' ')"
                        :title="editor.validation.errorsFor(d.id).map((p) => p.message).join(' ')"
                    />
                    <code class="font-mono text-xs">{{ d.handle }}</code>
                    <span v-if="d.usage" class="text-xs text-gray-600 dark:text-gray-300">
                        {{ __('workflows::messages.editor_usage_count', { count: d.usage }) }}
                    </span>
                    <Button class="ml-auto" size="xs" variant="ghost" :text="__('workflows::messages.editor_open')" @click="editor.select(d.id)" />
                </li>
            </ul>
        </div>

        <Button
            class="w-full"
            icon="cog"
            :text="__('workflows::messages.editor_kind_workflow')"
            :title="`${__('workflows::messages.editor_kind_workflow')} (${shortcutLabel(',')})`"
            :aria-keyshortcuts="IS_MAC ? 'Meta+,' : 'Control+,'"
            @click="$emit('open-settings')"
        />

        <section class="space-y-2" :aria-label="__('workflows::messages.editor_problems')">
            <h3 class="flex items-center gap-2 text-sm font-semibold">
                {{ __('workflows::messages.editor_problems') }}
                <Badge v-if="counts.errors" :text="String(counts.errors)" color="red" size="sm" :title="__('workflows::messages.editor_errors', { count: counts.errors })" />
                <Badge v-if="counts.warnings" :text="String(counts.warnings)" color="amber" size="sm" :title="__('workflows::messages.editor_warnings', { count: counts.warnings })" />
            </h3>
            <ProblemsList :problems="problems" @select="(problem) => $emit('select-problem', problem)" />
        </section>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Badge, Button, Icon } from '@statamic/cms/ui';
import { IS_MAC, shortcutLabel } from '../../composables/useShortcuts.js';
import { EDITOR_CONTEXT } from '../context.js';
import ProblemsList from './ProblemsList.vue';

const props = defineProps({
    // See ProblemsList.vue.
    problems: { type: Array, default: () => [] },
});

defineEmits(['open-settings', 'select-problem']);

const editor = inject(EDITOR_CONTEXT);
const model = editor.api.model;

const counts = computed(() => ({
    errors: props.problems.filter((p) => p.level === 'error').length,
    warnings: props.problems.filter((p) => p.level === 'warning').length,
}));

// Entries counted per stored state (stateUsage) — what the workflow governs
// right now, whatever the unsaved edits say.
const stats = computed(() => [
    { key: 'places', label: __('workflows::messages.editor_overview_places'), value: model.places.length },
    {
        key: 'transitions',
        label: __('workflows::messages.editor_overview_transitions'),
        value: model.transitions.filter((t) => !t.metadata?.system).length,
    },
    {
        key: 'entries',
        label: __('workflows::messages.editor_overview_entries'),
        value: Object.values(editor.stateUsage ?? {}).reduce((sum, n) => sum + (Number(n) || 0), 0),
    },
]);
</script>
