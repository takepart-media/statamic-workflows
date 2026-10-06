// The validation errors of the last save (422 `{message, errors[]}`), kept per
// editor; useServerValidation.applySave() maps them onto nodes and fields like
// any other answer of the server.
//
//   errors: [{ path, code, message, node: { kind: 'place'|'transition', handle } | null }]

import { reactive } from 'vue';

export function useSaveErrors() {
    const state = reactive({
        message: null,
        errors: [],
    });

    return {
        state,
        set(message, errors = []) {
            state.message = message ?? null;
            state.errors = Array.isArray(errors) ? errors.filter((e) => e && typeof e === 'object') : [];
            // A message without a list still deserves an entry.
            if (!state.errors.length && state.message) {
                state.errors = [{ path: null, code: null, message: state.message, node: null }];
            }
        },
        clear() {
            state.message = null;
            state.errors = [];
        },
    };
}
