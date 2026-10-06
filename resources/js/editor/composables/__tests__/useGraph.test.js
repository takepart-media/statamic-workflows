import { describe, expect, it } from 'vitest';
import { normalize } from '../../model/normalize.js';
import { toGraph } from '../../model/graphModel.js';
import { buildElements } from '../useGraph.js';

const injected = {
    places: { pending_revision: { label: 'Unpublished changes', metadata: { revision_pending: true } } },
    transitions: {
        mark_pending: { from: 'published', to: 'pending_revision', metadata: { label: 'Unpublished changes', system: true } },
        publish_revision: { from: 'pending_revision', to: 'published', metadata: { label: 'Publish changes', system: true } },
    },
};

const model = normalize({
    initial_place: 'draft',
    metadata: { editor: { places: { draft: { x: 1, y: 2 } } } },
    places: { draft: {}, review: {}, published: { metadata: { allow_publish: true } } },
    transitions: {
        submit: { from: 'draft', to: 'review', metadata: {} },
        publish: { from: 'review', to: 'published', metadata: {} },
        reject: { from: ['review', 'published', 'review'], to: 'draft', metadata: {} },
    },
});

const build = (showSystem) => buildElements(model, toGraph(model, { injected }), { showSystem, ctx: { labelLocale: 'de' } });

describe('buildElements', () => {
    it('makes one node per place/transition, typed, with stored positions', () => {
        const { nodes } = build(true);
        expect(nodes.map((n) => [n.id, n.type])).toEqual([
            ['p:draft', 'place'],
            ['p:review', 'place'],
            ['p:published', 'place'],
            ['p:pending_revision', 'ghost'],
            ['t:submit', 'transition'],
            ['t:publish', 'transition'],
            ['t:reject', 'transition'],
            ['t:mark_pending', 'ghost'],
            ['t:publish_revision', 'ghost'],
        ]);
        expect(nodes[0].position).toEqual({ x: 1, y: 2 });
        expect(nodes[1].position).toBeNull();
        expect(nodes.find((n) => n.id === 'p:pending_revision')).toMatchObject({ selectable: false });
    });

    it('draws from[i] → transition → to, deduplicated, with the locked start edge and dashed ghosts', () => {
        const { edges } = build(true);
        expect(edges.map((e) => e.id)).toEqual([
            'p:draft->t:submit',
            't:submit->p:review',
            'p:review->t:publish',
            't:publish->p:published',
            'p:review->t:reject',
            'p:published->t:reject',
            't:reject->p:draft',
            'p:published->t:mark_pending',
            't:mark_pending->p:pending_revision',
            'p:pending_revision->t:publish_revision',
            't:publish_revision->p:published',
        ]);
        expect(edges[0].class).toContain('wf-edge-locked');
        expect(edges[7].class).toContain('wf-edge-ghost');
        expect(edges[7].selectable).toBe(false);
    });

    it('leaves the system steps out when they are hidden', () => {
        const { nodes, edges } = build(false);
        expect(nodes.some((n) => n.type === 'ghost')).toBe(false);
        expect(edges.some((e) => e.class.includes('wf-edge-ghost'))).toBe(false);
    });
});
