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
        <span class="wf-node-muted text-xs">{{ __('workflows::messages.editor_read_only') }}</span>

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
        </div>
    </div>
</template>

<script setup>
import { Button, Switch } from '@statamic/cms/ui';

defineProps({
    minimap: { type: Boolean, default: false },
    showSystem: { type: Boolean, default: true },
    // Hides the toggle when the workflow has no injected parts to show.
    hasSystemSteps: { type: Boolean, default: false },
    // Text of the "back to the form" button; none → no button.
    formViewLabel: { type: String, default: '' },
});

defineEmits(['zoom-in', 'zoom-out', 'fit', 'auto-layout', 'form-view', 'update:minimap', 'update:showSystem']);
</script>
