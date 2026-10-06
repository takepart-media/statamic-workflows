<template>
    <div class="wf-editor-toolbar" role="toolbar" :aria-label="__('workflows::messages.editor_toolbar_label')">
        <Button
            v-if="formViewLabel"
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :text="formViewLabel"
            @click="$emit('form-view')"
        />
        <Button variant="ghost" size="sm" icon="plus" :text="__('workflows::messages.add_place')" @click="$emit('add-place')" />
        <Button variant="ghost" size="sm" icon="plus" :text="__('workflows::messages.add_transition')" @click="$emit('add-transition')" />

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

            <!-- The server's validation errors of the last save. F5 maps them
                 onto the nodes; for now a list that opens the node in question. -->
            <Popover v-if="errors.length" align="end" class="w-96!">
                <template #trigger>
                    <Button
                        variant="ghost"
                        size="sm"
                        icon="warning-diamond"
                        class="text-red-600! dark:text-red-400!"
                        :text="__('workflows::messages.editor_errors', { count: errors.length })"
                    />
                </template>
                <ul class="max-h-80 space-y-1 overflow-y-auto text-sm">
                    <li v-for="(error, i) in errors" :key="i">
                        <button
                            type="button"
                            class="w-full rounded px-2 py-1 text-left hover:bg-gray-100 disabled:cursor-default disabled:hover:bg-transparent dark:hover:bg-gray-700"
                            :disabled="!error.node"
                            @click="$emit('select-error', error)"
                        >
                            <span class="block">{{ error.message }}</span>
                            <code v-if="error.path" class="font-mono text-2xs text-gray-500">{{ error.path }}</code>
                        </button>
                    </li>
                </ul>
            </Popover>

            <span v-if="dirty" class="wf-node-muted text-xs" role="status">{{ __('workflows::messages.editor_unsaved') }}</span>
            <Button
                variant="primary"
                size="sm"
                icon="save"
                :text="__('workflows::messages.save')"
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
import { Button, Popover, Switch } from '@statamic/cms/ui';

defineProps({
    minimap: { type: Boolean, default: false },
    showSystem: { type: Boolean, default: true },
    // Hides the toggle when the workflow has no injected parts to show.
    hasSystemSteps: { type: Boolean, default: false },
    // Text of the "back to the form" button; none → no button.
    formViewLabel: { type: String, default: '' },
    saving: { type: Boolean, default: false },
    dirty: { type: Boolean, default: false },
    // [{path, code, message, node: {kind, handle}|null}] from the last 422.
    errors: { type: Array, default: () => [] },
    panelCollapsed: { type: Boolean, default: false },
});

defineEmits([
    'zoom-in',
    'zoom-out',
    'fit',
    'auto-layout',
    'form-view',
    'update:minimap',
    'update:showSystem',
    'save',
    'add-place',
    'add-transition',
    'select-error',
    'expand-panel',
]);
</script>
