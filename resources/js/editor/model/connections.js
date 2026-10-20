// What drawing on the canvas means for the editor model (editorModel.js): which
// connections are allowed, what a drawn line changes, what removing one does.
// Framework-free; the mutations mutate, everything else is pure.
//
// Lines run place → transition (one of the transition's `from` places) and
// transition → place (its `to`). The canvas connects only output → input
// (Vue Flow "strict" mode), so a connection is always (source node, target node):
//
//   place      → transition   adds the place to the transition's `from`
//   transition → place        sets the transition's `to` (replacing the old one)
//   place      → place        a NEW transition between the two ("bridge")
//   transition → transition   not allowed — a step leads into a status
//   place      → same place   not allowed directly; a loop goes through a
//                             transition (place → transition → same place is fine)
//
// The start coupling (editorModel.enforceStartCoupling) is respected rather than
// broken: while no `start_localization` action pins the start elsewhere, the
// first transition's `from` IS the initial place — it can neither gain another
// source nor lose that one, and a first transition can only be created out of
// the initial place.

import {
    addTransition,
    enforceStartCoupling,
    explicitStartTransitionId,
    findPlace,
    findTransition,
    keyOf,
    startingTransitionId,
    updateTransition,
} from './editorModel.js';

/**
 * Why a connection is refused (each maps to an `editor_connect_<reason>` message):
 *   unknown                   an end is not a node of the model (an injected
 *                             system step, or gone)
 *   self                      place → the same place, directly
 *   transition_to_transition  two transitions
 *   exists                    the line is already there
 *   start_locked              would change the first transition's coupled `from`
 *                             (or create a first transition outside the start)
 */
export const CONNECT_REASONS = ['unknown', 'self', 'transition_to_transition', 'exists', 'start_locked'];

const kindOf = (model, id) => (findPlace(model, id) ? 'place' : findTransition(model, id) ? 'transition' : null);

/** Whether `id` is the first transition with its `from` tied to the initial place. */
export function isCoupledStart(model, id) {
    return !explicitStartTransitionId(model) && !!model.workflow.initial_place && startingTransitionId(model) === id;
}

/**
 * Whether a NEW transition may leave place `placeId`. Only the very first
 * transition is constrained: it must start in the initial place, so drawing it
 * from anywhere else would be silently redirected there by the coupling.
 */
export function canStartTransitionFrom(model, placeId) {
    if (!findPlace(model, placeId)) return { ok: false, reason: 'unknown' };
    const initial = model.workflow.initial_place;
    if (model.transitions.length === 0 && initial && placeId !== initial) return { ok: false, reason: 'start_locked' };
    return { ok: true };
}

/**
 * May a line be drawn from node `source` (its output) to node `target` (its
 * input)?
 *
 * @returns {{ok: true, kind: 'from'|'to'|'bridge', replaces?: string|null} | {ok: false, reason: string}}
 */
export function canConnect(model, source, target) {
    const s = kindOf(model, source);
    const t = kindOf(model, target);
    if (!s || !t) return { ok: false, reason: 'unknown' };

    if (s === 'transition' && t === 'transition') return { ok: false, reason: 'transition_to_transition' };

    if (s === 'place' && t === 'place') {
        if (source === target) return { ok: false, reason: 'self' };
        const start = canStartTransitionFrom(model, source);
        return start.ok ? { ok: true, kind: 'bridge' } : start;
    }

    if (s === 'place') {
        const transition = findTransition(model, target);
        if (transition.from.includes(source)) return { ok: false, reason: 'exists' };
        if (isCoupledStart(model, target)) return { ok: false, reason: 'start_locked' };
        return { ok: true, kind: 'from' };
    }

    const transition = findTransition(model, source);
    if (transition.to === target) return { ok: false, reason: 'exists' };
    return { ok: true, kind: 'to', replaces: transition.to ?? null };
}

/**
 * The label a transition created between two places gets: "<A> → <B>".
 * `labelOf(placeId)` names a place (default: its handle).
 */
export function bridgeLabel(model, source, target, labelOf = null) {
    const name = (id) => (labelOf ? labelOf(id) : null) || keyOf(findPlace(model, id)) || id;
    return `${name(source)} → ${name(target)}`;
}

/**
 * Draw the line `source` → `target`. A place → place line creates the transition
 * in between (at `position`, labelled by bridgeLabel()).
 *
 * @param {object} model
 * @param {string} source
 * @param {string} target
 * @param {object} [options]
 * @param {{x: number, y: number}} [options.position]  where a bridge transition goes
 * @param {(id: string) => string} [options.labelOf]    place names for its label
 * @param {object} [options.ctx]                        editorModel ctx (reserved handles …)
 * @returns {{ok: true, kind: string, transition: string, created: boolean} | {ok: false, reason: string}}
 */
export function applyConnect(model, source, target, { position = null, labelOf = null, ctx = {} } = {}) {
    const check = canConnect(model, source, target);
    if (!check.ok) return check;

    if (check.kind === 'bridge') {
        const id = createBetween(model, source, target, { position, labelOf, ctx });
        return { ok: true, kind: 'bridge', transition: id, created: true };
    }

    if (check.kind === 'from') {
        const transition = findTransition(model, target);
        transition.from = [...transition.from, source];
        enforceStartCoupling(model);
        return { ok: true, kind: 'from', transition: target, created: false };
    }

    updateTransition(model, source, { to: target }, ctx);
    return { ok: true, kind: 'to', transition: source, created: false };
}

/**
 * A new transition `source` → `target` (both places; the same one for a loop),
 * labelled "<A> → <B>". The caller has checked canStartTransitionFrom().
 */
export function createBetween(model, source, target, { position = null, labelOf = null, ctx = {} } = {}) {
    const id = addTransition(model, { from: source, to: target, position });
    updateTransition(model, id, { metadata: { label: bridgeLabel(model, source, target, labelOf) } }, ctx);
    return id;
}

/**
 * What removing a drawn line would do, or why it can't go.
 *
 * @param {object} edge  `{transition, place, direction}` — direction 'in' is
 *                       place → transition (a `from` entry), 'out' is
 *                       transition → place (the `to`)
 */
export function canDisconnect(model, { transition: id, place, direction }) {
    const transition = findTransition(model, id);
    if (!transition) return { ok: false, reason: 'unknown' };
    if (direction === 'in') {
        if (!transition.from.includes(place)) return { ok: false, reason: 'unknown' };
        if (isCoupledStart(model, id)) return { ok: false, reason: 'start_locked' };
        return { ok: true };
    }
    if (direction === 'out') {
        return transition.to && transition.to === place ? { ok: true } : { ok: false, reason: 'unknown' };
    }
    return { ok: false, reason: 'unknown' };
}

/**
 * Remove a drawn line: the `from` entry or the `to` of the transition. The
 * transition itself stays (without a source or target it is flagged by the
 * validation until it is connected again or deleted).
 */
export function applyDisconnect(model, edge) {
    const check = canDisconnect(model, edge);
    if (!check.ok) return check;
    const transition = findTransition(model, edge.transition);
    if (edge.direction === 'in') {
        transition.from = transition.from.filter((v) => v !== edge.place);
    } else {
        transition.to = null;
    }
    return { ok: true };
}

/**
 * The model-side reading of a canvas edge (`{source, target, data: {transition,
 * direction}}`, see useGraph.buildElements()).
 */
export function edgeEnds(edge) {
    const direction = edge?.data?.direction;
    const transition = edge?.data?.transition;
    if (!transition || !direction) return null;
    return { transition, direction, place: direction === 'in' ? edge.source : edge.target };
}

/**
 * Moving one end of a drawn line (dragging it on the canvas) to `source` →
 * `target`: the old line goes and the new one is drawn, judged by canConnect()
 * as the model would stand WITHOUT the old line. The same line again is
 * `{ok: true, kind: 'same'}` — nothing to do.
 *
 * @param {object} edge  `{transition, place, direction}` (edgeEnds())
 * @returns {{ok: true, kind: string, replaces?: string|null} | {ok: false, reason: string}}
 */
export function canReconnect(model, edge, source, target) {
    const removable = canDisconnect(model, edge);
    if (!removable.ok) return removable;
    const [oldSource, oldTarget] = edge.direction === 'in' ? [edge.place, edge.transition] : [edge.transition, edge.place];
    if (source === oldSource && target === oldTarget) return { ok: true, kind: 'same' };
    // A throwaway copy without the old line — canConnect() only reads.
    const without = JSON.parse(JSON.stringify({ places: model.places, transitions: model.transitions, workflow: model.workflow }));
    applyDisconnect(without, edge);
    return canConnect(without, source, target);
}

/**
 * Move one end of a drawn line: remove the old line, draw `source` → `target`
 * (options as applyConnect()). Nothing changes when the move is refused. A
 * place swapped at the start of a line keeps its slot in the transition's `from`.
 *
 * @returns {{ok: true, kind: string, transition?: string, created?: boolean} | {ok: false, reason: string}}
 */
export function applyReconnect(model, edge, source, target, options = {}) {
    const check = canReconnect(model, edge, source, target);
    if (!check.ok || check.kind === 'same') return check;
    const slot = edge.direction === 'in' ? findTransition(model, edge.transition).from.indexOf(edge.place) : -1;
    applyDisconnect(model, edge);
    const result = applyConnect(model, source, target, options);
    if (result.ok && result.kind === 'from' && result.transition === edge.transition && slot >= 0) {
        const transition = findTransition(model, edge.transition);
        const from = transition.from.filter((v) => v !== source);
        from.splice(Math.min(slot, from.length), 0, source);
        transition.from = from;
    }
    return result;
}
