// The server's view of the model, fetched in the background: 800ms after the
// last change the payload goes to the validate endpoint (a dry run of the
// writer plus the linter, nothing is written). A newer change cancels the
// request in flight. The answer is by handle; it is mapped onto ids with the
// handle ↔ id map of the model state that was sent (validationPaths.js), so a
// node renamed in the meantime keeps its problems.
//
// A save's 422 is the same kind of answer (applySave()); a successful save
// carries the warnings over to the new ids (saved()).
//
// While a save is in flight nothing is validated — the save validates anyway;
// what was due runs once it is over.
//
//   problems  [{ level, code, path, nodeId, message, source: 'server' }]
//   stale     a request is pending, or the model changed since the answer

import { computed, onScopeDispose, reactive, watch } from 'vue';
import { handleMaps, mapServerResult, remapProblems } from '../model/validationPaths.js';

// The part of the payload validation depends on: canvas positions don't count.
function payloadKey(payload) {
    const definition = payload?.definition ?? {};
    const { editor: _positions, ...metadata } = definition.metadata ?? {};
    return JSON.stringify({ definition: { ...definition, metadata }, removed_places: payload?.removed_places ?? [] });
}

const isCancel = (e) => e?.name === 'CanceledError' || e?.name === 'AbortError' || e?.code === 'ERR_CANCELED';

/**
 * @param {object} api                 useWorkflowModel()
 * @param {object} options
 * @param {() => string} options.url   the validate endpoint; empty → off
 * @param {object} options.axios       the CP's axios
 * @param {() => string} [options.version]
 * @param {() => boolean} [options.saving]
 * @param {number} [options.debounce]
 */
export function useServerValidation(api, { url, axios, version = () => '', saving = () => false, debounce = 800 }) {
    const state = reactive({
        problems: [],
        // payloadKey() of the model state `problems` belong to; null = none yet.
        key: null,
        pending: false,
        failed: false,
    });

    const currentKey = computed(() => payloadKey(api.payload()));

    let timer = null;
    let controller = null;
    let deferred = false;

    function abort() {
        controller?.abort();
        controller = null;
        state.pending = false;
    }

    function schedule(delay = debounce) {
        clearTimeout(timer);
        if (!url() || !axios) return;
        timer = setTimeout(run, delay);
    }

    async function run() {
        clearTimeout(timer);
        if (!url() || !axios) return;
        if (saving()) {
            deferred = true;
            return;
        }
        abort();
        const mine = new AbortController();
        controller = mine;
        const maps = handleMaps(api.model);
        const payload = api.payload();
        const key = payloadKey(payload);
        state.pending = true;
        try {
            const { data } = await axios.post(url(), { version: version(), ...payload }, { signal: mine.signal });
            if (controller !== mine) return;
            state.problems = mapServerResult(data ?? {}, maps);
            state.key = key;
            state.failed = false;
        } catch (e) {
            if (controller !== mine || isCancel(e)) return;
            // The live check is a convenience: a failure leaves the last answer
            // standing and says so in the console, the save still validates.
            state.failed = true;
            console.warn('[workflows] live validation failed', e?.response?.status ?? e);
        } finally {
            if (controller === mine) {
                controller = null;
                state.pending = false;
            }
        }
    }

    watch(currentKey, () => schedule(), { immediate: true });

    watch(saving, (now) => {
        if (now) {
            if (state.pending) deferred = true;
            clearTimeout(timer);
            abort();
        } else if (deferred) {
            deferred = false;
            schedule();
        }
    });

    onScopeDispose(() => {
        clearTimeout(timer);
        abort();
    });

    return {
        state,
        problems: computed(() => state.problems),
        stale: computed(() => state.pending || (state.key !== null && state.key !== currentKey.value)),
        /** Validate now (e.g. after a reload). */
        revalidate: () => schedule(0),

        /**
         * A save failed with errors (422, or removals that need a target): they
         * are the server's answer for exactly this model state. The warnings of
         * the last check stay.
         */
        applySave(errors = []) {
            const maps = handleMaps(api.model);
            const warnings = state.problems.filter((p) => p.level === 'warning');
            state.problems = [...mapServerResult({ errors }, maps), ...warnings];
            state.key = currentKey.value;
        },

        /**
         * A save went through: the writer had nothing to object, the warnings
         * move to the new ids (`old id → new id`, editorModel.applyServerDefinition()).
         */
        saved(ids) {
            state.problems = remapProblems(state.problems.filter((p) => p.level !== 'error'), ids);
            state.key = currentKey.value;
        },

        clear() {
            abort();
            state.problems = [];
            state.key = null;
        },
    };
}
