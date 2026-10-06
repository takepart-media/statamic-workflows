import { describe, expect, it } from 'vitest';
import { NUDGE, resolveShortcut } from '../useShortcuts.js';

const key = (k, mods = {}) => ({ key: k, ...mods });

describe('resolveShortcut', () => {
    it('maps the Ctrl/⌘ shortcuts, also while typing where they belong', () => {
        expect(resolveShortcut(key('s', { ctrlKey: true }), { typing: true })).toEqual({ action: 'save' });
        expect(resolveShortcut(key(',', { metaKey: true }), { typing: true })).toEqual({ action: 'settings' });
        expect(resolveShortcut(key('z', { ctrlKey: true }), { typing: true })).toBeNull();
        expect(resolveShortcut(key('z', { ctrlKey: true }))).toEqual({ action: 'undo' });
        expect(resolveShortcut(key('Z', { metaKey: true, shiftKey: true }))).toEqual({ action: 'redo' });
    });

    it('maps the canvas keys only on the canvas', () => {
        expect(resolveShortcut(key('Delete'), { onCanvas: true })).toEqual({ action: 'delete' });
        expect(resolveShortcut(key('Delete'))).toBeNull();
        expect(resolveShortcut(key('ArrowLeft'), { onCanvas: true })).toEqual({ action: 'nudge', dx: -NUDGE, dy: 0 });
    });

    it('keeps only the Ctrl/⌘ shortcuts and Esc while a modal layer is open', () => {
        const where = { onCanvas: true, modal: true };
        expect(resolveShortcut(key('Escape'), where)).toEqual({ action: 'escape' });
        expect(resolveShortcut(key('s', { ctrlKey: true }), where)).toEqual({ action: 'save' });
        expect(resolveShortcut(key('z', { ctrlKey: true }), where)).toEqual({ action: 'undo' });
        expect(resolveShortcut(key(',', { ctrlKey: true }), where)).toEqual({ action: 'settings' });
        for (const k of ['Delete', 'Backspace', 'Tab', 'Enter', 'ArrowUp', '1', '0', '+', '-']) {
            expect(resolveShortcut(key(k), where)).toBeNull();
        }
        expect(resolveShortcut(key('L', { shiftKey: true }), where)).toBeNull();
    });
});
