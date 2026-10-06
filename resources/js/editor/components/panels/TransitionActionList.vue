<template>
    <!-- Steps (dialogs) or preconditions that run between the click and the
         transition, plus the mail after it. Only one DIALOG fits in that moment,
         so the option lists come from actionRules.actionTypeOptionsFor() — a type
         that is unavailable is left out instead of failing on save. -->
    <div class="space-y-2">
        <div
            v-for="(action, i) in transition.metadata.actions"
            :key="i"
            class="space-y-2 rounded-md border border-gray-200 p-2 dark:border-gray-700!"
        >
            <div class="flex items-start gap-2">
                <Select
                    adaptive-width
                    v-model="action.type"
                    :options="typeOptions(action)"
                    size="sm"
                    class="min-w-0 flex-1"
                />
                <Button
                    variant="ghost"
                    icon="trash"
                    size="sm"
                    icon-only
                    :aria-label="__('workflows::messages.delete')"
                    @click="transition.metadata.actions.splice(i, 1)"
                />
            </div>

            <!-- Which site this gate watches. -->
            <Select
                v-if="action.type === 'target_missing'"
                adaptive-width
                v-model="action.site"
                :options="editor.options.siteOptions"
                clearable
                :placeholder="__('workflows::messages.action_target_missing_site_none')"
            />

            <template v-else-if="action.type === 'mail'">
                <Select
                    adaptive-width
                    v-model="action.mail_type"
                    :options="editor.options.mailTypes"
                    size="sm"
                    :aria-label="__('workflows::messages.action_mail_type_label')"
                />
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.action_mail_hint') }}
                </p>
                <MailerStatusLine :status="editor.options.mailStatus" />
            </template>

            <!-- The mode changes what the action IS (a dialog or a silent
                 assignment); the criteria only who it concerns. -->
            <template v-else-if="action.type === 'user_assignment'">
                <div class="flex flex-wrap items-start gap-2">
                    <Select
                        adaptive-width
                        v-model="action.mode"
                        :options="modeOptions"
                        size="sm"
                        class="min-w-40 flex-1"
                        :aria-label="__('workflows::messages.assignment_mode')"
                    />
                    <Select
                        adaptive-width
                        v-model="action.match"
                        :options="matchOptions"
                        size="sm"
                        class="min-w-40 flex-1"
                    />
                </div>
                <div class="border-t border-gray-200 pt-2 dark:border-gray-700!">
                    <CriteriaBuilder :action="action" />
                </div>
            </template>

            <CustomActionFields :action="action" scope="transition" />
        </div>

        <p v-if="!transition.metadata.actions?.length" class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('workflows::messages.editor_no_actions') }}
        </p>

        <Dropdown v-if="typeOptions(null).length">
            <template #trigger>
                <Button variant="secondary" icon="plus" size="sm" :text="__('workflows::messages.action_add')" />
            </template>
            <DropdownMenu>
                <DropdownItem
                    v-for="option in typeOptions(null)"
                    :key="option.value"
                    :text="option.label"
                    @click="add(option.value)"
                />
            </DropdownMenu>
        </Dropdown>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Button, Dropdown, DropdownItem, DropdownMenu, Select } from '@statamic/cms/ui';
import MailerStatusLine from '../../../components/MailerStatusLine.vue';
import { ASSIGNMENT_MATCHES, ASSIGNMENT_MODES } from '../../model/constants.js';
import { actionTypeOptionsFor } from '../../model/actionRules.js';
import { keyOf } from '../../model/editorModel.js';
import { EDITOR_CONTEXT } from '../context.js';
import CriteriaBuilder from '../shared/CriteriaBuilder.vue';
import CustomActionFields from '../shared/CustomActionFields.vue';

const props = defineProps({
    // A transition entry of the editor model.
    transition: { type: Object, required: true },
});

const editor = inject(EDITOR_CONTEXT);

const modeOptions = computed(() =>
    ASSIGNMENT_MODES.map((value) => ({ value, label: __(`workflows::messages.assignment_mode_${value}`) })),
);
const matchOptions = computed(() =>
    ASSIGNMENT_MATCHES.map((value) => ({ value, label: __(`workflows::messages.assignment_match_${value}`) })),
);

// The view shares this transition's `metadata`, so `current` is recognised.
function typeOptions(current) {
    return actionTypeOptionsFor(editor.api.view.value, keyOf(props.transition), current, editor.ruleCtx);
}

// The type comes from the dropdown, but is checked against the live list: an
// entry that dropped out in the meantime must not add something the writer
// would reject. A new assignment starts manual and empty.
function add(wanted) {
    props.transition.metadata.actions ??= [];
    const available = typeOptions(null).map((o) => o.value);
    const type = available.includes(wanted) ? wanted : available[0];
    if (!type) return;
    props.transition.metadata.actions.push({
        type, mode: 'manual', match: 'all', criteria: [], site: null, values: {},
        ...(type === 'mail' ? { mail_type: 'assigned' } : {}),
    });
}
</script>
