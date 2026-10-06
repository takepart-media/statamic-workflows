// What a canvas node shows of the live validation (useValidation.js): a ring in
// the colour of its worst problem and a count badge. Shared by PlaceNode and
// TransitionNode; without a validation in the editor context nothing shows.

import { computed } from 'vue';

const NONE = { errors: 0, warnings: 0 };

export function useNodeProblems(editor, id) {
    const problems = computed(() => editor?.validation?.countsFor(id()) ?? NONE);
    const problemClass = computed(() => ({
        'wf-node--error': problems.value.errors > 0,
        'wf-node--warning': problems.value.errors === 0 && problems.value.warnings > 0,
    }));
    const problemTitle = computed(() => {
        const messages = editor?.validation?.problemsFor(id()).map((p) => p.message) ?? [];
        const counts = __('workflows::messages.editor_problems_count', problems.value);
        return [counts, ...messages].join('\n');
    });
    return { problems, problemClass, problemTitle };
}
