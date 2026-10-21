import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { parse } from 'yaml';
import { addPlace, addTransition, fromDefinition, removePlace, setHandle, updatePlace } from '../editorModel.js';
import { validate } from '../rules.js';
import {
    displayPath,
    handleMaps,
    mapServerPath,
    mapServerProblem,
    mapServerResult,
    mergeProblems,
    pathsRelated,
    problemsAt,
    remapProblems,
    sectionForPath,
    tabForPath,
} from '../validationPaths.js';

// A message that shows key and replacements — enough to tell rules apart.
const t = (key, replace = {}) => `${key}${Object.keys(replace).length ? ` ${JSON.stringify(replace)}` : ''}`;
const ctx = { t, reservedPlaces: ['pending_revision'], reservedTransitions: [] };

const loadExample = (name) => parse(readFileSync(fileURLToPath(new URL(`../../../../workflows/examples/${name}.yaml`, import.meta.url)), 'utf8'));

// A small, valid entry workflow: draft → review → published, plus a reject.
function definition(patch = {}) {
    return {
        type: 'state_machine',
        initial_place: 'draft',
        metadata: { create_transition: 'submit', ...(patch.metadata ?? {}) },
        places: {
            draft: { metadata: {} },
            review: { metadata: {} },
            published: { metadata: { allow_publish: true } },
            ...(patch.places ?? {}),
        },
        transitions: {
            submit: { from: 'draft', to: 'review', metadata: { label: 'Submit' } },
            publish: { from: 'review', to: 'published', metadata: { label: 'Publish' } },
            reject: { from: 'review', to: 'draft', metadata: { label: 'Reject' } },
            ...(patch.transitions ?? {}),
        },
    };
}

const model = (patch) => fromDefinition(definition(patch));
const codes = (problems, level = null) => problems.filter((p) => !level || p.level === level).map((p) => p.code);
const find = (problems, code) => problems.find((p) => p.code === code);

describe('rules.js', () => {
    it('finds nothing in a sound workflow', () => {
        expect(validate(model(), ctx)).toEqual([]);
    });

    it.each(['postmanager', 'translation', 'plainlanguage'])('reports no errors for the shipped example %s', (name) => {
        expect(codes(validate(fromDefinition(loadExample(name)), ctx), 'error')).toEqual([]);
    });

    it('names its message from the writer\'s lang key with the payload keys', () => {
        const m = model({ places: { draft: { metadata: { branch: true } } } });
        const problem = find(validate(m, ctx), 'place_branch_initial');
        expect(problem).toMatchObject({ level: 'error', path: 'place:p:draft.branch', nodeId: 'p:draft' });
        expect(problem.message).toBe('writer_place_branch_initial {"handle":"draft"}');
    });

    describe('handles of new nodes', () => {
        it('missing, invalid, taken, reserved', () => {
            const m = model();
            const a = addPlace(m, { labelLocales: ['de'] });
            expect(find(validate(m, ctx), 'handle_missing')).toMatchObject({ path: `place:${a}.handle`, nodeId: a });
            setHandle(m, a, 'Bad Handle');
            expect(codes(validate(m, ctx))).toContain('handle_invalid');
            setHandle(m, a, 'review');
            expect(codes(validate(m, ctx))).toContain('handle_taken');
            setHandle(m, a, 'pending_revision');
            expect(codes(validate(m, ctx))).toContain('handle_reserved');
            const tid = addTransition(m, { from: 'p:review', to: 'p:draft' });
            setHandle(m, tid, 'submit');
            expect(find(validate(m, ctx), 'handle_taken')).toBeTruthy();
            expect(validate(m, ctx).some((p) => p.path === `transition:${tid}.handle`)).toBe(true);
        });
    });

    describe('transitions', () => {
        it('without from / without to', () => {
            const m = model();
            const id = addTransition(m, {});
            setHandle(m, id, 'loose');
            const problems = validate(m, ctx);
            expect(find(problems, 'transition_no_from')).toMatchObject({ path: `transition:${id}.from`, nodeId: id });
            expect(find(problems, 'transition_to_unknown')).toMatchObject({ path: `transition:${id}.to`, message: 'writer_transition_to_unknown {"handle":"loose","place":""}' });
        });

        it('a gate without handles', () => {
            const m = model();
            m.transitions[1].metadata.required_by = 'group';
            m.transitions[1].metadata.required_handles = [];
            expect(find(validate(m, ctx), 'required_handles_missing')).toMatchObject({ path: 'transition:t:publish.required_handles' });
            m.transitions[1].metadata.required_handles = ['editors'];
            expect(codes(validate(m, ctx))).not.toContain('required_handles_missing');
        });

        it('more than one dialog, assignment, mail, resubmit', () => {
            const at = (actions) => {
                const m = model();
                m.transitions[1].metadata.actions = actions;
                return validate(m, ctx);
            };
            expect(codes(at([{ type: 'user_assignment', mode: 'manual', criteria: [] }, { type: 'user_assignment', mode: 'manual', criteria: [] }])))
                .toEqual(['transition_multiple_dialogs']);
            const automatic = { type: 'user_assignment', mode: 'automatic', criteria: [{ field: 'group', operator: 'is', value: 'x' }] };
            expect(codes(at([automatic, { ...automatic }]))).toEqual(['transition_multiple_assignments']);
            expect(find(at([{ type: 'mail' }, { type: 'mail' }]), 'transition_multiple_mails').path).toBe('transition:t:publish.actions');
            const resubmits = at([{ type: 'plain_language_resubmit' }, { type: 'plain_language_resubmit' }]);
            expect(codes(resubmits)).toContain('transition_multiple_resubmits');
        });

        it('an automatic assignment without (usable) criteria', () => {
            const m = model();
            m.transitions[1].metadata.actions = [{ type: 'user_assignment', mode: 'automatic', criteria: [{ field: 'group', operator: 'is', value: '' }] }];
            expect(find(validate(m, ctx), 'assignment_automatic_without_criteria')).toMatchObject({ path: 'transition:t:publish.actions.0' });
        });

        it('target_missing without a site', () => {
            const m = model();
            m.transitions[1].metadata.actions = [{ type: 'target_missing', site: null }];
            expect(find(validate(m, ctx), 'transition_target_missing_site_unknown').message).toContain('"site":"NULL"');
        });
    });

    describe('localization', () => {
        const localization = (patch = {}) => model({ metadata: { detail: 'translation', create_transition: null, ...patch } });

        it('needs exactly one start_localization', () => {
            const m = localization();
            expect(find(validate(m, ctx), 'start_localization_required')).toMatchObject({ path: 'transitions', nodeId: null });
            m.transitions[0].metadata.actions = [{ type: 'start_localization' }];
            m.transitions[1].metadata.actions = [{ type: 'start_localization' }];
            const twice = validate(m, ctx).filter((p) => p.code === 'start_localization_once');
            expect(twice.map((p) => p.nodeId)).toEqual(['t:submit', 't:publish']);
        });

        it('starts in the initial place', () => {
            const m = localization();
            m.transitions[1].metadata.actions = [{ type: 'start_localization' }];
            expect(find(validate(m, ctx), 'start_localization_from_initial')).toMatchObject({ path: 'transition:t:publish.from' });
            m.transitions[1].metadata.actions = [];
            m.transitions[0].metadata.actions = [{ type: 'start_localization' }];
            expect(codes(validate(m, ctx))).not.toContain('start_localization_from_initial');
        });

        it('start_localization outside a localization workflow', () => {
            const m = model();
            m.transitions[0].metadata.actions = [{ type: 'start_localization' }];
            expect(codes(validate(m, ctx))).toContain('transition_start_localization_needs_detail');
        });

        it('resubmit outside the plain-language manager, and on the start transition', () => {
            const m = model();
            m.transitions[2].metadata.actions = [{ type: 'plain_language_resubmit' }];
            expect(find(validate(m, ctx), 'transition_resubmit_action_needs_site')).toMatchObject({ path: 'transition:t:reject.actions.0' });

            const pl = localization({ plain_language_site: 'plain' });
            pl.transitions[0].metadata.actions = [{ type: 'start_localization' }, { type: 'plain_language_resubmit' }];
            expect(find(validate(pl, ctx), 'resubmit_on_start')).toMatchObject({ path: 'transition:t:submit.actions.1' });
        });

        it('the spinner outside the plain-language manager', () => {
            const m = model();
            m.places[1].metadata.actions = [{ type: 'pending_rewrite_spinner' }];
            expect(find(validate(m, ctx), 'place_spinner_action_needs_site')).toMatchObject({ path: 'place:p:review.actions.0' });
        });

        it('plain-language result places without plain_language_site', () => {
            const m = localization({ plain_language_completed_place: 'review', plain_language_failed_place: 'draft' });
            m.transitions[0].metadata.actions = [{ type: 'start_localization' }];
            const problems = validate(m, ctx);
            expect(find(problems, 'completed_place_needs_site').path).toBe('metadata.plain_language_completed_place');
            expect(find(problems, 'failed_place_needs_site').path).toBe('metadata.plain_language_failed_place');
        });

        it('plain-language skip fields: shape, and only with plain_language_site', () => {
            const m = localization({ plain_language_skip_fields: ['anchor.anchor_id'] });
            m.transitions[0].metadata.actions = [{ type: 'start_localization' }];
            expect(find(validate(m, ctx), 'skip_fields_needs_site').path).toBe('metadata.plain_language_skip_fields');

            const bad = localization({ plain_language_site: 'plain', plain_language_skip_fields: ['a.b.c'] });
            bad.transitions[0].metadata.actions = [{ type: 'start_localization' }];
            expect(find(validate(bad, ctx), 'skip_fields_not_list').path).toBe('metadata.plain_language_skip_fields');

            const ok = localization({ plain_language_site: 'plain', plain_language_skip_fields: ['*.embed_code', 'intro'] });
            ok.transitions[0].metadata.actions = [{ type: 'start_localization' }];
            expect(find(validate(ok, ctx), 'skip_fields_not_list')).toBeUndefined();
        });

        it('a source-draft place that publishes', () => {
            const m = localization({ source_draft_place: 'published' });
            m.transitions[0].metadata.actions = [{ type: 'start_localization' }];
            expect(find(validate(m, ctx), 'source_draft_place_publishes')).toMatchObject({ path: 'metadata.source_draft_place' });
        });
    });

    describe('places', () => {
        it('more than one action of a type', () => {
            const m = model();
            m.places[1].metadata.actions = [
                { type: 'refresh_interval', minutes: 5 },
                { type: 'refresh_interval', minutes: 5 },
                { type: 'mail', mail_type: 'assigned' },
                { type: 'mail', mail_type: 'assigned' },
                { type: 'reminder', every_days: 3, mail_type: 'reminder' },
                { type: 'reminder', every_days: 3, mail_type: 'reminder' },
            ];
            const problems = validate(m, ctx).filter((p) => p.path === 'place:p:review.actions');
            expect(problems.map((p) => p.code)).toEqual(['place_multiple_reminders', 'place_multiple_actions', 'place_multiple_mails']);
        });

        it('a reminder without interval or mail type', () => {
            const m = model();
            m.places[1].metadata.actions = [{ type: 'reminder', every_days: null, mail_type: 'reminder' }];
            expect(find(validate(m, ctx), 'place_reminder_invalid').message).toContain('"value":"NULL"');
            m.places[1].metadata.actions = [{ type: 'reminder', every_days: 7, mail_type: null }];
            expect(codes(validate(m, ctx))).toContain('place_reminder_mail_type_missing');
        });

        it('an auto_transition that does not leave the place, or is a system step', () => {
            const m = model();
            m.places[1].metadata.actions = [{ type: 'auto_transition', after_days: 3, transition: 't:submit' }];
            expect(find(validate(m, ctx), 'place_auto_transition_invalid').message).toContain('"transition":"submit"');
            m.places[1].metadata.actions = [{ type: 'auto_transition', after_days: 3, transition: 't:publish' }];
            expect(codes(validate(m, ctx))).not.toContain('place_auto_transition_invalid');
            m.transitions[1].metadata.system = true;
            expect(codes(validate(m, ctx))).toContain('place_auto_transition_invalid');
            m.places[1].metadata.actions = [{ type: 'auto_transition', after_days: 0, transition: 't:publish' }];
            expect(codes(validate(m, ctx))).toContain('place_auto_transition_days_invalid');
        });
    });

    describe('workflow-level', () => {
        it('initial_place missing', () => {
            const m = model();
            m.workflow.initial_place = null;
            expect(find(validate(m, ctx), 'initial_place_unknown')).toMatchObject({ path: 'initial_place', nodeId: null });
        });

        it('create_transition that does not leave the start place', () => {
            const m = model({ metadata: { create_transition: 'publish' } });
            expect(find(validate(m, ctx), 'create_transition_not_from_initial').message)
                .toBe('writer_create_transition_not_from_initial {"transition":"publish","place":"draft"}');
        });

        it('SLA: half a stage, an assignment without anyone', () => {
            const m = model({ metadata: { sla: { needs_update_days: 10 } } });
            expect(find(validate(m, ctx), 'sla_stage_incomplete')).toMatchObject({ path: 'metadata.sla.needs_update_place' });
            m.workflow.metadata.sla.needs_update_place = 'p:review';
            m.workflow.metadata.sla.needs_update_assign = { by: 'group', handles: [] };
            expect(find(validate(m, ctx), 'sla_assign_invalid')).toMatchObject({ path: 'metadata.sla.needs_update_assign' });
            m.workflow.metadata.sla.outdated_place = 'p:review';
            expect(find(validate(m, ctx), 'sla_stage_incomplete')).toMatchObject({ path: 'metadata.sla.outdated_days' });
        });

        it('a deleted place with entries but no target', () => {
            const m = model();
            removePlace(m, 'p:review', { usage: 4 });
            expect(find(validate(m, ctx), 'removed_place_needs_target')).toMatchObject({
                path: 'place:p:review.migrate_to',
                nodeId: 'p:review',
                message: 'removed_place_needs_target {"place":"review","count":4}',
            });
            m.deletions[0].migrateTo = 'p:draft';
            expect(codes(validate(m, ctx))).not.toContain('removed_place_needs_target');
        });
    });

    describe('warnings', () => {
        it('an unreachable place and one without any edge', () => {
            const m = model({ places: { lonely: { metadata: {} } } });
            const problems = validate(m, ctx).filter((p) => p.nodeId === 'p:lonely');
            expect(problems.map((p) => [p.level, p.code])).toEqual([['warning', 'place_isolated'], ['warning', 'place_unreachable']]);
        });

        it('a place the addon moves into counts as reachable', () => {
            const m = model({ places: { stale: { metadata: {} } }, transitions: { refresh: { from: 'stale', to: 'review', metadata: {} } }, metadata: { sla: { needs_update_days: 5, needs_update_place: 'stale' } } });
            expect(codes(validate(m, ctx))).not.toContain('place_unreachable');
        });

        it('no publish place and no seed place', () => {
            const m = model({ places: { published: { metadata: {} } } });
            expect(find(validate(m, ctx), 'no_publish_place')).toMatchObject({ level: 'warning', path: 'metadata.seed_published_place' });
            m.workflow.metadata.seed_published_place = 'p:published';
            expect(codes(validate(m, ctx))).not.toContain('no_publish_place');
        });
    });
});

describe('validationPaths.js', () => {
    it('maps server paths by handle onto ids, incl. new nodes', () => {
        const m = model();
        const fresh = addPlace(m, { labelLocales: ['de'] });
        updatePlace(m, fresh, { labels: { de: 'Lektorat' } }, { labelLocales: ['de'] });
        const unnamed = addTransition(m, {});
        removePlace(m, 'p:review', { usage: 2 });
        const maps = handleMaps(m);

        expect(mapServerPath('places.draft.metadata.actions.0', maps)).toEqual({ path: 'place:p:draft.actions.0', nodeId: 'p:draft' });
        expect(mapServerPath('places.lektorat', maps)).toEqual({ path: `place:${fresh}`, nodeId: fresh });
        expect(mapServerPath(`transitions.${unnamed}.metadata.actions`, maps)).toEqual({ path: `transition:${unnamed}.actions`, nodeId: unnamed });
        expect(mapServerPath('transitions.submit', maps)).toEqual({ path: 'transition:t:submit', nodeId: 't:submit' });
        expect(mapServerPath('removed_places.review', maps)).toEqual({ path: 'place:p:review.migrate_to', nodeId: 'p:review' });
        expect(mapServerPath('metadata.sla', maps)).toEqual({ path: 'metadata.sla', nodeId: null });
        expect(mapServerPath('collections.1', maps)).toEqual({ path: 'collections.1', nodeId: null });
        expect(mapServerPath('places.gone', maps)).toEqual({ path: 'places.gone', nodeId: null });
    });

    it('maps a whole answer, removals included', () => {
        const m = model();
        removePlace(m, 'p:review', { usage: 2 });
        const problems = mapServerResult({
            errors: [{ path: 'transitions.publish', code: 'x', message: 'E', node: { kind: 'transition', handle: 'publish' } }],
            warnings: [{ path: 'places.draft', code: 'y', message: 'W', node: { kind: 'place', handle: 'draft' } }],
            removals: [{ place: 'review', error: null, target_error: 'T' }, { place: 'x', error: null, target_error: null }],
        }, handleMaps(m));
        expect(problems.map((p) => [p.level, p.path, p.nodeId, p.message])).toEqual([
            ['error', 'transition:t:publish', 't:publish', 'E'],
            ['error', 'place:p:review.migrate_to', 'p:review', 'T'],
            ['warning', 'place:p:draft', 'p:draft', 'W'],
        ]);
    });

    it('falls back to the node of an entry', () => {
        const maps = handleMaps(model());
        expect(mapServerProblem({ path: 'workflow', message: 'M', node: { kind: 'place', handle: 'draft' } }, 'error', maps).nodeId).toBe('p:draft');
    });

    it('relates paths: equal, nested, plural', () => {
        expect(pathsRelated('metadata.sla', 'metadata.sla.outdated_days')).toBe(true);
        expect(pathsRelated('place:p:a', 'place:p:a.branch')).toBe(true);
        expect(pathsRelated('transitions', 'transition:t:a.actions.0')).toBe(true);
        expect(pathsRelated('place:p:a', 'place:p:ab')).toBe(false);
        expect(pathsRelated('metadata.sla', 'metadata.slack')).toBe(false);
    });

    it('merges: the client wins on the same message nearby, the server adds the rest', () => {
        const client = [
            { level: 'warning', path: 'place:p:a', nodeId: 'p:a', message: 'unreachable' },
            { level: 'error', path: 'metadata.sla.needs_update_place', nodeId: null, message: 'sla' },
        ];
        const server = [
            { level: 'error', path: 'metadata.sla', nodeId: null, message: 'sla', source: 'server' },
            { level: 'warning', path: 'place:p:a', nodeId: 'p:a', message: 'unreachable', source: 'server' },
            { level: 'error', path: 'collections.0', nodeId: null, message: 'unknown collection', source: 'server' },
            { level: 'error', path: 'collections.0', nodeId: null, message: 'unknown collection', source: 'server' },
            { level: 'error', path: 'metadata.other', nodeId: null, message: 'sla', source: 'server' },
        ];
        const merged = mergeProblems(client, server);
        expect(merged.map((p) => [p.level, p.path, p.message])).toEqual([
            ['error', 'metadata.sla.needs_update_place', 'sla'],
            ['error', 'collections.0', 'unknown collection'],
            ['error', 'metadata.other', 'sla'],
            ['warning', 'place:p:a', 'unreachable'],
        ]);
    });

    it('finds problems at a path, remaps ids, names tabs and sections', () => {
        const problems = [
            { path: 'place:p~1.actions.1', nodeId: 'p~1' },
            { path: 'place:p~1.actions.10', nodeId: 'p~1' },
            { path: 'place:p~1', nodeId: 'p~1' },
        ];
        expect(problemsAt(problems, 'place:p~1.actions.1')).toHaveLength(1);
        expect(problemsAt(problems, 'place:p~1')).toHaveLength(3);
        expect(remapProblems(problems, { 'p~1': 'p:lektorat' }).map((p) => [p.path, p.nodeId])).toEqual([
            ['place:p:lektorat.actions.1', 'p:lektorat'],
            ['place:p:lektorat.actions.10', 'p:lektorat'],
            ['place:p:lektorat', 'p:lektorat'],
        ]);
        expect(tabForPath('transition:t:a.required_handles')).toBe('permission');
        expect(tabForPath('place:p:a.actions.0')).toBe('actions');
        expect(tabForPath('place:p:a.branch')).toBe('general');
        expect(sectionForPath('metadata.sla.outdated_days')).toBe('sla');
        expect(sectionForPath('metadata.create_transition')).toBe('permissions');
        expect(sectionForPath('collections.2')).toBe('collections');
        expect(sectionForPath('initial_place')).toBe('general');
        expect(sectionForPath('place:p:a')).toBe(null);
    });

    it('shows paths by handle', () => {
        const m = model();
        const id = addPlace(m, { labelLocales: ['de'] });
        expect(displayPath('place:p:draft.actions.0', m)).toBe('places.draft.actions.0');
        expect(displayPath(`place:${id}.handle`, m)).toBe(`places.${id}.handle`);
        expect(displayPath('metadata.sla', m)).toBe('metadata.sla');
    });
});
