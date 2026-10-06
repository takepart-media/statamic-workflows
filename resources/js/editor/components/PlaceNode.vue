<template>
    <div :class="problemClass" class="wf-place" :title="data.handle">
        <!-- Live validation: red ring + count for errors, amber for warnings. -->
        <span
            v-if="problems.errors || problems.warnings"
            class="wf-node-problems"
            :class="problems.errors ? 'wf-node-problems--error' : 'wf-node-problems--warning'"
            role="img"
            :title="problemTitle"
            :aria-label="problemTitle"
        >{{ problems.errors || problems.warnings }}</span>
        <!-- Lines are drawn from an output to an input, never the other way. -->
        <Handle type="target" :position="Position.Left" :connectable-start="false" />
        <span class="wf-place-stripe" :class="colorClasses.dot" aria-hidden="true" />
        <div class="wf-place-body">
            <div class="flex items-start gap-2">
                <span class="min-w-0 flex-1 truncate text-sm font-semibold">{{ data.label }}</span>
                <span class="wf-node-chip font-mono" :title="__('workflows::messages.editor_order_hint')">#{{ data.order }}</span>
            </div>
            <div class="flex items-center gap-2">
                <code class="wf-node-muted min-w-0 flex-1 truncate font-mono text-2xs">{{ data.handle }}</code>
                <span
                    v-if="data.usage !== null"
                    class="wf-node-chip"
                    :title="__('workflows::messages.editor_usage_hint')"
                >
                    <Icon name="entry" class="size-3" />
                    {{ data.usage }}
                </span>
            </div>
            <div v-if="data.roles.length || data.actions.length" class="flex flex-wrap items-center gap-1">
                <Badge
                    v-for="role in data.roles"
                    :key="role"
                    size="sm"
                    :color="ROLE_COLORS[role] ?? 'default'"
                    :text="roleText(role)"
                    :title="roleHint(role)"
                />
                <span v-if="data.actions.length" class="wf-node-muted ml-auto flex items-center gap-1">
                    <Icon
                        v-for="action in data.actions"
                        :key="action.type"
                        :name="action.icon"
                        class="size-3.5"
                        :title="actionLabel(action)"
                        :aria-label="actionLabel(action)"
                    />
                </span>
            </div>
        </div>
        <Handle type="source" :position="Position.Right" />
        <!-- n8n's "+": add the next step right here (also Tab). -->
        <button
            v-if="editor.openAddMenu"
            type="button"
            class="wf-node-add nodrag nopan"
            :aria-label="__('workflows::messages.editor_add_next')"
            :title="__('workflows::messages.editor_add_next')"
            @pointerdown.stop
            @dblclick.stop
            @click.stop="editor.openAddMenu(id)"
        >
            <Icon name="plus" class="size-3" />
        </button>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Handle, Position } from '@vue-flow/core';
import { Badge, Icon } from '@statamic/cms/ui';
import { placeColorClasses } from '../../support/placeColors.js';
import { stateColor } from '../../support/states.js';
import { SHARED_ACTION_TYPES } from '../model/constants.js';
import { EDITOR_CONTEXT } from './context.js';
import { useNodeProblems } from './nodeProblems.js';

// Vue Flow passes every NodeProps field; only the declared ones are used, the
// rest must not land on the root element as attributes.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    id: { type: String, required: true },
    // nodeInfo.js placeInfo()
    data: { type: Object, required: true },
    selected: { type: Boolean, default: false },
});

const editor = inject(EDITOR_CONTEXT, { customActions: { place: [] } });

const { problems, problemClass, problemTitle } = useNodeProblems(editor, () => props.id);

const colorClasses = computed(() => placeColorClasses(props.data.color || stateColor(props.data.handle)));

// Badge colour per role. Start/publish carry the flow's two ends; the SLA stages
// use the warning colours the SLA places themselves default to.
const ROLE_COLORS = {
    start: 'blue',
    publish: 'green',
    branch: 'default',
    gate: 'purple',
    sla_1: 'amber',
    sla_2: 'red',
    seed: 'teal',
    source_draft: 'default',
    pl_done: 'green',
    pl_failed: 'red',
};

// What each badge stands for, by the name of the setting that makes it — the
// keys the form already uses for those fields.
const ROLE_HINTS = {
    start: 'settings_field_initial_place',
    publish: 'settings_field_allow_publish',
    branch: 'settings_field_branch',
    gate: 'editor_badge_gate_hint',
    sla_1: 'sla_needs_update_place',
    sla_2: 'sla_outdated_place',
    seed: 'seed_published_place',
    source_draft: 'source_draft',
    pl_done: 'plain_language_completed_place',
    pl_failed: 'plain_language_failed_place',
};

function roleText(role) {
    return role === 'branch'
        ? __('workflows::messages.settings_field_branch')
        : __(`workflows::messages.editor_badge_${role}`);
}

function roleHint(role) {
    return __(`workflows::messages.${ROLE_HINTS[role]}`);
}

// Same label source as the form's type Select: the language file for built-in
// types, the server-translated declaration for project ones.
function actionLabel(action) {
    if (action.custom) {
        return (editor.customActions?.place ?? []).find((a) => a.value === action.type)?.label ?? action.type;
    }
    const prefix = SHARED_ACTION_TYPES.includes(action.type) ? 'action_type' : 'place_action_type';
    return __(`workflows::messages.${prefix}_${action.type}`);
}
</script>
