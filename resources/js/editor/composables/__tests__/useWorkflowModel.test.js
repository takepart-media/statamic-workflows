import { describe, expect, it } from 'vitest';
import { useWorkflowModel } from '../useWorkflowModel.js';

const definition = {
    type: 'state_machine',
    initial_place: 'draft',
    places: { draft: { metadata: {} }, done: { metadata: { allow_publish: true } } },
    transitions: { finish: { from: 'draft', to: 'done', metadata: { label: 'Finish' } } },
};

const ctx = { labelLocales: ['de', 'en'], reservedPlaces: ['pending_revision'], reservedTransitions: [], customActions: {} };

describe('useWorkflowModel', () => {
    it('is clean after load and after an automatic layout', () => {
        const api = useWorkflowModel(definition, ctx);
        expect(api.dirty.value).toBe(false);
        api.setPositions({ 'p:draft': { x: 1, y: 2 } });
        expect(api.dirty.value).toBe(false);
        api.setPosition('p:draft', { x: 5, y: 2 });
        expect(api.dirty.value).toBe(true);
    });

    it('turns dirty on content edits and clean again after the server answered', () => {
        const api = useWorkflowModel(definition, ctx);
        api.updateTransition('t:finish', { metadata: { label: 'Done' } });
        expect(api.dirty.value).toBe(true);
        const { definition: saved } = api.payload();
        api.applyServerDefinition(saved);
        expect(api.dirty.value).toBe(false);
        expect(api.model.transitions[0].metadata.label).toBe('Done');
    });

    it('checks new handles against reserved ones', () => {
        const api = useWorkflowModel(definition, ctx);
        const id = api.addPlace();
        api.setHandle(id, 'pending_revision');
        expect(api.handleProblem(id)).toBe('reserved');
        expect(api.handleProblems()).toEqual([{ id, problem: 'reserved' }]);
    });

    it('merges workflow metadata and keeps the start coupling', () => {
        const api = useWorkflowModel(definition, ctx);
        api.updateWorkflow({ metadata: { create_transition: 't:finish' }, initial_place: 'p:done' });
        expect(api.model.workflow.metadata.create_transition).toBe('t:finish');
        expect(api.model.transitions[0].from).toEqual(['p:done']);
        expect(api.payload().definition.metadata.create_transition).toBe('finish');
    });
});
