import { describe, expect, it } from 'vitest';
import { normalize } from '../normalize.js';
import {
    clearPlaceRefs,
    clearTransitionRefs,
    placeReferences,
    REFERENCES,
    renamePlace,
    renameTransition,
    replaceReferences,
    transitionReferences,
} from '../refs.js';
import {
    actionTypeOptionsFor,
    autoTransitionOptions,
    canResubmitPlainLanguage,
    explicitStartTransition,
    placeActionTypeOptionsFor,
    startingTransition,
} from '../actionRules.js';
import { defaultVariant, ownPlaceLabels, placeDisplayName, placeLabelFallback } from '../labels.js';
import { danglingHandles, finalizeNewHandles, slugify } from '../serialize.js';

function model() {
    return normalize({
        initial_place: 'draft',
        metadata: {
            detail: 'translation',
            plain_language_site: 'plain',
            sla: { needs_update_days: 10, needs_update_place: 'draft', outdated_days: 20, outdated_place: 'review' },
            seed_published_place: 'review',
            source_draft_place: 'draft',
            plain_language_completed_place: 'review',
            plain_language_failed_place: 'draft',
            create_transition: 'submit',
        },
        places: {
            draft: { label: { de: 'Entwurf' }, metadata: { actions: [{ type: 'auto_transition', after_days: 3, transition: 'submit' }] } },
            review: { metadata: {} },
        },
        transitions: {
            submit: { from: 'draft', to: 'review', metadata: { label: 'Submit' } },
            back: { from: ['review', 'draft'], to: 'draft', metadata: { label: 'Back', actions: [{ type: 'start_localization' }] } },
            sys: { from: 'draft', to: 'review', metadata: { system: true } },
        },
    });
}

describe('refs registry', () => {
    it('lists every place and transition role with an i18n label', () => {
        const roles = REFERENCES.map((r) => r.role);
        expect(roles).toEqual(expect.arrayContaining([
            'initial_place', 'transition_from', 'transition_to', 'sla_needs_update_place', 'sla_outdated_place',
            'seed_published_place', 'source_draft_place', 'plain_language_completed_place',
            'plain_language_failed_place', 'create_transition', 'auto_transition',
        ]));
        for (const ref of REFERENCES) expect(ref.label).toMatch(/^workflows::messages\./);
    });

    it('finds what references a place', () => {
        const roles = placeReferences(model(), 'draft').map((r) => r.role).sort();
        expect(roles).toEqual([
            'initial_place', 'plain_language_failed_place', 'sla_needs_update_place', 'source_draft_place',
            'transition_from', 'transition_from', 'transition_from', 'transition_to',
        ].sort());
    });

    it('finds what references a transition', () => {
        expect(transitionReferences(model(), 'submit')).toEqual([
            { role: 'create_transition', label: 'workflows::messages.create_transition', owner: { kind: 'workflow' } },
            { role: 'auto_transition', label: 'workflows::messages.action_auto_transition_transition_label', owner: { kind: 'place', handle: 'draft', index: 0 } },
        ]);
    });

    it('renames a place everywhere, multi-source `from` included, and moves the key to the end', () => {
        const m = model();
        renamePlace(m, 'draft', 'idea');
        expect(Object.keys(m.places)).toEqual(['review', 'idea']);
        expect(m.initial_place).toBe('idea');
        expect(m.metadata.sla.needs_update_place).toBe('idea');
        expect(m.metadata.source_draft_place).toBe('idea');
        expect(m.metadata.plain_language_failed_place).toBe('idea');
        expect(m.transitions.submit.from).toBe('idea');
        expect(m.transitions.back.from).toEqual(['review', 'idea']);
        expect(m.transitions.back.to).toBe('idea');
        expect(placeReferences(m, 'draft')).toEqual([]);
    });

    it('clears a place: scalars become null, list entries are removed', () => {
        const m = model();
        clearPlaceRefs(m, 'review');
        expect(m.transitions.back.from).toEqual(['draft']);
        expect(m.transitions.submit.to).toBeNull();
        expect(m.metadata.seed_published_place).toBeNull();
        expect(m.metadata.sla.outdated_place).toBeNull();
    });

    it('limits a replacement to some roles', () => {
        const m = model();
        replaceReferences(m, 'place', 'draft', 'review', { roles: ['transition_to', 'initial_place'] });
        expect(m.initial_place).toBe('review');
        expect(m.transitions.back.to).toBe('review');
        expect(m.transitions.submit.from).toBe('draft');
        expect(m.metadata.source_draft_place).toBe('draft');
    });

    it('renames and clears transition references', () => {
        const m = model();
        renameTransition(m, 'submit', 'send');
        expect(Object.keys(m.transitions)).toEqual(['back', 'sys', 'send']);
        expect(m.metadata.create_transition).toBe('send');
        expect(m.places.draft.metadata.actions[0].transition).toBe('send');

        clearTransitionRefs(m, 'send');
        expect(m.metadata.create_transition).toBeNull();
        expect(m.places.draft.metadata.actions[0].transition).toBeNull();
    });
});

describe('new-row handles', () => {
    it('slugifies labels', () => {
        expect(slugify('  Größe & Übersicht ')).toBe('groesse_uebersicht');
        expect(slugify('Café — Straße')).toBe('cafe_strasse');
        // Decomposed input (u + combining diaeresis) spells out the same way.
        expect(slugify('Pru\u0308fung')).toBe('pruefung');
        expect(slugify('Ready for review!')).toBe('ready_for_review');
    });

    it('finalizes tmp handles from labels and reports what stays dangling', () => {
        const m = model();
        m.places.place_1 = { labels: { de: '', en: 'Final check' }, legacyLabel: '', metadata: { branch: false } };
        m.places.place_2 = { labels: { de: '', en: '' }, legacyLabel: '', metadata: { branch: false } };
        m.transitions.transition_3 = { from: 'place_1', to: 'place_1', metadata: { label: 'Check it' } };
        m.metadata.sla.outdated_place = 'place_1';
        const pending = { places: { place_1: '', place_2: '' }, transitions: { transition_3: '' } };

        finalizeNewHandles(m, pending, { reservedPlaces: [], reservedTransitions: [] });

        expect(m.places).toHaveProperty('final_check');
        expect(m.transitions).toHaveProperty('check_it');
        expect(m.transitions.check_it.from).toBe('final_check');
        expect(m.metadata.sla.outdated_place).toBe('final_check');
        expect(danglingHandles(pending)).toEqual(['place_2']);
    });

    it('refuses a taken or reserved handle', () => {
        const m = model();
        m.places.place_1 = { labels: {}, legacyLabel: '', metadata: {} };
        const pending = { places: { place_1: 'review' }, transitions: {} };
        finalizeNewHandles(m, pending);
        expect(pending.places).toEqual({ place_1: 'place_1' });

        pending.places.place_1 = 'pending_revision';
        finalizeNewHandles(m, pending, { reservedPlaces: ['pending_revision'] });
        expect(m.places).toHaveProperty('place_1');
    });
});

describe('action rules', () => {
    it('derives the start transitions', () => {
        const m = model();
        expect(startingTransition(m)).toBe('submit');
        expect(explicitStartTransition(m)).toBe('back');
        expect(canResubmitPlainLanguage(m, 'back')).toBe(false);
        expect(canResubmitPlainLanguage(m, 'submit')).toBe(true);
    });

    it('filters the transition action types', () => {
        const m = model();
        const values = (handle, current = null) => actionTypeOptionsFor(m, handle, current).map((o) => o.value);
        // start_localization is taken by `back`; everything else is free on `submit`.
        expect(values('submit')).toEqual(['user_assignment', 'target_missing', 'plain_language_resubmit', 'mail']);
        // `back` may keep its own start_localization but not resubmit (it IS the start).
        expect(values('back', m.transitions.back.metadata.actions[0])).toEqual(['user_assignment', 'target_missing', 'start_localization', 'mail']);

        m.transitions.submit.metadata.actions.push({ type: 'user_assignment' }, { type: 'mail' });
        expect(values('submit')).toEqual(['target_missing', 'plain_language_resubmit']);

        m.metadata.detail = null;
        expect(values('back', m.transitions.back.metadata.actions[0])).not.toContain('start_localization');
    });

    it('uses the caller\'s labelled options', () => {
        const options = [{ value: 'mail', label: 'Mail' }, { value: 'custom', label: 'Custom' }];
        expect(actionTypeOptionsFor(model(), 'submit', null, { actionTypeOptions: options })).toEqual(options);
    });

    it('filters the place action types', () => {
        const m = model();
        const values = (place) => placeActionTypeOptionsFor(m, place, null).map((o) => o.value);
        expect(values('draft')).toEqual(['pending_rewrite_spinner', 'refresh_interval', 'mail', 'reminder']);
        expect(values(m.places.review)).toContain('auto_transition');
        m.metadata.plain_language_site = null;
        expect(values('review')).not.toContain('pending_rewrite_spinner');
    });

    it('offers auto transitions leaving the place, minus system ones', () => {
        expect(autoTransitionOptions(model(), 'draft')).toEqual([
            { value: 'submit', label: 'Submit (submit)' },
            { value: 'back', label: 'Back (back)' },
        ]);
    });
});

describe('labels', () => {
    const ctx = {
        labelLocales: ['de', 'en'],
        labelLocale: 'en',
        shippedPlaceLabels: { review: { en: 'Review (shipped)' } },
        humanize: (h) => `H:${h}`,
    };

    it('resolves names in the loader\'s order', () => {
        const m = model();
        expect(ownPlaceLabels(m, 'draft', ctx)).toEqual(['Entwurf']);
        expect(placeLabelFallback(m, 'draft', 'en', ctx)).toBe('Entwurf');
        expect(placeDisplayName(m, 'review', ctx)).toBe('Review (shipped)');
        m.places.x = { labels: { de: '', en: '' }, legacyLabel: 'Legacy', metadata: {} };
        expect(placeDisplayName(m, 'x', ctx)).toBe('Legacy');
        m.places.y = { labels: {}, legacyLabel: '', metadata: {} };
        expect(placeDisplayName(m, 'y', ctx)).toBe('H:y');
        m.places.y.labels.en = ' Why ';
        expect(placeDisplayName(m, 'y', ctx)).toBe('Why');
    });

    it('knows the default button variant', () => {
        expect(defaultVariant('reject')).toBe('danger');
        expect(defaultVariant('submit')).toBe('primary');
    });
});
