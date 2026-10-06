// Undo/redo for the editor model, snapshot-based: every entry is the WHOLE model
// (places with positions, transitions, workflow settings, pending deletions) as
// JSON — never selection, viewport or panel state. Restoring means "make the model
// look like that snapshot again", so whatever a mutation touched (references
// cleared by a deletion, the start coupling, derived handles …) comes back with it.
//
// Two ways into the history:
//   commit(tag)  structural changes (add, delete, connect, reorder, move …) —
//                one entry right away. The model wrapper (useWorkflowModel) does
//                this through track(tag, fn).
//   (nothing)    any other change (typing a label, ticking a box, a field of an
//                action row) is noticed by a watcher and recorded once the model
//                has been quiet for `debounce` ms — a typed word is ONE entry.
//
// absorb(fn) runs a change without an entry: changes the author did not make
// (the canvas placing new nodes, usage counts from the server).
//
// Restoring happens IN PLACE (patchInPlace): entries with an `id` keep their
// objects, other objects keep theirs where the key survives, so components holding
// a node (`indexOf(place)`, keyed panels, Vue Flow's diffing) stay attached.

import { computed, ref, shallowRef, watch } from 'vue';

const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const hasIds = (list) => list.every((e) => isPlainObject(e) && typeof e.id === 'string');

/** The default snapshot: the editor model's four parts as one JSON string. */
export function modelSnapshot(model) {
    return JSON.stringify({
        places: model.places,
        transitions: model.transitions,
        workflow: model.workflow,
        deletions: model.deletions,
    });
}

// `next` merged into `current` where both are objects / both arrays (returns
// `current`, now equal to `next`), else `next` itself.
function reconcile(current, next) {
    if (Array.isArray(current) && Array.isArray(next)) {
        patchArray(current, next);
        return current;
    }
    if (isPlainObject(current) && isPlainObject(next)) {
        patchObject(current, next);
        return current;
    }
    return next;
}

function patchObject(target, source) {
    const targetKeys = Object.keys(target);
    const sourceKeys = Object.keys(source);
    const sameOrder = targetKeys.length === sourceKeys.length && targetKeys.every((k, i) => k === sourceKeys[i]);
    if (sameOrder) {
        for (const key of sourceKeys) {
            const value = reconcile(target[key], source[key]);
            if (target[key] !== value) target[key] = value;
        }
        return;
    }
    // Key order is part of the payload (serialize() copies objects as they are),
    // so a restored object gets the snapshot's order — re-adding every key while
    // reusing the old child objects.
    const old = {};
    for (const key of targetKeys) {
        old[key] = target[key];
        delete target[key];
    }
    for (const key of sourceKeys) target[key] = key in old ? reconcile(old[key], source[key]) : source[key];
}

function patchArray(target, source) {
    if (hasIds(source) && hasIds(target)) {
        // Places, transitions, deletions: matched by id, so a node keeps its object.
        const pool = new Map(target.map((e) => [e.id, e]));
        const next = source.map((e) => (pool.has(e.id) ? reconcile(pool.get(e.id), e) : e));
        if (next.length !== target.length || next.some((e, i) => e !== target[i])) target.splice(0, target.length, ...next);
        return;
    }
    for (let i = 0; i < source.length; i++) {
        if (i < target.length) {
            const value = reconcile(target[i], source[i]);
            if (target[i] !== value) target[i] = value;
        } else {
            target.push(source[i]);
        }
    }
    if (target.length > source.length) target.splice(source.length);
}

/**
 * Make `model` deep-equal to `state` (a parsed snapshot) without replacing the
 * objects that survive. Only the model's own four parts are touched.
 */
export function patchInPlace(model, state) {
    for (const key of ['places', 'transitions', 'deletions']) {
        if (Array.isArray(model[key])) {
            patchArray(model[key], state[key] ?? []);
        } else {
            model[key] = state[key] ?? [];
        }
    }
    if (isPlainObject(model.workflow)) {
        patchObject(model.workflow, state.workflow ?? {});
    } else {
        model.workflow = state.workflow ?? {};
    }
}

/**
 * @param {object} model  the REACTIVE editor model
 * @param {object} [options]
 * @param {number} [options.limit]     entries kept for undo
 * @param {number} [options.debounce]  ms of quiet before untagged edits become an entry
 * @param {(model) => string} [options.snapshot]
 * @param {(model, state) => void} [options.restore]
 * @param {(info: {tag: string, direction: 'undo'|'redo'}) => void} [options.onRestore]
 */
export function useHistory(model, {
    limit = 100,
    debounce = 400,
    snapshot = modelSnapshot,
    restore = patchInPlace,
    onRestore = null,
} = {}) {
    // past: [{state, tag}] — `state` is the model BEFORE the change `tag`.
    // future: [{state, tag}] — `state` is the model AFTER the change `tag`.
    const past = shallowRef([]);
    const future = shallowRef([]);

    const current = computed(() => snapshot(model));
    // What the model looked like after the last entry (or restore / absorb).
    const last = ref(current.value);

    let timer = null;
    // A burst of the same change (arrow-key nudges) extends one entry.
    let burst = null;
    // Inside track(): nested tracked mutations become part of the outer entry.
    let depth = 0;

    const changed = computed(() => current.value !== last.value);

    function push(entry) {
        const list = [...past.value, entry];
        past.value = list.length > limit ? list.slice(list.length - limit) : list;
        future.value = [];
    }

    function clearTimer() {
        if (timer) clearTimeout(timer);
        timer = null;
    }

    /** Record pending untagged edits as their own entry right now. */
    function flush() {
        clearTimer();
        const now = current.value;
        if (now === last.value) return false;
        push({ state: last.value, tag: 'edit' });
        last.value = now;
        burst = null;
        return true;
    }

    /**
     * The model changed on purpose: one entry tagged `tag`.
     *
     * @param {string} tag
     * @param {object} [options]
     * @param {string} [options.coalesce]  same key within `debounce` ms → the
     *                                     previous entry grows instead
     */
    function commit(tag, { coalesce = null } = {}) {
        if (depth > 0) return false;
        clearTimer();
        const now = current.value;
        if (now === last.value) return false;
        const time = Date.now();
        const extend = coalesce && burst && burst.key === coalesce && time - burst.time < debounce && past.value.length > 0;
        if (extend) {
            future.value = [];
        } else {
            push({ state: last.value, tag });
        }
        burst = coalesce ? { key: coalesce, time } : null;
        last.value = now;
        return true;
    }

    /** Run `fn` (a mutation) as one entry: pending edits first, then `tag`. */
    function track(tag, fn, options = {}) {
        if (depth > 0) return fn();
        flush();
        depth++;
        try {
            return fn();
        } finally {
            depth--;
            commit(tag, options);
        }
    }

    /** Run `fn` (a change the author did not make) without an entry. */
    function absorb(fn) {
        if (depth > 0) return fn();
        flush();
        try {
            return fn();
        } finally {
            last.value = current.value;
        }
    }

    function apply(state, tag, direction) {
        restore(model, JSON.parse(state));
        // Re-read rather than trusting `state`: whatever the restore could not
        // reproduce byte for byte must not look like a fresh edit.
        last.value = current.value;
        burst = null;
        onRestore?.({ tag, direction });
    }

    /** Step back. Returns the tag undone, or null. */
    function undo() {
        if (depth > 0) return null;
        flush();
        const list = past.value;
        if (!list.length) return null;
        const entry = list[list.length - 1];
        past.value = list.slice(0, -1);
        future.value = [...future.value, { state: last.value, tag: entry.tag }];
        apply(entry.state, entry.tag, 'undo');
        return entry.tag;
    }

    /** Step forward again. Returns the tag redone, or null. */
    function redo() {
        if (depth > 0) return null;
        // An edit after an undo starts a new branch — nothing left to redo.
        if (flush()) return null;
        const list = future.value;
        if (!list.length) return null;
        const entry = list[list.length - 1];
        future.value = list.slice(0, -1);
        past.value = [...past.value, { state: last.value, tag: entry.tag }];
        apply(entry.state, entry.tag, 'redo');
        return entry.tag;
    }

    /** Forget everything (after a save: undo must not cross it). */
    function clear() {
        clearTimer();
        past.value = [];
        future.value = [];
        burst = null;
        last.value = current.value;
    }

    // Untagged edits: recorded after `debounce` ms of quiet.
    const stop = watch(current, (now) => {
        if (now === last.value) return;
        clearTimer();
        timer = setTimeout(() => {
            timer = null;
            flush();
        }, debounce);
    });

    return {
        canUndo: computed(() => past.value.length > 0 || changed.value),
        canRedo: computed(() => future.value.length > 0 && !changed.value),
        // The tags next in line — for tooltips / announcements.
        undoTag: computed(() => (changed.value ? 'edit' : past.value[past.value.length - 1]?.tag ?? null)),
        redoTag: computed(() => (changed.value ? null : future.value[future.value.length - 1]?.tag ?? null)),
        size: computed(() => past.value.length),
        commit,
        track,
        flush,
        absorb,
        undo,
        redo,
        clear,
        stop() {
            clearTimer();
            stop();
        },
    };
}
