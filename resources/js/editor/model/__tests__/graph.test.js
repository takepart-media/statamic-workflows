import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { parse } from 'yaml';
import { normalize } from '../normalize.js';
import { serialize } from '../serialize.js';
import {
    fromGraph,
    nextNewId,
    parseId,
    placeId,
    toGraph,
    toPosition,
    transitionId,
    transitionLinks,
} from '../graphModel.js';
import { isGatePlace, placeInfo, transitionInfo } from '../nodeInfo.js';
import { computeLayout, placeNear } from '../layout.js';

const examples = ['postmanager', 'translation', 'plainlanguage'];

function loadExample(name) {
    const path = fileURLToPath(new URL(`../../../../workflows/examples/${name}.yaml`, import.meta.url));
    return parse(readFileSync(path, 'utf8'));
}

const clone = (value) => JSON.parse(JSON.stringify(value));

const injected = {
    places: { pending_revision: { label: 'Unpublished changes', metadata: { revision_pending: true } } },
    transitions: {
        mark_pending: { from: 'published', to: 'pending_revision', metadata: { label: 'Unpublished changes', system: true } },
        publish_revision: { from: 'pending_revision', to: 'published', metadata: { label: 'Publish changes', system: true } },
    },
};

function small() {
    return normalize({
        initial_place: 'draft',
        metadata: {
            create_transition: 'submit',
            sla: { needs_update_days: 10, needs_update_place: 'stale' },
            editor: {
                zoom: 1.2,
                places: { draft: { x: 10, y: 20 }, gone: { x: 1, y: 1 } },
                transitions: { submit: { x: 100.4, y: '20' } },
            },
        },
        places: {
            draft: { metadata: { actions: [{ type: 'mail' }, { type: 'reminder', every_days: 2 }, { type: 'mail' }, { type: 'my_hook' }] } },
            review: { metadata: {} },
            published: { metadata: { allow_publish: true } },
            stale: { metadata: { branch: true } },
        },
        transitions: {
            submit: { from: 'draft', to: 'review', metadata: { label: 'Einreichen', actions: [{ type: 'user_assignment', mode: 'automatic', criteria: [{ field: 'group', value: 'x' }] }, { type: 'mail' }] } },
            publish: { from: ['review'], to: 'published', metadata: { required_role: ['chief'] } },
            reject: { from: ['review', 'published'], to: 'draft', metadata: {} },
            age: { from: 'published', to: 'stale', metadata: {} },
        },
    });
}

describe('ids', () => {
    it('parses the three id shapes', () => {
        expect(placeId('draft')).toBe('p:draft');
        expect(transitionId('submit')).toBe('t:submit');
        expect(parseId('p:draft')).toEqual({ kind: 'place', handle: 'draft', isNew: false });
        expect(parseId('t:submit')).toEqual({ kind: 'transition', handle: 'submit', isNew: false });
        expect(parseId('p~3')).toEqual({ kind: 'place', handle: null, isNew: true });
        expect(parseId('x')).toBeNull();
    });

    it('hands out the next free new id', () => {
        const graph = { places: [{ id: 'p:a' }, { id: 'p~2' }, { id: 'p~7' }], transitions: [] };
        expect(nextNewId(graph, 'place')).toBe('p~8');
        expect(nextNewId(graph, 'transition')).toBe('t~1');
    });

    it('accepts only finite positions', () => {
        expect(toPosition({ x: 1, y: '2' })).toEqual({ x: 1, y: 2 });
        expect(toPosition({ x: 1 })).toBeNull();
        expect(toPosition({ x: null, y: 2 })).toBeNull();
        expect(toPosition('1,2')).toBeNull();
    });
});

describe('toGraph', () => {
    it('keeps YAML order and reads positions', () => {
        const graph = toGraph(small());
        expect(graph.places.map((p) => p.id)).toEqual(['p:draft', 'p:review', 'p:published', 'p:stale']);
        expect(graph.transitions.map((t) => t.id)).toEqual(['t:submit', 't:publish', 't:reject', 't:age']);
        expect(graph.places[0].position).toEqual({ x: 10, y: 20 });
        expect(graph.places[1].position).toBeNull();
        expect(graph.transitions[0].position).toEqual({ x: 100.4, y: 20 });
    });

    it('turns from/to into place ids and remembers the list spelling', () => {
        const [submit, publish, reject] = toGraph(small()).transitions;
        expect(submit).toMatchObject({ from: ['p:draft'], to: 'p:review', fromList: false });
        expect(publish).toMatchObject({ from: ['p:review'], fromList: true });
        expect(reject.from).toEqual(['p:review', 'p:published']);
        expect(transitionLinks(reject)).toEqual([
            ['p:review', 't:reject'],
            ['p:published', 't:reject'],
            ['t:reject', 'p:draft'],
        ]);
    });

    it('appends injected parts, flagged', () => {
        const model = normalize({ places: { published: {} }, transitions: {} });
        const graph = toGraph(model, { injected });
        expect(graph.places.map((p) => [p.id, p.injected])).toEqual([['p:published', false], ['p:pending_revision', true]]);
        expect(graph.transitions.map((t) => [t.id, t.injected, t.to])).toEqual([
            ['t:mark_pending', true, 'p:pending_revision'],
            ['t:publish_revision', true, 'p:published'],
        ]);
    });
});

describe('fromGraph', () => {
    for (const name of examples) {
        it(`${name}.yaml survives model → graph → model unchanged`, () => {
            const model = normalize(loadExample(name));
            const back = fromGraph(toGraph(model, { injected }), model);
            expect(back).toEqual(model);
            expect(serialize(back)).toEqual(serialize(model));
        });
    }

    it('applies order, endpoints and positions; prunes stale positions, keeps other editor keys', () => {
        const model = small();
        const graph = toGraph(model, { injected });
        graph.places.reverse();
        graph.places.find((p) => p.id === 'p:review').position = { x: 5.6, y: 7.2 };
        graph.transitions[0].from = ['p:draft', 'p:published'];
        graph.transitions[1].from = ['p:draft'];
        graph.transitions[3].to = null;

        const back = fromGraph(graph, model);
        expect(Object.keys(back.places)).toEqual(['stale', 'published', 'review', 'draft']);
        expect(back.transitions.submit.from).toEqual(['draft', 'published']);
        // Spelled as a list in the YAML → stays a list.
        expect(back.transitions.publish.from).toEqual(['draft']);
        expect(back.transitions.age.to).toBeNull();
        expect(back.metadata.editor).toEqual({
            zoom: 1.2,
            places: { review: { x: 6, y: 7 }, draft: { x: 10, y: 20 } },
            transitions: { submit: { x: 100, y: 20 } },
        });
        // Never mutates its input.
        expect(model.metadata.editor.places.gone).toEqual({ x: 1, y: 1 });
    });

    it('drops removed nodes, creates named new ones, skips unnamed ones', () => {
        const model = small();
        const graph = toGraph(model);
        graph.places = graph.places.filter((p) => p.handle !== 'stale');
        graph.transitions = graph.transitions.filter((t) => t.handle !== 'age');
        graph.places.push({ id: 'p~1', handle: 'archived', injected: false, position: { x: 0, y: 0 } });
        graph.places.push({ id: 'p~2', handle: null, injected: false, position: null });
        graph.transitions.push({ id: 't~1', handle: 'archive', from: ['p:published'], to: 'p~1', fromList: false, injected: false, position: null });

        const back = fromGraph(graph, model, { labelLocales: ['de', 'en'] });
        expect(Object.keys(back.places)).toEqual(['draft', 'review', 'published', 'archived']);
        expect(back.places.archived).toEqual({ labels: { de: '', en: '' }, legacyLabel: '', metadata: { branch: false, actions: [] } });
        expect(back.transitions.archive).toMatchObject({ from: 'published', to: 'archived' });
        expect(back.transitions.age).toBeUndefined();
    });

    it('leaves metadata.editor out while nothing has a position', () => {
        const model = normalize({ places: { a: {} }, transitions: {} });
        expect(fromGraph(toGraph(model), model).metadata.editor).toBeUndefined();
    });
});

describe('nodeInfo', () => {
    const ctx = { labelLocale: 'de', labelLocales: ['de', 'en'], stateUsage: { draft: 3 } };

    it('derives place roles, actions, order and usage', () => {
        const model = small();
        expect(placeInfo(model, 'draft', ctx)).toMatchObject({
            order: 1,
            roles: ['start'],
            usage: 3,
            actions: [
                { type: 'mail', icon: 'mail', custom: false },
                { type: 'reminder', icon: 'bell', custom: false },
                { type: 'my_hook', icon: 'puzzle-piece', custom: true },
            ],
        });
        expect(placeInfo(model, 'published', ctx).roles).toEqual(['publish']);
        expect(placeInfo(model, 'stale', ctx).roles).toEqual(['branch', 'sla_1']);
        expect(placeInfo(model, 'review', ctx).usage).toBeNull();
    });

    it('marks a gate on a gated or forking place, not on a branch exit', () => {
        const model = small();
        // review → publish is role-gated.
        expect(isGatePlace(model, 'review')).toBe(true);
        // published → reject (main line) + age (into a branch place): one main-line exit.
        expect(isGatePlace(model, 'published')).toBe(false);
        expect(isGatePlace(model, 'draft')).toBe(false);
        model.transitions.reject.from = ['draft'];
        model.transitions.extra = { from: 'draft', to: 'published', metadata: {} };
        expect(isGatePlace(model, 'draft')).toBe(true);
    });

    it('derives transition chips and badges', () => {
        const model = small();
        expect(transitionInfo(model, 'submit')).toEqual({
            handle: 'submit',
            label: 'Einreichen',
            variant: 'primary',
            chips: [{ code: 'assign', mode: 'automatic' }, { code: 'mail' }],
            badges: ['start', 'cta'],
        });
        expect(transitionInfo(model, 'publish').chips).toEqual([{ code: 'gate', by: 'role', count: 1 }]);
        expect(transitionInfo(model, 'reject')).toMatchObject({ label: 'reject', variant: 'danger', badges: [] });
    });
});

describe('layout', () => {
    const nodes = [
        { id: 'a', width: 100, height: 40 },
        { id: 't1', width: 80, height: 30 },
        { id: 'b', width: 100, height: 40 },
        { id: 't2', width: 80, height: 30, band: 'below' },
        { id: 'c', width: 100, height: 40, band: 'below' },
    ];
    const edges = [
        { source: 'a', target: 't1' },
        { source: 't1', target: 'b' },
        { source: 'b', target: 't2' },
        { source: 't2', target: 'c' },
    ];

    it('lays out left to right and puts the branch band below the main line', () => {
        const pos = computeLayout({ nodes, edges });
        expect(pos.a.x).toBeLessThan(pos.t1.x);
        expect(pos.t1.x).toBeLessThan(pos.b.x);
        expect(pos.b.x).toBeLessThan(pos.c.x);
        const mainBottom = Math.max(pos.a.y + 40, pos.t1.y + 30, pos.b.y + 40);
        expect(pos.t2.y).toBeGreaterThanOrEqual(mainBottom);
        expect(pos.c.y).toBeGreaterThanOrEqual(mainBottom);
        for (const p of Object.values(pos)) {
            expect(Number.isInteger(p.x) && Number.isInteger(p.y)).toBe(true);
        }
    });

    it('places late nodes next to their neighbours', () => {
        const known = { a: { x: 0, y: 0 }, b: { x: 400, y: 0 } };
        const out = placeNear(['t', 'p', 'lonely'], known, [
            { source: 'b', target: 't' },
            { source: 't', target: 'p' },
        ]);
        expect(out.t).toEqual({ x: 400, y: 120 });
        expect(out.p.y).toBeGreaterThan(out.t.y);
        expect(out.lonely).toBeDefined();
        expect(known.t).toBeUndefined();
    });
});
