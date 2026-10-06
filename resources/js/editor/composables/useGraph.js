// The editor model → Vue Flow nodes and edges, kept in sync by DIFFING into the
// flow's store rather than handing it new arrays: a node that is still there
// keeps its object, its measured size and wherever the author dragged it; only
// what changed is touched. That is what lets the canvas follow live edits
// without flicker or losing the viewport.
//
// Positions: a node takes the model's position (metadata.editor) when it first
// appears and whenever that stored position itself changes. Everything else
// about where a node sits is the canvas's business. A node with no stored
// position is reported in `missing` so the caller can auto-layout once sizes
// are known.

import { computed, watch } from 'vue';
import { MarkerType } from '@vue-flow/core';
import { explicitStartTransition, startingTransition } from '../model/actionRules.js';
import { parseId, toGraph, transitionLinks } from '../model/graphModel.js';
import { placeInfo, transitionInfo } from '../model/nodeInfo.js';

const MARKER = { type: MarkerType.ArrowClosed, color: 'currentColor', width: 18, height: 18 };

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/**
 * @param {object} flow                    the instance useVueFlow() returned
 * @param {object} options
 * @param {() => object} options.model     the handle-keyed editor model
 * @param {() => object} [options.injected]
 * @param {() => boolean} [options.showSystem]
 * @param {() => object} [options.ctx]     labels.js/nodeInfo.js context (incl. stateUsage)
 * @param {() => object} [options.graph]   the graph to draw (editorModel.editorGraph());
 *                                         default: toGraph(model, injected) — then
 *                                         `model` must be keyed like the graph's handles
 */
export function useGraph(flow, { model, injected = () => null, showSystem = () => true, ctx = () => ({}), graph: graphSource = null }) {
    const graph = computed(() => (graphSource ? graphSource() : toGraph(model(), { injected: injected() })));

    const elements = computed(() => buildElements(model(), graph.value, { showSystem: showSystem(), ctx: ctx() }));

    // Last model position applied per node id — so a stored position is
    // re-applied only when IT changes, never over a node the author moved.
    const applied = new Map();

    /**
     * Bring the store in line with the model.
     *
     * @returns {{added: string[], missing: string[]}}  ids added this round, and
     *          those of them that came without a stored position
     */
    function sync() {
        const { nodes, edges } = elements.value;
        const current = new Map(flow.getNodes.value.map((n) => [n.id, n]));
        const wanted = new Set(nodes.map((n) => n.id));

        const gone = [...current.keys()].filter((id) => !wanted.has(id));
        if (gone.length) {
            flow.removeNodes(gone, false);
            gone.forEach((id) => applied.delete(id));
        }

        const add = [];
        const missing = [];
        for (const node of nodes) {
            const existing = current.get(node.id);
            if (!existing) {
                if (!node.position) missing.push(node.id);
                if (node.position) applied.set(node.id, node.position);
                add.push({ ...node, position: node.position ? { ...node.position } : { x: 0, y: 0 } });
                continue;
            }
            if (existing.type !== node.type) existing.type = node.type;
            if (!same(existing.data, node.data)) existing.data = node.data;
            if (existing.class !== node.class) existing.class = node.class;
            for (const key of ['selectable', 'draggable', 'connectable', 'focusable']) {
                if (existing[key] !== node[key]) existing[key] = node[key];
            }
            if (node.position && !same(applied.get(node.id), node.position)) {
                existing.position = { ...node.position };
                applied.set(node.id, node.position);
            }
        }
        if (add.length) flow.addNodes(add);

        const currentEdges = new Map(flow.getEdges.value.map((e) => [e.id, e]));
        const wantedEdges = new Set(edges.map((e) => e.id));
        const goneEdges = [...currentEdges.keys()].filter((id) => !wantedEdges.has(id));
        if (goneEdges.length) flow.removeEdges(goneEdges);
        const addEdges = [];
        for (const edge of edges) {
            const existing = currentEdges.get(edge.id);
            if (!existing) {
                addEdges.push(edge);
                continue;
            }
            if (existing.class !== edge.class) existing.class = edge.class;
            if (!same(existing.data, edge.data)) existing.data = edge.data;
        }
        if (addEdges.length) flow.addEdges(addEdges);

        return { added: add.map((n) => n.id), missing };
    }

    // The ids of every node whose kind puts it below the main line in the
    // auto-layout: branch/SLA places, the steps leading into them, and the
    // injected system steps.
    const belowBand = computed(() => {
        const ids = new Set();
        for (const node of elements.value.nodes) {
            if (node.type === 'ghost') ids.add(node.id);
            if (node.type === 'place' && (node.data.roles.includes('branch') || node.data.roles.includes('sla_1') || node.data.roles.includes('sla_2'))) {
                ids.add(node.id);
            }
        }
        for (const t of graph.value.transitions) {
            if (t.to && ids.has(t.to)) ids.add(t.id);
        }
        return ids;
    });

    return { graph, elements, sync, belowBand };
}

/**
 * Pure: the nodes and edges for a model. Exported for the editor shell, which
 * needs to know whether anything lacks a stored position before the first sync.
 */
export function buildElements(model, graph, { showSystem = true, ctx = {} } = {}) {
    const nodes = [];
    const visible = new Set();

    for (const place of graph.places) {
        if (place.injected && !showSystem) continue;
        visible.add(place.id);
        nodes.push(place.injected
            ? ghostNode(place.id, 'place', place.handle, ctx.humanize ? ctx.humanize(place.handle) : place.handle, place.position)
            : {
                id: place.id,
                type: 'place',
                position: place.position,
                data: placeInfo(model, place.handle, ctx),
                selectable: true,
                draggable: true,
                connectable: true,
                focusable: true,
                class: 'wf-node-place',
            });
    }

    for (const transition of graph.transitions) {
        if (transition.injected && !showSystem) continue;
        visible.add(transition.id);
        if (transition.injected) {
            const label = ctx.injectedTransitions?.[transition.handle]?.metadata?.label || transition.handle;
            nodes.push(ghostNode(transition.id, 'transition', transition.handle, label, transition.position));
            continue;
        }
        nodes.push({
            id: transition.id,
            type: 'transition',
            position: transition.position,
            data: transitionInfo(model, transition.handle),
            selectable: true,
            draggable: true,
            connectable: true,
            focusable: true,
            class: 'wf-node-transition',
        });
    }

    // The default first step stays tied to the initial place (the panel locks its
    // `from`); its edge says so.
    // Looked up by handle rather than built with placeId(): a node created in the
    // editor has an id (`p~1`) that is not derived from its handle.
    const start = explicitStartTransition(model) ? null : startingTransition(model);
    const startPlace = graph.places.find((p) => !p.injected && p.handle === model.initial_place)?.id;
    const startStep = graph.transitions.find((t) => !t.injected && t.handle === start)?.id;
    const lockedEdge = startPlace && startStep ? `${startPlace}->${startStep}` : null;
    const ghosts = new Set(nodes.filter((n) => n.type === 'ghost').map((n) => n.id));

    const edges = [];
    const seen = new Set();
    for (const transition of graph.transitions) {
        if (!visible.has(transition.id)) continue;
        for (const [source, target] of transitionLinks(transition)) {
            if (!visible.has(source) || !visible.has(target)) continue;
            const id = `${source}->${target}`;
            if (seen.has(id)) continue;
            seen.add(id);
            const classes = ['wf-edge'];
            if (ghosts.has(source) || ghosts.has(target)) classes.push('wf-edge-ghost');
            if (id === lockedEdge) classes.push('wf-edge-locked');
            const ghostly = ghosts.has(source) || ghosts.has(target);
            // Removing an edge (Delete, context menu) is the canvas's own
            // business — Vue Flow's deleting stays off.
            edges.push({
                id,
                source,
                target,
                type: 'default',
                markerEnd: MARKER,
                selectable: !ghostly,
                updatable: false,
                deletable: false,
                focusable: !ghostly,
                class: classes.join(' '),
                data: {
                    transition: transition.id,
                    direction: parseId(source)?.kind === 'place' ? 'in' : 'out',
                    locked: id === lockedEdge,
                },
            });
        }
    }

    return { nodes, edges };
}

function ghostNode(id, kind, handle, label, position) {
    return {
        id,
        type: 'ghost',
        position,
        data: { kind, handle, label },
        selectable: false,
        draggable: true,
        connectable: false,
        focusable: false,
        class: 'wf-node-ghost',
    };
}

/**
 * Re-run sync() whenever the elements change. Returns the stop handle.
 *
 * @param {ReturnType<typeof useGraph>} graph
 * @param {(result: {added: string[], missing: string[]}) => void} onSynced
 */
export function watchGraph({ elements, sync }, onSynced) {
    return watch(elements, () => onSynced(sync()), { immediate: true });
}
