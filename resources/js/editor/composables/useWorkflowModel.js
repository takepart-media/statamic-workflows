// The editor's model as reactive state with named mutations — the only way the
// panels and the canvas change it structurally. Field edits inside an action row
// (criteria, custom values …) still write to the row object directly; everything
// that touches ids, order, handles or references goes through here.
//
// The logic lives in model/editorModel.js (pure, tested); this binds it to one
// reactive model and to the editor's context (label languages, reserved handles,
// custom action declarations).

import { computed, reactive, ref } from 'vue';
import * as M from '../model/editorModel.js';
import * as C from '../model/connections.js';
import * as A from '../model/canvasActions.js';

/**
 * @param {object} definition         the stored definition (editSettings() `definition`)
 * @param {object} ctx                getters: labelLocales, reservedPlaces,
 *                                    reservedTransitions, customActions
 */
export function useWorkflowModel(definition, ctx) {
    const model = reactive(M.fromDefinition(definition, { labelLocales: ctx.labelLocales }));

    // Handle-keyed read views — rebuilt when the model changes.
    const view = computed(() => M.viewModel(model));

    // Content baseline for the dirty flag: the payload without node positions
    // (an auto-layout on open must not count as an edit). Moving nodes on purpose
    // is tracked separately in `layoutTouched`.
    const snapshot = () => {
        const { definition: def, removed_places } = M.toPayload(model, ctx);
        const { editor, ...metadata } = def.metadata ?? {};
        return JSON.stringify({ def: { ...def, metadata }, removed_places, names: M.handleSnapshot(model) });
    };
    const baseline = ref(snapshot());
    const layoutTouched = ref(false);
    const dirty = computed(() => layoutTouched.value || snapshot() !== baseline.value);

    function markClean() {
        baseline.value = snapshot();
        layoutTouched.value = false;
    }

    const api = {
        model,
        view,
        dirty,

        graph: (injected) => M.editorGraph(model, injected),
        payload: () => M.toPayload(model, ctx),
        handleProblems: () => M.handleProblems(model, ctx),
        handleProblem: (id) => M.handleProblem(model, id, ctx),

        findPlace: (id) => M.findPlace(model, id),
        findTransition: (id) => M.findTransition(model, id),
        findDeletion: (id) => M.findDeletion(model, id),
        keyOf: M.keyOf,

        updatePlace: (id, patch) => M.updatePlace(model, id, patch, ctx),
        updateTransition: (id, patch) => M.updateTransition(model, id, patch, ctx),
        // Workflow-level fields: `{metadata: {...}}` merges one level deep,
        // anything else (collections, initial_place) is set.
        updateWorkflow(patch) {
            for (const [key, value] of Object.entries(patch ?? {})) {
                if (key === 'metadata') {
                    Object.assign(model.workflow.metadata, value);
                } else if (key === 'initial_place') {
                    M.setInitialPlace(model, value);
                } else {
                    model.workflow[key] = value;
                }
            }
        },
        setHandle: (id, value) => M.setHandle(model, id, value, ctx),
        setInitialPlace: (id) => M.setInitialPlace(model, id),
        movePlace: (id, toIndex) => M.movePlace(model, id, toIndex),
        moveTransition: (id, toIndex) => M.moveTransition(model, id, toIndex),
        reorder: (kind, ids) => M.reorder(model, kind, ids),
        setPosition(id, position, { touched = true } = {}) {
            M.setPosition(model, id, position);
            if (touched) layoutTouched.value = true;
        },
        setPositions(positions, { touched = false } = {}) {
            for (const [id, position] of Object.entries(positions ?? {})) M.setPosition(model, id, position);
            if (touched) layoutTouched.value = true;
        },
        resetPositions() {
            M.resetPositions(model);
            layoutTouched.value = true;
        },
        addPlace: (options = {}) => M.addPlace(model, { labelLocales: ctx.labelLocales, ...options }),
        addTransition: (options = {}) => M.addTransition(model, options),
        removeTransition: (id) => M.removeTransition(model, id),
        removePlace: (id, options) => M.removePlace(model, id, options),
        restoreDeletion: (id) => M.restoreDeletion(model, id),
        setMigrationTarget: (id, target) => M.setMigrationTarget(model, id, target),

        // Drawing on the canvas (model/connections.js, model/canvasActions.js).
        // `labelOf(placeId)` names places for the "<A> → <B>" label of a
        // transition created between two of them.
        canConnect: (source, target) => C.canConnect(model, source, target),
        connect: (source, target, { position = null, labelOf = null } = {}) => C.applyConnect(model, source, target, { position, labelOf, ctx }),
        canDisconnect: (edge) => C.canDisconnect(model, edge),
        disconnect: (edge) => C.applyDisconnect(model, edge),
        isCoupledStart: (id) => C.isCoupledStart(model, id),
        addMenuEntries: (source) => A.addMenuEntries(model, source),
        checkAddAction: (source, entry) => A.checkAddAction(model, source, entry),
        runAddAction: (source, entry, { anchor, labelOf = null } = {}) => A.runAddAction(model, source, entry, { anchor, labelOf, labelLocales: ctx.labelLocales, ctx }),

        setDeletionUsage(handle, usage) {
            const deletion = model.deletions.find((d) => d.handle === handle);
            if (deletion) deletion.usage = Number(usage) || 0;
        },

        /** Replace everything with the server's definition; returns old id → new id. */
        applyServerDefinition(def, { snapshot: ids = null } = {}) {
            const map = M.applyServerDefinition(model, def, { labelLocales: ctx.labelLocales, snapshot: ids });
            markClean();
            return map;
        },
        handleSnapshot: () => M.handleSnapshot(model),
        markClean,
    };

    return api;
}
