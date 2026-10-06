import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { parse } from 'yaml';
import { normalize } from '../normalize.js';
import { serialize } from '../serialize.js';
import { explicitStartTransition, startingTransition } from '../actionRules.js';
import {
    addPlace,
    addTransition,
    applyServerDefinition,
    editorGraph,
    fromDefinition,
    handleProblem,
    moveTransition,
    movePlace,
    removePlace,
    removeTransition,
    reorder,
    restoreDeletion,
    setHandle,
    setInitialPlace,
    setMigrationTarget,
    setPosition,
    toHandleModel,
    toPayload,
    updatePlace,
    updateTransition,
    viewModel,
} from '../editorModel.js';

const examples = ['postmanager', 'translation', 'plainlanguage'];

function loadExample(name) {
    const path = fileURLToPath(new URL(`../../../../workflows/examples/${name}.yaml`, import.meta.url));
    return parse(readFileSync(path, 'utf8'));
}

const clone = (value) => JSON.parse(JSON.stringify(value));

// What the old settings form sends for an untouched definition: normalize(),
// then its start-coupling watcher (immediate), then serialize().
function formPayload(def, ctx = {}) {
    const form = normalize(def, ctx);
    if (!explicitStartTransition(form)) {
        const first = startingTransition(form);
        if (first && form.initial_place) form.transitions[first].from = form.initial_place;
    }
    return serialize(form, ctx);
}

const base = () => ({
    type: 'state_machine',
    marking_store: { type: 'method', property: 'marking' },
    initial_place: 'draft',
    collections: ['posts'],
    metadata: {
        sla: { needs_update_days: 30, needs_update_place: 'stale' },
        create_transition: 'submit',
        seed_published_place: 'published',
    },
    places: {
        draft: { metadata: {} },
        review: { metadata: { actions: [{ type: 'auto_transition', after_days: 3, transition: 'publish' }] } },
        published: { metadata: { allow_publish: true } },
        stale: { metadata: { branch: true } },
    },
    transitions: {
        submit: { from: 'draft', to: 'review', metadata: { label: 'Submit' } },
        publish: { from: 'review', to: 'published', metadata: { label: 'Publish' } },
        reject: { from: ['review', 'published'], to: 'draft', metadata: { label: 'Reject' } },
    },
});

describe('field round-trip parity with the old form', () => {
    for (const name of examples) {
        it(`${name}.yaml serializes exactly like the form does`, () => {
            const def = loadExample(name);
            const expected = formPayload(def);
            const actual = toPayload(fromDefinition(def)).definition;
            expect(actual).toEqual(expected);
            // Order is meaning (progress bar, final place) — check it explicitly.
            expect(Object.keys(actual.places)).toEqual(Object.keys(expected.places));
            expect(Object.keys(actual.transitions)).toEqual(Object.keys(expected.transitions));
        });

        it(`${name}.yaml keeps unknown keys and metadata.editor`, () => {
            const def = loadExample(name);
            def.metadata.editor = {
                places: { [def.initial_place]: { x: 10, y: -20 } },
                transitions: { [Object.keys(def.transitions)[0]]: { x: 300, y: 40 } },
                viewport: { zoom: 1.25 },
            };
            def.metadata.some_project_key = { nested: ['a', 1, null] };
            def.places[def.initial_place].custom_top_level = 'kept';
            Object.values(def.transitions)[0].guard = 'is_granted("x")';

            const actual = toPayload(fromDefinition(def)).definition;
            expect(actual).toEqual(formPayload(def));
            expect(actual.metadata.editor).toEqual(def.metadata.editor);
            expect(actual.places[def.initial_place].custom_top_level).toBe('kept');
        });

        it(`${name}.yaml survives save → server → load unchanged`, () => {
            const once = toPayload(fromDefinition(loadExample(name))).definition;
            const twice = toPayload(fromDefinition(once)).definition;
            expect(twice).toEqual(once);
        });
    }

    it('keeps a dangling reference to an unknown handle as it was', () => {
        const def = base();
        def.metadata.source_draft_place = 'nowhere';
        expect(toPayload(fromDefinition(def)).definition.metadata.source_draft_place).toBe('nowhere');
    });
});

describe('ids', () => {
    it('loads every reference as an id', () => {
        const m = fromDefinition(base());
        expect(m.workflow.initial_place).toBe('p:draft');
        expect(m.workflow.metadata.sla.needs_update_place).toBe('p:stale');
        expect(m.workflow.metadata.create_transition).toBe('t:submit');
        expect(m.workflow.metadata.seed_published_place).toBe('p:published');
        expect(m.places[1].metadata.actions[0].transition).toBe('t:publish');
        expect(m.transitions[2]).toMatchObject({ from: ['p:review', 'p:published'], to: 'p:draft', fromList: true });
    });

    it('offers a handle view for the rule helpers that shares the action objects', () => {
        const m = fromDefinition(base());
        const view = viewModel(m);
        expect(view.initial_place).toBe('draft');
        expect(view.metadata.sla.needs_update_place).toBe('stale');
        expect(view.transitions.reject.from).toEqual(['review', 'published']);
        expect(view.places.review.metadata.actions[0]).toBe(m.places[1].metadata.actions[0]);
        // The view never writes back.
        expect(m.workflow.metadata.sla.needs_update_place).toBe('p:stale');
    });

    it('builds the canvas graph with injected parts appended', () => {
        const m = fromDefinition(base());
        const graph = editorGraph(m, {
            places: { pending_revision: {} },
            transitions: { mark_pending: { from: 'published', to: 'pending_revision', metadata: { system: true } } },
        });
        expect(graph.places.map((p) => p.id)).toEqual(['p:draft', 'p:review', 'p:published', 'p:stale', 'p:pending_revision']);
        expect(graph.transitions.at(-1)).toMatchObject({ id: 't:mark_pending', from: ['p:published'], injected: true });
    });
});

describe('mutations', () => {
    it('derives a new node\'s handle from its label until one is typed', () => {
        const m = fromDefinition(base());
        const id = addPlace(m, { labelLocales: ['de', 'en'] });
        expect(id).toBe('p~1');
        expect(handleProblem(m, id)).toBe('missing');
        updatePlace(m, id, { labels: { de: 'In Prüfung' } });
        expect(m.places.at(-1).handle).toBe('in_pruefung');
        updatePlace(m, id, { labels: { de: 'Review' } });
        // "review" is taken → suffixed.
        expect(m.places.at(-1).handle).toBe('review_2');
        setHandle(m, id, 'checked');
        updatePlace(m, id, { labels: { de: 'Something else' } });
        expect(m.places.at(-1).handle).toBe('checked');
        setHandle(m, id, 'Bad Handle');
        expect(handleProblem(m, id)).toBe('invalid');
        setHandle(m, id, '');
        expect(m.places.at(-1).handle).toBe('something_else');
    });

    it('never touches a saved handle', () => {
        const m = fromDefinition(base());
        setHandle(m, 'p:draft', 'other');
        updateTransition(m, 't:submit', { metadata: { label: 'Send' } });
        expect(m.places[0].handle).toBe('draft');
        expect(m.transitions[0].handle).toBe('submit');
        expect(m.transitions[0].metadata.label).toBe('Send');
    });

    it('keeps the first transition coupled to the start place', () => {
        const m = fromDefinition(base());
        setInitialPlace(m, 'p:review');
        expect(m.transitions[0].from).toEqual(['p:review']);
        moveTransition(m, 't:reject', 0);
        expect(m.transitions.map((t) => t.id)).toEqual(['t:reject', 't:submit', 't:publish']);
        expect(m.transitions[0]).toMatchObject({ from: ['p:review'], fromList: false });
    });

    it('reorders places and transitions', () => {
        const m = fromDefinition(base());
        movePlace(m, 'p:stale', 1);
        expect(m.places.map((p) => p.handle)).toEqual(['draft', 'stale', 'review', 'published']);
        reorder(m, 'place', ['p:published', 'p:draft', 'p:stale', 'p:review']);
        expect(Object.keys(toPayload(m).definition.places)).toEqual(['published', 'draft', 'stale', 'review']);
    });

    it('writes positions to metadata.editor', () => {
        const m = fromDefinition(base());
        setPosition(m, 'p:draft', { x: 10.4, y: 20.6 });
        setPosition(m, 't:submit', { x: 1, y: 2 });
        expect(toPayload(m).definition.metadata.editor).toEqual({
            places: { draft: { x: 10, y: 21 } },
            transitions: { submit: { x: 1, y: 2 } },
        });
    });

    it('removes a transition and clears what pointed at it', () => {
        const m = fromDefinition(base());
        removeTransition(m, 't:publish');
        expect(m.places[1].metadata.actions[0].transition).toBeNull();
        removeTransition(m, 't:submit');
        expect(m.workflow.metadata.create_transition).toBeNull();
        // The new first transition is now coupled to the start place.
        expect(m.transitions[0]).toMatchObject({ id: 't:reject', from: ['p:draft'] });
    });

    it('refuses to remove the start place', () => {
        const m = fromDefinition(base());
        expect(removePlace(m, 'p:draft')).toBe(false);
        expect(m.places).toHaveLength(4);
    });
});

describe('deletions', () => {
    it('records a saved place for removed_places, with its migration target', () => {
        const m = fromDefinition(base());
        const fresh = addPlace(m, { label: 'Archive' });
        removePlace(m, 'p:stale', { usage: 0 });
        removePlace(m, 'p:published', { usage: 5 });
        setMigrationTarget(m, 'p:published', fresh);

        const payload = toPayload(m);
        expect(payload.removed_places).toEqual([
            { place: 'stale' },
            { place: 'published', migrate_to: 'archive' },
        ]);
        expect(Object.keys(payload.definition.places)).toEqual(['draft', 'review', 'archive']);
        // References went with the places.
        expect(payload.definition.metadata.sla).toBeUndefined();
        expect(payload.definition.metadata.seed_published_place).toBeUndefined();
        expect(payload.definition.transitions.publish.to).toBeUndefined();
        expect(payload.definition.transitions.reject.from).toEqual(['review']);
    });

    it('drops a new place without a trace and clears a migration target pointing at it', () => {
        const m = fromDefinition(base());
        const fresh = addPlace(m, { label: 'Archive' });
        removePlace(m, 'p:stale', { usage: 2, migrateTo: fresh });
        removePlace(m, fresh);
        expect(m.deletions).toEqual([expect.objectContaining({ handle: 'stale', migrateTo: null })]);
    });

    it('can be undone', () => {
        const m = fromDefinition(base());
        removePlace(m, 'p:review');
        restoreDeletion(m, 'p:review');
        expect(m.places.map((p) => p.handle)).toEqual(['draft', 'review', 'published', 'stale']);
        expect(toPayload(m).removed_places).toEqual([]);
    });

    it('treats the handle of a place marked for deletion as taken', () => {
        const m = fromDefinition(base());
        removePlace(m, 'p:stale');
        const id = addPlace(m);
        setHandle(m, id, 'stale');
        expect(handleProblem(m, id)).toBe('taken');
    });
});

describe('id ↔ handle across a save', () => {
    it('maps new ids to the saved ones and rebuilds from the server', () => {
        const m = fromDefinition(base());
        const place = addPlace(m, { label: 'Archive' });
        const step = addTransition(m, { from: 'p:published', to: place });
        updateTransition(m, step, { metadata: { label: 'Archive it' } });
        setPosition(m, place, { x: 5, y: 6 });
        removePlace(m, 'p:stale');

        const { definition } = toPayload(m);
        expect(definition.transitions.archive_it).toMatchObject({ from: 'published', to: 'archive' });

        // What the server would answer: the stored definition.
        const ids = applyServerDefinition(m, clone(definition));
        expect(ids[place]).toBe('p:archive');
        expect(ids[step]).toBe('t:archive_it');
        expect(m.places.find((p) => p.id === 'p:archive')).toMatchObject({ isNew: false, position: { x: 5, y: 6 } });
        expect(m.deletions).toEqual([]);
        expect(m.transitions.at(-1)).toMatchObject({ id: 't:archive_it', from: ['p:published'], to: 'p:archive' });
    });

    it('serializes an unnamed new node under its id so the save can be stopped', () => {
        const m = fromDefinition(base());
        const id = addPlace(m);
        expect(Object.keys(toHandleModel(m).places)).toContain(id);
    });
});
