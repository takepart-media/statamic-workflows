// Auto-layout for the graph: dagre, left to right. Framework-free — takes plain
// node/edge descriptions and returns top-left positions, so it is testable and
// independent of Vue Flow's store.
//
//   nodes: [{ id, width, height, band? }]   band: 'main' (default) | 'below'
//   edges: [{ source, target }]
//
// Order hints: nodes and edges are handed to dagre in the order given (the YAML
// order), which is what its ordering heuristic starts from. Nodes in the 'below'
// band (branch / SLA places and the steps into them) are moved under the main
// line afterwards, keeping their horizontal rank, so the happy path reads as one
// straight line.

import { graphlib, layout } from '@dagrejs/dagre';

export const LAYOUT_DEFAULTS = {
    rankdir: 'LR',
    nodesep: 40,
    ranksep: 80,
    // Vertical gap between the bottom of the main line and the branch band.
    bandGap: 60,
};

// Node size before Vue Flow has measured it.
export const FALLBACK_SIZE = {
    place: { width: 240, height: 84 },
    transition: { width: 180, height: 44 },
    ghost: { width: 180, height: 44 },
};

/**
 * @param {{nodes: object[], edges: object[]}} graph
 * @param {object} [options]  overrides for LAYOUT_DEFAULTS
 * @returns {Record<string, {x: number, y: number}>} top-left position per node id
 */
export function computeLayout({ nodes = [], edges = [] }, options = {}) {
    const opts = { ...LAYOUT_DEFAULTS, ...options };
    const g = new graphlib.Graph({ multigraph: true });
    g.setGraph({ rankdir: opts.rankdir, nodesep: opts.nodesep, ranksep: opts.ranksep, marginx: 0, marginy: 0 });
    g.setDefaultEdgeLabel(() => ({}));

    const ids = new Set();
    for (const node of nodes) {
        ids.add(node.id);
        g.setNode(node.id, { width: node.width || 1, height: node.height || 1 });
    }
    edges.forEach((edge, i) => {
        if (!ids.has(edge.source) || !ids.has(edge.target) || edge.source === edge.target) return;
        // Edges into the branch band pull less, so the main line stays straight.
        const below = nodes.find((n) => n.id === edge.target)?.band === 'below';
        g.setEdge(edge.source, edge.target, { weight: below ? 1 : 4, minlen: 1 }, `e${i}`);
    });

    layout(g);

    const positions = {};
    for (const node of nodes) {
        const n = g.node(node.id);
        // dagre gives centres; Vue Flow wants the top-left corner.
        positions[node.id] = { x: n.x - (node.width || 1) / 2, y: n.y - (node.height || 1) / 2 };
    }

    moveBandBelow(nodes, positions, opts.bandGap);

    for (const id of Object.keys(positions)) {
        positions[id] = { x: Math.round(positions[id].x), y: Math.round(positions[id].y) };
    }

    return positions;
}

// Shift the 'below' band as a block so its top sits `gap` under the main line's
// bottom — only ever downwards, and only when it overlaps the main line.
function moveBandBelow(nodes, positions, gap) {
    const band = nodes.filter((n) => n.band === 'below');
    const main = nodes.filter((n) => n.band !== 'below');
    if (!band.length || !main.length) return;

    const mainBottom = Math.max(...main.map((n) => positions[n.id].y + (n.height || 1)));
    const bandTop = Math.min(...band.map((n) => positions[n.id].y));
    const shift = mainBottom + gap - bandTop;
    if (shift <= 0) return;

    for (const n of band) positions[n.id].y += shift;
}

/**
 * A position for each node in `missing`, next to the neighbours it is linked to
 * — for nodes added to an already laid-out graph (the injected system steps),
 * where re-running the whole layout would throw the author's arrangement away.
 * Placed below the centre of their positioned neighbours; one with none goes
 * below everything.
 *
 * @param {string[]} missing
 * @param {Record<string, {x, y}>} positions  known positions (not modified)
 * @param {{source, target}[]} edges
 * @param {Record<string, {width, height}>} [sizes]
 * @returns {Record<string, {x, y}>} positions for the missing nodes only
 */
export function placeNear(missing, positions, edges, sizes = {}) {
    const out = {};
    const known = { ...positions };
    const allY = Object.values(known).map((p) => p.y);
    const floor = allY.length ? Math.max(...allY) : 0;
    let orphan = 0;

    // Iterate until nothing changes, so a chain of missing nodes (place → step →
    // place) resolves from the end that touches the graph.
    let pending = [...missing];
    for (let pass = 0; pass < 4 && pending.length; pass++) {
        const next = [];
        for (const id of pending) {
            const neighbours = edges
                .flatMap((e) => (e.source === id ? [e.target] : e.target === id ? [e.source] : []))
                .map((n) => known[n])
                .filter(Boolean);
            if (!neighbours.length) {
                next.push(id);
                continue;
            }
            const x = neighbours.reduce((s, p) => s + p.x, 0) / neighbours.length;
            const y = Math.max(...neighbours.map((p) => p.y));
            out[id] = known[id] = { x: Math.round(x), y: Math.round(y + (sizes[id]?.height ?? 60) + 60) };
        }
        pending = next;
    }
    for (const id of pending) {
        out[id] = known[id] = { x: orphan * 220, y: Math.round(floor + 160) };
        orphan++;
    }

    return out;
}
