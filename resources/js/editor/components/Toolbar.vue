<template>
    <div class="wf-editor-toolbar" role="toolbar" :aria-label="__('workflows::messages.editor_toolbar_label')">
        <!-- Which workflow, and whether it runs. Active is not part of the
             definition: the switch posts right away, independent of saving. -->
        <div class="flex min-w-0 items-center gap-3 px-1">
            <p class="flex min-w-0 items-baseline gap-1.5">
                <span class="truncate text-sm font-semibold" :title="workflowName">{{ workflowName }}</span>
                <code v-if="workflowHandle && workflowHandle !== workflowName" class="wf-node-muted hidden font-mono text-2xs sm:inline">{{ workflowHandle }}</code>
            </p>
            <label class="flex shrink-0 cursor-pointer items-center gap-1.5 text-xs" :title="__('workflows::messages.editor_active_hint')">
                <Switch
                    size="xs"
                    :model-value="active"
                    :disabled="activeBusy || !canToggleActive"
                    @update:model-value="$emit('update:active', $event)"
                />
                {{ __('workflows::messages.workflow_active') }}
            </label>
        </div>
        <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700!" aria-hidden="true" />
        <Button variant="ghost" size="sm" icon="plus" :text="__('workflows::messages.add_place')" @click="$emit('add-place')" />
        <Button variant="ghost" size="sm" icon="plus" :text="__('workflows::messages.add_transition')" @click="$emit('add-transition')" />
        <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700!" aria-hidden="true" />
        <!-- The kit has no undo/redo icons — arrows as text, like the zoom buttons. -->
        <Button
            variant="ghost"
            size="sm"
            text="↶"
            :disabled="!canUndo"
            :aria-label="undoTitle"
            :aria-keyshortcuts="isMac ? 'Meta+Z' : 'Control+Z'"
            :title="undoTitle"
            @click="$emit('undo')"
        />
        <Button
            variant="ghost"
            size="sm"
            text="↷"
            :disabled="!canRedo"
            :aria-label="redoTitle"
            :aria-keyshortcuts="isMac ? 'Meta+Shift+Z' : 'Control+Shift+Z Control+Y'"
            :title="redoTitle"
            @click="$emit('redo')"
        />

        <div class="ml-auto flex flex-wrap items-center gap-1">
            <Button
                variant="ghost"
                size="sm"
                text="−"
                :aria-label="__('workflows::messages.editor_zoom_out')"
                :title="__('workflows::messages.editor_zoom_out')"
                @click="$emit('zoom-out')"
            />
            <Button
                variant="ghost"
                size="sm"
                text="+"
                :aria-label="__('workflows::messages.editor_zoom_in')"
                :title="__('workflows::messages.editor_zoom_in')"
                @click="$emit('zoom-in')"
            />
            <Button
                variant="ghost"
                size="sm"
                icon="zoom-fit-screen"
                icon-only
                :aria-label="__('workflows::messages.editor_fit')"
                :title="__('workflows::messages.editor_fit')"
                @click="$emit('fit')"
            />
            <Button
                variant="ghost"
                size="sm"
                icon="hierarchy"
                :text="__('workflows::messages.editor_auto_layout')"
                @click="$emit('auto-layout')"
            />
            <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700!" aria-hidden="true" />
            <label class="flex cursor-pointer items-center gap-1.5 text-xs">
                <Switch size="xs" :model-value="minimap" @update:model-value="$emit('update:minimap', $event)" />
                {{ __('workflows::messages.editor_minimap') }}
            </label>
            <label v-if="hasSystemSteps" class="flex cursor-pointer items-center gap-1.5 text-xs">
                <Switch size="xs" :model-value="showSystem" @update:model-value="$emit('update:showSystem', $event)" />
                {{ __('workflows::messages.editor_show_system') }}
            </label>
            <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700!" aria-hidden="true" />

            <!-- Live validation: the client's rules and the server's last check,
                 merged. The list opens the node (or settings section) in question. -->
            <span
                v-if="validating"
                class="wf-node-muted flex items-center gap-1 text-xs"
                :title="__('workflows::messages.editor_validating_hint')"
            >
                <Icon name="loading" class="size-3.5 animate-spin" />
                {{ __('workflows::messages.editor_validating') }}
            </span>
            <Popover v-if="problems.length" align="end" class="w-96!">
                <template #trigger>
                    <Button
                        variant="ghost"
                        size="sm"
                        :icon="summary.errors ? 'warning-diamond' : 'alert-warning-exclamation-mark'"
                        :class="summary.errors ? 'text-red-600! dark:text-red-400!' : 'text-amber-600! dark:text-amber-400!'"
                        :text="summaryText"
                        :aria-label="`${__('workflows::messages.editor_problems')}: ${summaryText}`"
                    />
                </template>
                <div class="max-h-80 overflow-y-auto">
                    <ProblemsList :problems="problems" @select="(problem) => $emit('select-problem', problem)" />
                </div>
            </Popover>

            <span v-if="dirty" class="wf-node-muted text-xs" role="status">{{ __('workflows::messages.editor_unsaved') }}</span>
            <span ref="settingsButton" class="contents">
                <Button
                    variant="ghost"
                    size="sm"
                    icon="cog"
                    :text="__('workflows::messages.editor_kind_workflow')"
                    :title="`${__('workflows::messages.editor_kind_workflow')} (${shortcut(',')})`"
                    :aria-keyshortcuts="isMac ? 'Meta+,' : 'Control+,'"
                    :aria-expanded="settingsOpen ? 'true' : 'false'"
                    @click="$emit('open-settings')"
                />
            </span>
            <Button
                variant="primary"
                size="sm"
                icon="save"
                :text="__('workflows::messages.save')"
                :title="`${__('workflows::messages.save')} (${shortcut('S')})`"
                :aria-keyshortcuts="isMac ? 'Meta+S' : 'Control+S'"
                :loading="saving"
                :disabled="saving"
                @click="$emit('save')"
            />
            <Button
                v-if="panelCollapsed"
                variant="ghost"
                size="sm"
                icon="bar-sidebar-right-panel-open"
                icon-only
                :aria-label="__('workflows::messages.editor_expand_panel')"
                :title="__('workflows::messages.editor_expand_panel')"
                @click="$emit('expand-panel')"
            />
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Button, Icon, Popover, Switch } from '@statamic/cms/ui';
import ProblemsList from './panels/ProblemsList.vue';
import { IS_MAC, shortcutLabel } from '../composables/useShortcuts.js';

const props = defineProps({
    minimap: { type: Boolean, default: false },
    showSystem: { type: Boolean, default: true },
    // Hides the toggle when the workflow has no injected parts to show.
    hasSystemSteps: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    dirty: { type: Boolean, default: false },
    // The merged problem list (useValidation.js) — see ProblemsList.vue.
    problems: { type: Array, default: () => [] },
    // { errors, warnings } of that list.
    summary: { type: Object, default: () => ({ errors: 0, warnings: 0 }) },
    // The server's check is pending or older than the last edit.
    validating: { type: Boolean, default: false },
    panelCollapsed: { type: Boolean, default: false },
    canUndo: { type: Boolean, default: false },
    canRedo: { type: Boolean, default: false },
    // What the next undo / redo would revert (a history step's name), if known.
    undoAction: { type: String, default: '' },
    redoAction: { type: String, default: '' },
    // The workflow's name (nav label, else handle) and handle.
    workflowName: { type: String, default: '' },
    workflowHandle: { type: String, default: '' },
    active: { type: Boolean, default: false },
    // The active switch is posting / has nowhere to post.
    activeBusy: { type: Boolean, default: false },
    canToggleActive: { type: Boolean, default: true },
    settingsOpen: { type: Boolean, default: false },
});

const isMac = IS_MAC;
const shortcut = shortcutLabel;

const summaryText = computed(() => [
    props.summary.errors ? __('workflows::messages.editor_errors', { count: props.summary.errors }) : null,
    props.summary.warnings ? __('workflows::messages.editor_warnings', { count: props.summary.warnings }) : null,
].filter(Boolean).join(' · '));

const undoTitle = computed(() => {
    const label = props.undoAction
        ? __('workflows::messages.editor_undo_action', { action: props.undoAction })
        : __('workflows::messages.editor_undo');
    return `${label} (${shortcut('Z')})`;
});
const redoTitle = computed(() => {
    const label = props.redoAction
        ? __('workflows::messages.editor_redo_action', { action: props.redoAction })
        : __('workflows::messages.editor_redo');
    return `${label} (${shortcut('Z', { shift: true })}${isMac ? '' : `, ${shortcut('Y')}`})`;
});

defineEmits([
    'zoom-in',
    'zoom-out',
    'fit',
    'auto-layout',
    'update:minimap',
    'update:showSystem',
    'save',
    'add-place',
    'add-transition',
    'select-problem',
    'expand-panel',
    'undo',
    'redo',
    'update:active',
    'open-settings',
]);

// The settings stack hands the focus back here when it closes.
const settingsButton = ref(null);
defineExpose({
    focusSettings: () => settingsButton.value?.querySelector('button')?.focus(),
});
</script>
