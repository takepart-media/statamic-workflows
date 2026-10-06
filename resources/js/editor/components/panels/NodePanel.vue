<template>
    <!-- The right-hand side of the editor: the selected node's settings, or an
         overview when nothing is selected. The workflow's own settings live in
         a stack (WorkflowSettingsStack.vue). -->
    <aside class="wf-panel" :aria-label="title">
        <header class="wf-panel-head">
            <div class="min-w-0 flex-1">
                <p class="wf-node-muted text-2xs font-medium uppercase tracking-wide">{{ kindLabel }}</p>
                <h2 class="truncate text-base font-semibold">{{ title }}</h2>
            </div>
            <Button
                v-if="selectedId"
                variant="ghost"
                size="sm"
                icon="x"
                icon-only
                :aria-label="__('workflows::messages.editor_close_panel')"
                :title="__('workflows::messages.editor_close_panel')"
                @click="editor.select(null)"
            />
            <Button
                variant="ghost"
                size="sm"
                icon="bar-sidebar-right-panel-open"
                icon-only
                :aria-label="__('workflows::messages.editor_collapse_panel')"
                :title="__('workflows::messages.editor_collapse_panel')"
                @click="$emit('collapse')"
            />
        </header>
        <div class="wf-panel-body">
            <PlacePanel v-if="place" :key="place.id" :place="place" :initial-tab="placeTab" />
            <PlacePanel v-else-if="deletion" :key="`del-${deletion.id}`" :deletion="deletion" />
            <TransitionPanel v-else-if="transition" :key="transition.id" :transition="transition" :initial-tab="placeTab" />
            <OverviewPanel
                v-else
                :problems="problems"
                @open-settings="$emit('open-settings')"
                @select-problem="(problem) => $emit('select-problem', problem)"
            />
        </div>
    </aside>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Button } from '@statamic/cms/ui';
import { keyOf } from '../../model/editorModel.js';
import { placeDisplayName } from '../../model/labels.js';
import { EDITOR_CONTEXT } from '../context.js';
import OverviewPanel from './OverviewPanel.vue';
import PlacePanel from './PlacePanel.vue';
import TransitionPanel from './TransitionPanel.vue';

const props = defineProps({
    selectedId: { type: String, default: null },
    // Which tab of the node's panel to open first (place: 'general' | 'actions' |
    // 'connections'; transition: 'general' | 'permission' | 'actions').
    placeTab: { type: String, default: 'general' },
    // For the overview: see ProblemsList.vue.
    problems: { type: Array, default: () => [] },
});

defineEmits(['collapse', 'open-settings', 'select-problem']);

const editor = inject(EDITOR_CONTEXT);

const place = computed(() => (props.selectedId ? editor.api.findPlace(props.selectedId) : null));
const transition = computed(() => (props.selectedId ? editor.api.findTransition(props.selectedId) : null));
const deletion = computed(() => (props.selectedId ? editor.api.findDeletion(props.selectedId) : null));

const kindLabel = computed(() => {
    if (place.value || deletion.value) return __('workflows::messages.editor_kind_place');
    if (transition.value) return __('workflows::messages.editor_kind_transition');
    return __('workflows::messages.editor_kind_overview');
});

const title = computed(() => {
    if (place.value) return placeDisplayName(editor.api.view.value, keyOf(place.value), editor.labelCtx);
    if (deletion.value) return deletion.value.handle;
    if (transition.value) return transition.value.metadata?.label || keyOf(transition.value);
    return editor.workflowName;
});
</script>
