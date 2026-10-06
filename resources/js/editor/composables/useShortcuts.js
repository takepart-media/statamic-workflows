// The editor's keyboard, in one place.
//
//   Ctrl/⌘+Z            undo             (native inside text fields)
//   Ctrl/⌘+Shift+Z      redo             (native inside text fields)
//   Ctrl+Y              redo             (native inside text fields)
//   Ctrl/⌘+S            save             (everywhere, also while typing)
//   Ctrl/⌘+,            workflow settings
//   Esc                 close menu → deselect → collapse the panel
//   Delete / Backspace  delete the selection          ┐
//   Tab                 add menu beside the node      │ on the canvas (or with
//   Enter               open the focused node's panel │ nothing focused)
//   ←↑→↓                nudge the focused node 10px   │
//                       (Shift: 50px)                 ┘
//   1 / 0 / + / -       fit / 100 % / zoom in / zoom out
//   Shift+L             arrange automatically
//
// Single keys never fire while typing (inputs, textareas, selects, contenteditable
// — the CodeEditor), inside a menu or a dialog, or outside the editor. The arrow
// keys are taken in the capture phase: Vue Flow moves a selected node itself
// (1px, not recorded), so the nudge has to get there first.

import { onBeforeUnmount, onMounted } from 'vue';

export const NUDGE = 10;
export const NUDGE_FAR = 50;

const ARROWS = {
    ArrowLeft: [-1, 0],
    ArrowRight: [1, 0],
    ArrowUp: [0, -1],
    ArrowDown: [0, 1],
};

const TYPING_ROLES = ['textbox', 'combobox', 'searchbox', 'listbox', 'option', 'menu', 'menuitem', 'dialog', 'alertdialog'];

/** Whether keys typed at `el` belong to that element (a field, a menu, a dialog). */
export function isTypingTarget(el) {
    if (!el || typeof el !== 'object' || el === globalThis.document?.body) return false;
    if (el.isContentEditable) return true;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return true;
    if (TYPING_ROLES.includes(el.getAttribute?.('role'))) return true;
    // The CodeEditor (CodeMirror) and anything inside an open menu / dialog.
    return !!el.closest?.('.cm-editor, [role="menu"], [role="dialog"], [role="alertdialog"]');
}

/**
 * Pure: which shortcut a key event means, or null.
 *
 * @param {{key: string, ctrlKey?: boolean, metaKey?: boolean, shiftKey?: boolean, altKey?: boolean}} event
 * @param {object} where
 * @param {boolean} where.typing    focus is in a text field / menu / dialog
 * @param {boolean} where.onCanvas  focus is on the canvas (or nowhere)
 * @returns {{action: string, dx?: number, dy?: number}|null}
 */
export function resolveShortcut(event, { typing = false, onCanvas = false } = {}) {
    const key = event.key ?? '';
    const lower = key.length === 1 ? key.toLowerCase() : key;
    const mod = !!(event.ctrlKey || event.metaKey);

    if (mod) {
        if (event.altKey) return null;
        if (lower === 's') return { action: 'save' };
        if (lower === ',') return { action: 'settings' };
        if (typing) return null;
        if (lower === 'z') return { action: event.shiftKey ? 'redo' : 'undo' };
        if (lower === 'y' && event.ctrlKey && !event.shiftKey) return { action: 'redo' };
        return null;
    }
    if (event.altKey || typing) return null;

    if (key === 'Escape') return { action: 'escape' };

    if (onCanvas) {
        if (key === 'Delete' || key === 'Backspace') return { action: 'delete' };
        if (key === 'Tab' && !event.shiftKey) return { action: 'add-menu' };
        if (key === 'Enter') return { action: 'open' };
        if (ARROWS[key]) {
            const step = event.shiftKey ? NUDGE_FAR : NUDGE;
            return { action: 'nudge', dx: ARROWS[key][0] * step, dy: ARROWS[key][1] * step };
        }
    }

    if (lower === 'l' && event.shiftKey) return { action: 'auto-layout' };
    if (event.shiftKey && key !== '+') return null;
    if (key === '1') return { action: 'fit' };
    if (key === '0') return { action: 'reset-zoom' };
    if (key === '+' || key === '=') return { action: 'zoom-in' };
    if (key === '-') return { action: 'zoom-out' };
    return null;
}

/**
 * @param {object} options
 * @param {() => HTMLElement|null} options.root    the editor (shortcuts only act on
 *                                                 events from inside it or with
 *                                                 nothing focused)
 * @param {() => HTMLElement|null} options.canvas  the canvas wrapper
 * @param {() => boolean} [options.enabled]        e.g. false while a dialog is open
 * @param {Record<string, (shortcut, event) => boolean|void>} options.handlers
 *        one per action (see resolveShortcut); returning false means "not
 *        handled" — the key then keeps its default behaviour
 */
export function useShortcuts({ root, canvas, enabled = () => true, handlers }) {
    const nothingFocused = (el) => !el || el === document.body || el === document.documentElement;

    function locate(event) {
        const el = event.target;
        const inEditor = nothingFocused(el) || !!root()?.contains(el);
        return {
            inEditor,
            typing: isTypingTarget(el),
            onCanvas: nothingFocused(el) || !!canvas()?.contains(el),
        };
    }

    function run(event, { arrowsOnly }) {
        if (event.defaultPrevented || event.isComposing || !enabled()) return;
        const where = locate(event);
        if (!where.inEditor) return;
        const shortcut = resolveShortcut(event, where);
        if (!shortcut || (shortcut.action === 'nudge') !== arrowsOnly) return;
        const handler = handlers[shortcut.action];
        if (!handler || handler(shortcut, event) === false) return;
        event.preventDefault();
        // Vue Flow's own arrow-key move sits on the node below.
        if (arrowsOnly) event.stopPropagation();
    }

    const onCapture = (event) => run(event, { arrowsOnly: true });
    const onBubble = (event) => run(event, { arrowsOnly: false });

    onMounted(() => {
        window.addEventListener('keydown', onCapture, true);
        window.addEventListener('keydown', onBubble);
    });
    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onCapture, true);
        window.removeEventListener('keydown', onBubble);
    });
}
