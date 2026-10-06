// What the editor shell hands down to the node components without threading it
// through Vue Flow's node data: things that are the same for every node.
//
//   { customActions: { transition: [{value, label}], place: [{value, label}] } }
export const EDITOR_CONTEXT = Symbol('workflows.editor');
