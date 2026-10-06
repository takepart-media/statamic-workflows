<template>
    <!-- The workflow's own settings, out of the side panel: a wide stack with the
         sections listed on the left and the open one on the right. It only edits
         the editor model — undo/redo, Ctrl/⌘+S and the dirty state keep working
         (WorkflowEditor.vue counts this teleported layer as part of the editor).
         Esc is the stack's own: it closes first, before anything on the canvas. -->
    <Stack
        :open="open"
        :title="title"
        icon="cog"
        inset
        @update:open="(v) => $emit('update:open', v)"
        @opened="focusNavigation"
        @closed="$emit('closed')"
    >
        <template #header-actions>
            <span v-if="dirty" class="text-xs text-gray-500 dark:text-gray-400" role="status">{{ __('workflows::messages.editor_unsaved') }}</span>
            <Button
                variant="primary"
                size="sm"
                icon="save"
                :text="__('workflows::messages.save')"
                :title="saveTitle"
                :loading="saving"
                :disabled="saving"
                @click="$emit('save')"
            />
        </template>

        <div ref="root" class="flex min-h-full flex-col md:flex-row">
            <nav
                class="shrink-0 border-b border-gray-200 md:w-56 md:border-e md:border-b-0 dark:border-gray-700!"
                :aria-label="__('workflows::messages.editor_settings_sections')"
            >
                <ul class="flex gap-0.5 overflow-x-auto p-3 md:sticky md:top-0 md:flex-col md:overflow-visible">
                    <li v-for="item in sections" :key="item.name" class="shrink-0">
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-md px-3 py-1.5 text-left text-sm whitespace-nowrap text-gray-700 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-blue-500 dark:text-gray-300 dark:hover:bg-gray-800"
                            :class="item.name === current ? 'bg-gray-100 font-medium text-gray-950! dark:bg-gray-800! dark:text-white!' : ''"
                            :aria-current="item.name === current ? 'true' : undefined"
                            @click="current = item.name"
                        >
                            <Icon :name="item.icon" class="size-4 shrink-0 text-gray-500 dark:text-gray-400" />
                            {{ item.label }}
                            <!-- Live validation: problems in this section. -->
                            <span
                                v-if="countOf(item.name)"
                                class="ms-auto rounded-full px-1.5 text-2xs font-semibold tabular-nums"
                                :class="counts[item.name]?.errors ? 'bg-red-100 text-red-700 dark:bg-red-950! dark:text-red-300!' : 'bg-amber-100 text-amber-800 dark:bg-amber-950! dark:text-amber-300!'"
                                :title="__('workflows::messages.editor_problems_count', counts[item.name])"
                                :aria-label="__('workflows::messages.editor_problems_count', counts[item.name])"
                            >{{ countOf(item.name) }}</span>
                        </button>
                    </li>
                </ul>
            </nav>

            <section class="min-w-0 flex-1 p-6" :aria-labelledby="headingId">
                <div class="max-w-3xl">
                    <h3 :id="headingId" class="mb-5 text-base font-semibold text-gray-950 dark:text-white">{{ currentLabel }}</h3>
                    <WorkflowSettingsSection :key="current" :section="current" @reset-positions="$emit('reset-positions')" />
                </div>
            </section>
        </div>
    </Stack>
</template>

<script>
let instances = 0;
</script>

<script setup>
import { computed, inject, nextTick, ref } from 'vue';
import { Button, Icon, Stack } from '@statamic/cms/ui';
import { isLocalizationWorkflow } from '../../model/actionRules.js';
import { EDITOR_CONTEXT } from '../context.js';
import WorkflowSettingsSection from './WorkflowSettingsSection.vue';
import { focusPath } from '../../composables/useFocusRequest.js';

defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    dirty: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    saveTitle: { type: String, default: '' },
});

// closed: after the slide-out — the editor hands the focus back to the toolbar.
defineEmits(['update:open', 'closed', 'save', 'reset-positions']);

const editor = inject(EDITOR_CONTEXT);
const root = ref(null);
// A module counter, not useId(): the CP's own Vue decides which APIs exist.
const headingId = `wf-settings-heading-${++instances}`;

const isLocalization = computed(() => isLocalizationWorkflow(editor.api.view.value));

// Localization only for localization workflows, SLA only for the others (the
// owning workflow ages the posts; a localization workflow reacts to that).
const sections = computed(() => [
    { name: 'general', icon: 'sliders-horizontal', label: __('workflows::messages.editor_tab_general') },
    { name: 'permissions', icon: 'key', label: __('workflows::messages.editor_tab_permissions') },
    isLocalization.value && { name: 'localization', icon: 'globe-world-wide-web', label: __('workflows::messages.editor_tab_localization') },
    { name: 'collections', icon: 'collections', label: __('workflows::messages.collections') },
    !isLocalization.value && { name: 'sla', icon: 'time-clock', label: __('workflows::messages.editor_tab_sla') },
    { name: 'order', icon: 'list-ol', label: __('workflows::messages.editor_tab_order') },
    { name: 'advanced', icon: 'code-block', label: __('workflows::messages.editor_tab_advanced') },
].filter(Boolean));

// Kept while the stack is closed, so it reopens where it was left.
const chosen = ref('general');
const current = computed({
    get: () => (sections.value.some((s) => s.name === chosen.value) ? chosen.value : 'general'),
    set: (value) => (chosen.value = value),
});
const currentLabel = computed(() => sections.value.find((s) => s.name === current.value)?.label ?? '');

const counts = computed(() => editor.validation?.sectionCounts.value ?? {});
const countOf = (name) => counts.value[name]?.errors || counts.value[name]?.warnings || 0;

// A problem's field to show once the section is there (show()).
let pendingPath = null;

// Into the stack once it has slid in: the field a problem asked for, else the
// open section's entry.
function focusNavigation() {
    nextTick(() => {
        if (pendingPath && focusPending()) return;
        root.value?.querySelector('nav [aria-current="true"]')?.focus();
    });
}

function focusPending() {
    const path = pendingPath;
    pendingPath = null;
    return focusPath(root.value, path);
}

defineExpose({
    /**
     * Open `section` and focus the field for `path` (`data-path`). `now`: the
     * stack is already open — otherwise that happens once it has slid in.
     */
    show(section, path = null, { now = false } = {}) {
        if (sections.value.some((s) => s.name === section)) chosen.value = section;
        pendingPath = path;
        if (now) nextTick(() => setTimeout(focusPending, 50));
    },
    // The stack is teleported out of the editor; its shortcuts still apply here —
    // header (save, close) included.
    element: () => root.value?.closest('.stack-content') ?? root.value,
});
</script>
