// The pure part of CreatePostButton: which workflow the "New post" modal starts
// on, whether the editor may switch it, and the fields that belong to a
// workflow. `creates` is the server's list (WorkflowController::createOptions()
// per workflow the user may create in): [{workflow, label, createUrl,
// createCta, createCollections}].

// The workflow the page is filtered to, if the user may create in it;
// otherwise the first allowed one — the modal always starts on a valid choice.
export function defaultWorkflow(creates, preferred = null) {
    if (preferred && (creates ?? []).some((c) => c.workflow === preferred)) {
        return preferred;
    }

    return creates?.[0]?.workflow ?? null;
}

export function entryFor(creates, workflow) {
    return (creates ?? []).find((c) => c.workflow === workflow) ?? null;
}

// One workflow on offer: shown, but not changeable.
export function isWorkflowLocked(creates) {
    return (creates ?? []).length <= 1;
}

export function workflowOptions(creates) {
    return (creates ?? []).map((c) => ({ value: c.workflow, label: c.label ?? c.workflow }));
}

// What depends on the workflow, reset whenever it changes: its first owned
// collection, and no picks — the candidates are the new workflow's.
export function workflowFields(entry) {
    return {
        collection: entry?.createCollections?.[0]?.value ?? '',
        assignees: [],
        notify: true,
    };
}
