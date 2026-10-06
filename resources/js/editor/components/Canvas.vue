<template>
    <!-- Right-click anywhere on the canvas opens one context menu; what it offers
         depends on what was hit (`menuTarget`, set by Vue Flow's own
         node/edge/pane events before the menu opens) and comes from the
         `context-menu` slot. -->
    <Context>
        <template #trigger>
            <div
                ref="wrapper"
                class="h-full w-full"
                @contextmenu.capture="onContextMenuCapture"
                @dblclick="onDoubleClick"
            >
                <VueFlow
                    :id="flowId"
                    class="h-full w-full"
                    :node-types="nodeTypes"
                    :nodes-connectable="true"
                    :connection-mode="ConnectionMode.Strict"
                    :connection-radius="28"
                    :is-valid-connection="isValidConnection"
                    :edges-updatable="false"
                    :delete-key-code="null"
                    :zoom-on-double-click="false"
                    :min-zoom="0.2"
                    :max-zoom="2"
                    :aria-label="__('workflows::messages.editor_canvas_label')"
                    @nodes-initialized="onNodesInitialized"
                    @nodes-change="onNodesChange"
                    @node-drag-stop="onDragStop"
                    @edge-click="onEdgeClick"
                    @pane-click="$emit('select', null)"
                    @connect-start="onConnectStart"
                    @connect="onConnect"
                    @connect-end="onConnectEnd"
                    @node-context-menu="onNodeContextMenu"
                    @edge-context-menu="onEdgeContextMenu"
                    @pane-context-menu="onPaneContextMenu"
                    @selection-context-menu="onSelectionContextMenu"
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
            </div>
        </template>
        <ContextMenu>
            <slot name="context-menu" :target="menuTarget" />
        </ContextMenu>
    </Context>
</template>

<script>
let instances = 0;
</script>

<script setup>
import { markRaw, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ConnectionMode, VueFlow, useVueFlow } from '@vue-flow/core';
import { Context, ContextMenu } from '@statamic/cms/ui';
import { Background } from '@vue-flow/background';
import { MiniMap } from '@vue-flow/minimap';
import { useGraph, watchGraph } from '../composables/useGraph.js';
import { useLayout } from '../composables/useLayout.js';
import { edgeEnds } from '../model/connections.js';
import { FALLBACK_SIZE } from '../model/layout.js';
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
    // (source, target) => {ok, reason?}: may a line be drawn from `source`'s
    // output to `target`'s input (model/connections.js canConnect()). Without
    // it nothing connects.
    connectionRule: { type: Function, default: null },
});

// select: a node (or, via its edges, a transition) was picked; null = nothing.
// node-moved: the author dropped a node — {id, position}.
// positions: the canvas arranged nodes itself — {positions: {id: {x, y}}, touched}
//            (touched: the author asked for it, e.g. "Arrange automatically").
// connect: a line was drawn — {source, target, position} (position: where a
//          transition created between two places goes, the midpoint).
// connect-refused: a line was dropped on something it can't connect to — {reason}.
// connect-drop: a line from an output was dropped on empty canvas — {source,
//               client: {x, y}, position} (flow coordinates of the drop point).
// pane-dblclick: {position} on the empty canvas.
// delete: Delete/Backspace on the canvas — {nodes: [id], edges: [{transition,
//         place, direction}]} (edges win when one is selected).
// add-menu: Tab with a node selected — {id}.
const emit = defineEmits(['select', 'node-moved', 'positions', 'connect', 'connect-refused', 'connect-drop', 'pane-dblclick', 'delete', 'add-menu']);

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

// An edge belongs to exactly one transition — clicking it opens that one in the
// panel, while the EDGE stays what is selected on the canvas (so Delete removes
// the line, not the step). `edgePick` keeps the selection watch below from
// moving the canvas selection onto the transition.
let edgePick = null;
function onEdgeClick({ edge }) {
    const id = edge?.data?.transition;
    if (!id || flow.findNode(id)?.type !== 'transition') return;
    if (id !== props.selected) edgePick = id;
    emit('select', id);
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
        if (edgePick && edgePick === id) {
            edgePick = null;
            return;
        }
        edgePick = null;
        const current = flow.getSelectedNodes.value;
        if (current.length === 1 && current[0].id === id) return;
        if (current.length) flow.removeSelectedNodes(current);
        const node = id ? flow.findNode(id) : null;
        if (node) {
            flow.addSelectedNodes([node]);
        } else if (id) {
            // Just created: the node reaches the flow store with this tick's sync.
            nextTick(() => {
                const created = props.selected === id ? flow.findNode(id) : null;
                if (created) flow.addSelectedNodes([created]);
            });
        }
    },
);

// --- connecting ---

const isModelNode = (id) => {
    const type = flow.findNode(id)?.type;
    return type === 'place' || type === 'transition';
};

// Called by Vue Flow for every handle the line comes near.
function isValidConnection(connection) {
    if (!props.connectionRule || !isModelNode(connection.source) || !isModelNode(connection.target)) return false;
    return props.connectionRule(connection.source, connection.target).ok === true;
}

// The line being drawn: where it started, and whether it ended on a handle.
let drawing = null;

function onConnectStart({ nodeId, handleType }) {
    drawing = { nodeId, handleType, connected: false };
}

function onConnect(connection) {
    if (drawing) drawing.connected = true;
    emit('connect', { source: connection.source, target: connection.target, position: midpoint(connection.source, connection.target) });
}

const pointOf = (event) => {
    const touch = event?.changedTouches?.[0];
    return touch ? { x: touch.clientX, y: touch.clientY } : { x: event?.clientX ?? 0, y: event?.clientY ?? 0 };
};

// Dropped somewhere that is not a valid handle: onto a node's body → connect
// to that node if the rules allow it (else say why); onto the empty canvas →
// the add menu at that spot. Only lines started at an output count.
function onConnectEnd(event) {
    const started = drawing;
    drawing = null;
    if (!started || started.connected || started.handleType !== 'source' || !event) return;
    const point = pointOf(event);
    const hit = document.elementFromPoint(point.x, point.y);
    if (!hit || !wrapper.value?.contains(hit)) return;

    const nodeEl = hit.closest('.vue-flow__node');
    if (nodeEl) {
        const target = nodeEl.getAttribute('data-id');
        // Let go on the node it started from: nothing happened.
        if (!target || target === started.nodeId) return;
        const verdict = isModelNode(target) && props.connectionRule
            ? props.connectionRule(started.nodeId, target)
            : { ok: false, reason: 'unknown' };
        if (verdict.ok) {
            emit('connect', { source: started.nodeId, target, position: midpoint(started.nodeId, target) });
        } else if (verdict.reason !== 'exists') {
            emit('connect-refused', { reason: verdict.reason });
        }
        return;
    }
    // Not over the minimap or another overlay.
    if (hit.closest('.vue-flow__panel, .vue-flow__minimap')) return;
    emit('connect-drop', { source: started.nodeId, client: point, position: flow.screenToFlowCoordinate(point) });
}

// Top-left of a transition centred between two nodes.
function midpoint(a, b) {
    const ra = nodeRect(a);
    const rb = nodeRect(b);
    if (!ra || !rb) return null;
    const size = FALLBACK_SIZE.transition;
    return {
        x: Math.round((ra.x + ra.width / 2 + rb.x + rb.width / 2) / 2 - size.width / 2),
        y: Math.round((ra.y + ra.height / 2 + rb.y + rb.height / 2) / 2 - size.height / 2),
    };
}

/** A node's box in flow coordinates, or null. */
function nodeRect(id) {
    const node = flow.findNode(id);
    if (!node) return null;
    const fallback = FALLBACK_SIZE[node.type] ?? FALLBACK_SIZE.place;
    return {
        x: node.position.x,
        y: node.position.y,
        width: node.dimensions?.width || fallback.width,
        height: node.dimensions?.height || fallback.height,
    };
}

// Flow coordinates → viewport (client) pixels. Vue Flow's own
// flowToScreenCoordinate adds the container offset before scaling, so it is
// done by hand.
function flowToClient(point) {
    const box = flow.vueFlowRef.value?.getBoundingClientRect();
    const { x, y, zoom } = flow.getViewport();
    return { x: (box?.left ?? 0) + point.x * zoom + x, y: (box?.top ?? 0) + point.y * zoom + y };
}

// --- context menu ---

const wrapper = ref(null);
// { kind: 'pane'|'node'|'edge'|'selection', id?, type?, edge?, ids?, position }
const menuTarget = ref({ kind: 'pane', position: { x: 0, y: 0 } });

const flowPoint = (event) => flow.screenToFlowCoordinate(pointOf(event));

// Capture phase: runs before Vue Flow's handlers, which refine it.
function onContextMenuCapture(event) {
    menuTarget.value = { kind: 'pane', position: flowPoint(event) };
}

function onNodeContextMenu({ event, node }) {
    if (!isModelNode(node.id)) return;
    menuTarget.value = { kind: 'node', id: node.id, type: node.type, position: flowPoint(event) };
}

function onEdgeContextMenu({ event, edge }) {
    const ends = edgeEnds(edge);
    if (!ends || !edge.selectable) return;
    menuTarget.value = { kind: 'edge', id: edge.id, edge: ends, locked: !!edge.data?.locked, position: flowPoint(event) };
}

function onPaneContextMenu(event) {
    menuTarget.value = { kind: 'pane', position: flowPoint(event) };
}

function onSelectionContextMenu({ event, nodes }) {
    const ids = (nodes ?? []).map((n) => n.id).filter(isModelNode);
    if (ids.length) menuTarget.value = { kind: 'selection', ids, position: flowPoint(event) };
}

// Double-click on the empty canvas (zooming on double-click is off).
function onDoubleClick(event) {
    if (!event.target?.classList?.contains('vue-flow__pane')) return;
    emit('pane-dblclick', { position: flowPoint(event) });
}

// --- keyboard ---
// Vue Flow's own delete key is off (`delete-key-code` null): removing a node
// has to go through the model (pending deletions, migration targets).

function isTyping(el) {
    if (!el || el === document.body) return false;
    if (el.isContentEditable) return true;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return true;
    return ['textbox', 'combobox', 'searchbox', 'listbox', 'option', 'menu', 'menuitem', 'dialog'].includes(el.getAttribute?.('role'));
}

function onKeydown(event) {
    if (event.defaultPrevented || event.isComposing || event.altKey || event.ctrlKey || event.metaKey) return;
    const el = event.target;
    // Only on the canvas itself (or with nothing focused) — never while typing
    // in the panel or in a dialog.
    if (isTyping(el)) return;
    if (!(el === document.body || el === document.documentElement || wrapper.value?.contains(el))) return;

    if (event.key === 'Delete' || event.key === 'Backspace') {
        const edges = flow.getSelectedEdges.value.map(edgeEnds).filter(Boolean);
        const nodes = flow.getSelectedNodes.value.map((n) => n.id).filter(isModelNode);
        if (!edges.length && !nodes.length && props.selected && isModelNode(props.selected)) nodes.push(props.selected);
        if (!edges.length && !nodes.length) return;
        event.preventDefault();
        emit('delete', edges.length ? { nodes: [], edges } : { nodes, edges: [] });
        return;
    }

    if (event.key === 'Tab' && !event.shiftKey && props.selected && isModelNode(props.selected)) {
        event.preventDefault();
        emit('add-menu', { id: props.selected });
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

defineExpose({
    zoomIn: () => flow.zoomIn({ duration: 150 }),
    zoomOut: () => flow.zoomOut({ duration: 150 }),
    fit: () => flow.fitView({ padding: 0.15, duration: 200 }),
    autoLayout() {
        autoLayout({ fit: true });
        emit('positions', { positions: modelPositions(), touched: true });
    },
    nodeRect,
    flowToClient,
    // The middle of what the canvas shows, in flow coordinates.
    visibleCenter() {
        const box = flow.vueFlowRef.value?.getBoundingClientRect();
        if (!box) return { x: 0, y: 0 };
        return flow.screenToFlowCoordinate({ x: box.left + box.width / 2, y: box.top + box.height / 2 });
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
