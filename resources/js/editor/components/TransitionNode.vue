<template>
    <div class="wf-transition" :data-variant="data.variant" :title="data.handle">
        <Handle type="target" :position="Position.Left" :connectable="false" />
        <div class="flex max-w-full items-center gap-1.5">
            <span class="truncate text-xs font-semibold">{{ data.label }}</span>
            <Badge
                v-for="badge in data.badges"
                :key="badge"
                size="sm"
                :color="BADGE_COLORS[badge]"
                :text="__(`workflows::messages.editor_badge_${badge}`)"
                :title="badgeHint(badge)"
            />
        </div>
        <code v-if="data.label !== data.handle" class="max-w-full truncate font-mono text-2xs opacity-70">{{ data.handle }}</code>
        <div v-if="data.chips.length" class="flex flex-wrap justify-center gap-1">
            <span
                v-for="(chip, i) in data.chips"
                :key="`${chip.code}-${i}`"
                class="wf-node-chip"
                :class="{ 'wf-node-chip--warn': chip.code === 'target_missing' }"
                :title="chipHint(chip)"
            >
                <Icon v-if="CHIP_ICONS[chip.code]" :name="CHIP_ICONS[chip.code]" class="size-3" />
                {{ chipText(chip) }}
            </span>
        </div>
        <Handle type="source" :position="Position.Right" :connectable="false" />
    </div>
</template>

<script setup>
import { inject } from 'vue';
import { Handle, Position } from '@vue-flow/core';
import { Badge, Icon } from '@statamic/cms/ui';
import { EDITOR_CONTEXT } from './context.js';

// Vue Flow passes every NodeProps field; only the declared ones are used, the
// rest must not land on the root element as attributes.
defineOptions({ inheritAttrs: false });

defineProps({
    id: { type: String, required: true },
    // nodeInfo.js transitionInfo()
    data: { type: Object, required: true },
    selected: { type: Boolean, default: false },
});

const editor = inject(EDITOR_CONTEXT, { customActions: { transition: [] } });

const BADGE_COLORS = { start: 'blue', cta: 'green', system: 'default' };

const CHIP_ICONS = {
    gate: 'padlock-locked',
    assign: 'add-user',
    mail: 'mail',
    target_missing: 'warning-diamond',
    start_localization: 'earth',
    resubmit: 'sync',
    custom: 'puzzle-piece',
};

function chipText(chip) {
    switch (chip.code) {
        case 'gate':
            return __(`workflows::messages.editor_chip_gate_${chip.by}`, { count: chip.count });
        case 'assign':
            return __(`workflows::messages.editor_chip_assign_${chip.mode}`);
        case 'custom':
            return (editor.customActions?.transition ?? []).find((a) => a.value === chip.type)?.label ?? chip.type;
        default:
            return __(`workflows::messages.editor_chip_${chip.code}`);
    }
}

// Long form of a chip: the action's name as the form's type Select shows it.
function chipHint(chip) {
    switch (chip.code) {
        case 'gate':
            return __('workflows::messages.required_by');
        case 'assign':
            return __(`workflows::messages.assignment_mode_${chip.mode}`);
        case 'resubmit':
            return __('workflows::messages.action_type_plain_language_resubmit');
        case 'custom':
            return chip.type;
        default:
            return __(`workflows::messages.action_type_${chip.code}`);
    }
}

function badgeHint(badge) {
    return {
        start: __('workflows::messages.editor_badge_start_transition_hint'),
        cta: __('workflows::messages.create_transition'),
        system: __('workflows::messages.editor_ghost_hint'),
    }[badge];
}
</script>
