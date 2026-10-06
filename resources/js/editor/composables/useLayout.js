// Auto-layout on the live canvas: reads the measured node sizes from the Vue Flow
// store, runs model/layout.js (dagre) and writes the positions back into the
// store. Nothing is persisted here — the read-only viewer only arranges.

import { nextTick } from 'vue';
import { computeLayout, FALLBACK_SIZE, placeNear } from '../model/layout.js';

const sizeOf = (node) => ({
    width: node.dimensions?.width || FALLBACK_SIZE[node.type]?.width || 200,
    height: node.dimensions?.height || FALLBACK_SIZE[node.type]?.height || 60,
});

/**
 * @param {object} flow                       the instance useVueFlow() returned
 * @param {object} options
 * @param {() => Set<string>} options.belowBand  ids laid out under the main line
 */
export function useLayout(flow, { belowBand = () => new Set() } = {}) {
    const visibleNodes = () => flow.getNodes.value.filter((n) => !n.hidden);
    const visibleEdges = () => flow.getEdges.value.filter((e) => !e.hidden);

    /** Lay out every visible node from scratch. */
    function autoLayout({ fit = true } = {}) {
        const band = belowBand();
        const nodes = visibleNodes().map((n) => ({ id: n.id, ...sizeOf(n), band: band.has(n.id) ? 'below' : 'main' }));
        const positions = computeLayout({ nodes, edges: visibleEdges().map(({ source, target }) => ({ source, target })) });
        for (const node of visibleNodes()) {
            if (positions[node.id]) node.position = positions[node.id];
        }
        if (fit) fitSoon();
    }

    /** Position only `ids`, next to the nodes they connect to. */
    function placeMissing(ids) {
        if (!ids.length) return;
        const missing = new Set(ids);
        const known = {};
        const sizes = {};
        for (const node of visibleNodes()) {
            sizes[node.id] = sizeOf(node);
            if (!missing.has(node.id)) known[node.id] = { ...node.position };
        }
        const positions = placeNear(ids, known, visibleEdges(), sizes);
        for (const node of visibleNodes()) {
            if (positions[node.id]) node.position = positions[node.id];
        }
    }

    function fitSoon() {
        // One frame for Vue Flow to apply the new transforms before measuring.
        nextTick(() => requestAnimationFrame(() => flow.fitView({ padding: 0.15 })));
    }

    return { autoLayout, placeMissing, fitSoon };
}
