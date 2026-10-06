// The canvas's "add" menu (AddNodeMenu: the "+" next to a node's output, a line
// dropped on empty canvas, Tab, the pane's context menu) as model operations.
// Framework-free: which entries a source offers, where new nodes go, and what
// picking an entry does to the editor model.
//
// A source is `{kind: 'place'|'transition', id}` — the node the menu was opened
// from — or null for the empty canvas. Positions are flow coordinates; an
// `anchor` is the point the FIRST new node's left edge is centred on (the drop
// point, or a spot beside the source node). Further nodes of a chain follow to
// the right.

import { FALLBACK_SIZE } from './layout.js';
import { addPlace, addTransition, findPlace, findTransition, keyOf } from './editorModel.js';
import { applyConnect, canStartTransitionFrom, createBetween } from './connections.js';

/** Space between a node and the next one a menu entry creates. */
export const NODE_GAP = 80;

/**
 * Top-left positions for a chain of new nodes of `kinds`, the first one's left
 * edge centred on `anchor`, each next one NODE_GAP to the right of the previous.
 */
export function chainPositions(anchor, kinds) {
    const out = [];
    let x = anchor.x;
    for (const kind of kinds) {
        const size = FALLBACK_SIZE[kind] ?? FALLBACK_SIZE.place;
        out.push({ x: Math.round(x), y: Math.round(anchor.y - size.height / 2) });
        x += size.width + NODE_GAP;
    }
    return out;
}

/**
 * The anchor beside a node: NODE_GAP right of its right edge, level with its
 * middle. `rect` = `{x, y, width, height}` of the node in flow coordinates.
 */
export function besideAnchor(rect) {
    return { x: rect.x + rect.width + NODE_GAP, y: rect.y + rect.height / 2 };
}

/** Top-left position for a node of `kind` centred on `point` (pane double-click, context menu). */
export function centredAt(point, kind) {
    const size = FALLBACK_SIZE[kind] ?? FALLBACK_SIZE.place;
    return { x: Math.round(point.x - size.width / 2), y: Math.round(point.y - size.height / 2) };
}

/**
 * The entries the menu offers for `source`, as data. Each entry:
 *   { action, target?, group }   group: 'create' | 'connect'
 * Places to connect to are listed in the model's order. Labels are the
 * component's business (it knows the language files).
 *
 *   place      → new transition + new place, new transition to each existing
 *                place (itself = a loop), a lone new place
 *   transition → new target place, each existing place as its target
 *   null       → a lone new place, a lone new transition
 */
export function addMenuEntries(model, source) {
    if (source?.kind === 'place' && findPlace(model, source.id)) {
        return [
            { action: 'transition_new_place', group: 'create' },
            { action: 'place', group: 'create' },
            ...model.places.map((p) => ({ action: 'transition_to_place', target: p.id, group: 'connect' })),
        ];
    }
    if (source?.kind === 'transition' && findTransition(model, source.id)) {
        const transition = findTransition(model, source.id);
        return [
            { action: 'target_new_place', group: 'create' },
            ...model.places
                .filter((p) => p.id !== transition.to)
                .map((p) => ({ action: 'target_place', target: p.id, group: 'connect' })),
        ];
    }
    return [
        { action: 'place', group: 'create' },
        { action: 'transition', group: 'create' },
    ];
}

/**
 * Whether an entry can be run, or why not (an `editor_connect_<reason>` key).
 * Only the transitions out of a place are constrained (the start coupling).
 */
export function checkAddAction(model, source, entry) {
    if (['transition_new_place', 'transition_to_place'].includes(entry.action)) {
        return canStartTransitionFrom(model, source?.id);
    }
    return { ok: true };
}

/**
 * Run a menu entry.
 *
 * @param {object} model
 * @param {{kind: string, id: string}|null} source
 * @param {{action: string, target?: string}} entry
 * @param {object} options
 * @param {{x: number, y: number}} options.anchor   see the file comment
 * @param {string[]} [options.labelLocales]
 * @param {(id: string) => string} [options.labelOf]  place names for "<A> → <B>"
 * @param {object} [options.ctx]                     editorModel ctx
 * @returns {{ok: true, select: string, created: string[]} | {ok: false, reason: string}}
 *          `select`: the node to select and name next
 */
export function runAddAction(model, source, entry, { anchor, labelLocales, labelOf = null, ctx = {} } = {}) {
    const check = checkAddAction(model, source, entry);
    if (!check.ok) return check;
    const point = anchor ?? { x: 0, y: 0 };

    switch (entry.action) {
        case 'place': {
            const [position] = chainPositions(point, ['place']);
            const id = addPlace(model, { labelLocales, position });
            return { ok: true, select: id, created: [id] };
        }
        case 'transition': {
            const [position] = chainPositions(point, ['transition']);
            const id = addTransition(model, { position });
            return { ok: true, select: id, created: [id] };
        }
        case 'transition_new_place': {
            const [tPos, pPos] = chainPositions(point, ['transition', 'place']);
            const place = addPlace(model, { labelLocales, position: pPos });
            const transition = addTransition(model, { from: source.id, to: place, position: tPos });
            return { ok: true, select: transition, created: [transition, place] };
        }
        case 'transition_to_place': {
            if (!findPlace(model, entry.target)) return { ok: false, reason: 'unknown' };
            const [position] = chainPositions(point, ['transition']);
            const id = createBetween(model, source.id, entry.target, { position, labelOf, ctx });
            return { ok: true, select: id, created: [id] };
        }
        case 'target_new_place': {
            if (!findTransition(model, source?.id)) return { ok: false, reason: 'unknown' };
            const [position] = chainPositions(point, ['place']);
            const place = addPlace(model, { labelLocales, position });
            const result = applyConnect(model, source.id, place, { ctx });
            if (!result.ok) return result;
            return { ok: true, select: place, created: [place] };
        }
        case 'target_place': {
            const result = applyConnect(model, source?.id, entry.target, { ctx });
            if (!result.ok) return result;
            return { ok: true, select: source.id, created: [] };
        }
        default:
            return { ok: false, reason: 'unknown' };
    }
}

/** Search text of an entry's target (for the menu's filter): name + handle. */
export function entrySearchText(model, entry, labelOf = null) {
    if (!entry.target) return '';
    const place = findPlace(model, entry.target);
    return `${labelOf ? labelOf(entry.target) : ''} ${keyOf(place) ?? ''}`.toLowerCase();
}
