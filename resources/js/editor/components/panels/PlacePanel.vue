<template>
    <!-- A place marked for deletion: nothing to edit but where its entries go. -->
    <div v-if="deletion" class="space-y-4 p-4">
        <div class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-700! dark:bg-amber-950/40! dark:text-amber-100!">
            <Icon name="warning-diamond" class="mt-0.5 size-4 shrink-0" />
            <span>{{ __('workflows::messages.editor_place_pending_deletion', { place: deletion.handle }) }}</span>
        </div>
        <MigrationTarget
            v-if="deletion.usage > 0"
            :usage="deletion.usage"
            :place="deletion.handle"
            :model-value="deletion.migrateTo"
            :options="migrationOptions(deletion.id)"
            @update:model-value="(v) => editor.api.setMigrationTarget(deletion.id, v)"
        />
        <Button icon="history" :text="__('workflows::messages.editor_restore_place')" @click="restore" />
    </div>

    <Tabs v-else v-model="tab" class="wf-panel-tabs">
        <TabList>
            <TabTrigger name="general" :text="__('workflows::messages.editor_tab_general')" />
            <TabTrigger name="actions" :text="actionsTabText" />
            <!-- Short in the tab, so the three fit the panel's width. -->
            <TabTrigger
                name="connections"
                :text="__('workflows::messages.editor_tab_connections_short')"
                :title="__('workflows::messages.editor_tab_connections')"
            />
        </TabList>

        <TabContent name="general">
            <div class="space-y-5 p-4">
                <Field
                    :label="__('workflows::messages.editor_field_handle')"
                    :instructions="place.isNew ? __('workflows::messages.editor_handle_new_hint') : __('workflows::messages.editor_handle_saved_hint')"
                    :error="handleError"
                >
                    <Input
                        v-if="place.isNew"
                        :model-value="place.handle ?? ''"
                        :placeholder="__('workflows::messages.handle_placeholder')"
                        class="font-mono"
                        @update:model-value="(v) => editor.api.setHandle(place.id, v)"
                    />
                    <code v-else class="inline-block rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 dark:bg-gray-900! dark:text-gray-200!">{{ place.handle }}</code>
                </Field>

                <!-- One name per language. Empty = the grey placeholder: what the
                     place is called without one (language file, else the other
                     name) — see labels.placeLabelFallback(). -->
                <Field :label="__('workflows::messages.editor_field_labels')" :instructions="__('workflows::messages.editor_labels_hint', { key: `state_${key}` })">
                    <div ref="labelFields" class="space-y-2">
                        <div v-for="locale in editor.options.labelLocales" :key="locale" class="flex items-center gap-2">
                            <span class="w-6 shrink-0 font-mono text-2xs uppercase text-gray-500 dark:text-gray-400">{{ locale }}</span>
                            <Input
                                class="flex-1"
                                :model-value="place.labels[locale] ?? ''"
                                :placeholder="fallback(locale)"
                                :aria-label="`${__('workflows::messages.label_placeholder')} (${locale})`"
                                @update:model-value="(v) => update({ labels: { [locale]: v } })"
                            />
                        </div>
                    </div>
                </Field>

                <Field :label="__('workflows::messages.settings_field_color')">
                    <div class="flex items-center gap-2">
                        <span class="size-3 shrink-0 rounded-full" :class="colorClasses.dot" aria-hidden="true" />
                        <Select
                            adaptive-width
                            class="flex-1"
                            :model-value="place.metadata.color ?? null"
                            :options="editor.options.colorOptions"
                            clearable
                            :placeholder="__('workflows::messages.color_default')"
                            @update:model-value="(v) => update({ metadata: { color: v ?? null } })"
                        />
                        <Badge :text="displayName" :color="place.metadata.color || stateColor(key)" size="sm" />
                    </div>
                </Field>

                <Field :label="__('workflows::messages.settings_field_allow_publish')">
                    <Checkbox
                        solo
                        :model-value="!!place.metadata.allow_publish"
                        @update:model-value="(v) => update({ metadata: { allow_publish: v } })"
                    />
                </Field>

                <!-- Presentation only: drawn beside its predecessor in the progress
                     bar instead of as a stage on the way. Never for the start. -->
                <Field
                    v-if="!isInitial"
                    :label="__('workflows::messages.settings_field_branch')"
                    :instructions="__('workflows::messages.settings_field_branch_hint')"
                >
                    <Checkbox
                        solo
                        :model-value="!!place.metadata.branch"
                        @update:model-value="(v) => update({ metadata: { branch: v } })"
                    />
                </Field>

                <Field :label="__('workflows::messages.editor_field_order')" :instructions="__('workflows::messages.editor_order_places_hint')">
                    <div class="flex items-center gap-2">
                        <span class="text-sm">{{ __('workflows::messages.editor_order_position', { position: index + 1, total: editor.api.model.places.length }) }}</span>
                        <Button
                            variant="ghost"
                            icon="chevron-up"
                            size="sm"
                            icon-only
                            :disabled="index === 0"
                            :aria-label="__('workflows::messages.move_up')"
                            @click="editor.api.movePlace(place.id, index - 1)"
                        />
                        <Button
                            variant="ghost"
                            icon="chevron-down"
                            size="sm"
                            icon-only
                            :disabled="index === editor.api.model.places.length - 1"
                            :aria-label="__('workflows::messages.move_down')"
                            @click="editor.api.movePlace(place.id, index + 1)"
                        />
                    </div>
                </Field>

                <Field :label="__('workflows::messages.settings_field_initial_place')">
                    <Badge v-if="isInitial" color="blue" :text="__('workflows::messages.editor_is_initial')" />
                    <Button v-else size="sm" icon="pin" :text="__('workflows::messages.editor_set_initial')" @click="editor.api.setInitialPlace(place.id)" />
                </Field>
            </div>
        </TabContent>

        <TabContent name="actions">
            <div class="p-4">
                <PlaceActionList :place="place" />
            </div>
        </TabContent>

        <TabContent name="connections">
            <div class="space-y-5 p-4">
                <Field :label="__('workflows::messages.editor_incoming')">
                    <TransitionLinks :transitions="incoming" />
                </Field>
                <Field :label="__('workflows::messages.editor_outgoing')">
                    <TransitionLinks :transitions="outgoing" />
                </Field>
                <Field v-if="roles.length" :label="__('workflows::messages.editor_referenced_by')">
                    <ul class="space-y-1 text-sm">
                        <li v-for="role in roles" :key="role.role" class="flex items-center gap-1.5">
                            <Icon name="link" class="size-3.5 text-gray-400" />
                            {{ __(role.label) }}
                        </li>
                    </ul>
                </Field>
                <Field :label="__('workflows::messages.editor_usage')">
                    <p class="text-sm">
                        {{ place.isNew ? __('workflows::messages.editor_usage_new') : __('workflows::messages.editor_usage_count', { count: usage }) }}
                    </p>
                </Field>

                <div class="space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700!">
                    <p v-if="isInitial" class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('workflows::messages.editor_delete_initial_blocked') }}
                    </p>
                    <!-- The dialog asks, wants a target for entries still in
                         here and lists what loses its link. -->
                    <template v-else>
                        <p v-if="!place.isNew" class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('workflows::messages.editor_delete_on_save') }}
                        </p>
                        <Button
                            variant="destructive"
                            icon="trash"
                            :text="__('workflows::messages.delete')"
                            @click="editor.requestDelete([place.id])"
                        />
                    </template>
                </div>
            </div>
        </TabContent>
    </Tabs>
</template>

<script setup>
import { computed, defineComponent, h, inject, ref, watch } from 'vue';
import { useFocusRequest } from '../../composables/useFocusRequest.js';
import { Badge, Button, Checkbox, Field, Icon, Input, Select, TabContent, TabList, TabTrigger, Tabs } from '@statamic/cms/ui';
import { placeColorClasses } from '../../../support/placeColors.js';
import { stateColor } from '../../../support/states.js';
import { incomingTransitions, keyOf, outgoingTransitions } from '../../model/editorModel.js';
import { placeDisplayName, placeLabelFallback } from '../../model/labels.js';
import { placeReferences } from '../../model/refs.js';
import { EDITOR_CONTEXT } from '../context.js';
import PlaceActionList from './PlaceActionList.vue';

const props = defineProps({
    // The place entry — or null while showing a pending deletion.
    place: { type: Object, default: null },
    // A `deletions` row of the model, for a place marked for deletion.
    deletion: { type: Object, default: null },
    // Which tab to open (e.g. 'connections' after a save asked for a target).
    initialTab: { type: String, default: 'general' },
});

const editor = inject(EDITOR_CONTEXT);

const tab = ref(props.initialTab);
watch(() => props.initialTab, (value) => { tab.value = value; });

// A node just created on the canvas: straight into its name.
const labelFields = ref(null);
useFocusRequest(editor, () => props.place?.id, 'label', () => {
    tab.value = 'general';
    return labelFields;
});

const key = computed(() => keyOf(props.place));
const index = computed(() => editor.api.model.places.indexOf(props.place));
const isInitial = computed(() => editor.api.model.workflow.initial_place === props.place?.id);
const usage = computed(() => (props.place?.isNew ? 0 : Number(editor.stateUsage[props.place?.handle] ?? 0)));

const update = (patch) => editor.api.updatePlace(props.place.id, patch);

const handleError = computed(() => {
    const problem = props.place?.isNew ? editor.api.handleProblem(props.place.id) : null;
    return problem ? __(`workflows::messages.editor_handle_${problem}`) : null;
});

const fallback = (locale) => placeLabelFallback(editor.api.view.value, key.value, locale, editor.labelCtx);
const displayName = computed(() => placeDisplayName(editor.api.view.value, key.value, editor.labelCtx));
const colorClasses = computed(() => placeColorClasses(props.place?.metadata?.color || stateColor(key.value)));

const actionsTabText = computed(() => {
    const count = props.place?.metadata?.actions?.length ?? 0;
    return count ? `${__('workflows::messages.actions')} (${count})` : __('workflows::messages.actions');
});

const incoming = computed(() => incomingTransitions(editor.api.model, props.place.id));
const outgoing = computed(() => outgoingTransitions(editor.api.model, props.place.id));

// What else names this place — the edges are listed above, so they're left out.
const roles = computed(() => {
    const seen = new Set();
    return placeReferences(editor.api.view.value, key.value)
        .filter((r) => !['transition_from', 'transition_to'].includes(r.role))
        .filter((r) => (seen.has(r.role) ? false : seen.add(r.role)));
});

function migrationOptions(exclude) {
    return editor.api.model.places
        .filter((p) => p.id !== exclude)
        .map((p) => ({ value: p.id, label: `${placeDisplayName(editor.api.view.value, keyOf(p), editor.labelCtx)} (${keyOf(p)})` }));
}

// Back from the pending deletion, with the links it lost (one undo step).
function restore() {
    const id = props.deletion.id;
    editor.api.restoreDeletion(id);
    editor.select(id);
}

// A clickable list of transitions — opens the one clicked.
const TransitionLinks = defineComponent({
    props: { transitions: { type: Array, required: true } },
    setup(linkProps) {
        return () => (linkProps.transitions.length
            ? h('ul', { class: 'space-y-1' }, linkProps.transitions.map((t) => h('li', { key: t.id }, [
                h('button', {
                    type: 'button',
                    class: 'flex w-full items-center gap-1.5 rounded px-1 py-0.5 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-800',
                    onClick: () => editor.select(t.id),
                }, [
                    h('span', { class: 'truncate' }, t.metadata?.label || keyOf(t)),
                    h('code', { class: 'ml-auto font-mono text-2xs text-gray-500' }, keyOf(t)),
                ]),
            ])))
            : h('p', { class: 'text-sm text-gray-500 dark:text-gray-400' }, __('workflows::messages.editor_none')));
    },
});

// Where the entries of a deleted place go.
const MigrationTarget = defineComponent({
    props: {
        modelValue: { type: String, default: null },
        options: { type: Array, required: true },
        usage: { type: Number, required: true },
        place: { type: String, required: true },
    },
    emits: ['update:modelValue'],
    setup(targetProps, { emit }) {
        return () => h(Field, {
            label: __('workflows::messages.migrate_target'),
            instructions: __('workflows::messages.migrate_place_prompt', { place: targetProps.place, count: targetProps.usage }),
        }, () => h(Select, {
            adaptiveWidth: true,
            modelValue: targetProps.modelValue,
            options: targetProps.options,
            placeholder: __('workflows::messages.select_place'),
            'onUpdate:modelValue': (v) => emit('update:modelValue', v ?? null),
        }));
    },
});
</script>
