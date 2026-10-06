// The graph editor's own model: every place and transition is an entry with a
// STABLE id (graphModel.js: `p:<handle>` / `t:<handle>` for what was loaded,
// `p~1` / `t~1` … for what the author adds), and every reference between them —
// a transition's `from`/`to`, `initial_place`, the SLA steps, `create_transition`,
// an `auto_transition` action … — holds that id instead of a handle. So a new
// node can be renamed (its handle follows its label until the author types one)
// without chasing references, and the canvas keeps tracking the same node.
//
// Framework-free and, apart from the mutations at the bottom (which exist to
// mutate), pure. The handle-keyed model the F0 modules work on (normalize.js /
// serialize.js / actionRules.js / labels.js / nodeInfo.js) is produced at the
// boundary:
//
//   definition ──normalize()──► handle model ──fromDefinition()──► editor model
//   editor model ──toHandleModel()──► handle model ──serialize()──► payload
//   editor model ──viewModel()──► shallow handle model for the rule/label/badge
//                                  helpers (shares the action objects, so the
//                                  identity checks in actionRules.js still work)
//
//   model = {
//       places:      [{ id, handle, isNew, handleEdited, injected: false, position,
//                       labels, legacyLabel, metadata, extra }],
//       transitions: [{ id, handle, isNew, handleEdited, injected: false, position,
//                       from: [placeId], to: placeId|null, fromList, metadata, extra }],
//       workflow:    { metadata, collections, initial_place: placeId|null, marking_store, type },
//       deletions:   [{ id, handle, usage, migrateTo: placeId|null, entry, index }],
//   }
//
// Array order IS the YAML order. `extra` carries keys of a place/transition this
// editor does not know, so they survive the round trip. `metadata.editor` lives
// on the entries as `position`; its other keys stay in workflow.metadata.editor.
// A node without a handle yet (a new one with neither label nor handle) is keyed
// by its id in the handle model, and blocks a save (danglingEntries()).

import { HANDLE_PATTERN } from './constants.js';
import { NEW_PLACE_PREFIX, NEW_TRANSITION_PREFIX, nextNewId, placeId, storedPosition, toGraph, toPosition, transitionId } from './graphModel.js';
import { emptyLabels, normalize } from './normalize.js';
import { ownPlaceLabels } from './labels.js';
import { REFERENCES } from './refs.js';
import { serialize, slugify } from './serialize.js';

const clone = (value) => (value === undefined ? undefined : JSON.parse(JSON.stringify(value)));

const PLACE_KEYS = ['labels', 'legacyLabel', 'metadata'];
const TRANSITION_KEYS = ['from', 'to', 'metadata'];

// The key a node has in the handle model: its handle, or its id while it has none.
export const keyOf = (entry) => entry?.handle || entry?.id;

/**
 * Rewrite every reference of `kind` in a handle-shaped model through `map`
 * (value → replacement, or undefined to leave it). Mutates `model` — callers hand
 * in their own copy. Goes through the refs.js registry, so a new referencing key
 * is covered here the moment it is registered there.
 */
export function mapReferences(model, kind, map, { roles = null } = {}) {
    for (const ref of REFERENCES) {
        if (ref.kind !== kind || (roles && !roles.includes(ref.role))) continue;
        for (const slot of ref.slots(model)) {
            for (const value of slot.values()) {
                const next = map(value);
                if (next !== undefined && next !== value) slot.replace(value, next);
            }
        }
    }
    return model;
}

/**
 * Definition (as the server sends it) → editor model.
 *
 * @param {object} def
 * @param {object} [options]
 * @param {string[]} [options.labelLocales]
 */
export function fromDefinition(def, { labelLocales } = {}) {
    const handleModel = normalize(def, { labelLocales });
    return fromHandleModel(handleModel);
}

/** Handle model (normalize()) → editor model. `handleModel` is consumed (mutated). */
export function fromHandleModel(handleModel) {
    const placeHandles = Object.keys(handleModel.places ?? {});
    const transitionHandles = Object.keys(handleModel.transitions ?? {});
    const placeSet = new Set(placeHandles);
    const transitionSet = new Set(transitionHandles);

    // Positions first: reading them needs the handle-keyed `metadata.editor`.
    const placePositions = Object.fromEntries(placeHandles.map((h) => [h, storedPosition(handleModel, 'places', h)]));
    const transitionPositions = Object.fromEntries(transitionHandles.map((h) => [h, storedPosition(handleModel, 'transitions', h)]));
    const fromLists = Object.fromEntries(transitionHandles.map((h) => [h, Array.isArray(handleModel.transitions[h]?.from)]));

    // Handles → ids, in place. References to handles that don't exist stay as
    // they are (a hand-written typo survives the round trip instead of vanishing).
    mapReferences(handleModel, 'place', (v) => (placeSet.has(v) ? placeId(v) : undefined));
    mapReferences(handleModel, 'transition', (v) => (transitionSet.has(v) ? transitionId(v) : undefined));

    const places = placeHandles.map((handle) => {
        const { labels, legacyLabel, metadata, ...extra } = handleModel.places[handle];
        return {
            id: placeId(handle),
            handle,
            isNew: false,
            handleEdited: true,
            injected: false,
            position: placePositions[handle],
            labels,
            legacyLabel,
            metadata,
            extra,
        };
    });

    const transitions = transitionHandles.map((handle) => {
        const { from, to, metadata, ...extra } = handleModel.transitions[handle];
        return {
            id: transitionId(handle),
            handle,
            isNew: false,
            handleEdited: true,
            injected: false,
            position: transitionPositions[handle],
            from: [].concat(from ?? []).filter((v) => typeof v === 'string' && v !== ''),
            to: typeof to === 'string' && to !== '' ? to : null,
            fromList: fromLists[handle],
            metadata,
            extra,
        };
    });

    const metadata = handleModel.metadata ?? {};
    if (metadata.editor && typeof metadata.editor === 'object') {
        const { places: _p, transitions: _t, ...rest } = metadata.editor;
        if (Object.keys(rest).length) {
            metadata.editor = rest;
        } else {
            delete metadata.editor;
        }
    }

    const model = {
        places,
        transitions,
        workflow: {
            metadata,
            collections: handleModel.collections ?? [],
            initial_place: handleModel.initial_place ?? null,
            marking_store: handleModel.marking_store,
            type: handleModel.type,
        },
        deletions: [],
    };

    enforceStartCoupling(model);

    return model;
}

// id → handle (or the id itself while the node has none).
function keyMaps(model) {
    const places = new Map(model.places.map((p) => [p.id, keyOf(p)]));
    const transitions = new Map(model.transitions.map((t) => [t.id, keyOf(t)]));
    return { places, transitions };
}

const fromValue = (entry, keys) => {
    const from = entry.from.map((id) => keys.get(id) ?? id);
    return entry.fromList || from.length > 1 ? from : (from[0] ?? null);
};

/**
 * Editor model → handle model (deep copy, refs as handles, positions written to
 * `metadata.editor`). What serialize() takes.
 */
export function toHandleModel(model) {
    const keys = keyMaps(model);

    const places = {};
    const placePositions = {};
    for (const entry of model.places) {
        const key = keyOf(entry);
        places[key] = clone({ ...entry.extra, labels: entry.labels, legacyLabel: entry.legacyLabel, metadata: entry.metadata });
        const pos = toPosition(entry.position);
        if (pos) placePositions[key] = { x: Math.round(pos.x), y: Math.round(pos.y) };
    }

    const transitions = {};
    const transitionPositions = {};
    for (const entry of model.transitions) {
        const key = keyOf(entry);
        const out = {};
        const from = fromValue(entry, keys.places);
        // An absent key stays absent while there is nothing to put there.
        if (from !== null) out.from = from;
        if (entry.to) out.to = keys.places.get(entry.to) ?? entry.to;
        out.metadata = clone(entry.metadata);
        transitions[key] = Object.assign(out, clone(entry.extra ?? {}));
        const pos = toPosition(entry.position);
        if (pos) transitionPositions[key] = { x: Math.round(pos.x), y: Math.round(pos.y) };
    }

    const handleModel = {
        places,
        transitions,
        metadata: clone(model.workflow.metadata ?? {}),
        collections: clone(model.workflow.collections ?? []),
        initial_place: model.workflow.initial_place,
        marking_store: model.workflow.marking_store,
        type: model.workflow.type,
    };

    // Workflow-level refs and the auto_transition actions. from/to are already
    // handles — mapping them again is a no-op for keys the maps don't hold.
    mapReferences(handleModel, 'place', (v) => keys.places.get(v));
    mapReferences(handleModel, 'transition', (v) => keys.transitions.get(v));

    const editor = { ...(handleModel.metadata.editor && typeof handleModel.metadata.editor === 'object' ? handleModel.metadata.editor : {}) };
    if (Object.keys(placePositions).length) editor.places = placePositions;
    if (Object.keys(transitionPositions).length) editor.transitions = transitionPositions;
    if (Object.keys(editor).length) {
        handleModel.metadata.editor = editor;
    } else {
        delete handleModel.metadata.editor;
    }

    return handleModel;
}

/**
 * A SHALLOW handle-keyed view for the read-side helpers (nodeInfo, actionRules,
 * labels): places/transitions share their `labels`/`metadata` objects with the
 * editor model — so `a !== current` in actionRules.js still recognises the row
 * being edited — while `from`/`to`, `initial_place` and the workflow-level
 * references are translated to handles on copies. The `transition` of an
 * `auto_transition` action stays an id here (it lives inside a shared object).
 * Never mutate it.
 */
export function viewModel(model) {
    const keys = keyMaps(model);
    const places = {};
    for (const entry of model.places) {
        places[keyOf(entry)] = { labels: entry.labels, legacyLabel: entry.legacyLabel, metadata: entry.metadata };
    }
    const transitions = {};
    for (const entry of model.transitions) {
        transitions[keyOf(entry)] = {
            from: fromValue(entry, keys.places),
            to: entry.to ? (keys.places.get(entry.to) ?? entry.to) : null,
            metadata: entry.metadata,
        };
    }
    const source = model.workflow.metadata ?? {};
    const metadata = { ...source, sla: { ...(source.sla ?? {}) } };
    const view = {
        places,
        transitions,
        metadata,
        collections: model.workflow.collections,
        initial_place: model.workflow.initial_place,
        marking_store: model.workflow.marking_store,
        type: model.workflow.type,
    };
    const workflowRoles = REFERENCES.filter((r) => !['transition_from', 'transition_to', 'auto_transition'].includes(r.role)).map((r) => r.role);
    mapReferences(view, 'place', (v) => keys.places.get(v), { roles: workflowRoles });
    mapReferences(view, 'transition', (v) => keys.transitions.get(v), { roles: workflowRoles });
    return view;
}

/**
 * The graph the canvas draws: the model's nodes (keyed for nodeInfo by
 * keyOf()) plus the parts DefinitionLoader injects, which keep their
 * `p:<handle>` / `t:<handle>` ids.
 */
export function editorGraph(model, injected = null) {
    const places = model.places.map((p) => ({ id: p.id, handle: keyOf(p), injected: false, position: p.position ?? null }));
    const transitions = model.transitions.map((t) => ({
        id: t.id,
        handle: keyOf(t),
        from: [...new Set(t.from)],
        to: t.to,
        fromList: t.fromList,
        injected: false,
        position: t.position ?? null,
    }));
    const extra = toGraph({}, { injected });
    const placeKeys = new Set(places.map((p) => p.handle));
    const transitionKeys = new Set(transitions.map((t) => t.handle));
    for (const p of extra.places) if (!placeKeys.has(p.handle)) places.push(p);
    for (const t of extra.transitions) if (!transitionKeys.has(t.handle)) transitions.push(t);
    return { places, transitions };
}

/**
 * The save payload: `{version?, definition, removed_places}` minus request
 * flags. `ctx` as for serialize() (customActions …).
 */
export function toPayload(model, ctx = {}) {
    const keys = keyMaps(model);
    return {
        definition: serialize(toHandleModel(model), ctx),
        removed_places: model.deletions.map((d) => (d.migrateTo
            ? { place: d.handle, migrate_to: keys.places.get(d.migrateTo) ?? d.migrateTo }
            : { place: d.handle })),
    };
}

/** id → handle for every node right now (the ids a save will turn into `p:<handle>`). */
export function handleSnapshot(model) {
    const out = {};
    for (const p of model.places) if (p.handle) out[p.id] = placeId(p.handle);
    for (const t of model.transitions) if (t.handle) out[t.id] = transitionId(t.handle);
    return out;
}

// --- lookups ---

export const findPlace = (model, id) => model.places.find((p) => p.id === id) ?? null;
export const findTransition = (model, id) => model.transitions.find((t) => t.id === id) ?? null;
export const findNode = (model, id) => findPlace(model, id) ?? findTransition(model, id);
export const findDeletion = (model, id) => model.deletions.find((d) => d.id === id) ?? null;

/** The id of the place / transition with `handle`, or null. */
export const placeIdOf = (model, handle) => model.places.find((p) => p.handle === handle)?.id ?? null;
export const transitionIdOf = (model, handle) => model.transitions.find((t) => t.handle === handle)?.id ?? null;

// The DEFAULT start (first transition) and the one `start_localization` pins —
// actionRules.js on ids.
export const startingTransitionId = (model) => model.transitions[0]?.id ?? null;
export function explicitStartTransitionId(model) {
    return model.transitions.find((t) => (t.metadata?.actions ?? []).some((a) => a?.type === 'start_localization'))?.id ?? null;
}

/** Transitions into / out of a place (non-injected), as entries. */
export const incomingTransitions = (model, id) => model.transitions.filter((t) => t.to === id);
export const outgoingTransitions = (model, id) => model.transitions.filter((t) => t.from.includes(id));

/** The steps an `auto_transition` on place `id` may take, with ids as values. */
export function autoTransitionOptions(model, id) {
    return outgoingTransitions(model, id)
        .filter((t) => !t.metadata?.system)
        .map((t) => ({ value: t.id, label: `${t.metadata?.label || keyOf(t)} (${keyOf(t)})` }));
}

// --- handles of new nodes ---

/**
 * What is wrong with a node's handle, or null: 'missing' | 'invalid' |
 * 'reserved' | 'taken'. Handles of places marked for deletion count as taken —
 * the server refuses a removed place that is still present.
 */
export function handleProblem(model, id, { reservedPlaces = [], reservedTransitions = [] } = {}) {
    const entry = findNode(model, id);
    if (!entry) return null;
    const kind = model.places.includes(entry) ? 'place' : 'transition';
    const handle = entry.handle ?? '';
    if (!handle) return 'missing';
    if (!entry.isNew) return null;
    if (!HANDLE_PATTERN.test(handle)) return 'invalid';
    if ((kind === 'place' ? reservedPlaces : reservedTransitions).includes(handle)) return 'reserved';
    const list = kind === 'place' ? model.places : model.transitions;
    if (list.some((e) => e !== entry && e.handle === handle)) return 'taken';
    if (kind === 'place' && model.deletions.some((d) => d.handle === handle)) return 'taken';
    return null;
}

/** Every node a save would fail on for its handle, as [{id, problem}]. */
export function handleProblems(model, ctx = {}) {
    return [...model.places, ...model.transitions]
        .map((e) => ({ id: e.id, problem: handleProblem(model, e.id, ctx) }))
        .filter((p) => p.problem);
}

// A handle derived from a label that no other node of the kind uses yet.
function uniqueHandle(model, kind, base, self, reserved = []) {
    if (!base) return null;
    const list = kind === 'place' ? model.places : model.transitions;
    const taken = new Set(list.filter((e) => e !== self).map((e) => e.handle).filter(Boolean));
    if (kind === 'place') model.deletions.forEach((d) => taken.add(d.handle));
    reserved.forEach((h) => taken.add(h));
    let candidate = /^[a-z]/.test(base) ? base : `n_${base}`;
    let n = 2;
    const stem = candidate;
    while (taken.has(candidate)) candidate = `${stem}_${n++}`;
    return candidate;
}

function deriveHandle(model, entry, kind, ctx) {
    if (!entry.isNew || entry.handleEdited) return;
    const label = kind === 'place'
        ? ownPlaceLabels({ places: { x: entry } }, 'x', ctx)[0] ?? ''
        : entry.metadata?.label ?? '';
    entry.handle = uniqueHandle(model, kind, slugify(label), entry, kind === 'place' ? ctx.reservedPlaces : ctx.reservedTransitions);
}

// --- mutations ---

// Merge `patch` into `entry`: one level deep for the object-valued keys
// (labels, metadata), so a panel can send `{metadata: {color}}`.
function merge(entry, patch) {
    for (const [key, value] of Object.entries(patch ?? {})) {
        if (value && typeof value === 'object' && !Array.isArray(value) && entry[key] && typeof entry[key] === 'object' && !Array.isArray(entry[key])) {
            Object.assign(entry[key], value);
        } else {
            entry[key] = value;
        }
    }
}

export function updatePlace(model, id, patch, ctx = {}) {
    const entry = findPlace(model, id);
    if (!entry) return;
    merge(entry, patch);
    if (patch?.labels) deriveHandle(model, entry, 'place', ctx);
}

export function updateTransition(model, id, patch, ctx = {}) {
    const entry = findTransition(model, id);
    if (!entry) return;
    merge(entry, patch);
    if (patch?.metadata && 'label' in patch.metadata) deriveHandle(model, entry, 'transition', ctx);
    if ('from' in (patch ?? {}) || 'to' in (patch ?? {})) enforceStartCoupling(model);
}

/** Type a handle for a new node. Empty → back to deriving it from the label. */
export function setHandle(model, id, value, ctx = {}) {
    const entry = findNode(model, id);
    if (!entry?.isNew) return;
    const kind = model.places.includes(entry) ? 'place' : 'transition';
    const handle = String(value ?? '').trim();
    if (handle === '') {
        entry.handleEdited = false;
        entry.handle = null;
        deriveHandle(model, entry, kind, ctx);
        return;
    }
    entry.handleEdited = true;
    entry.handle = handle;
}

/**
 * The first transition leads out of the start place — the old form's watcher,
 * as an invariant: unless a `start_localization` action pins the start
 * elsewhere, the first transition's `from` IS `initial_place` (and a scalar).
 */
export function enforceStartCoupling(model) {
    if (explicitStartTransitionId(model)) return;
    const first = model.transitions[0];
    const initial = model.workflow.initial_place;
    if (!first || !initial) return;
    first.from = [initial];
    first.fromList = false;
}

export function setInitialPlace(model, id) {
    model.workflow.initial_place = id;
    enforceStartCoupling(model);
}

function moveIn(list, id, toIndex) {
    const from = list.findIndex((e) => e.id === id);
    if (from < 0) return false;
    const to = Math.max(0, Math.min(list.length - 1, toIndex));
    if (to === from) return false;
    const [entry] = list.splice(from, 1);
    list.splice(to, 0, entry);
    return true;
}

export function movePlace(model, id, toIndex) {
    return moveIn(model.places, id, toIndex);
}

export function moveTransition(model, id, toIndex) {
    const moved = moveIn(model.transitions, id, toIndex);
    if (moved) enforceStartCoupling(model);
    return moved;
}

/** Replace the whole place/transition order (OrderList's drag result). */
export function reorder(model, kind, ids) {
    const list = kind === 'place' ? model.places : model.transitions;
    const byId = new Map(list.map((e) => [e.id, e]));
    const next = ids.map((id) => byId.get(id)).filter(Boolean);
    if (next.length !== list.length) return;
    list.splice(0, list.length, ...next);
    if (kind === 'transition') enforceStartCoupling(model);
}

export function setPosition(model, id, position) {
    const entry = findNode(model, id);
    if (entry) entry.position = toPosition(position);
}

export function resetPositions(model) {
    for (const entry of [...model.places, ...model.transitions]) entry.position = null;
}

/** A new place at the end of the order. Returns its id. */
export function addPlace(model, { labelLocales, position = null, label = null } = {}) {
    const id = nextNewId(model, 'place');
    const labels = emptyLabels(labelLocales);
    model.places.push({
        id,
        handle: null,
        isNew: true,
        handleEdited: false,
        injected: false,
        position: toPosition(position),
        labels,
        legacyLabel: '',
        metadata: { branch: false, actions: [] },
        extra: {},
    });
    if (label) updatePlace(model, id, { labels: { [Object.keys(labels)[0]]: label } }, { labelLocales });
    return id;
}

/** A new transition at the end of the order. Returns its id. */
export function addTransition(model, { from = null, to = null, position = null } = {}) {
    const id = nextNewId(model, 'transition');
    model.transitions.push({
        id,
        handle: null,
        isNew: true,
        handleEdited: false,
        injected: false,
        position: toPosition(position),
        from: [].concat(from ?? []).filter(Boolean),
        to: to ?? null,
        fromList: false,
        metadata: { label: '', required_by: 'none', required_handles: [], actions: [] },
        extra: {},
    });
    enforceStartCoupling(model);
    return id;
}

// Clear every reference to `id` (a place or a transition) in the model.
function clearReferences(model, kind, id) {
    if (kind === 'place') {
        for (const t of model.transitions) {
            if (t.from.includes(id)) t.from = t.from.filter((v) => v !== id);
            if (t.to === id) t.to = null;
        }
        if (model.workflow.initial_place === id) model.workflow.initial_place = null;
        for (const d of model.deletions) if (d.migrateTo === id) d.migrateTo = null;
    }
    // The workflow-level slots and the auto_transition actions: the registry,
    // on a handle-shaped stand-in that shares the real objects.
    const shape = {
        metadata: model.workflow.metadata,
        places: Object.fromEntries(model.places.map((p) => [p.id, p])),
        transitions: {},
    };
    const roles = REFERENCES.filter((r) => r.kind === kind && !['transition_from', 'transition_to', 'initial_place'].includes(r.role)).map((r) => r.role);
    mapReferences(shape, kind, (v) => (v === id ? null : undefined), { roles });
}

export function removeTransition(model, id) {
    const index = model.transitions.findIndex((t) => t.id === id);
    if (index < 0) return false;
    model.transitions.splice(index, 1);
    clearReferences(model, 'transition', id);
    enforceStartCoupling(model);
    return true;
}

// Everything in the model that points at place `id`, as a record removePlace()
// keeps on the deletion so restoreDeletion() can put it back: the transitions it
// is a source / the target of, other deletions migrating into it, and the
// workflow-level slots (SLA places, seed place …) by role.
function placeLinks(model, id) {
    const shape = { metadata: model.workflow.metadata };
    const roles = REFERENCES
        .filter((r) => r.kind === 'place' && !['transition_from', 'transition_to', 'initial_place'].includes(r.role))
        .filter((r) => r.slots(shape).some((slot) => slot.values().includes(id)))
        .map((r) => r.role);
    return {
        from: model.transitions.filter((t) => t.from.includes(id)).map((t) => ({ transition: t.id, index: t.from.indexOf(id) })),
        to: model.transitions.filter((t) => t.to === id).map((t) => t.id),
        migrations: model.deletions.filter((d) => d.migrateTo === id).map((d) => d.id),
        roles,
    };
}

/**
 * Remove a place. The start place can't go (returns false). A place that was
 * saved before is recorded in `deletions` (the server needs it in
 * `removed_places`, with a migration target when entries sit in it); a new one
 * just disappears. Every reference to it is cleared — and remembered on the
 * deletion (`links`), so restoreDeletion() brings them back.
 */
export function removePlace(model, id, { usage = 0, migrateTo = null } = {}) {
    if (model.workflow.initial_place === id) return false;
    const index = model.places.findIndex((p) => p.id === id);
    if (index < 0) return false;
    const links = placeLinks(model, id);
    const [entry] = model.places.splice(index, 1);
    if (!entry.isNew) {
        model.deletions.push({ id, handle: entry.handle, usage: Number(usage) || 0, migrateTo: migrateTo ?? null, entry, index, links });
    }
    clearReferences(model, 'place', id);
    return true;
}

/**
 * Undo a pending deletion: the place comes back, and so do the references it
 * lost — as far as their holders still exist and have not been pointed
 * elsewhere since (a transition's `to` that now leads to another place stays).
 */
export function restoreDeletion(model, id) {
    const i = model.deletions.findIndex((d) => d.id === id);
    if (i < 0) return false;
    const [deletion] = model.deletions.splice(i, 1);
    model.places.splice(Math.min(deletion.index, model.places.length), 0, deletion.entry);

    const links = deletion.links ?? {};
    for (const { transition: tid, index } of links.from ?? []) {
        const t = findTransition(model, tid);
        if (!t || t.from.includes(id)) continue;
        const from = [...t.from];
        from.splice(Math.min(index, from.length), 0, id);
        t.from = from;
    }
    for (const tid of links.to ?? []) {
        const t = findTransition(model, tid);
        if (t && !t.to) t.to = id;
    }
    for (const did of links.migrations ?? []) {
        const d = findDeletion(model, did);
        if (d && !d.migrateTo) d.migrateTo = id;
    }
    const shape = { metadata: model.workflow.metadata };
    for (const ref of REFERENCES.filter((r) => (links.roles ?? []).includes(r.role))) {
        for (const slot of ref.slots(shape)) {
            if (slot.values().length) continue;
            for (const empty of [null, undefined, '']) slot.replace(empty, id);
        }
    }
    enforceStartCoupling(model);
    return true;
}

export function setMigrationTarget(model, id, target) {
    const deletion = findDeletion(model, id);
    if (deletion) deletion.migrateTo = target || null;
}

/**
 * Replace the model's contents with what the server saved. Returns `old id →
 * new id` for every node that survived (new nodes `p~1` → `p:<handle>`), so the
 * caller can keep its selection.
 */
export function applyServerDefinition(model, def, { labelLocales, snapshot = null } = {}) {
    const map = snapshot ?? handleSnapshot(model);
    const fresh = fromDefinition(def, { labelLocales });
    model.places = fresh.places;
    model.transitions = fresh.transitions;
    model.workflow = fresh.workflow;
    model.deletions = [];
    return map;
}

// The id helpers graphModel.js counts on.
export { NEW_PLACE_PREFIX, NEW_TRANSITION_PREFIX };
