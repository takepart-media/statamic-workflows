import { describe, expect, it } from 'vitest';
import { addMenuEntries, besideAnchor, centredAt, chainPositions, checkAddAction, NODE_GAP, runAddAction } from '../canvasActions.js';
import {
    applyConnect,
    applyDisconnect,
    bridgeLabel,
    canConnect,
    canDisconnect,
    canStartTransitionFrom,
    edgeEnds,
    isCoupledStart,
} from '../connections.js';
import { addTransition, findPlace, findTransition, fromDefinition, toPayload } from '../editorModel.js';
import { FALLBACK_SIZE } from '../layout.js';

const base = () => fromDefinition({
    type: 'state_machine',
    marking_store: { type: 'method', property: 'marking' },
    initial_place: 'draft',
    collections: ['posts'],
    metadata: {},
    places: {
        draft: { metadata: {} },
        review: { metadata: {} },
        published: { metadata: {} },
        archived: { metadata: {} },
    },
    transitions: {
        submit: { from: 'draft', to: 'review', metadata: { label: 'Submit' } },
        publish: { from: 'review', to: 'published', metadata: { label: 'Publish' } },
        reject: { from: ['review', 'published'], to: 'draft', metadata: { label: 'Reject' } },
    },
}, { labelLocales: ['de', 'en'] });

const empty = () => fromDefinition({
    initial_place: 'draft',
    places: { draft: { metadata: {} }, review: { metadata: {} } },
    transitions: {},
}, { labelLocales: ['de', 'en'] });

describe('canConnect', () => {
    it('allows place → transition, transition → place and place → place', () => {
        const m = base();
        expect(canConnect(m, 'p:archived', 't:publish')).toEqual({ ok: true, kind: 'from' });
        expect(canConnect(m, 't:publish', 'p:archived')).toEqual({ ok: true, kind: 'to', replaces: 'p:published' });
        expect(canConnect(m, 'p:review', 'p:archived')).toEqual({ ok: true, kind: 'bridge' });
    });

    it('refuses what makes no sense', () => {
        const m = base();
        expect(canConnect(m, 't:submit', 't:publish')).toEqual({ ok: false, reason: 'transition_to_transition' });
        expect(canConnect(m, 'p:review', 'p:review')).toEqual({ ok: false, reason: 'self' });
        expect(canConnect(m, 'p:review', 't:publish')).toEqual({ ok: false, reason: 'exists' });
        expect(canConnect(m, 't:publish', 'p:published')).toEqual({ ok: false, reason: 'exists' });
        // Injected system steps are not part of the model.
        expect(canConnect(m, 'p:published', 't:mark_pending')).toEqual({ ok: false, reason: 'unknown' });
    });

    it('keeps the first transition coupled to the initial place', () => {
        const m = base();
        expect(isCoupledStart(m, 't:submit')).toBe(true);
        expect(canConnect(m, 'p:review', 't:submit')).toEqual({ ok: false, reason: 'start_locked' });
        // Its target is free.
        expect(canConnect(m, 't:submit', 'p:archived').ok).toBe(true);
    });

    it('lifts the lock when start_localization pins the start elsewhere', () => {
        const m = base();
        findTransition(m, 't:publish').metadata.actions = [{ type: 'start_localization' }];
        expect(isCoupledStart(m, 't:submit')).toBe(false);
        expect(canConnect(m, 'p:review', 't:submit')).toEqual({ ok: true, kind: 'from' });
    });

    it('allows a loop through a transition', () => {
        const m = base();
        // review → publish → review
        expect(canConnect(m, 't:publish', 'p:review').ok).toBe(true);
    });

    it('creates the first transition only out of the initial place', () => {
        const m = empty();
        expect(canStartTransitionFrom(m, 'p:review')).toEqual({ ok: false, reason: 'start_locked' });
        expect(canConnect(m, 'p:review', 'p:draft')).toEqual({ ok: false, reason: 'start_locked' });
        expect(canConnect(m, 'p:draft', 'p:review')).toEqual({ ok: true, kind: 'bridge' });
    });
});

describe('applyConnect', () => {
    it('adds a source to a transition', () => {
        const m = base();
        expect(applyConnect(m, 'p:archived', 't:publish')).toEqual({ ok: true, kind: 'from', transition: 't:publish', created: false });
        expect(findTransition(m, 't:publish').from).toEqual(['p:review', 'p:archived']);
        expect(toPayload(m).definition.transitions.publish.from).toEqual(['review', 'archived']);
    });

    it('replaces a transition\'s target', () => {
        const m = base();
        applyConnect(m, 't:publish', 'p:archived');
        expect(findTransition(m, 't:publish').to).toBe('p:archived');
    });

    it('creates a labelled transition between two places', () => {
        const m = base();
        const result = applyConnect(m, 'p:review', 'p:archived', {
            position: { x: 10, y: 20 },
            labelOf: (id) => ({ 'p:review': 'In Prüfung', 'p:archived': 'Archiv' }[id]),
        });
        expect(result).toMatchObject({ ok: true, kind: 'bridge', created: true });
        const t = findTransition(m, result.transition);
        expect(t).toMatchObject({ from: ['p:review'], to: 'p:archived', position: { x: 10, y: 20 }, isNew: true });
        expect(t.metadata.label).toBe('In Prüfung → Archiv');
        // The handle follows the label (umlauts spelled out).
        expect(t.handle).toBe('in_pruefung_archiv');
    });

    it('labels by handle without names, and leaves the model alone when refused', () => {
        const m = base();
        expect(bridgeLabel(m, 'p:review', 'p:draft')).toBe('review → draft');
        const before = JSON.stringify(m);
        expect(applyConnect(m, 'p:review', 't:submit')).toEqual({ ok: false, reason: 'start_locked' });
        expect(JSON.stringify(m)).toBe(before);
    });
});

describe('disconnect', () => {
    it('removes a from-entry and keeps the transition', () => {
        const m = base();
        const edge = edgeEnds({ source: 'p:published', target: 't:reject', data: { transition: 't:reject', direction: 'in' } });
        expect(edge).toEqual({ transition: 't:reject', direction: 'in', place: 'p:published' });
        expect(applyDisconnect(m, edge)).toEqual({ ok: true });
        expect(findTransition(m, 't:reject').from).toEqual(['p:review']);
    });

    it('clears a target', () => {
        const m = base();
        const edge = edgeEnds({ source: 't:publish', target: 'p:published', data: { transition: 't:publish', direction: 'out' } });
        expect(applyDisconnect(m, edge)).toEqual({ ok: true });
        expect(findTransition(m, 't:publish')).toMatchObject({ to: null, from: ['p:review'] });
    });

    it('refuses the locked start edge', () => {
        const m = base();
        expect(canDisconnect(m, { transition: 't:submit', place: 'p:draft', direction: 'in' })).toEqual({ ok: false, reason: 'start_locked' });
        expect(applyDisconnect(m, { transition: 't:submit', place: 'p:draft', direction: 'in' }).ok).toBe(false);
        expect(findTransition(m, 't:submit').from).toEqual(['p:draft']);
        // Its target can go.
        expect(canDisconnect(m, { transition: 't:submit', place: 'p:review', direction: 'out' }).ok).toBe(true);
    });

    it('ignores edges that are not (or no longer) there', () => {
        const m = base();
        expect(canDisconnect(m, { transition: 't:nope', place: 'p:draft', direction: 'in' }).ok).toBe(false);
        expect(canDisconnect(m, { transition: 't:publish', place: 'p:draft', direction: 'out' }).ok).toBe(false);
        expect(edgeEnds({ source: 'a', target: 'b' })).toBeNull();
    });
});

describe('positions', () => {
    it('chains new nodes to the right of the anchor', () => {
        const [t, p] = chainPositions({ x: 100, y: 200 }, ['transition', 'place']);
        expect(t).toEqual({ x: 100, y: 200 - FALLBACK_SIZE.transition.height / 2 });
        expect(p).toEqual({ x: 100 + FALLBACK_SIZE.transition.width + NODE_GAP, y: 200 - FALLBACK_SIZE.place.height / 2 });
    });

    it('anchors beside a node and centres on a point', () => {
        expect(besideAnchor({ x: 0, y: 0, width: 240, height: 80 })).toEqual({ x: 240 + NODE_GAP, y: 40 });
        expect(centredAt({ x: 500, y: 300 }, 'place')).toEqual({ x: 500 - 120, y: 300 - FALLBACK_SIZE.place.height / 2 });
    });
});

describe('add menu', () => {
    it('offers per source', () => {
        const m = base();
        const fromPlace = addMenuEntries(m, { kind: 'place', id: 'p:review' });
        expect(fromPlace.slice(0, 2).map((e) => e.action)).toEqual(['transition_new_place', 'place']);
        expect(fromPlace.filter((e) => e.group === 'connect').map((e) => e.target)).toEqual(['p:draft', 'p:review', 'p:published', 'p:archived']);

        const fromTransition = addMenuEntries(m, { kind: 'transition', id: 't:publish' });
        expect(fromTransition[0].action).toBe('target_new_place');
        // The current target is not offered again.
        expect(fromTransition.filter((e) => e.group === 'connect').map((e) => e.target)).toEqual(['p:draft', 'p:review', 'p:archived']);

        expect(addMenuEntries(m, null).map((e) => e.action)).toEqual(['place', 'transition']);
    });

    it('creates a transition and a new place out of a place', () => {
        const m = base();
        const result = runAddAction(m, { kind: 'place', id: 'p:archived' }, { action: 'transition_new_place' }, { anchor: { x: 400, y: 100 }, labelLocales: ['de', 'en'] });
        expect(result.ok).toBe(true);
        const [tId, pId] = result.created;
        expect(result.select).toBe(tId);
        expect(findTransition(m, tId)).toMatchObject({ from: ['p:archived'], to: pId, isNew: true });
        expect(findPlace(m, pId).position.x).toBeGreaterThan(findTransition(m, tId).position.x);
    });

    it('connects a place to an existing one through a new transition (also to itself)', () => {
        const m = base();
        const result = runAddAction(m, { kind: 'place', id: 'p:archived' }, { action: 'transition_to_place', target: 'p:archived' }, { anchor: { x: 0, y: 0 } });
        expect(findTransition(m, result.select)).toMatchObject({ from: ['p:archived'], to: 'p:archived' });
        expect(findTransition(m, result.select).metadata.label).toBe('archived → archived');
    });

    it('adds a lone place or transition', () => {
        const m = base();
        const place = runAddAction(m, null, { action: 'place' }, { anchor: { x: 0, y: 0 }, labelLocales: ['de', 'en'] });
        expect(findPlace(m, place.select)).toMatchObject({ isNew: true, labels: { de: '', en: '' } });
        const transition = runAddAction(m, null, { action: 'transition' }, { anchor: { x: 0, y: 0 } });
        expect(findTransition(m, transition.select)).toMatchObject({ from: [], to: null });
    });

    it('gives a transition a new or an existing target', () => {
        const m = base();
        const created = runAddAction(m, { kind: 'transition', id: 't:publish' }, { action: 'target_new_place' }, { anchor: { x: 0, y: 0 }, labelLocales: ['de', 'en'] });
        expect(findTransition(m, 't:publish').to).toBe(created.select);
        const existing = runAddAction(m, { kind: 'transition', id: 't:publish' }, { action: 'target_place', target: 'p:archived' }, {});
        expect(existing).toEqual({ ok: true, select: 't:publish', created: [] });
        expect(findTransition(m, 't:publish').to).toBe('p:archived');
    });

    it('refuses a first transition outside the start place', () => {
        const m = empty();
        const entry = { action: 'transition_new_place' };
        expect(checkAddAction(m, { kind: 'place', id: 'p:review' }, entry)).toEqual({ ok: false, reason: 'start_locked' });
        expect(runAddAction(m, { kind: 'place', id: 'p:review' }, entry, { anchor: { x: 0, y: 0 } })).toEqual({ ok: false, reason: 'start_locked' });
        expect(m.transitions).toHaveLength(0);
        expect(runAddAction(m, { kind: 'place', id: 'p:draft' }, entry, { anchor: { x: 0, y: 0 } }).ok).toBe(true);
        // Now that a first one exists, others can leave anywhere.
        addTransition(m, { from: 'p:review' });
        expect(checkAddAction(m, { kind: 'place', id: 'p:review' }, entry).ok).toBe(true);
    });
});
