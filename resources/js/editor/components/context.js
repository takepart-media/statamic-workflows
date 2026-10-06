// What the editor shell hands down to the node components without threading it
// through Vue Flow's node data: things that are the same for every node.
//
//   { customActions: { transition: [{value, label}], place: [{value, label}] } }
//
// …plus the shell's services (WorkflowEditor.vue): api, select(), requestDelete(ids)
// (the delete dialog), announce(text) (the aria-live region), focus requests.
export const EDITOR_CONTEXT = Symbol('workflows.editor');
