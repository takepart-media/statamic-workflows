// The editor's model as reactive state with named mutations — the only way the
// panels and the canvas change it structurally. Field edits inside an action row
// (criteria, custom values …) still write to the row object directly; everything
// that touches ids, order, handles or references goes through here.
//
// Undo/redo (useHistory.js): every structural mutation below is one step; field
// edits — through updatePlace() or straight into a row — are collected by the
// history's watcher (a typed word = one step).
//
// The logic lives in model/editorModel.js (pure, tested); this binds it to one
// reactive model and to the editor's context (label languages, reserved handles,
// custom action declarations).

import { computed, reactive, ref } from 'vue';
import * as M from '../model/editorModel.js';
import * as C from '../model/connections.js';
import * as A from '../model/canvasActions.js';
import { useHistory } from './useHistory.js';

/**
 * @param {object} definition         the stored definition (editSettings() `definition`)
 * @param {object} ctx                getters: labelLocales, reservedPlaces,
 *                                    reservedTransitions, customActions
 * @param {object} [options]
 * @param {object} [options.history]  useHistory() options (limit, debounce, onRestore)
 */
export function useWorkflowModel(definition, ctx, { history: historyOptions = {} } = {}) {
    const model = reactive(M.fromDefinition(definition, { labelLocales: ctx.labelLocales }));

    // Handle-keyed read views — rebuilt when the model changes.
    const view = computed(() => M.viewModel(model));

    // Dirty = what a save would send differs from what is stored: the payload
    // INCLUDING node positions, so undoing back to the saved state is clean
    // again. Positions the canvas assigns by itself (the auto-layout on open,
    // newcomers placed next to their neighbours) are taken into the baseline
    // while nothing else is pending — arranging is not an edit.
    const snapshot = () => {
        const { definition: def, removed_places } = M.toPayload(model, ctx);
        return JSON.stringify({ def, removed_places, names: M.handleSnapshot(model) });
    };
    const baseline = ref(snapshot());
    const dirty = computed(() => snapshot() !== baseline.value);

    const history = useHistory(model, historyOptions);

    function markClean() {
        baseline.value = snapshot();
    }

    // A mutation that is one undo step on its own.
    const tracked = (tag, fn) => (...args) => history.track(tag, () => fn(...args));

    const api = {
        model,
        view,
        dirty,
        history,

        graph: (injected) => M.editorGraph(model, injected),
        payload: () => M.toPayload(model, ctx),
        handleProblems: () => M.handleProblems(model, ctx),
        handleProblem: (id) => M.handleProblem(model, id, ctx),

        findPlace: (id) => M.findPlace(model, id),
        findTransition: (id) => M.findTransition(model, id),
        findDeletion: (id) => M.findDeletion(model, id),
        keyOf: M.keyOf,

        // Field edits: no entry of their own — the history's watcher collects
        // them (a typed word = one step). Rewiring a transition is structural.
        updatePlace: (id, patch) => M.updatePlace(model, id, patch, ctx),
        updateTransition(id, patch) {
            if ('from' in (patch ?? {}) || 'to' in (patch ?? {})) {
                return history.track('connect', () => M.updateTransition(model, id, patch, ctx));
            }
            return M.updateTransition(model, id, patch, ctx);
        },
        // Workflow-level fields: `{metadata: {...}}` merges one level deep,
        // anything else (collections, initial_place) is set.
        updateWorkflow(patch) {
            const run = () => {
                for (const [key, value] of Object.entries(patch ?? {})) {
                    if (key === 'metadata') {
                        Object.assign(model.workflow.metadata, value);
                    } else if (key === 'initial_place') {
                        M.setInitialPlace(model, value);
                    } else {
                        model.workflow[key] = value;
                    }
                }
            };
            return 'initial_place' in (patch ?? {}) ? history.track('initial', run) : run();
        },
        setHandle: (id, value) => M.setHandle(model, id, value, ctx),
        setInitialPlace: tracked('initial', (id) => M.setInitialPlace(model, id)),
        movePlace: tracked('reorder', (id, toIndex) => M.movePlace(model, id, toIndex)),
        moveTransition: tracked('reorder', (id, toIndex) => M.moveTransition(model, id, toIndex)),
        reorder: tracked('reorder', (kind, ids) => M.reorder(model, kind, ids)),

        // The author dragged nodes: one step for all of them.
        setPosition: tracked('move', (id, position) => M.setPosition(model, id, position)),
        moveNodes: tracked('move', (positions) => {
            for (const [id, position] of Object.entries(positions ?? {})) M.setPosition(model, id, position);
        }),
        // Arrow keys: a burst of nudges on the same node is one step.
        nudge(id, dx, dy) {
            const position = M.findNode(model, id)?.position;
            if (!position) return false;
            history.flush();
            M.setPosition(model, id, { x: position.x + dx, y: position.y + dy });
            history.commit('move', { coalesce: `nudge:${id}` });
            return true;
        },
        /**
         * Positions from the canvas. `touched`: the author asked for it ("Arrange
         * automatically") — one step. Otherwise the canvas arranged by itself:
         * no step, and no edit while nothing else is pending.
         */
        setPositions(positions, { touched = false } = {}) {
            const apply = () => {
                for (const [id, position] of Object.entries(positions ?? {})) M.setPosition(model, id, position);
            };
            if (touched) {
                history.track('layout', apply);
                return;
            }
            const wasDirty = dirty.value;
            history.absorb(apply);
            if (!wasDirty) markClean();
        },
        // Forget the stored positions. The editor runs it together with the
        // auto-layout that follows in one batch('layout', …).
        resetPositions: tracked('layout', () => M.resetPositions(model)),
        addPlace: tracked('add', (options = {}) => M.addPlace(model, { labelLocales: ctx.labelLocales, ...options })),
        addTransition: tracked('add', (options = {}) => M.addTransition(model, options)),
        removeTransition: tracked('delete', (id) => M.removeTransition(model, id)),
        removePlace: tracked('delete', (id, options) => M.removePlace(model, id, options)),
        restoreDeletion: tracked('restore', (id) => M.restoreDeletion(model, id)),
        setMigrationTarget: tracked('migration', (id, target) => M.setMigrationTarget(model, id, target)),

        /** Several mutations as ONE undo step (e.g. deleting a selection). */
        batch: (tag, fn) => history.track(tag, fn),
        undo: () => history.undo(),
        redo: () => history.redo(),

        // Drawing on the canvas (model/connections.js, model/canvasActions.js).
        // `labelOf(placeId)` names places for the "<A> → <B>" label of a
        // transition created between two of them.
        canConnect: (source, target) => C.canConnect(model, source, target),
        connect: tracked('connect', (source, target, { position = null, labelOf = null } = {}) => C.applyConnect(model, source, target, { position, labelOf, ctx })),
        canDisconnect: (edge) => C.canDisconnect(model, edge),
        disconnect: tracked('disconnect', (edge) => C.applyDisconnect(model, edge)),
        isCoupledStart: (id) => C.isCoupledStart(model, id),
        addMenuEntries: (source) => A.addMenuEntries(model, source),
        checkAddAction: (source, entry) => A.checkAddAction(model, source, entry),
        runAddAction: tracked('add', (source, entry, { anchor, labelOf = null } = {}) => A.runAddAction(model, source, entry, { anchor, labelOf, labelLocales: ctx.labelLocales, ctx })),

        // What the server reports — not an edit of the author's.
        setDeletionUsage(handle, usage) {
            const deletion = model.deletions.find((d) => d.handle === handle);
            if (!deletion) return;
            const wasDirty = dirty.value;
            history.absorb(() => {
                deletion.usage = Number(usage) || 0;
            });
            if (!wasDirty) markClean();
        },

        /**
         * Replace everything with the server's definition; returns old id → new
         * id. The history starts over: the server has acted on the deletions,
         * stepping back across a save must not be possible.
         */
        applyServerDefinition(def, { snapshot: ids = null } = {}) {
            const map = M.applyServerDefinition(model, def, { labelLocales: ctx.labelLocales, snapshot: ids });
            markClean();
            history.clear();
            return map;
        },
        handleSnapshot: () => M.handleSnapshot(model),
        markClean,
    };

    return api;
}
