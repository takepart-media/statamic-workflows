import { describe, expect, it } from 'vitest';
import { fromDefinition, toPayload } from '../editorModel.js';
import { validate } from '../rules.js';
import { serializeMetadata } from '../serialize.js';
import { sectionForPath } from '../validationPaths.js';
import { autoContentFields, contentFieldChoices, contentFieldSelectOptions } from '../contentFields.js';

const t = (key, replace = {}) => `${key}${Object.keys(replace).length ? ` ${JSON.stringify(replace)}` : ''}`;

// What editSettings() sends as contentFieldOptions / contentFieldAuto.
const options = {
    pages: [
        { handle: 'title', label: 'Titel', type: 'text', supported: true, blueprints: ['page'] },
        { handle: 'sections', label: 'Abschnitte', type: 'replicator', supported: true, blueprints: ['page'] },
        { handle: 'published_at', label: 'Datum', type: 'date', supported: false, blueprints: ['page'] },
    ],
    articles: [
        { handle: 'intro', label: 'Intro', type: 'textarea', supported: true, blueprints: ['article'] },
        { handle: 'sections', label: 'Abschnitte', type: 'replicator', supported: true, blueprints: ['article'] },
    ],
    empty: [],
};
const auto = { pages: 'sections', articles: 'sections', empty: null };
const ctx = { t, reservedPlaces: [], reservedTransitions: [], contentFieldOptions: options };

function definition(metadata = {}, collections = ['pages', 'articles']) {
    return {
        type: 'state_machine',
        initial_place: 'draft',
        collections,
        metadata,
        places: { draft: { metadata: {} }, published: { metadata: { allow_publish: true } } },
        transitions: {
            publish: { from: 'draft', to: 'published', metadata: { label: 'Publish' } },
            unpublish: { from: 'published', to: 'draft', metadata: { label: 'Unpublish' } },
        },
    };
}

const contentProblems = (def, c = ctx) => validate(fromDefinition(def), c).filter((p) => p.path === 'metadata.content_fields');

describe('content_fields rules', () => {
    it('accepts no list, an empty list and known supported fields', () => {
        expect(contentProblems(definition())).toEqual([]);
        expect(contentProblems(definition({ content_fields: [] }))).toEqual([]);
        expect(contentProblems(definition({ content_fields: ['intro', 'sections'] }))).toEqual([]);
    });

    it('mirrors the writer: shape, duplicates, unknown, unsupported — one error', () => {
        expect(contentProblems(definition({ content_fields: 'sections' })).map((p) => p.code)).toEqual(['content_fields_not_list']);
        expect(contentProblems(definition({ content_fields: ['sections', ''] })).map((p) => p.code)).toEqual(['content_fields_not_list']);
        const dup = contentProblems(definition({ content_fields: ['sections', 'sections'] }));
        expect(dup.map((p) => p.code)).toEqual(['content_fields_duplicate']);
        expect(dup[0].message).toBe('writer_content_fields_duplicate {"field":"sections"}');
        const unknown = contentProblems(definition({ content_fields: ['body'] }));
        expect(unknown.map((p) => [p.code, p.level])).toEqual([['content_field_unknown', 'error']]);
        expect(unknown[0].message).toBe('writer_content_field_unknown {"field":"body","collections":"pages, articles"}');
        const unsupported = contentProblems(definition({ content_fields: ['published_at', 'body'] }));
        expect(unsupported.map((p) => p.code)).toEqual(['content_field_unsupported']);
        expect(unsupported[0].message).toBe('writer_content_field_unsupported {"field":"published_at","type":"date"}');
    });

    it('a field of only one selected collection is fine (the linter warns)', () => {
        expect(contentProblems(definition({ content_fields: ['intro'] }))).toEqual([]);
        expect(contentProblems(definition({ content_fields: ['title'] }))).toEqual([]);
    });

    it('checks only the shape without collections or without options for one of them', () => {
        expect(contentProblems(definition({ content_fields: ['body'] }, []))).toEqual([]);
        expect(contentProblems(definition({ content_fields: ['body'] }, ['pages', 'brand_new']))).toEqual([]);
        expect(contentProblems(definition({ content_fields: ['body'] }), { t })).toEqual([]);
        expect(contentProblems(definition({ content_fields: ['a', 'a'] }, [])).map((p) => p.code)).toEqual(['content_fields_duplicate']);
    });
});

describe('content_fields serialize', () => {
    it('drops an empty list', () => {
        expect(serializeMetadata({ content_fields: [] })).not.toHaveProperty('content_fields');
        expect(serializeMetadata({ content_fields: null })).not.toHaveProperty('content_fields');
        expect(toPayload(fromDefinition(definition({ content_fields: [] }))).definition.metadata).not.toHaveProperty('content_fields');
    });

    it('keeps the order and copies the list', () => {
        const list = ['sections', 'intro'];
        const out = serializeMetadata({ content_fields: list });
        expect(out.content_fields).toEqual(['sections', 'intro']);
        expect(out.content_fields).not.toBe(list);
        expect(toPayload(fromDefinition(definition({ content_fields: ['sections', 'intro'] }))).definition.metadata.content_fields)
            .toEqual(['sections', 'intro']);
    });

    it('passes a malformed value through for the writer to refuse', () => {
        expect(serializeMetadata({ content_fields: 'sections' }).content_fields).toBe('sections');
    });
});

describe('content_fields paths', () => {
    it('belongs to the content section', () => {
        expect(sectionForPath('metadata.content_fields')).toBe('content');
        expect(sectionForPath('metadata.content_fields.1')).toBe('content');
    });
});

describe('contentFields.js', () => {
    it('unions the supported fields and says where one is missing', () => {
        expect(contentFieldChoices(options, ['pages', 'articles'])).toEqual([
            { handle: 'title', label: 'Titel', type: 'text', collections: ['pages'], onlyIn: ['pages'] },
            { handle: 'sections', label: 'Abschnitte', type: 'replicator', collections: ['pages', 'articles'], onlyIn: [] },
            { handle: 'intro', label: 'Intro', type: 'textarea', collections: ['articles'], onlyIn: ['articles'] },
        ]);
        expect(contentFieldChoices(options, ['pages']).every((c) => c.onlyIn.length === 0)).toBe(true);
        expect(contentFieldChoices(options, [])).toEqual([]);
    });

    it('labels select options and keeps stale stored values', () => {
        const list = contentFieldSelectOptions(options, ['pages', 'articles'], ['gone', 'sections'], {
            onlyInText: (c) => `nur in ${c.join(', ')}`,
            staleText: (h) => `${h} ?`,
        });
        expect(list).toEqual([
            { value: 'title', label: 'Titel (title · text) — nur in pages' },
            { value: 'sections', label: 'Abschnitte (sections · replicator)' },
            { value: 'intro', label: 'Intro (intro · textarea) — nur in articles' },
            { value: 'gone', label: 'gone ?', stale: true },
        ]);
    });

    it('names the auto-detected field per collection', () => {
        expect(autoContentFields(options, auto, ['pages', 'empty', 'missing'])).toEqual([
            { collection: 'pages', handle: 'sections', label: 'Abschnitte' },
            { collection: 'empty', handle: null, label: null },
            { collection: 'missing', handle: null, label: null },
        ]);
    });
});
