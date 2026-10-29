<template>
    <!-- One section of the workflow settings (WorkflowSettingsStack.vue shows the
         navigation and the heading). Every edit goes through the editor model,
         so undo/redo and the dirty state work here like on the canvas. -->
    <div>
        <template v-if="section === 'general'">
            <div class="space-y-5">
                <ProblemField :label="__('workflows::messages.nav_label')" :instructions="__('workflows::messages.nav_label_hint')" path="metadata.nav">
                    <Input :model-value="nav.label ?? ''" @update:model-value="(v) => setNav('label', v)" />
                </ProblemField>
                <div class="grid grid-cols-2 gap-4">
                    <Field :label="__('workflows::messages.nav_icon')">
                        <Input :model-value="nav.icon ?? ''" @update:model-value="(v) => setNav('icon', v)" />
                    </Field>
                    <Field :label="__('workflows::messages.nav_order')">
                        <Input type="number" :model-value="nav.order ?? ''" @update:model-value="(v) => setNav('order', v === '' || v === null ? undefined : Number(v))" />
                    </Field>
                </div>
                <Field :label="__('workflows::messages.settings_field_nav_slug')">
                    <Input :model-value="editor.options.slug" disabled />
                </Field>

                <ProblemField :label="__('workflows::messages.settings_field_initial_place')" path="initial_place">
                    <Select
                        adaptive-width
                        :model-value="model.workflow.initial_place"
                        :options="placeOptions"
                        @update:model-value="(v) => editor.api.setInitialPlace(v)"
                    />
                </ProblemField>

                <ProblemField
                    :label="__('workflows::messages.settings_field_progress_orientation')"
                    :instructions="__('workflows::messages.settings_field_progress_orientation_hint')"
                    path="metadata.progress_orientation"
                >
                    <Select
                        adaptive-width
                        :model-value="meta.progress_orientation"
                        :options="editor.options.progressOrientationOptions"
                        @update:model-value="(v) => setMeta('progress_orientation', v)"
                    />
                </ProblemField>

                <!-- Only when auto-detection would fail (no allow_publish place)
                     or an override is stored, so it can be cleared. -->
                <ProblemField
                    v-if="showSeed"
                    :label="__('workflows::messages.seed_published_place')"
                    :instructions="__('workflows::messages.seed_published_place_hint')"
                    path="metadata.seed_published_place"
                >
                    <Select
                        adaptive-width
                        clearable
                        :model-value="meta.seed_published_place"
                        :options="placeOptions"
                        :placeholder="__('workflows::messages.seed_published_place_none')"
                        @update:model-value="(v) => setMeta('seed_published_place', v ?? null)"
                    />
                </ProblemField>
            </div>
        </template>

        <template v-if="section === 'permissions'">
            <div class="space-y-5">
                <Field :label="__('workflows::messages.settings_field_default_assignee_group')">
                    <Select
                        adaptive-width
                        clearable
                        :model-value="meta.default_assignee_group"
                        :options="defaultAssigneeGroupOptions"
                        :placeholder="__('workflows::messages.default_assignee_group_none')"
                        @update:model-value="(v) => setMeta('default_assignee_group', v ?? null)"
                    />
                </Field>
                <Field :label="__('workflows::messages.settings_field_assign_creator')">
                    <Checkbox solo :model-value="!!meta.assign_creator" @update:model-value="(v) => setMeta('assign_creator', v)" />
                </Field>
                <!-- SelfAssignmentGuard: an AUTOMATIC assignment that would land on
                     the clicker alone opens the picker with this audience instead.
                     Off = no block in the YAML. -->
                <ProblemField
                    :label="__('workflows::messages.self_assignment')"
                    :instructions="__('workflows::messages.self_assignment_instructions')"
                    path="metadata.self_assignment"
                >
                    <div class="space-y-3">
                        <Checkbox solo :model-value="!!selfAssignment.by" @update:model-value="toggleSelfAssignment" />
                        <template v-if="selfAssignment.by">
                            <AudiencePicker
                                :by="selfAssignment.by"
                                :handles="selfAssignment.handles"
                                :by-options="audienceOptions"
                                :placeholder="__('workflows::messages.self_assignment_handles_none')"
                                @update:by="(v) => setSelfAssignment({ by: v, handles: [] })"
                                @update:handles="(v) => setSelfAssignment({ handles: v })"
                            />
                            <Textarea
                                :model-value="selfAssignment.hint"
                                :placeholder="__('workflows::messages.self_assignment_hint_default')"
                                :rows="2"
                                @update:model-value="(v) => setSelfAssignment({ hint: v ?? '' })"
                            />
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('workflows::messages.self_assignment_hint_instructions') }}</p>
                        </template>
                    </div>
                </ProblemField>
                <!-- FourEyesGuard: the person who passed the post on last is never a
                     candidate; if nobody else is left, the picker opens with this audience.
                     Off = no block in the YAML. -->
                <ProblemField
                    :label="__('workflows::messages.four_eyes')"
                    :instructions="__('workflows::messages.four_eyes_instructions')"
                    path="metadata.four_eyes"
                >
                    <div class="space-y-3">
                        <Checkbox solo :model-value="!!fourEyes.by" @update:model-value="toggleFourEyes" />
                        <template v-if="fourEyes.by">
                            <AudiencePicker
                                :by="fourEyes.by"
                                :handles="fourEyes.handles"
                                :by-options="audienceOptions"
                                :placeholder="__('workflows::messages.four_eyes_handles_none')"
                                @update:by="(v) => setFourEyes({ by: v, handles: [] })"
                                @update:handles="(v) => setFourEyes({ handles: v })"
                            />
                            <Textarea
                                :model-value="fourEyes.hint"
                                :placeholder="__('workflows::messages.four_eyes_hint_default')"
                                :rows="2"
                                @update:model-value="(v) => setFourEyes({ hint: v ?? '' })"
                            />
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('workflows::messages.four_eyes_hint_instructions') }}</p>
                        </template>
                    </div>
                </ProblemField>
                <ProblemField
                    v-if="!isLocalization"
                    :label="__('workflows::messages.post_creator_groups')"
                    :instructions="postCreatorHint"
                    path="metadata.post_creator_groups"
                >
                    <Select
                        adaptive-width
                        multiple
                        close-on-select
                        clearable
                        :model-value="meta.post_creator_groups ?? []"
                        :options="editor.options.groupOptions"
                        :placeholder="__('workflows::messages.post_creator_groups_none')"
                        @update:model-value="(v) => setMeta('post_creator_groups', v ?? [])"
                    />
                </ProblemField>
                <ProblemField
                    v-if="!isLocalization"
                    :label="__('workflows::messages.create_transition')"
                    :instructions="__('workflows::messages.create_transition_hint')"
                    path="metadata.create_transition"
                >
                    <Select
                        adaptive-width
                        clearable
                        :model-value="meta.create_transition"
                        :options="transitionOptions"
                        :placeholder="__('workflows::messages.create_transition_none')"
                        @update:model-value="(v) => setMeta('create_transition', v ?? null)"
                    />
                </ProblemField>
                <ProblemField :label="__('workflows::messages.assigner_groups')" :instructions="assignerHint" path="metadata.assigner_groups">
                    <Select
                        adaptive-width
                        multiple
                        close-on-select
                        clearable
                        :model-value="meta.assigner_groups ?? []"
                        :options="editor.options.groupOptions"
                        :placeholder="__('workflows::messages.assigner_groups_none')"
                        @update:model-value="(v) => setMeta('assigner_groups', v ?? [])"
                    />
                </ProblemField>
            </div>
        </template>

        <!-- Only what matters for THIS kind of localization manager: offering
             plain_language_site in a translation manager would let a dropdown
             swap the two managers' roles. -->
        <template v-if="section === 'localization' && isLocalization">
            <div class="space-y-5">
                <p class="text-sm font-medium">
                    {{ isPlainLanguage ? __('workflows::messages.plain_language_settings') : __('workflows::messages.translation_settings') }}
                </p>
                <ProblemField
                    :label="__('workflows::messages.translation_source_site')"
                    :instructions="__('workflows::messages.translation_source_site_hint')"
                    path="metadata.translation_source_site"
                >
                    <Select
                        adaptive-width
                        clearable
                        :model-value="meta.translation_source_site"
                        :options="editor.options.siteOptions"
                        :placeholder="__('workflows::messages.translation_source_site_none')"
                        @update:model-value="(v) => setMeta('translation_source_site', v ?? null)"
                    />
                </ProblemField>
                <template v-if="isPlainLanguage">
                    <ProblemField
                        :label="__('workflows::messages.plain_language_site')"
                        :instructions="__('workflows::messages.plain_language_site_hint')"
                        path="metadata.plain_language_site"
                    >
                        <Select
                            adaptive-width
                            clearable
                            :model-value="meta.plain_language_site"
                            :options="editor.options.siteOptions"
                            :placeholder="__('workflows::messages.plain_language_site_none')"
                            @update:model-value="(v) => setMeta('plain_language_site', v ?? null)"
                        />
                    </ProblemField>
                    <ProblemField
                        :label="__('workflows::messages.plain_language_completed_place')"
                        :instructions="__('workflows::messages.plain_language_completed_place_hint')"
                        path="metadata.plain_language_completed_place"
                    >
                        <Select
                            adaptive-width
                            clearable
                            :model-value="meta.plain_language_completed_place"
                            :options="placeOptions"
                            :placeholder="__('workflows::messages.plain_language_completed_place_none')"
                            @update:model-value="(v) => setMeta('plain_language_completed_place', v ?? null)"
                        />
                    </ProblemField>
                    <ProblemField
                        :label="__('workflows::messages.plain_language_failed_place')"
                        :instructions="__('workflows::messages.plain_language_failed_place_hint')"
                        path="metadata.plain_language_failed_place"
                    >
                        <Select
                            adaptive-width
                            clearable
                            :model-value="meta.plain_language_failed_place"
                            :options="placeOptions"
                            :placeholder="__('workflows::messages.plain_language_failed_place_none')"
                            @update:model-value="(v) => setMeta('plain_language_failed_place', v ?? null)"
                        />
                    </ProblemField>
                    <ProblemField
                        :label="__('workflows::messages.plain_language_skip_fields')"
                        :instructions="__('workflows::messages.plain_language_skip_fields_hint')"
                        path="metadata.plain_language_skip_fields"
                    >
                        <Input
                            :model-value="skipFieldsText"
                            placeholder="anchor.anchor_id, *.embed_code"
                            @update:model-value="updateSkipFields"
                        />
                    </ProblemField>
                </template>
                <ProblemField
                    v-else
                    :label="__('workflows::messages.translation_source_pick')"
                    :instructions="__('workflows::messages.translation_source_pick_hint')"
                    path="metadata.translation_source_pick"
                >
                    <Checkbox solo :model-value="!!meta.translation_source_pick" @update:model-value="(v) => setMeta('translation_source_pick', v)" />
                </ProblemField>

                <!-- Who sees the source column how: mode + audience per rule. A rule
                     without handles is dropped on save. -->
                <ProblemField
                    :label="__('workflows::messages.translation_source_view')"
                    :instructions="__('workflows::messages.translation_source_view_hint')"
                    path="metadata.translation_source_view"
                >
                    <div class="space-y-2">
                        <div
                            v-for="(rule, i) in meta.translation_source_view"
                            :key="i"
                            class="space-y-2 rounded-md border border-gray-200 p-2 dark:border-gray-700!"
                        >
                            <div class="flex items-center gap-2">
                                <Select adaptive-width v-model="rule.mode" :options="sourceViewModeOptions" size="sm" class="flex-1" />
                                <Button
                                    icon="trash"
                                    variant="ghost"
                                    size="sm"
                                    icon-only
                                    :aria-label="__('workflows::messages.translation_source_view_remove')"
                                    @click="meta.translation_source_view.splice(i, 1)"
                                />
                            </div>
                            <AudiencePicker
                                :by="rule.by"
                                :handles="rule.handles"
                                :by-options="audienceOptions"
                                :placeholder="__('workflows::messages.translation_source_view_handles_none')"
                                @update:by="(v) => (rule.by = v)"
                                @update:handles="(v) => (rule.handles = v)"
                            />
                        </div>
                        <p v-if="!meta.translation_source_view?.length" class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('workflows::messages.translation_source_view_empty') }}
                        </p>
                        <Button
                            variant="secondary"
                            size="sm"
                            icon="plus"
                            :text="__('workflows::messages.translation_source_view_add')"
                            @click="meta.translation_source_view.push({ mode: 'hidden', by: 'group', handles: [] })"
                        />
                    </div>
                </ProblemField>

                <!-- Where a version falls back to when its source goes to draft. -->
                <ProblemField
                    :label="__('workflows::messages.source_draft_place')"
                    :instructions="`${__('workflows::messages.source_draft_intro')} ${__('workflows::messages.source_draft_hint')}`"
                    path="metadata.source_draft_place"
                >
                    <Select
                        adaptive-width
                        :model-value="meta.source_draft_place"
                        :options="sourceDraftPlaceOptions"
                        @update:model-value="(v) => setMeta('source_draft_place', v ?? null)"
                    />
                </ProblemField>

                <div class="space-y-1 border-t border-gray-200 pt-4 dark:border-gray-700!">
                    <p class="mb-2 text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('workflows::messages.translation_apis') }}</p>
                    <template v-if="isPlainLanguage">
                        <EnvStatusLine :status="editor.options.envStatus.plain_language_url" />
                        <EnvStatusLine :status="editor.options.envStatus.plain_language_token" />
                    </template>
                    <EnvStatusLine v-else :status="editor.options.envStatus.deepl" />
                </div>
            </div>
        </template>

        <template v-if="section === 'collections'">
            <div class="space-y-3" data-path="collections">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('workflows::messages.collections_intro') }}</p>
                <Select
                    adaptive-width
                    multiple
                    close-on-select
                    clearable
                    :model-value="model.workflow.collections"
                    :options="editor.options.collectionOptions"
                    :placeholder="__('workflows::messages.select_collections')"
                    @update:model-value="(v) => editor.api.updateWorkflow({ collections: v ?? [] })"
                />
                <p v-if="err('collections')" class="text-sm text-red-600 dark:text-red-400">{{ err('collections') }}</p>
                <!-- The alphabetically first workflow owns a shared collection; a
                     non-owner only governs entries with a stored state. -->
                <ul v-if="foreignCollections.length" class="space-y-1">
                    <li v-for="item in foreignCollections" :key="item.handle" class="flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <Icon name="info" class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ __('workflows::messages.collections_owned_by', { collection: item.label, workflow: item.owner }) }}</span>
                    </li>
                </ul>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('workflows::messages.collections_hint') }}</p>
            </div>
        </template>

        <!-- Which fields of the entries are this workflow's content, in reading
             order (`metadata.content_fields`). The choice is the union of the
             readable fields of the selected collections; nothing picked means
             each collection reads its auto-detected field. -->
        <template v-if="section === 'content'">
            <div class="space-y-5">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('workflows::messages.content_fields_intro') }}</p>
                <ProblemField
                    :label="__('workflows::messages.content_fields_label')"
                    :instructions="__('workflows::messages.content_fields_hint')"
                    path="metadata.content_fields"
                >
                    <div class="space-y-3">
                        <Select
                            adaptive-width
                            multiple
                            close-on-select
                            clearable
                            :model-value="contentFields"
                            :options="contentFieldOptions"
                            :placeholder="__('workflows::messages.content_fields_placeholder')"
                            @update:model-value="(v) => setMeta('content_fields', [...(v ?? [])])"
                        />
                        <ContentFieldList
                            v-if="contentFields.length"
                            :model-value="contentFields"
                            :choices="contentFieldChoiceList"
                            @update:model-value="(v) => setMeta('content_fields', v)"
                        />
                    </div>
                </ProblemField>
                <p v-if="!(model.workflow.collections ?? []).length" class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.content_fields_no_collections') }}
                </p>
                <!-- Empty choice: what each collection reads instead. -->
                <div v-else-if="!contentFields.length" class="space-y-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('workflows::messages.content_fields_auto_intro') }}</p>
                    <ul class="space-y-1">
                        <li v-for="item in autoFields" :key="item.collection" class="flex items-start gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                            <Icon
                                :name="item.handle ? 'magic-wand-action' : 'alert-warning-exclamation-mark'"
                                class="mt-0.5 size-3.5 shrink-0"
                                :class="item.handle ? 'text-gray-400' : 'text-amber-600 dark:text-amber-400'"
                            />
                            <span>
                                <span class="font-medium">{{ collectionLabel(item.collection) }}</span> —
                                <template v-if="item.handle">
                                    {{ __('workflows::messages.content_fields_auto', { field: item.label }) }}
                                    <code class="font-mono text-2xs text-gray-500">{{ item.handle }}</code>
                                </template>
                                <span v-else class="text-amber-700 dark:text-amber-300">{{ __('workflows::messages.content_fields_auto_none') }}</span>
                            </span>
                        </li>
                    </ul>
                </div>
                <p v-else class="text-xs text-gray-500 dark:text-gray-400">{{ __('workflows::messages.content_fields_order_hint') }}</p>
            </div>
        </template>

        <!-- Ageing belongs to the workflow that owns the posts; a localization
             workflow reacts to that one's deadline instead. -->
        <template v-if="section === 'sla' && !isLocalization">
            <div class="space-y-5">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('workflows::messages.sla_intro') }}</p>
                <div v-for="stage in SLA_STAGES" :key="stage" class="space-y-3 rounded-md border border-gray-200 p-3 dark:border-gray-700!">
                    <div class="grid grid-cols-2 gap-3">
                        <ProblemField :label="__(`workflows::messages.sla_${stage}_days`)" :path="`metadata.sla.${stage}_days`">
                            <Input
                                type="number"
                                min="0"
                                :model-value="sla[`${stage}_days`] ?? ''"
                                :placeholder="__('workflows::messages.sla_placeholder_disabled')"
                                @update:model-value="(v) => (sla[`${stage}_days`] = v === '' || v === null ? null : Number(v))"
                            />
                        </ProblemField>
                        <ProblemField :label="__(`workflows::messages.sla_${stage}_place`)" :path="`metadata.sla.${stage}_place`">
                            <Select
                                adaptive-width
                                :model-value="sla[`${stage}_place`]"
                                :options="slaPlaceOptions"
                                @update:model-value="(v) => (sla[`${stage}_place`] = v ?? null)"
                            />
                        </ProblemField>
                    </div>
                    <!-- Nobody clicks into an ageing stage — the scheduler moves it —
                         so who takes over is set here. Empty = keep. -->
                    <ProblemField :label="__(`workflows::messages.sla_${stage}_assign`)" :path="`metadata.sla.${stage}_assign`">
                        <AudiencePicker
                            :by="sla[`${stage}_assign`]?.by ?? null"
                            :handles="sla[`${stage}_assign`]?.handles ?? []"
                            :by-options="slaAssignByOptions"
                            :placeholder="__('workflows::messages.sla_assign_handles_none')"
                            @update:by="(v) => (assignOf(stage).by = v)"
                            @update:handles="(v) => (assignOf(stage).handles = v)"
                        />
                    </ProblemField>
                </div>
                <!-- What the writer says about the block as a whole. -->
                <p v-if="slaBlockError" class="text-sm text-red-600 dark:text-red-400" data-path="metadata.sla">{{ slaBlockError }}</p>
                <div class="space-y-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('workflows::messages.sla_hint') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('workflows::messages.sla_place_hint') }}</p>
                    <p class="flex items-start gap-1.5 text-xs text-gray-400 dark:text-gray-500">
                        <Icon name="time-clock" class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ __('workflows::messages.sla_clock_hint') }}</span>
                    </p>
                    <p class="flex items-start gap-1.5 text-xs text-gray-400 dark:text-gray-500">
                        <Icon name="time-clock" class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ __('workflows::messages.sla_cron_hint') }}</span>
                    </p>
                </div>
            </div>
        </template>

        <template v-if="section === 'order'">
            <div class="space-y-5">
                <ProblemField :label="__('workflows::messages.places')" :instructions="__('workflows::messages.editor_order_places_hint')" path="places">
                    <OrderList kind="place" />
                </ProblemField>
                <ProblemField :label="__('workflows::messages.transitions')" :instructions="__('workflows::messages.editor_order_transitions_hint')" path="transitions">
                    <OrderList kind="transition" />
                </ProblemField>
            </div>
        </template>

        <template v-if="section === 'advanced'">
            <div class="space-y-5">
                <Field :label="__('workflows::messages.editor_reset_positions')" :instructions="__('workflows::messages.editor_reset_positions_hint')">
                    <Button size="sm" icon="hierarchy" :text="__('workflows::messages.editor_reset_positions')" @click="$emit('reset-positions')" />
                </Field>
                <Field :label="__('workflows::messages.editor_payload_preview')" :instructions="__('workflows::messages.editor_payload_preview_hint')">
                    <CodeEditor
                        read-only
                        mode="application/json"
                        :allow-mode-selection="false"
                        :show-mode-label="false"
                        :model-value="payloadPreview"
                    />
                </Field>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue';
import { Button, Checkbox, CodeEditor, Field, Icon, Input, Select, Textarea } from '@statamic/cms/ui';
import EnvStatusLine from '../../../components/EnvStatusLine.vue';
import { SLA_STAGES } from '../../model/constants.js';
import { hasPublishPlace, isLocalizationWorkflow, isPlainLanguageWorkflow } from '../../model/actionRules.js';
import { keyOf } from '../../model/editorModel.js';
import { placeDisplayName } from '../../model/labels.js';
import { EDITOR_CONTEXT } from '../context.js';
import AudiencePicker from '../shared/AudiencePicker.vue';
import ProblemField from '../shared/ProblemField.vue';
import OrderList from '../panels/OrderList.vue';
import ContentFieldList from './ContentFieldList.vue';
import { autoContentFields, contentFieldChoices, contentFieldSelectOptions } from '../../model/contentFields.js';

const props = defineProps({
    // 'general' | 'permissions' | 'localization' | 'collections' | 'content' | 'sla' | 'order' | 'advanced'
    section: { type: String, default: 'general' },
});

defineEmits(['reset-positions']);

const editor = inject(EDITOR_CONTEXT);

const model = editor.api.model;
const meta = computed(() => model.workflow.metadata);
const nav = computed(() => model.workflow.metadata.nav ?? {});
const sla = computed(() => model.workflow.metadata.sla);

const setMeta = (key, value) => editor.api.updateWorkflow({ metadata: { [key]: value } });

// plain_language_skip_fields as a comma-separated input. The typed text is kept
// locally so "a, " survives until the next handle is typed; the model only ever
// holds the trimmed, non-empty list.
const splitList = (value) => String(value ?? '').split(',').map((item) => item.trim()).filter(Boolean);
const skipFieldsText = ref((meta.value.plain_language_skip_fields ?? []).join(', '));
watch(
    () => meta.value.plain_language_skip_fields,
    (list) => {
        if (splitList(skipFieldsText.value).join(',') !== (list ?? []).join(',')) {
            skipFieldsText.value = (list ?? []).join(', ');
        }
    },
);
function updateSkipFields(value) {
    skipFieldsText.value = value ?? '';
    setMeta('plain_language_skip_fields', splitList(value));
}

// Live validation (useValidation.js): the errors at a field's path, as one string.
const err = (path) => editor.validation?.errorFor(path);

// Errors on `metadata.sla` itself (the server's, which has no finer path) —
// minus what a stage's field already shows.
const slaBlockError = computed(() => {
    const found = editor.validation?.problemsExactly('metadata.sla').filter((p) => p.level === 'error') ?? [];
    return found.length ? [...new Set(found.map((p) => p.message))].join(' ') : null;
});

function setNav(key, value) {
    const next = { ...(model.workflow.metadata.nav ?? {}) };
    if (value === undefined) {
        delete next[key];
    } else {
        next[key] = value;
    }
    setMeta('nav', next);
}

// normalize() always gives the block an object; `by: null` means the guard is off.
const selfAssignment = computed(() => meta.value.self_assignment ?? { by: null, handles: [], hint: '' });
const setSelfAssignment = (patch) => setMeta('self_assignment', { ...selfAssignment.value, ...patch });
// On starts with groups, the usual answer ("project leads"); off clears it all.
const toggleSelfAssignment = (on) => setMeta('self_assignment', on
    ? { by: 'group', handles: [], hint: '' }
    : { by: null, handles: [], hint: '' });

// The four-eyes guard, same shape and same switch.
const fourEyes = computed(() => meta.value.four_eyes ?? { by: null, handles: [], hint: '' });
const setFourEyes = (patch) => setMeta('four_eyes', { ...fourEyes.value, ...patch });
const toggleFourEyes = (on) => setMeta('four_eyes', on
    ? { by: 'group', handles: [], hint: '' }
    : { by: null, handles: [], hint: '' });

// normalize() always gives each stage an assign object; a hand-made one may not.
function assignOf(stage) {
    sla.value[`${stage}_assign`] ??= { by: null, handles: [] };
    return sla.value[`${stage}_assign`];
}

const view = computed(() => editor.api.view.value);
const isLocalization = computed(() => isLocalizationWorkflow(view.value));
const isPlainLanguage = computed(() => isPlainLanguageWorkflow(view.value));
const showSeed = computed(() => !hasPublishPlace(view.value) || !!meta.value.seed_published_place);

const placeOptions = computed(() => model.places.map((p) => ({
    value: p.id,
    label: `${placeDisplayName(view.value, keyOf(p), editor.labelCtx)} (${keyOf(p)})`,
})));

const slaPlaceOptions = computed(() => [
    { value: null, label: __('workflows::messages.sla_place_none') },
    ...placeOptions.value,
]);

// Only into a step that takes the version offline — the writer checks the same.
const sourceDraftPlaceOptions = computed(() => [
    { value: null, label: __('workflows::messages.source_draft_place_none') },
    ...placeOptions.value.filter((o) => model.places.find((p) => p.id === o.value)?.metadata?.allow_publish !== true),
]);

// Real transitions only — the injected system steps are driven by saves.
const transitionOptions = computed(() => model.transitions
    .filter((t) => !t.metadata?.system)
    .map((t) => ({ value: t.id, label: `${t.metadata?.label || keyOf(t)} (${keyOf(t)})` })));

// A stored group that no longer exists is listed rather than silently cleared.
const defaultAssigneeGroupOptions = computed(() => {
    const current = meta.value.default_assignee_group;
    const options = editor.options.groupOptions ?? [];
    return current && !options.some((o) => o.value === current) ? [...options, { value: current, label: current }] : options;
});

const postCreatorHint = computed(() => (editor.options.postCreatorFallback?.length
    ? __('workflows::messages.post_creator_groups_hint_fallback', { groups: editor.options.postCreatorFallback.join(', ') })
    : __('workflows::messages.post_creator_groups_hint')));

const assignerHint = computed(() => (editor.options.assignerFallback?.length
    ? __('workflows::messages.assigner_groups_hint_fallback', { groups: editor.options.assignerFallback.join(', ') })
    : __('workflows::messages.assigner_groups_hint')));

// `collectionOwners` is a snapshot from page load: a collection added in this
// session has no owner yet and correctly stays out.
const foreignCollections = computed(() => (model.workflow.collections ?? [])
    .filter((handle) => {
        const owner = editor.options.collectionOwners?.[handle];
        return owner && owner !== editor.options.workflow;
    })
    .map((handle) => ({
        handle,
        label: editor.options.collectionOptions.find((o) => o.value === handle)?.label ?? handle,
        owner: editor.options.collectionOwners[handle],
    })));

const collectionLabel = (handle) => editor.options.collectionOptions.find((o) => o.value === handle)?.label ?? handle;

// The content section. A hand-written non-list value shows as nothing picked;
// the rule (rules.js) says what is wrong with it.
const contentFields = computed(() => (Array.isArray(meta.value.content_fields) ? meta.value.content_fields : []));
const contentFieldChoiceList = computed(() => contentFieldChoices(editor.options.contentFieldOptions ?? {}, model.workflow.collections));
const contentFieldOptions = computed(() => contentFieldSelectOptions(
    editor.options.contentFieldOptions ?? {},
    model.workflow.collections,
    contentFields.value,
    {
        onlyInText: (collections) => __('workflows::messages.content_fields_only_in', { collections: collections.map(collectionLabel).join(', ') }),
        staleText: (handle) => `${handle} — ${__('workflows::messages.content_fields_stale')}`,
    },
));
const autoFields = computed(() => autoContentFields(
    editor.options.contentFieldOptions ?? {},
    editor.options.contentFieldAuto ?? {},
    model.workflow.collections,
));

const sourceViewModeOptions = computed(() => [
    { value: 'hidden', label: __('workflows::messages.translation_source_view_mode_hidden') },
    { value: 'collapsed', label: __('workflows::messages.translation_source_view_mode_collapsed') },
]);

const audienceOptions = computed(() => [
    { value: 'group', label: __('workflows::messages.assignment_criterion_group') },
    { value: 'role', label: __('workflows::messages.assignment_criterion_role') },
    { value: 'user', label: __('workflows::messages.translation_source_view_by_user') },
]);

const slaAssignByOptions = computed(() => [
    { value: null, label: __('workflows::messages.sla_assign_keep') },
    ...audienceOptions.value,
]);

// What a save would send right now — read-only, for the curious and for bug
// reports. Only computed while that section is open.
const payloadPreview = computed(() => (props.section === 'advanced'
    ? JSON.stringify(editor.api.payload(), null, 2)
    : ''));
</script>
