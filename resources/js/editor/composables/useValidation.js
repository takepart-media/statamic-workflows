// Live validation for the editor: the client's rules (model/rules.js) on every
// change, merged with what the server found (useServerValidation.js) — and the
// lookups the canvas, the panels and the settings need from that one list.
//
//   problems: [{ level, code, path, nodeId, message, source?, section, displayPath }]
//             section: the settings section of a workflow-level path (or null);
//             displayPath: the path by handle, for people

import { computed } from 'vue';
import { validate } from '../model/rules.js';
import { displayPath, mergeProblems, problemsAt, sectionForPath } from '../model/validationPaths.js';

/**
 * @param {object} api       useWorkflowModel()
 * @param {object} ctx       rules.js ctx (t, reservedPlaces, reservedTransitions — getters are fine)
 * @param {object} [options]
 * @param {{problems: import('vue').Ref<object[]>}} [options.server]  useServerValidation()
 */
export function useValidation(api, ctx, { server = null } = {}) {
    const client = computed(() => validate(api.model, ctx));

    const problems = computed(() => mergeProblems(client.value, server?.problems.value ?? []).map((p) => ({
        ...p,
        section: p.nodeId ? null : sectionForPath(p.path),
        displayPath: p.path ? displayPath(p.path, api.model) : null,
    })));

    // nodeId → {errors, warnings}
    const byNode = computed(() => {
        const counts = new Map();
        for (const p of problems.value) {
            if (!p.nodeId) continue;
            const entry = counts.get(p.nodeId) ?? { errors: 0, warnings: 0 };
            if (p.level === 'error') entry.errors++;
            if (p.level === 'warning') entry.warnings++;
            counts.set(p.nodeId, entry);
        }
        return counts;
    });

    // settings section → {errors, warnings}
    const sectionCounts = computed(() => {
        const counts = {};
        for (const p of problems.value) {
            if (!p.section) continue;
            counts[p.section] ??= { errors: 0, warnings: 0 };
            if (p.level === 'error') counts[p.section].errors++;
            if (p.level === 'warning') counts[p.section].warnings++;
        }
        return counts;
    });

    const summary = computed(() => ({
        errors: problems.value.filter((p) => p.level === 'error').length,
        warnings: problems.value.filter((p) => p.level === 'warning').length,
    }));

    const clientErrors = computed(() => client.value.filter((p) => p.level === 'error'));

    const NONE = Object.freeze({ errors: 0, warnings: 0 });

    return {
        client,
        problems,
        summary,
        clientErrors,
        sectionCounts,
        /** The problems of one node (all levels). */
        problemsFor: (nodeId) => problems.value.filter((p) => p.nodeId === nodeId),
        /** The errors of one node. */
        errorsFor: (nodeId) => problems.value.filter((p) => p.nodeId === nodeId && p.level === 'error'),
        countsFor: (nodeId) => byNode.value.get(nodeId) ?? NONE,
        /** Errors at `path` or inside it. */
        errorsForPath: (path) => problemsAt(problems.value, path).filter((p) => p.level === 'error'),
        /** The same as one string for a Field's `error` prop; undefined when fine. */
        errorFor(path) {
            const found = problemsAt(problems.value, path).filter((p) => p.level === 'error');
            return found.length ? [...new Set(found.map((p) => p.message))].join(' ') : undefined;
        },
        /** Problems exactly at `path` (not inside it) — e.g. the node itself. */
        problemsExactly: (path) => problems.value.filter((p) => p.path === path),
    };
}
