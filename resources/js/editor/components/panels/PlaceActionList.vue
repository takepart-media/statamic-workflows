<template>
    <!-- Behaviours that apply while a subject sits in this place — see
         Workflow\PlaceActions. A list: a place may want the spinner AND a
         reminder at once. Rows are edited in place. -->
    <div class="space-y-2" :data-path="`place:${place.id}.actions`">
        <ActionProblems :path="`place:${place.id}.actions`" exact />
        <div
            v-for="(action, i) in place.metadata.actions"
            :key="i"
            class="space-y-2 rounded-md border p-2"
            :class="rowHasError(i) ? 'border-red-400 dark:border-red-500!' : 'border-gray-200 dark:border-gray-700!'"
            :data-path="`place:${place.id}.actions.${i}`"
        >
            <div class="flex items-start gap-2">
                <Select
                    adaptive-width
                    :model-value="action.type"
                    :options="typeOptions(action)"
                    size="sm"
                    class="min-w-0 flex-1"
                    @update:model-value="(type) => onType(action, type)"
                />
                <Button
                    variant="ghost"
                    icon="trash"
                    size="sm"
                    icon-only
                    :aria-label="__('workflows::messages.delete')"
                    @click="place.metadata.actions.splice(i, 1)"
                />
            </div>

            <!-- Minutes for the refresh reminder. -->
            <Input
                v-if="action.type === 'refresh_interval'"
                v-model.number="action.minutes"
                type="number"
                min="1"
                max="1440"
                :placeholder="__('workflows::messages.action_refresh_interval_minutes_placeholder')"
            />

            <!-- The same mail picker as on a transition: it is the same action. -->
            <template v-else-if="action.type === 'mail'">
                <Select
                    adaptive-width
                    v-model="action.mail_type"
                    :options="editor.options.mailTypes"
                    size="sm"
                    :aria-label="__('workflows::messages.action_mail_type_label')"
                />
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.action_mail_place_hint') }}
                </p>
                <MailerStatusLine :status="editor.options.mailStatus" />
            </template>

            <!-- Interval in days plus the mail type, preset to `reminder`. -->
            <template v-else-if="action.type === 'reminder'">
                <div class="flex items-start gap-2">
                    <div class="w-24 shrink-0">
                        <Input
                            v-model.number="action.every_days"
                            type="number"
                            min="1"
                            max="365"
                            :aria-label="__('workflows::messages.action_reminder_every_days_label')"
                            :placeholder="__('workflows::messages.action_reminder_every_days_placeholder')"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <Select
                            adaptive-width
                            v-model="action.mail_type"
                            :options="editor.options.mailTypes"
                            size="sm"
                            class="w-full"
                            :aria-label="__('workflows::messages.action_mail_type_label')"
                        />
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.action_reminder_hint', { time: editor.options.reminderTime ?? '08:00' }) }}
                </p>
                <MailerStatusLine :status="editor.options.mailStatus" />
            </template>

            <!-- Days plus one of the transitions that start in THIS place. -->
            <template v-else-if="action.type === 'auto_transition'">
                <div class="flex items-start gap-2">
                    <div class="w-24 shrink-0">
                        <Input
                            v-model.number="action.after_days"
                            type="number"
                            min="1"
                            max="365"
                            :aria-label="__('workflows::messages.action_auto_transition_days_label')"
                            :placeholder="__('workflows::messages.action_reminder_every_days_placeholder')"
                        />
                    </div>
                    <div class="min-w-0 flex-1">
                        <Select
                            adaptive-width
                            v-model="action.transition"
                            :options="autoTransitionOptions"
                            size="sm"
                            class="w-full"
                            :aria-label="__('workflows::messages.action_auto_transition_transition_label')"
                        />
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.action_auto_transition_hint', { time: editor.options.reminderTime ?? '08:00' }) }}
                </p>
            </template>

            <CustomActionFields :action="action" scope="place" />
            <ActionProblems :path="`place:${place.id}.actions.${i}`" />
        </div>

        <p v-if="!place.metadata.actions?.length" class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('workflows::messages.editor_no_actions') }}
        </p>

        <!-- Pick the type first, then add — the list depends on what the place
             already carries. Gone only when nothing is left to add. -->
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
import { Button, Dropdown, DropdownItem, DropdownMenu, Input, Select } from '@statamic/cms/ui';
import MailerStatusLine from '../../../components/MailerStatusLine.vue';
import { placeActionTypeOptionsFor } from '../../model/actionRules.js';
import { autoTransitionOptions as autoOptions } from '../../model/editorModel.js';
import { EDITOR_CONTEXT } from '../context.js';
import CustomActionFields from '../shared/CustomActionFields.vue';
import ActionProblems from './ActionProblems.vue';

const props = defineProps({
    // A place entry of the editor model.
    place: { type: Object, required: true },
});

const editor = inject(EDITOR_CONTEXT);

// Same rule as the form: a type already used on this place drops out, the
// spinner only in the plain-language manager. The view shares this place's
// `metadata`, so the identity check against `current` holds.
function typeOptions(current) {
    return placeActionTypeOptionsFor(editor.api.view.value, props.place, current, editor.ruleCtx);
}

const rowHasError = (i) => !!editor.validation?.errorFor(`place:${props.place.id}.actions.${i}`);

// Values are transition ids — the model's reference currency.
const autoTransitionOptions = computed(() => autoOptions(editor.api.model, props.place.id));

function add(wanted) {
    props.place.metadata.actions ??= [];
    const available = typeOptions(null).map((o) => o.value);
    const type = available.includes(wanted) ? wanted : available[0];
    if (!type) return;
    props.place.metadata.actions.push({
        type, minutes: type === 'refresh_interval' ? 10 : null, values: {},
        ...(type === 'mail' ? { mail_type: 'assigned' } : {}),
        ...(type === 'reminder' ? { every_days: 7, mail_type: 'reminder' } : {}),
        ...(type === 'auto_transition' ? { after_days: 7, transition: null } : {}),
    });
}

// Switching a row to `reminder` / `auto_transition` brings the defaults a new
// row gets — otherwise it would save without its interval.
function onType(action, type) {
    action.type = type;
    if (type === 'auto_transition' && !(Number(action.after_days) >= 1)) action.after_days = 7;
    if (type !== 'reminder') return;
    if (!Number.isFinite(Number(action.every_days)) || action.every_days === null || action.every_days === '') {
        action.every_days = 7;
    }
    if (!action.mail_type || action.mail_type === 'assigned') action.mail_type = 'reminder';
}
</script>
