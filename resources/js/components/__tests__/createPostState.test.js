import { describe, expect, it } from 'vitest';
import { defaultWorkflow, entryFor, isWorkflowLocked, workflowFields, workflowOptions } from '../createPostState.js';

const creates = [
    { workflow: 'postmanager', label: 'Beiträge', createUrl: '/x', createCta: null, createCollections: [{ value: 'posts', label: 'Posts' }, { value: 'news', label: 'News' }] },
    { workflow: 'videos', label: 'Videos', createUrl: '/x', createCta: { needs_pick: true }, createCollections: [{ value: 'videos', label: 'Videos' }] },
];

describe('create post state', () => {
    it('preselects the first workflow', () => {
        expect(defaultWorkflow(creates)).toBe('postmanager');
        expect(defaultWorkflow([])).toBeNull();
    });

    it('prefers the filtered workflow when the user may create in it', () => {
        expect(defaultWorkflow(creates, 'videos')).toBe('videos');
        expect(defaultWorkflow(creates, 'translation')).toBe('postmanager');
        expect(defaultWorkflow(creates, null)).toBe('postmanager');
    });

    it('locks the select when only one workflow is on offer', () => {
        expect(isWorkflowLocked(creates)).toBe(false);
        expect(isWorkflowLocked([creates[1]])).toBe(true);
    });

    it('lists one option per workflow', () => {
        expect(workflowOptions(creates)).toEqual([
            { value: 'postmanager', label: 'Beiträge' },
            { value: 'videos', label: 'Videos' },
        ]);
    });

    it('resets the workflow-dependent fields from the chosen workflow', () => {
        expect(workflowFields(entryFor(creates, 'videos'))).toEqual({ collection: 'videos', assignees: [], notify: true });
        expect(workflowFields(entryFor(creates, 'missing'))).toEqual({ collection: '', assignees: [], notify: true });
    });
});
