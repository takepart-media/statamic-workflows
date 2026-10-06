<template>
    <NodeProblems :path="`transition:${transition.id}`" />
    <Tabs v-model="tab" class="wf-panel-tabs">
        <TabList>
            <TabTrigger name="general" :text="__('workflows::messages.editor_tab_general')" />
            <TabTrigger name="permission" :text="__('workflows::messages.editor_tab_permission')" />
            <TabTrigger name="actions" :text="actionsTabText" />
        </TabList>

        <TabContent name="general">
            <div class="space-y-5 p-4">
                <div v-if="isSystem || isStart || isCta" class="space-y-1.5">
                    <p v-if="isSystem" class="flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <Badge size="sm" :text="__('workflows::messages.editor_badge_system')" />
                        <span>{{ __('workflows::messages.editor_system_hint') }}</span>
                    </p>
                    <p v-if="isStart" class="flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <Badge size="sm" color="blue" :text="__('workflows::messages.editor_badge_start')" />
                        <span>{{ explicitStart ? __('workflows::messages.editor_start_explicit_hint') : __('workflows::messages.editor_start_hint') }}</span>
                    </p>
                    <p v-if="isCta" class="flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <Badge size="sm" color="green" :text="__('workflows::messages.editor_badge_cta')" />
                        <span>{{ __('workflows::messages.create_transition_hint') }}</span>
                    </p>
                </div>

                <ProblemField
                    :label="__('workflows::messages.editor_field_handle')"
                    :instructions="transition.isNew ? __('workflows::messages.editor_handle_new_hint') : __('workflows::messages.editor_handle_saved_hint')"
                    :error="handleError"
                    :path="`transition:${transition.id}.handle`"
                >
                    <Input
                        v-if="transition.isNew"
                        :model-value="transition.handle ?? ''"
                        :placeholder="__('workflows::messages.handle_placeholder')"
                        class="font-mono"
                        @update:model-value="(v) => editor.api.setHandle(transition.id, v)"
                    />
                    <code v-else class="inline-block rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 dark:bg-gray-900! dark:text-gray-200!">{{ transition.handle }}</code>
                </ProblemField>

                <ProblemField
                    :label="__('workflows::messages.label_placeholder')"
                    :instructions="__('workflows::messages.editor_transition_label_hint', { key: `action_${key}` })"
                    :path="`transition:${transition.id}.label`"
                >
                    <div ref="labelField">
                        <Input
                            :model-value="transition.metadata.label ?? ''"
                            :placeholder="key"
                            @update:model-value="(v) => update({ metadata: { label: v } })"
                        />
                    </div>
                </ProblemField>

                <Field :label="__('workflows::messages.settings_field_variant')">
                    <div class="flex flex-wrap items-center gap-2">
                        <Select
                            adaptive-width
                            :model-value="transition.metadata.variant ?? null"
                            :options="editor.options.variantOptions"
                            clearable
                            :placeholder="defaultVariant(key)"
                            :class="['wf-variant-select min-w-32 flex-1', variantTint]"
                            @update:model-value="(v) => update({ metadata: { variant: v ?? null } })"
                        />
                        <!-- Live preview: the button the entry editor renders. -->
                        <Button :variant="variant" :text="transition.metadata.label || key" size="sm" />
                    </div>
                </Field>

                <!-- The first transition leads out of the start place: its `from`
                     follows initial_place (shown, not editable) — unless a
                     `start_localization` action pins the start elsewhere. -->
                <ProblemField
                    :label="__('workflows::messages.settings_field_from')"
                    :instructions="coupled ? __('workflows::messages.from_follows_initial_place') : __('workflows::messages.editor_from_hint')"
                    :path="`transition:${transition.id}.from`"
                >
                    <Select
                        adaptive-width
                        multiple
                        close-on-select
                        :model-value="transition.from"
                        :options="placeOptions"
                        :disabled="coupled"
                        :placeholder="__('workflows::messages.select_place')"
                        @update:model-value="onFrom"
                    />
                </ProblemField>

                <ProblemField
                    :label="__('workflows::messages.settings_field_to')"
                    :path="`transition:${transition.id}.to`"
                >
                    <Select
                        adaptive-width
                        :model-value="transition.to"
                        :options="placeOptions"
                        :placeholder="__('workflows::messages.select_place')"
                        @update:model-value="(v) => update({ to: v ?? null })"
                    />
                </ProblemField>

                <Field :label="__('workflows::messages.editor_field_order')" :instructions="__('workflows::messages.editor_order_transitions_hint')">
                    <div class="flex items-center gap-2">
                        <span class="text-sm">{{ __('workflows::messages.editor_order_position', { position: index + 1, total: editor.api.model.transitions.length }) }}</span>
                        <Button
                            variant="ghost"
                            icon="chevron-up"
                            size="sm"
                            icon-only
                            :disabled="index === 0"
                            :aria-label="__('workflows::messages.move_up')"
                            @click="editor.api.moveTransition(transition.id, index - 1)"
                        />
                        <Button
                            variant="ghost"
                            icon="chevron-down"
                            size="sm"
                            icon-only
                            :disabled="index === editor.api.model.transitions.length - 1"
                            :aria-label="__('workflows::messages.move_down')"
                            @click="editor.api.moveTransition(transition.id, index + 1)"
                        />
                    </div>
                </Field>

                <div class="border-t border-gray-200 pt-4 dark:border-gray-700!">
                    <Button variant="destructive" icon="trash" :text="__('workflows::messages.delete')" @click="remove" />
                </div>
            </div>
        </TabContent>

        <TabContent name="permission">
            <!-- The gate: who may run this step in addition to being responsible.
                 Stored as `required_group` / `required_role` (serialize()). -->
            <div class="space-y-5 p-4">
                <ProblemField
                    :label="__('workflows::messages.required_by')"
                    :instructions="__('workflows::messages.required_by_hint')"
                    :path="`transition:${transition.id}.required_handles`"
                >
                    <AudiencePicker
                        :by="transition.metadata.required_by ?? 'none'"
                        :handles="transition.metadata.required_handles ?? []"
                        :by-options="requiredByOptions"
                        :placeholder="__('workflows::messages.required_group_none')"
                        @update:by="(v) => update({ metadata: { required_by: v ?? 'none' } })"
                        @update:handles="(v) => update({ metadata: { required_handles: v } })"
                    />
                </ProblemField>
            </div>
        </TabContent>

        <TabContent name="actions">
            <div class="p-4">
                <TransitionActionList :transition="transition" />
            </div>
        </TabContent>
    </Tabs>
</template>

<script setup>
import { computed, inject, ref } from 'vue';
import { Badge, Button, Field, Input, Select, TabContent, TabList, TabTrigger, Tabs } from '@statamic/cms/ui';
import { useFocusRequest, usePathRequest } from '../../composables/useFocusRequest.js';
import { tabForPath } from '../../model/validationPaths.js';
import NodeProblems from './NodeProblems.vue';
import ProblemField from '../shared/ProblemField.vue';
import { explicitStartTransitionId, keyOf, startingTransitionId } from '../../model/editorModel.js';
import { defaultVariant, placeDisplayName } from '../../model/labels.js';
import { EDITOR_CONTEXT } from '../context.js';
import AudiencePicker from '../shared/AudiencePicker.vue';
import TransitionActionList from './TransitionActionList.vue';

const props = defineProps({
    // A transition entry of the editor model.
    transition: { type: Object, required: true },
    // Which tab to open first ('general' | 'permission' | 'actions').
    initialTab: { type: String, default: 'general' },
});

const editor = inject(EDITOR_CONTEXT);

const TABS = ['general', 'permission', 'actions'];
const tab = ref(TABS.includes(props.initialTab) ? props.initialTab : 'general');

// A problem picked in a list: the tab that shows it (the shell focuses it).
usePathRequest(editor, () => props.transition.id, (path) => {
    const wanted = tabForPath(path);
    tab.value = TABS.includes(wanted) ? wanted : 'general';
});

// Live validation (useValidation.js) for one field of this transition.
const errorFor = (field) => editor.validation?.errorFor(`transition:${props.transition.id}.${field}`);

// A node just created on the canvas: straight into its name.
const labelField = ref(null);
useFocusRequest(editor, () => props.transition.id, 'label', () => {
    tab.value = 'general';
    return labelField;
});

const key = computed(() => keyOf(props.transition));
const index = computed(() => editor.api.model.transitions.indexOf(props.transition));
const update = (patch) => editor.api.updateTransition(props.transition.id, patch);

const explicitStart = computed(() => explicitStartTransitionId(editor.api.model));
const coupled = computed(() => !explicitStart.value && startingTransitionId(editor.api.model) === props.transition.id);
const isStart = computed(() => (explicitStart.value ?? startingTransitionId(editor.api.model)) === props.transition.id);
const isCta = computed(() => editor.api.model.workflow.metadata?.create_transition === props.transition.id);
const isSystem = computed(() => !!props.transition.metadata?.system);

const handleError = computed(() => {
    const live = errorFor('handle');
    if (live) return live;
    const problem = props.transition.isNew ? editor.api.handleProblem(props.transition.id) : null;
    return problem ? __(`workflows::messages.editor_handle_${problem}`) : undefined;
});

const variant = computed(() => props.transition.metadata.variant || defaultVariant(key.value));

// `.wf-variant-*` (addon.css), so the Select carries the chosen button colour.
const variantTint = computed(() => ({
    primary: 'wf-variant-primary',
    danger: 'wf-variant-danger',
    filled: 'wf-variant-filled',
}[variant.value] ?? 'wf-variant-default'));

const placeOptions = computed(() => editor.api.model.places.map((p) => ({
    value: p.id,
    label: `${placeDisplayName(editor.api.view.value, keyOf(p), editor.labelCtx)} (${keyOf(p)})`,
})));

const requiredByOptions = computed(() => [
    { value: 'none', label: __('workflows::messages.required_by_none') },
    { value: 'group', label: __('workflows::messages.assignment_criterion_group') },
    { value: 'role', label: __('workflows::messages.assignment_criterion_role') },
]);

const actionsTabText = computed(() => {
    const count = props.transition.metadata?.actions?.length ?? 0;
    return count ? `${__('workflows::messages.actions')} (${count})` : __('workflows::messages.actions');
});

// Several sources make a list; the YAML spelling of a single one is kept.
function onFrom(value) {
    const from = [].concat(value ?? []);
    update({ from, fromList: from.length > 1 ? true : props.transition.fromList && from.length === 1 });
}

// Through the editor's delete dialog, like the Delete key.
function remove() {
    editor.requestDelete([props.transition.id]);
}
</script>
