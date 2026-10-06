<template>
    <!-- What DefinitionLoader adds at read time (pending_revision and its two
         system steps): shown so the graph matches what runs, never editable. -->
    <div
        class="wf-ghost"
        :class="data.kind === 'transition' ? 'wf-ghost--transition' : null"
        :title="__('workflows::messages.editor_ghost_hint')"
    >
        <Handle type="target" :position="Position.Left" :connectable="false" />
        <span class="max-w-full truncate text-xs font-medium">{{ data.label }}</span>
        <code class="max-w-full truncate font-mono text-2xs opacity-80">{{ data.handle }}</code>
        <Handle type="source" :position="Position.Right" :connectable="false" />
    </div>
</template>

<script setup>
import { Handle, Position } from '@vue-flow/core';

// Vue Flow passes every NodeProps field; only the declared ones are used, the
// rest must not land on the root element as attributes.
defineOptions({ inheritAttrs: false });

defineProps({
    id: { type: String, required: true },
    // { kind: 'place'|'transition', handle, label }
    data: { type: Object, required: true },
});
</script>
