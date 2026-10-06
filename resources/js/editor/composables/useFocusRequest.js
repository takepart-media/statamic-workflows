// "Focus this field once the panel shows it": the editor shell records a request
// (`select(id, {focus: 'label'})` after creating a node on the canvas), the
// panel of that node takes it when it is mounted — or right away if it already
// is. A request is taken once; it never steals focus on a later re-render.
//
// The shell side (WorkflowEditor) provides on EDITOR_CONTEXT:
//   focusRequest   { id, field, delay } | null   (reactive getter)
//   takeFocus(id, field) → boolean               consumes a matching request

import { nextTick, watch } from 'vue';

/**
 * @param {object} editor        the injected EDITOR_CONTEXT
 * @param {() => string} id      the node the panel shows
 * @param {string} field         e.g. 'label'
 * @param {() => import('vue').Ref<HTMLElement|null>} prepare
 *        makes the field visible (switches tabs …) and returns the ref of the
 *        element containing the input to focus
 */
export function useFocusRequest(editor, id, field, prepare) {
    watch(
        () => editor?.focusRequest,
        (request) => {
            if (!request || request.id !== id() || request.field !== field) return;
            const container = prepare();
            // The tab switch renders first; a context menu that just closed
            // hands focus back to its trigger — wait for both.
            nextTick(() => setTimeout(() => {
                const input = container.value?.querySelector('input, textarea');
                if (!input || !editor.takeFocus(id(), field)) return;
                input.focus();
                input.select?.();
            }, request.delay ?? 0));
        },
        { immediate: true },
    );
}
