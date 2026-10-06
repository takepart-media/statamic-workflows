import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { useHistory } from '../useHistory.js';
import { useWorkflowModel } from '../useWorkflowModel.js';
import { resolveShortcut } from '../useShortcuts.js';

const definition = () => ({
    type: 'state_machine',
    initial_place: 'draft',
    metadata: {
        sla: { needs_update_days: 30, needs_update_place: 'review' },
        seed_published_place: 'review',
    },
    places: {
        draft: { metadata: {} },
        review: { metadata: { actions: [{ type: 'auto_transition', transition: 'publish', after_days: 3 }] } },
        done: { metadata: { allow_publish: true } },
    },
    transitions: {
        submit: { from: 'draft', to: 'review', metadata: { label: 'Submit' } },
        publish: { from: ['review', 'draft'], to: 'done', metadata: { label: 'Publish' } },
        reject: { from: 'review', to: 'draft', metadata: { label: 'Reject' } },
    },
});

const ctx = { labelLocales: ['de', 'en'], reservedPlaces: [], reservedTransitions: [], customActions: {} };

// A model whose nodes have positions (what the canvas sets on open).
function editor(history = {}) {
    const api = useWorkflowModel(definition(), ctx, { history: { debounce: 400, ...history } });
    api.setPositions({
        'p:draft': { x: 0, y: 0 },
        'p:review': { x: 200, y: 0 },
        'p:done': { x: 400, y: 0 },
        't:submit': { x: 100, y: 0 },
        't:publish': { x: 300, y: 0 },
        't:reject': { x: 100, y: 100 },
    });
    return api;
}

// Let the history's watcher see a change, then let `ms` pass.
async function settle(ms = 0) {
    await nextTick();
    vi.advanceTimersByTime(ms);
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe('useHistory', () => {
    it('collects typing into one step once the model is quiet', async () => {
        const api = editor();
        for (const text of ['R', 'Re', 'Rev']) {
            api.updatePlace('p:review', { labels: { de: text } });
            await settle(200);
        }
        expect(api.history.size.value).toBe(0);
        await settle(400);
        expect(api.history.size.value).toBe(1);
        expect(api.history.undoTag.value).toBe('edit');

        expect(api.undo()).toBe('edit');
        expect(api.findPlace('p:review').labels.de).toBe('');
        expect(api.redo()).toBe('edit');
        expect(api.findPlace('p:review').labels.de).toBe('Rev');
    });

    it('records structural changes at once and keeps pending typing apart', async () => {
        const api = editor();
        api.updatePlace('p:review', { labels: { de: 'Prüfung' } });
        await settle(100);
        const id = api.addPlace();
        expect(api.history.size.value).toBe(2);

        expect(api.undo()).toBe('add');
        expect(api.findPlace(id)).toBeNull();
        expect(api.findPlace('p:review').labels.de).toBe('Prüfung');
        expect(api.undo()).toBe('edit');
        expect(api.findPlace('p:review').labels.de).toBe('');
    });

    it('drops the redo branch on a new edit', async () => {
        const api = editor();
        api.addPlace();
        api.undo();
        expect(api.history.canRedo.value).toBe(true);
        api.setInitialPlace('p:review');
        expect(api.history.canRedo.value).toBe(false);
        expect(api.redo()).toBeNull();
    });

    it('keeps at most `limit` steps', () => {
        const api = editor({ limit: 3 });
        for (let i = 0; i < 5; i++) api.addPlace();
        expect(api.history.size.value).toBe(3);
        while (api.undo());
        // The two oldest additions can't be undone any more.
        expect(api.model.places).toHaveLength(5);
    });

    it('restores in place: surviving nodes and rows keep their objects', async () => {
        const api = editor();
        const review = api.findPlace('p:review');
        const row = review.metadata.actions[0];
        const publish = api.findTransition('t:publish');

        api.updatePlace('p:review', { labels: { de: 'Prüfung' }, metadata: { color: 'red' } });
        row.after_days = 9;
        api.moveTransition('t:publish', 0);
        expect(api.model.transitions[0]).toBe(publish);

        api.undo();
        api.undo();
        expect(api.findPlace('p:review')).toBe(review);
        expect(api.findPlace('p:review').metadata.actions[0]).toBe(row);
        expect(row.after_days).toBe(3);
        expect(review.metadata.color).toBeUndefined();
        expect(api.findTransition('t:publish')).toBe(publish);
        expect(api.model.transitions.map((t) => t.id)).toEqual(['t:submit', 't:publish', 't:reject']);
    });

    it('is clean again after undoing back to the saved state', async () => {
        const api = editor();
        expect(api.dirty.value).toBe(false);

        api.moveNodes({ 'p:done': { x: 480, y: 40 } });
        api.updateTransition('t:reject', { metadata: { label: 'Back' } });
        await settle(400);
        expect(api.dirty.value).toBe(true);

        api.undo();
        expect(api.dirty.value).toBe(true);
        api.undo();
        expect(api.dirty.value).toBe(false);
        expect(api.findPlace('p:done').position).toEqual({ x: 400, y: 0 });
        api.redo();
        expect(api.dirty.value).toBe(true);
    });

    it('takes positions the canvas assigns without a step, and as clean', () => {
        const api = editor();
        const id = api.addPlace();
        api.setPositions({ [id]: { x: 10, y: 300 } });
        expect(api.history.size.value).toBe(1);
        api.undo();
        api.redo();
        expect(api.findPlace(id).position).toEqual({ x: 10, y: 300 });
    });

    it('records reset + auto-layout as one step', () => {
        const api = editor();
        api.batch('layout', () => {
            api.resetPositions();
            api.setPositions({ 'p:draft': { x: 5, y: 5 }, 'p:review': { x: 50, y: 5 } }, { touched: true });
        });
        expect(api.history.size.value).toBe(1);
        expect(api.undo()).toBe('layout');
        expect(api.findPlace('p:draft').position).toEqual({ x: 0, y: 0 });
        expect(api.findTransition('t:submit').position).toEqual({ x: 100, y: 0 });
        expect(api.dirty.value).toBe(false);
    });

    it('turns a burst of nudges into one step', () => {
        const api = editor();
        api.nudge('p:done', 10, 0);
        api.nudge('p:done', 10, 0);
        api.nudge('p:done', 0, 50);
        expect(api.findPlace('p:done').position).toEqual({ x: 420, y: 50 });
        expect(api.history.size.value).toBe(1);
        vi.advanceTimersByTime(1000);
        api.nudge('p:done', -10, 0);
        expect(api.history.size.value).toBe(2);
        api.undo();
        api.undo();
        expect(api.findPlace('p:done').position).toEqual({ x: 400, y: 0 });
    });

    it('starts over after a save', async () => {
        const api = editor();
        api.removePlace('p:done');
        api.updatePlace('p:review', { labels: { de: 'Prüfung' } });
        expect(api.history.canUndo.value).toBe(true);

        const { definition: saved } = api.payload();
        api.applyServerDefinition(JSON.parse(JSON.stringify(saved)));
        expect(api.history.canUndo.value).toBe(false);
        expect(api.undo()).toBeNull();
        await settle(400);
        expect(api.history.canUndo.value).toBe(false);
        expect(api.dirty.value).toBe(false);
        expect(api.findPlace('p:done')).toBeNull();
    });

    it('works on any reactive object with a custom snapshot', async () => {
        const state = reactive({ places: [], transitions: [], workflow: { metadata: { n: 1 } }, deletions: [] });
        const history = useHistory(state, { debounce: 100 });
        state.workflow.metadata.n = 2;
        await settle(100);
        expect(history.undo()).toBe('edit');
        expect(state.workflow.metadata.n).toBe(1);
        history.stop();
    });
});

describe('deleting a place and undoing it', () => {
    it('brings back the place AND every reference it lost', () => {
        const api = editor();
        const before = api.payload();
        const review = api.findPlace('p:review');

        api.batch('delete', () => {
            api.removeTransition('t:reject');
            api.removePlace('p:review', { usage: 4, migrateTo: 'p:done' });
        });
        expect(api.history.size.value).toBe(1);
        const after = api.payload();
        expect(after.removed_places).toEqual([{ place: 'review', migrate_to: 'done' }]);
        expect(after.definition.transitions.submit.to).toBeUndefined();
        expect(after.definition.transitions.reject).toBeUndefined();
        expect(after.definition.metadata.sla?.needs_update_place).toBeUndefined();
        expect(after.definition.metadata.seed_published_place).toBeUndefined();

        expect(api.undo()).toBe('delete');
        expect(api.payload()).toEqual(before);
        expect(api.findPlace('p:review')).not.toBeNull();
        expect(api.findPlace('p:review').metadata.actions[0].transition).toBe(review.metadata.actions[0].transition);
        expect(api.dirty.value).toBe(false);
    });

    it('restores the links from the pending deletion too (panel "Restore")', () => {
        const api = editor();
        const before = api.payload();
        api.removePlace('p:review');
        api.restoreDeletion('p:review');
        expect(api.payload()).toEqual(before);
        expect(api.history.size.value).toBe(2);
        expect(api.history.undoTag.value).toBe('restore');
    });

    it('does not re-link a slot that was pointed elsewhere meanwhile', () => {
        const api = editor();
        api.removePlace('p:review');
        api.updateTransition('t:submit', { to: 'p:done' });
        api.restoreDeletion('p:review');
        expect(api.findTransition('t:submit').to).toBe('p:done');
        expect(api.findTransition('t:publish').from).toEqual(['p:review', 'p:draft']);
        expect(api.model.workflow.metadata.sla.needs_update_place).toBe('p:review');
    });
});

describe('resolveShortcut', () => {
    const key = (k, extra = {}) => ({ key: k, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, ...extra });

    it('keeps undo native while typing but saves everywhere', () => {
        expect(resolveShortcut(key('z', { ctrlKey: true }), { typing: true })).toBeNull();
        expect(resolveShortcut(key('z', { metaKey: true }))).toEqual({ action: 'undo' });
        expect(resolveShortcut(key('Z', { ctrlKey: true, shiftKey: true }))).toEqual({ action: 'redo' });
        expect(resolveShortcut(key('y', { ctrlKey: true }))).toEqual({ action: 'redo' });
        expect(resolveShortcut(key('s', { ctrlKey: true }), { typing: true })).toEqual({ action: 'save' });
        expect(resolveShortcut(key(',', { metaKey: true }))).toEqual({ action: 'settings' });
    });

    it('ignores single keys while typing and limits node keys to the canvas', () => {
        expect(resolveShortcut(key('1'), { typing: true })).toBeNull();
        expect(resolveShortcut(key('Delete'), { onCanvas: false })).toBeNull();
        expect(resolveShortcut(key('Delete'), { onCanvas: true })).toEqual({ action: 'delete' });
        expect(resolveShortcut(key('ArrowLeft', { shiftKey: true }), { onCanvas: true })).toEqual({ action: 'nudge', dx: -50, dy: 0 });
        expect(resolveShortcut(key('ArrowDown'), { onCanvas: true })).toEqual({ action: 'nudge', dx: 0, dy: 10 });
        expect(resolveShortcut(key('L', { shiftKey: true }))).toEqual({ action: 'auto-layout' });
        expect(resolveShortcut(key('l'))).toBeNull();
        expect(resolveShortcut(key('+', { shiftKey: true }))).toEqual({ action: 'zoom-in' });
        expect(resolveShortcut(key('0'))).toEqual({ action: 'reset-zoom' });
        expect(resolveShortcut(key('Escape'))).toEqual({ action: 'escape' });
    });
});
