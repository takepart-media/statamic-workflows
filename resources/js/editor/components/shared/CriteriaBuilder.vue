<template>
    <!-- Who a user assignment concerns: a list of criteria plus, one level deep,
         groups with their own match — "in the group AND (category OR entity)".
         Edits the action's `criteria` list in place, like every action row. -->
    <div class="space-y-2">
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('workflows::messages.assignment_criteria_hint') }}
        </p>

        <template v-for="(node, c) in action.criteria" :key="c">
            <div
                v-if="isCriteriaGroup(node)"
                class="space-y-2 rounded-md border border-gray-200 bg-gray-50 p-2 dark:border-gray-700! dark:bg-gray-900!"
            >
                <div class="flex items-center gap-2">
                    <Select
                        adaptive-width
                        v-model="node.match"
                        :options="matchOptions"
                        size="sm"
                        class="flex-1"
                    />
                    <Button
                        variant="ghost"
                        icon="trash"
                        size="sm"
                        icon-only
                        :aria-label="__('workflows::messages.delete')"
                        @click="remove(action, c)"
                    />
                </div>
                <CriterionRow
                    v-for="(inner, ic) in node.criteria"
                    :key="ic"
                    :criterion="inner"
                    @remove="remove(node, ic)"
                />
                <Button
                    variant="ghost"
                    icon="plus"
                    size="sm"
                    :text="__('workflows::messages.assignment_criterion_add')"
                    @click="add(node)"
                />
            </div>
            <CriterionRow v-else :criterion="node" @remove="remove(action, c)" />
        </template>

        <!-- What no criteria means differs per mode — the automatic one is a
             save error rather than a choice. -->
        <p
            v-if="!action.criteria?.length"
            class="text-xs"
            :class="action.mode === 'automatic' ? 'text-red-600 dark:text-red-400!' : 'text-gray-500 dark:text-gray-400!'"
        >
            {{ action.mode === 'automatic'
                ? __('workflows::messages.assignment_criteria_none_automatic')
                : __('workflows::messages.assignment_criteria_none_manual') }}
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                variant="secondary"
                icon="plus"
                size="sm"
                :text="__('workflows::messages.assignment_add_criterion')"
                @click="add(action)"
            />
            <Button
                variant="ghost"
                icon="plus"
                size="sm"
                :text="__('workflows::messages.assignment_add_group')"
                @click="addGroup"
            />
        </div>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, inject } from 'vue';
import { Button, Combobox, Select } from '@statamic/cms/ui';
import { ASSIGNMENT_MATCHES } from '../../model/constants.js';
import { isCriteriaGroup } from '../../model/serialize.js';
import { EDITOR_CONTEXT } from '../context.js';

const props = defineProps({
    // A `user_assignment` action (normalize() shape): { mode, match, criteria }.
    action: { type: Object, required: true },
});

const editor = inject(EDITOR_CONTEXT);

const criteria = computed(() => editor.options.assignmentCriteria ?? []);

const matchOptions = computed(() =>
    ASSIGNMENT_MATCHES.map((value) => ({ value, label: __(`workflows::messages.assignment_match_${value}`) })),
);

const fieldOptions = computed(() => criteria.value.map(({ value, label }) => ({ value, label })));

const definitionOf = (criterion) => criteria.value.find((c) => c.value === criterion?.field) ?? null;

// Only the operators this criterion supports — `matches_entry` exists only where
// the criterion names an entry field to compare against.
function operatorOptions(criterion) {
    return (definitionOf(criterion)?.operators ?? ['is', 'is_not'])
        .map((value) => ({ value, label: __(`workflows::messages.assignment_operator_${value}`) }));
}

// A new row starts on an operator its own criterion accepts.
function newCriterion() {
    return {
        field: criteria.value[0]?.value ?? 'group',
        operator: criteria.value[0]?.operators?.[0] ?? 'is',
        value: null,
    };
}

// `holder` is the action OR a group — both carry a `criteria` list.
function add(holder) {
    holder.criteria ??= [];
    holder.criteria.push(newCriterion());
}

function remove(holder, index) {
    holder.criteria.splice(index, 1);
}

// A group starts with one criterion on `any` — the reason to make one is an OR
// inside an AND.
function addGroup() {
    props.action.criteria ??= [];
    props.action.criteria.push({ match: 'any', criteria: [newCriterion()] });
}

// Switching the criterion can invalidate the operator; reset it and drop the
// value, which belonged to the old criterion.
function onField(criterion, field) {
    criterion.field = field;
    const allowed = definitionOf(criterion)?.operators ?? ['is', 'is_not'];
    if (!allowed.includes(criterion.operator)) criterion.operator = allowed[0] ?? 'is';
    criterion.value = null;
}

// One criterion row: field, operator, value (taggable — a group, role or term
// may be created after the workflow).
const CriterionRow = defineComponent({
    props: { criterion: { type: Object, required: true } },
    emits: ['remove'],
    setup(rowProps, { emit }) {
        return () => {
            const c = rowProps.criterion;
            return h('div', { class: 'flex flex-wrap items-start gap-2' }, [
                h(Select, {
                    adaptiveWidth: true,
                    modelValue: c.field,
                    options: fieldOptions.value,
                    size: 'sm',
                    class: 'min-w-32 flex-1',
                    placeholder: __('workflows::messages.assignment_criterion_placeholder'),
                    'onUpdate:modelValue': (v) => onField(c, v),
                }),
                h(Select, {
                    adaptiveWidth: true,
                    modelValue: c.operator,
                    options: operatorOptions(c),
                    size: 'sm',
                    class: 'w-44 shrink-0',
                    'onUpdate:modelValue': (v) => { c.operator = v; },
                }),
                c.operator !== 'matches_entry'
                    ? h(Combobox, {
                        adaptiveWidth: true,
                        modelValue: c.value,
                        options: definitionOf(c)?.options ?? [],
                        taggable: true,
                        searchable: true,
                        clearable: true,
                        class: 'min-w-32 flex-1',
                        placeholder: __('workflows::messages.assignment_value_placeholder'),
                        'onUpdate:modelValue': (v) => { c.value = v; },
                    })
                    : h('span', { class: 'flex-1' }),
                h(Button, {
                    variant: 'ghost',
                    icon: 'trash',
                    size: 'sm',
                    iconOnly: true,
                    'aria-label': __('workflows::messages.delete'),
                    onClick: () => emit('remove'),
                }),
            ]);
        };
    },
});
</script>
