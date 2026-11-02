import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { parse } from 'yaml';
import { normalize } from '../normalize.js';
import { serialize } from '../serialize.js';

const examples = ['postmanager', 'translation', 'plainlanguage'];

function loadExample(name) {
    const path = fileURLToPath(new URL(`../../../../workflows/examples/${name}.yaml`, import.meta.url));
    return parse(readFileSync(path, 'utf8'));
}

const clone = (value) => JSON.parse(JSON.stringify(value));

// What saving an untouched definition is EXPECTED to change — and nothing else:
//   - only the stored keys go along (`name` comes from the file name);
//   - the workflow booleans/lists the form binds get written as real values
//     (nav, assign_creator, post_creator_groups, assigner_groups);
//   - places lose the retired `required_group`;
//   - a transition's `required_group` is always a list.
function cleaned(def) {
    const out = clone({
        places: def.places,
        transitions: def.transitions,
        metadata: def.metadata,
        collections: def.collections ?? [],
        initial_place: def.initial_place,
        marking_store: def.marking_store,
        type: def.type,
    });
    out.metadata.nav ??= {};
    out.metadata.assign_creator ??= false;
    out.metadata.post_creator_groups ??= [];
    out.metadata.assigner_groups ??= [];
    for (const place of Object.values(out.places)) {
        delete place.metadata.required_group;
    }
    for (const transition of Object.values(out.transitions)) {
        const group = transition.metadata.required_group;
        if (group !== undefined) transition.metadata.required_group = [].concat(group);
    }
    return out;
}

const roundTrip = (def, ctx = {}) => serialize(normalize(def, ctx), ctx);

describe('normalize → serialize round trip', () => {
    for (const name of examples) {
        it(`${name}.yaml comes back as its cleaned definition`, () => {
            const def = loadExample(name);
            expect(roundTrip(def)).toEqual(cleaned(def));
        });

        it(`${name}.yaml is stable on a second save`, () => {
            const once = roundTrip(loadExample(name));
            expect(roundTrip(once)).toEqual(once);
        });

        it(`${name}.yaml keeps unknown metadata keys and metadata.editor`, () => {
            const def = loadExample(name);
            const editor = {
                places: { [def.initial_place]: { x: 10, y: -20 } },
                transitions: { [Object.keys(def.transitions)[0]]: { x: 300, y: 40.5 } },
                viewport: { zoom: 1.25 },
            };
            def.metadata.editor = clone(editor);
            def.metadata.some_project_key = { nested: ['a', 1, null] };
            def.metadata.another_flag = false;
            def.places[def.initial_place].metadata.custom_place_key = 'kept';
            Object.values(def.transitions)[0].metadata.custom_transition_key = 42;

            const payload = roundTrip(def);

            expect(payload.metadata.editor).toEqual(editor);
            expect(payload.metadata.some_project_key).toEqual({ nested: ['a', 1, null] });
            expect(payload.metadata.another_flag).toBe(false);
            expect(payload.places[def.initial_place].metadata.custom_place_key).toBe('kept');
            expect(Object.values(payload.transitions)[0].metadata.custom_transition_key).toBe(42);
        });
    }

    it('does not mutate its input', () => {
        const def = loadExample('postmanager');
        const before = clone(def);
        const model = normalize(def);
        const modelBefore = clone(model);
        serialize(model);
        expect(def).toEqual(before);
        expect(model).toEqual(modelBefore);
    });
});

describe('legacy shapes', () => {
    const base = (transitionMeta = {}, placeMeta = {}, placeExtra = {}) => ({
        type: 'state_machine',
        initial_place: 'a',
        places: {
            a: { ...placeExtra, metadata: { ...placeMeta } },
            b: { metadata: {} },
        },
        transitions: {
            go: { from: 'a', to: 'b', metadata: { label: 'Go', ...transitionMeta } },
        },
    });

    it('keeps a string label as legacy label and writes it back unchanged', () => {
        const model = normalize(base({}, {}, { label: 'Alpha' }));
        expect(model.places.a.labels).toEqual({ de: '', en: '' });
        expect(model.places.a.legacyLabel).toBe('Alpha');
        expect(serialize(model).places.a.label).toBe('Alpha');
    });

    it('replaces the legacy label once the author names the place', () => {
        const model = normalize(base({}, {}, { label: 'Alpha' }));
        model.places.a.labels.en = '  Alpha EN ';
        expect(serialize(model).places.a.label).toEqual({ en: 'Alpha EN' });
    });

    it('reads a per-language label map for the configured locales only', () => {
        const model = normalize(base({}, {}, { label: { de: 'A-de', en: 'A-en', fr: 'A-fr' } }), { labelLocales: ['de', 'en'] });
        expect(model.places.a.labels).toEqual({ de: 'A-de', en: 'A-en' });
        expect(serialize(model).places.a.label).toEqual({ de: 'A-de', en: 'A-en' });
    });

    it('drops the retired place required_group', () => {
        const model = normalize(base({}, { required_group: 'editor' }));
        expect(model.places.a.metadata.required_group).toBeUndefined();
        expect(serialize(model).places.a.metadata).toEqual({});
    });

    it('folds a scalar transition required_group into the form pair and back into a list', () => {
        const model = normalize(base({ required_group: 'editor' }));
        expect(model.transitions.go.metadata.required_by).toBe('group');
        expect(model.transitions.go.metadata.required_handles).toEqual(['editor']);
        expect(serialize(model).transitions.go.metadata.required_group).toEqual(['editor']);
    });

    it('prefers required_role over required_group', () => {
        const model = normalize(base({ required_group: ['editor'], required_role: 'chief' }));
        expect(model.transitions.go.metadata.required_by).toBe('role');
        const meta = serialize(model).transitions.go.metadata;
        expect(meta.required_role).toEqual(['chief']);
        expect(meta.required_group).toBeUndefined();
    });

    it('migrates a user_modal action into a manual assignment over its groups', () => {
        const model = normalize(base({ actions: [{ type: 'user_modal', groups: ['editor', 'expert'] }] }));
        expect(model.transitions.go.metadata.actions[0]).toMatchObject({
            type: 'user_assignment',
            mode: 'manual',
            match: 'any',
            criteria: [
                { field: 'group', operator: 'is', value: 'editor' },
                { field: 'group', operator: 'is', value: 'expert' },
            ],
        });
        expect(serialize(model).transitions.go.metadata.actions).toEqual([{
            type: 'user_assignment',
            mode: 'manual',
            match: 'any',
            criteria: [
                { field: 'group', operator: 'is', value: 'editor' },
                { field: 'group', operator: 'is', value: 'expert' },
            ],
        }]);
    });

    it('migrates assign_from_group and drops the old key', () => {
        const model = normalize(base({ assign_from_group: 'editor', needs_site_pick: true }));
        expect(model.transitions.go.metadata.actions).toHaveLength(1);
        const meta = serialize(model).transitions.go.metadata;
        expect(meta.assign_from_group).toBeUndefined();
        expect(meta.needs_site_pick).toBeUndefined();
        expect(meta.actions).toEqual([{
            type: 'user_assignment',
            mode: 'manual',
            match: 'any',
            criteria: [{ field: 'group', operator: 'is', value: 'editor' }],
        }]);
    });

    it('turns notify_assignees into mail, on transitions and places', () => {
        const model = normalize(base(
            { actions: [{ type: 'notify_assignees' }] },
            { actions: [{ type: 'notify_assignees', mail: 'reviewed' }] },
        ));
        const payload = serialize(model);
        expect(payload.transitions.go.metadata.actions).toEqual([{ type: 'mail', mail_type: 'assigned' }]);
        expect(payload.places.a.metadata.actions).toEqual([{ type: 'mail', mail_type: 'reviewed' }]);
    });

    it('folds the plain_language_resubmit and pending_rewrite_spinner booleans into actions', () => {
        const model = normalize(base({ plain_language_resubmit: true }, { pending_rewrite_spinner: true }));
        const payload = serialize(model);
        expect(payload.transitions.go.metadata).not.toHaveProperty('plain_language_resubmit');
        expect(payload.transitions.go.metadata.actions).toEqual([{ type: 'plain_language_resubmit' }]);
        expect(payload.places.a.metadata).not.toHaveProperty('pending_rewrite_spinner');
        expect(payload.places.a.metadata.actions).toEqual([{ type: 'pending_rewrite_spinner' }]);
    });
});

describe('metadata cleanup', () => {
    it('sends an SLA stage only as a complete pair', () => {
        const model = normalize({
            places: { a: { metadata: {} }, b: { metadata: {} } },
            metadata: { sla: { needs_update_days: '30', needs_update_place: 'a', outdated_days: 90 } },
        });
        expect(serialize(model).metadata.sla).toEqual({ needs_update_days: 30, needs_update_place: 'a' });

        model.metadata.sla.needs_update_place = null;
        expect(serialize(model).metadata).not.toHaveProperty('sla');
    });

    it('keeps an SLA assignment only when complete', () => {
        const model = normalize({
            metadata: { sla: { outdated_days: 5, outdated_place: 'b', outdated_assign: { by: 'group', handles: ['x'] }, needs_update_assign: { by: 'role' } } },
        });
        expect(serialize(model).metadata.sla).toEqual({
            outdated_days: 5,
            outdated_place: 'b',
            outdated_assign: { by: 'group', handles: ['x'] },
        });
    });

    it('drops cleared selects, the default orientation, empty source-view rules and credentials', () => {
        const model = normalize({
            metadata: {
                progress_orientation: 'horizontal',
                translation_source_view: [
                    { mode: 'hidden', by: 'group', handles: [] },
                    { mode: 'collapsed', by: 'user', handles: ['7'], extra: 'dropped' },
                ],
                deepl_key: 'secret',
                cortex_token: 'secret',
            },
        });
        const metadata = serialize(model).metadata;
        for (const key of [
            'sla', 'source_draft_place', 'seed_published_place', 'create_transition', 'plain_language_site',
            'translation_source_site', 'plain_language_completed_place', 'plain_language_failed_place',
            'translation_source_pick', 'progress_orientation', 'default_assignee_group', 'deepl_key', 'cortex_token',
        ]) {
            expect(metadata, key).not.toHaveProperty(key);
        }
        expect(metadata.translation_source_view).toEqual([{ mode: 'collapsed', by: 'user', handles: ['7'] }]);
        expect(metadata.post_creator_groups).toEqual([]);
        expect(metadata.assigner_groups).toEqual([]);

        model.metadata.progress_orientation = 'vertical';
        expect(serialize(model).metadata.progress_orientation).toBe('vertical');
    });

    it('sends revisions only as a real choice', () => {
        expect(serialize(normalize({ metadata: {} })).metadata).not.toHaveProperty('revisions');
        expect(serialize(normalize({ metadata: { revisions: false } })).metadata.revisions).toBe(false);
        expect(serialize(normalize({ metadata: { revisions: true } })).metadata.revisions).toBe(true);
    });

    it('writes revision publishers only when switched on', () => {
        expect(serialize(normalize({ metadata: {} })).metadata).not.toHaveProperty('revision_publishers');
        const model = normalize({ metadata: { revision_publishers: [
            { by: 'group', handles: ['redaktion'] },
            { by: 'user', handles: ['7', '8'] },
            { by: 'role', handles: [] },
        ] } });
        expect(serialize(model).metadata.revision_publishers).toEqual([
            { by: 'group', handles: ['redaktion'] },
            { by: 'user', handles: ['7', '8'] },
        ]);

        model.metadata.revision_publishers = [{ by: 'role', handles: [] }];
        expect(serialize(model).metadata).not.toHaveProperty('revision_publishers');
    });

    it('writes the edit lock only when on, its exemptions only with it', () => {
        expect(serialize(normalize({ metadata: {} })).metadata).not.toHaveProperty('assignees_only_edit');

        const on = normalize({ metadata: { assignees_only_edit: true, edit_exempt: [{ by: 'group', handles: ['chief'] }] } });
        expect(serialize(on).metadata).toMatchObject({ assignees_only_edit: true, edit_exempt: [{ by: 'group', handles: ['chief'] }] });

        on.metadata.assignees_only_edit = false;
        expect(serialize(on).metadata).not.toHaveProperty('assignees_only_edit');
        expect(serialize(on).metadata).not.toHaveProperty('edit_exempt');
    });

    it('writes comment resolvers and deleters only when they name someone', () => {
        const empty = serialize(normalize({ metadata: {} })).metadata;
        expect(empty).not.toHaveProperty('comment_resolvers');
        expect(empty).not.toHaveProperty('comment_deleters');

        const model = normalize({ metadata: {
            comment_resolvers: [{ by: 'group', handles: ['redaktion'] }, { by: 'user', handles: [] }],
            comment_deleters: [{ by: 'role', handles: ['chief'] }],
        } });
        expect(serialize(model).metadata.comment_resolvers).toEqual([{ by: 'group', handles: ['redaktion'] }]);
        expect(serialize(model).metadata.comment_deleters).toEqual([{ by: 'role', handles: ['chief'] }]);
    });

    it('writes the four-eyes guard only when switched on, the hint only when given', () => {
        expect(serialize(normalize({ metadata: {} })).metadata).not.toHaveProperty('four_eyes');

        const model = normalize({ metadata: { four_eyes: { by: 'group', handles: ['leads'], hint: '  ' } } });
        expect(serialize(model).metadata.four_eyes).toEqual({ by: 'group', handles: ['leads'] });

        model.metadata.four_eyes.hint = ' Ask a lead. ';
        expect(serialize(model).metadata.four_eyes).toEqual({ by: 'group', handles: ['leads'], hint: 'Ask a lead.' });

        model.metadata.four_eyes.by = null;
        expect(serialize(model).metadata).not.toHaveProperty('four_eyes');
    });

    it('keeps plain-language skip fields trimmed and unique, and drops an empty list', () => {
        expect(serialize(normalize({ metadata: {} })).metadata).not.toHaveProperty('plain_language_skip_fields');
        const model = normalize({ metadata: { plain_language_skip_fields: [' *.embed_code ', 'anchor.anchor_id', '*.embed_code', ''] } });
        expect(serialize(model).metadata.plain_language_skip_fields).toEqual(['*.embed_code', 'anchor.anchor_id']);
    });

    it('drops empty colour/variant and an unset branch flag', () => {
        const model = normalize({
            places: { a: { metadata: { color: null, branch: false } }, b: { metadata: { color: 'teal', branch: true } } },
            transitions: { go: { from: 'a', to: 'b', metadata: { variant: '' } } },
        });
        const payload = serialize(model);
        expect(payload.places.a.metadata).toEqual({});
        expect(payload.places.b.metadata).toEqual({ color: 'teal', branch: true });
        expect(payload.transitions.go.metadata).toEqual({});
    });

    it('keeps only declared values of project actions', () => {
        const customActions = {
            transition: [{ value: 'notify_slack', fields: [{ handle: 'channel' }, { handle: 'tags' }] }],
            place: [],
        };
        const model = normalize({
            places: { a: { metadata: { actions: [{ type: 'unknown_place_type', values: { x: 1 } }] } } },
            transitions: {
                go: {
                    from: 'a', to: 'a',
                    metadata: { actions: [{ type: 'notify_slack', values: { channel: '#news', tags: [], stale: 'x' } }] },
                },
            },
        });
        const payload = serialize(model, { customActions });
        expect(payload.transitions.go.metadata.actions).toEqual([{ type: 'notify_slack', values: { channel: '#news' } }]);
        expect(payload.places.a.metadata.actions).toEqual([{ type: 'unknown_place_type' }]);
    });
});
