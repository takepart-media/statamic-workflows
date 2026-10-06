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
        @nodes-change="onNodesChange"
        @node-drag-stop="onDragStop"
        @edge-click="onEdgeClick"
        @pane-click="$emit('select', null)"
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
import { markRaw, watch } from 'vue';
import { VueFlow, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { MiniMap } from '@vue-flow/minimap';
import { useGraph, watchGraph } from '../composables/useGraph.js';
import { useLayout } from '../composables/useLayout.js';
import PlaceNode from './PlaceNode.vue';
import TransitionNode from './TransitionNode.vue';
import GhostNode from './GhostNode.vue';

const props = defineProps({
    // The handle-keyed model the node badges are computed from (normalize(),
    // or editorModel.viewModel() in the editor).
    model: { type: Object, required: true },
    // { places, transitions } DefinitionLoader adds at read time.
    injected: { type: Object, default: () => ({ places: {}, transitions: {} }) },
    // Context for labels.js / nodeInfo.js: labelLocales, labelLocale,
    // shippedPlaceLabels, humanize, stateUsage.
    ctx: { type: Object, default: () => ({}) },
    showSystem: { type: Boolean, default: true },
    showMinimap: { type: Boolean, default: false },
    // The graph to draw (editorModel.editorGraph()). Without it the canvas
    // derives one from `model` — the read-only viewer's way.
    graph: { type: Object, default: null },
    // The selected node's id, mirrored into Vue Flow's own selection.
    selected: { type: String, default: null },
});

// select: a node (or, via its edges, a transition) was picked; null = nothing.
// node-moved: the author dropped a node — {id, position}.
// positions: the canvas arranged nodes itself — {positions: {id: {x, y}}, touched}
//            (touched: the author asked for it, e.g. "Arrange automatically").
const emit = defineEmits(['select', 'node-moved', 'positions']);

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
    graph: props.graph ? () => props.graph : null,
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

// Positions of the model's own nodes (not the injected ghosts — the server
// never stores theirs) as they stand in the flow store right now.
function modelPositions(ids = null) {
    const out = {};
    for (const node of flow.getNodes.value) {
        if (node.type === 'ghost' || (ids && !ids.includes(node.id))) continue;
        out[node.id] = { x: Math.round(node.position.x), y: Math.round(node.position.y) };
    }
    return out;
}

function onNodesInitialized() {
    if (fullLayout) {
        fullLayout = false;
        unplaced.clear();
        autoLayout({ fit: false });
        emit('positions', { positions: modelPositions(), touched: false });
    } else if (unplaced.size) {
        const ids = [...unplaced];
        placeMissing(ids);
        unplaced.clear();
        emit('positions', { positions: modelPositions(ids), touched: false });
    }
    if (fitPending) {
        fitPending = false;
        fitSoon();
    }
}

const minimapNodeClass = (node) => `wf-minimap-${node.type}`;

// Keyboard and mouse selection both arrive as `select` changes; only the
// selecting ones matter here — a click on the empty pane is what clears.
function onNodesChange(changes) {
    const picked = changes.find((c) => c.type === 'select' && c.selected);
    if (picked && picked.id !== props.selected) emit('select', picked.id);
}

// An edge belongs to exactly one transition — clicking it opens that one.
function onEdgeClick({ edge }) {
    const id = edge?.data?.transition;
    if (id && flow.findNode(id)?.type === 'transition') emit('select', id);
}

function onDragStop({ nodes, node }) {
    for (const moved of nodes?.length ? nodes : [node]) {
        if (!moved || moved.type === 'ghost') continue;
        emit('node-moved', { id: moved.id, position: { x: Math.round(moved.position.x), y: Math.round(moved.position.y) } });
    }
}

// Selection from outside (a panel, the order list) shows on the canvas too.
watch(
    () => props.selected,
    (id) => {
        const current = flow.getSelectedNodes.value;
        if (current.length === 1 && current[0].id === id) return;
        if (current.length) flow.removeSelectedNodes(current);
        const node = id ? flow.findNode(id) : null;
        if (node) flow.addSelectedNodes([node]);
    },
);

defineExpose({
    zoomIn: () => flow.zoomIn({ duration: 150 }),
    zoomOut: () => flow.zoomOut({ duration: 150 }),
    fit: () => flow.fitView({ padding: 0.15, duration: 200 }),
    autoLayout() {
        autoLayout({ fit: true });
        emit('positions', { positions: modelPositions(), touched: true });
    },
    // Bring a node into view without changing the zoom much.
    reveal(id) {
        const node = flow.findNode(id);
        if (!node) return;
        const { width = 200, height = 60 } = node.dimensions ?? {};
        flow.setCenter(node.position.x + width / 2, node.position.y + height / 2, { zoom: Math.max(flow.getViewport().zoom, 0.8), duration: 250 });
    },
});
</script>
