<template>
    <VueFlow
        :id="flowId"
        class="h-full w-full"
        :node-types="nodeTypes"
        :nodes-connectable="false"
        :edges-updatable="false"
        :delete-key-code="null"
        :min-zoom="0.2"
        :max-zoom="2"
        :aria-label="__('workflows::messages.editor_canvas_label')"
        @nodes-initialized="onNodesInitialized"
    >
        <Background :gap="20" :size="1.25" />
        <MiniMap
            v-if="showMinimap"
            pannable
            zoomable
            :node-class-name="minimapNodeClass"
            :node-border-radius="6"
            :width="180"
            :height="120"
        />
    </VueFlow>
</template>

<script>
let instances = 0;
</script>

<script setup>
import { markRaw } from 'vue';
import { VueFlow, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { MiniMap } from '@vue-flow/minimap';
import { useGraph, watchGraph } from '../composables/useGraph.js';
import { useLayout } from '../composables/useLayout.js';
import PlaceNode from './PlaceNode.vue';
import TransitionNode from './TransitionNode.vue';
import GhostNode from './GhostNode.vue';

const props = defineProps({
    // The handle-keyed editor model (normalize()).
    model: { type: Object, required: true },
    // { places, transitions } DefinitionLoader adds at read time.
    injected: { type: Object, default: () => ({ places: {}, transitions: {} }) },
    // Context for labels.js / nodeInfo.js: labelLocales, labelLocale,
    // shippedPlaceLabels, humanize, stateUsage.
    ctx: { type: Object, default: () => ({}) },
    showSystem: { type: Boolean, default: true },
    showMinimap: { type: Boolean, default: false },
});

// One store per canvas — the id keeps two editors (or a remount) apart. A module
// counter rather than Vue's useId(): the CP's own Vue decides which APIs exist.
const flowId = `wf-editor-${++instances}`;
const flow = useVueFlow(flowId);

const nodeTypes = {
    place: markRaw(PlaceNode),
    transition: markRaw(TransitionNode),
    ghost: markRaw(GhostNode),
};

const graph = useGraph(flow, {
    model: () => props.model,
    injected: () => props.injected,
    showSystem: () => props.showSystem,
    ctx: () => ({ ...props.ctx, injectedTransitions: props.injected?.transitions ?? {} }),
});
const { autoLayout, placeMissing, fitSoon } = useLayout(flow, { belowBand: () => graph.belowBand.value });

// What the next "nodes initialized" (sizes measured) has to do. Layout needs
// real sizes, so it waits for Vue Flow to have rendered the new nodes.
let firstRound = true;
let fullLayout = false;
let fitPending = true;
const unplaced = new Set();

watchGraph(graph, ({ missing }) => {
    if (!missing.length) return;
    const positioned = flow.getNodes.value.filter((n) => n.type !== 'ghost' && !missing.includes(n.id)).length;
    // Nothing stored at all → lay the whole graph out once. Otherwise only the
    // newcomers are placed, next to what they connect to, so the stored
    // arrangement survives.
    if (firstRound && positioned === 0) {
        fullLayout = true;
    } else {
        missing.forEach((id) => unplaced.add(id));
    }
});
firstRound = false;

function onNodesInitialized() {
    if (fullLayout) {
        fullLayout = false;
        unplaced.clear();
        autoLayout({ fit: false });
    } else if (unplaced.size) {
        placeMissing([...unplaced]);
        unplaced.clear();
    }
    if (fitPending) {
        fitPending = false;
        fitSoon();
    }
}

const minimapNodeClass = (node) => `wf-minimap-${node.type}`;

defineExpose({
    zoomIn: () => flow.zoomIn({ duration: 150 }),
    zoomOut: () => flow.zoomOut({ duration: 150 }),
    fit: () => flow.fitView({ padding: 0.15, duration: 200 }),
    autoLayout: () => autoLayout({ fit: true }),
});
</script>
