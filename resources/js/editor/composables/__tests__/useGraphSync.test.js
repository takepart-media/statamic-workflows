// useGraph's sync() against a REAL Vue Flow store (no component needed): what
// the model says must be what the store holds, right after each mutation — with
// the canvas's connection rule installed, as in the editor.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick } from 'vue';
import { useVueFlow } from '@vue-flow/core';
import { useWorkflowModel } from '../useWorkflowModel.js';
import { useGraph, watchGraph } from '../useGraph.js';
import { edgeEnds } from '../../model/connections.js';

const definition = {
    type: 'state_machine',
    initial_place: 'draft',
    places: { draft: { metadata: {} }, review: { metadata: {} }, done: { metadata: { allow_publish: true } } },
    transitions: {
        submit: { from: 'draft', to: 'review', metadata: { label: 'Submit' } },
        finish: { from: 'review', to: 'done', metadata: { label: 'Finish' } },
    },
};

const ctx = { labelLocales: ['de', 'en'], reservedPlaces: [], reservedTransitions: [], customActions: {} };

let scope;
let counter = 0;

beforeEach(() => {
    // useVueFlow() outside a component: Vue warns about provide/inject.
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    scope = effectScope();
});

afterEach(() => {
    scope.stop();
    vi.restoreAllMocks();
});

function setup() {
    return scope.run(() => {
        const api = useWorkflowModel(definition, ctx);
        const flow = useVueFlow(`sync-test-${++counter}`);
        // As Canvas.vue does: only lines the rules allow may be DRAWN.
        flow.isValidConnection.value = (c) => api.canConnect(c.source, c.target).ok === true;
        const graph = useGraph(flow, { model: () => api.view.value, graph: () => api.graph(null) });
        watchGraph(graph, () => {});
        const edgeIds = () => flow.getEdges.value.map((e) => e.id).sort();
        return { api, flow, edgeIds };
    });
}

describe('useGraph sync with a Vue Flow store', () => {
    it('shows a line drawn place → transition at once, and drops it on disconnect / brings it back on undo', async () => {
        const { api, edgeIds } = setup();
        expect(edgeIds()).toEqual(['p:draft->t:submit', 'p:review->t:finish', 't:finish->p:done', 't:submit->p:review']);

        expect(api.connect('p:draft', 't:finish').ok).toBe(true);
        await nextTick();
        expect(edgeIds()).toContain('p:draft->t:finish');

        api.disconnect({ transition: 't:finish', place: 'p:draft', direction: 'in' });
        await nextTick();
        expect(edgeIds()).not.toContain('p:draft->t:finish');

        api.undo();
        await nextTick();
        expect(edgeIds()).toContain('p:draft->t:finish');

        api.undo();
        await nextTick();
        expect(edgeIds()).not.toContain('p:draft->t:finish');
    });

    it('rewires a transition → place line in place', async () => {
        const { api, edgeIds } = setup();
        api.connect('t:submit', 'p:done');
        await nextTick();
        expect(edgeIds()).toContain('t:submit->p:done');
        expect(edgeIds()).not.toContain('t:submit->p:review');

        api.updateTransition('t:submit', { to: 'p:review' });
        await nextTick();
        expect(edgeIds()).toContain('t:submit->p:review');
        expect(edgeIds()).not.toContain('t:submit->p:done');
    });

    it('draws both lines of a transition created between two places with its node', async () => {
        const { api, flow, edgeIds } = setup();
        const result = api.connect('p:review', 'p:draft', { position: { x: 10, y: 10 } });
        expect(result.created).toBe(true);
        await nextTick();
        expect(flow.findNode(result.transition)).toBeTruthy();
        expect(edgeIds()).toEqual(expect.arrayContaining([`p:review->${result.transition}`, `${result.transition}->p:draft`]));
    });

    it('removes the lines of a deleted transition', async () => {
        const { api, edgeIds } = setup();
        api.removeTransition('t:finish');
        await nextTick();
        expect(edgeIds()).toEqual(['p:draft->t:submit', 't:submit->p:review']);
    });

    // What Canvas.vue does when an end of a line is dragged: off onto the empty
    // canvas → api.disconnect(edgeEnds(edge)); onto another node → api.reconnect().
    describe('dragging an end of a line', () => {
        const ends = (flow, id) => edgeEnds(flow.findEdge(id));

        it('makes the model\'s lines updatable at both ends', () => {
            const { flow } = setup();
            expect(flow.getEdges.value.every((e) => e.updatable === true)).toBe(true);
        });

        it('drops a place → transition line dragged away, both nodes keep standing; undo brings it back', async () => {
            const { api, flow, edgeIds } = setup();
            expect(api.disconnect(ends(flow, 'p:review->t:finish')).ok).toBe(true);
            await nextTick();
            expect(edgeIds()).not.toContain('p:review->t:finish');
            expect(flow.findNode('p:review')).toBeTruthy();
            expect(flow.findNode('t:finish')).toBeTruthy();
            expect(api.findTransition('t:finish').from).toEqual([]);
            expect(api.history.undoTag.value).toBe('disconnect');

            api.undo();
            await nextTick();
            expect(edgeIds()).toContain('p:review->t:finish');
        });

        it('drops a transition → place line dragged away', async () => {
            const { api, flow, edgeIds } = setup();
            expect(api.disconnect(ends(flow, 't:finish->p:done')).ok).toBe(true);
            await nextTick();
            expect(edgeIds()).not.toContain('t:finish->p:done');
            expect(api.findTransition('t:finish').to).toBeNull();

            api.undo();
            await nextTick();
            expect(edgeIds()).toContain('t:finish->p:done');
        });

        it('moves a line onto another place in one undo step', async () => {
            const { api, flow, edgeIds } = setup();
            const result = api.reconnect(ends(flow, 't:finish->p:done'), 't:finish', 'p:draft');
            expect(result.ok).toBe(true);
            await nextTick();
            expect(edgeIds()).toContain('t:finish->p:draft');
            expect(edgeIds()).not.toContain('t:finish->p:done');
            expect(api.history.undoTag.value).toBe('connect');

            api.undo();
            await nextTick();
            expect(edgeIds()).toContain('t:finish->p:done');
            expect(edgeIds()).not.toContain('t:finish->p:draft');
            expect(api.history.canUndo.value).toBe(false);
        });

        it('moves the source end of a place → transition line', async () => {
            const { api, flow, edgeIds } = setup();
            expect(api.reconnect(ends(flow, 'p:review->t:finish'), 'p:done', 't:finish').ok).toBe(true);
            await nextTick();
            expect(edgeIds()).toContain('p:done->t:finish');
            expect(edgeIds()).not.toContain('p:review->t:finish');
        });

        it('leaves model, lines and history alone when the move is refused', async () => {
            const { api, flow, edgeIds } = setup();
            const before = edgeIds();
            // Onto the coupled first transition, and the locked start line itself.
            expect(api.reconnect(ends(flow, 'p:review->t:finish'), 'p:review', 't:submit')).toEqual({ ok: false, reason: 'start_locked' });
            expect(api.reconnect(ends(flow, 'p:draft->t:submit'), 'p:review', 't:submit')).toEqual({ ok: false, reason: 'start_locked' });
            // Two transitions.
            expect(api.reconnect(ends(flow, 't:finish->p:done'), 't:finish', 't:submit').ok).toBe(false);
            await nextTick();
            expect(edgeIds()).toEqual(before);
            expect(api.history.canUndo.value).toBe(false);
        });
    });
});
