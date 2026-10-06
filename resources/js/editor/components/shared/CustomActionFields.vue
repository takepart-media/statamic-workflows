<template>
    <!-- The fields of a project-declared action type, rendered from its
         declaration (customActions prop). Same building blocks as everywhere
         else in the editor, so a project field doesn't stand out. -->
    <div v-if="declaration?.fields?.length" class="grid grid-cols-1 gap-3 border-t border-gray-200 pt-2 dark:border-gray-700!">
        <Field v-for="field in declaration.fields" :key="field.handle" :label="field.label">
            <component
                :is="componentFor(field)"
                v-bind="propsFor(field)"
                :model-value="action.values?.[field.handle] ?? emptyFor(field)"
                @update:model-value="(v) => setValue(field, v)"
            />
        </Field>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Combobox, Field, Input } from '@statamic/cms/ui';
import { customActionFor } from '../../model/serialize.js';
import { EDITOR_CONTEXT } from '../context.js';

const props = defineProps({
    action: { type: Object, required: true },
    scope: { type: String, required: true }, // 'transition' | 'place'
});

const editor = inject(EDITOR_CONTEXT);

const declaration = computed(() => customActionFor(editor.customActions, props.scope, props.action.type));

// Four field types, two components — select and multiselect are the same
// Combobox with and without `multiple`.
function componentFor(field) {
    return field.type === 'select' || field.type === 'multiselect' ? Combobox : Input;
}

function propsFor(field) {
    if (field.type === 'number') {
        return { type: 'number', ...(field.min !== null ? { min: field.min } : {}), ...(field.max !== null ? { max: field.max } : {}) };
    }
    if (field.type === 'select' || field.type === 'multiselect') {
        return {
            options: field.options ?? [],
            multiple: field.type === 'multiselect',
            closeOnSelect: true,
            clearable: true,
            taggable: true,
            adaptiveWidth: true,
        };
    }
    return { type: 'text' };
}

// A multiselect needs a real array to push into; everything else binds null so
// the placeholder stays visible.
const emptyFor = (field) => (field.type === 'multiselect' ? [] : null);

// Empty values are removed rather than stored as null — the writer would reject
// a null as the wrong type.
function setValue(field, value) {
    props.action.values ??= {};
    const empty = value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
    if (empty) {
        delete props.action.values[field.handle];
        return;
    }
    props.action.values[field.handle] = field.type === 'number' ? Number(value) : value;
}
</script>
