// The handle-keyed editor model (normalize.js) ↔ the id-based structure the graph
// works on. Framework-free and pure: neither direction mutates its input.
//
// Why ids at all: a handle is the YAML key and the thing other parts of the
// definition point at, so it can change (a new row gets its handle only once it
// is named) while the canvas must keep tracking the same node. Every node gets a
// stable id instead:
//
//   p:<handle>   a place loaded from the definition
//   t:<handle>   a transition loaded from the definition
//   p~1, t~1 …   a node created in the editor that has no handle yet
//
//   graph = {
//       places:      [{ id, handle, injected, position }],
//       transitions: [{ id, handle, from: [placeId], to: placeId|null, fromList, injected, position }],
//   }
//
// Array order IS the YAML order — it drives the progress bar, the final place and
// the filter order, so it is kept separate from the canvas positions. Positions
// come from `metadata.editor.{places,transitions}.<handle>: {x, y}`; a node
// without one has `position: null` and is placed by the auto-layout.
//
// The places/transitions DefinitionLoader injects at read time (pending_revision
// and its two system steps) come in through `injected` and are appended with
// `injected: true`. They are shown, never written back.

export const PLACE_PREFIX = 'p:';
export const TRANSITION_PREFIX = 't:';
export const NEW_PLACE_PREFIX = 'p~';
export const NEW_TRANSITION_PREFIX = 't~';

export const placeId = (handle) => `${PLACE_PREFIX}${handle}`;
export const transitionId = (handle) => `${TRANSITION_PREFIX}${handle}`;

/**
 * What an id stands for.
 *
 * @param {string} id
 * @returns {{kind: 'place'|'transition', handle: string|null, isNew: boolean}|null}
 */
export function parseId(id) {
    if (typeof id !== 'string') return null;
    if (id.startsWith(PLACE_PREFIX)) return { kind: 'place', handle: id.slice(PLACE_PREFIX.length), isNew: false };
    if (id.startsWith(TRANSITION_PREFIX)) return { kind: 'transition', handle: id.slice(TRANSITION_PREFIX.length), isNew: false };
    if (id.startsWith(NEW_PLACE_PREFIX)) return { kind: 'place', handle: null, isNew: true };
    if (id.startsWith(NEW_TRANSITION_PREFIX)) return { kind: 'transition', handle: null, isNew: true };
    return null;
}

/**
 * The next free id for a node created in the editor (`p~1`, `p~2`, …).
 *
 * @param {object} graph
 * @param {'place'|'transition'} kind
 */
export function nextNewId(graph, kind) {
    const prefix = kind === 'place' ? NEW_PLACE_PREFIX : NEW_TRANSITION_PREFIX;
    const list = kind === 'place' ? graph?.places : graph?.transitions;
    let max = 0;
    for (const node of list ?? []) {
        if (typeof node?.id === 'string' && node.id.startsWith(prefix)) {
            const n = Number(node.id.slice(prefix.length));
            if (Number.isInteger(n) && n > max) max = n;
        }
    }
    return `${prefix}${max + 1}`;
}

// A stored position, or null for anything that is not two finite numbers.
export function toPosition(value) {
    if (!value || typeof value !== 'object') return null;
    const x = Number(value.x);
    const y = Number(value.y);
    if (value.x === null || value.y === null || value.x === '' || value.y === '') return null;
    return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
}

// The `{x, y}` stored for `handle` under metadata.editor[`section`], or null.
export function storedPosition(model, section, handle) {
    return toPosition(model?.metadata?.editor?.[section]?.[handle]);
}

const asList = (from) => [].concat(from ?? []).filter((v) => typeof v === 'string' && v !== '');

function transitionEntry(handle, transition, injected, position) {
    const from = asList(transition?.from);
    return {
        id: transitionId(handle),
        handle,
        // Unique ids — a definition naming the same source twice still has one edge.
        from: [...new Set(from.map(placeId))],
        to: typeof transition?.to === 'string' && transition.to !== '' ? placeId(transition.to) : null,
        // Remember the YAML spelling: a list stays a list even with one entry.
        fromList: Array.isArray(transition?.from),
        injected,
        position,
    };
}

/**
 * Handle-keyed model → graph.
 *
 * @param {object} model                 the editor model (normalize()) or a raw definition
 * @param {object} [options]
 * @param {{places?: object, transitions?: object}} [options.injected]
 * @returns {{places: object[], transitions: object[]}}
 */
export function toGraph(model, { injected = null } = {}) {
    const places = Object.keys(model?.places ?? {}).map((handle) => ({
        id: placeId(handle),
        handle,
        injected: false,
        position: storedPosition(model, 'places', handle),
    }));
    const transitions = Object.entries(model?.transitions ?? {}).map(([handle, transition]) =>
        transitionEntry(handle, transition, false, storedPosition(model, 'transitions', handle)),
    );

    const known = new Set(places.map((p) => p.handle));
    for (const handle of Object.keys(injected?.places ?? {})) {
        if (known.has(handle)) continue;
        places.push({ id: placeId(handle), handle, injected: true, position: null });
    }
    const knownTransitions = new Set(transitions.map((t) => t.handle));
    for (const [handle, transition] of Object.entries(injected?.transitions ?? {})) {
        if (knownTransitions.has(handle)) continue;
        transitions.push(transitionEntry(handle, transition, true, null));
    }

    return { places, transitions };
}

// A place created in the editor, in the shape normalize() produces.
function blankPlace(labelLocales) {
    return {
        labels: Object.fromEntries((labelLocales ?? ['de', 'en']).map((l) => [l, ''])),
        legacyLabel: '',
        metadata: { branch: false, actions: [] },
    };
}

function blankTransition() {
    return { from: null, to: null, metadata: { required_by: 'none', required_handles: [], actions: [] } };
}

const roundPosition = ({ x, y }) => ({ x: Math.round(x), y: Math.round(y) });

/**
 * Graph → handle-keyed model. `model` provides everything the graph does not
 * carry (labels, metadata, actions …); the result is a new model with
 *
 *   - places and transitions in graph order, dropping those the graph lacks;
 *   - each transition's `from`/`to` taken from the graph (a single source as a
 *     scalar unless the YAML spelled it as a list);
 *   - `metadata.editor.{places,transitions}` rewritten from the graph positions
 *     (other keys under `metadata.editor` are kept; positions of nodes without
 *     one are dropped; the block is left out entirely while nothing has one).
 *
 * Injected nodes are ignored. A node without a handle cannot be written and is
 * skipped — naming it is the caller's job.
 *
 * @param {object} graph
 * @param {object} model
 * @param {object} [options]
 * @param {string[]} [options.labelLocales]  for places created in the editor
 */
export function fromGraph(graph, model, { labelLocales } = {}) {
    const copy = JSON.parse(JSON.stringify(model ?? {}));
    const handleOf = new Map();
    for (const p of graph?.places ?? []) {
        if (!p.injected && p.handle) handleOf.set(p.id, p.handle);
    }
    // Injected places can still be a transition's endpoint in a hand-written
    // definition — resolve them by handle all the same.
    for (const p of graph?.places ?? []) {
        if (p.injected && p.handle) handleOf.set(p.id, p.handle);
    }

    const places = {};
    const placePositions = {};
    for (const p of graph?.places ?? []) {
        if (p.injected || !p.handle) continue;
        places[p.handle] = copy.places?.[p.handle] ?? blankPlace(labelLocales);
        const pos = toPosition(p.position);
        if (pos) placePositions[p.handle] = roundPosition(pos);
    }

    const transitions = {};
    const transitionPositions = {};
    for (const t of graph?.transitions ?? []) {
        if (t.injected || !t.handle) continue;
        const transition = copy.transitions?.[t.handle] ?? blankTransition();
        const from = (t.from ?? []).map((id) => handleOf.get(id)).filter(Boolean);
        const nextFrom = t.fromList || from.length > 1 ? from : (from[0] ?? null);
        const nextTo = t.to ? (handleOf.get(t.to) ?? null) : null;
        // An absent key stays absent while there is nothing to put there.
        if (nextFrom !== null || 'from' in transition) transition.from = nextFrom;
        if (nextTo !== null || 'to' in transition) transition.to = nextTo;
        transitions[t.handle] = transition;
        const pos = toPosition(t.position);
        if (pos) transitionPositions[t.handle] = roundPosition(pos);
    }

    copy.places = places;
    copy.transitions = transitions;
    copy.metadata ??= {};

    const editor = { ...(copy.metadata.editor && typeof copy.metadata.editor === 'object' ? copy.metadata.editor : {}) };
    delete editor.places;
    delete editor.transitions;
    if (Object.keys(placePositions).length) editor.places = placePositions;
    if (Object.keys(transitionPositions).length) editor.transitions = transitionPositions;
    if (Object.keys(editor).length) {
        copy.metadata.editor = editor;
    } else {
        delete copy.metadata.editor;
    }

    return copy;
}

/**
 * The place ids at either end of a transition's edges, as [source, target]
 * pairs: one per source into the transition, one out of it to its target.
 *
 * @param {object} transition  a graph transition
 * @returns {Array<[string, string]>}
 */
export function transitionLinks(transition) {
    const links = (transition.from ?? []).map((from) => [from, transition.id]);
    if (transition.to) links.push([transition.id, transition.to]);
    return links;
}
