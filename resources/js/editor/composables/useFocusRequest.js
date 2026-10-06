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

// --- problems: "show me where this is" ---
//
// The shell records `pathRequest` { id, path, seq } when a problem is picked
// (WorkflowEditor selectProblem()); the panel of that node switches to the tab
// that shows the path, then the shell focuses the element carrying it as
// `data-path` (focusPath()).

/**
 * @param {object} editor              the injected EDITOR_CONTEXT
 * @param {() => string} id            the node the panel shows
 * @param {(path: string) => void} setTab
 */
export function usePathRequest(editor, id, setTab) {
    watch(
        () => editor?.pathRequest,
        (request) => {
            if (request && request.id === id() && request.path) setTab(request.path);
        },
        { immediate: true },
    );
}

const FOCUSABLE = 'input:not([disabled]), textarea:not([disabled]), select:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])';

const escape = (value) => (typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(value) : value.replace(/["\\]/g, '\\$&'));

/**
 * Focus (and scroll to) the element for `path` inside `root` — or, if there is
 * none, the closest enclosing path that has one (`…actions.0.criteria` →
 * `…actions.0` → `…actions` → the node).
 *
 * @returns {boolean} whether an element was found
 */
export function focusPath(root, path) {
    if (!root || !path) return false;
    let current = path;
    while (current) {
        const el = root.querySelector(`[data-path="${escape(current)}"]`);
        if (el) {
            el.scrollIntoView?.({ block: 'nearest' });
            const target = el.matches?.(FOCUSABLE) ? el : el.querySelector?.(FOCUSABLE);
            target?.focus?.({ preventScroll: true });
            return true;
        }
        const dot = current.lastIndexOf('.');
        if (dot < 0) break;
        current = current.slice(0, dot);
    }
    return false;
}
