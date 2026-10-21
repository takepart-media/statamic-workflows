import { describe, expect, it } from 'vitest';
import { buildCreatePayload, handleProblem, mapCreateErrors, picksContentFields, slugifyHandle, uniqueHandle } from '../createWorkflowForm.js';

const opts = { pattern: '^[a-z][a-z0-9_]*$', max: 20, reserved: ['create', 'install'], taken: new Set(['posts']) };

describe('slugifyHandle', () => {
    it('transliterates and joins with underscores', () => {
        expect(slugifyHandle('Übersetzung (EN)')).toBe('uebersetzung_en');
        expect(slugifyHandle('  Café — Straße  ')).toBe('cafe_strasse');
        expect(slugifyHandle('Pru\u0308fung')).toBe('pruefung');
    });

    it('starts with a letter and respects max', () => {
        expect(slugifyHandle('2024 Review')).toBe('review');
        expect(slugifyHandle('123')).toBe('');
        expect(slugifyHandle('abc def ghi', 8)).toBe('abc_def');
    });
});

describe('uniqueHandle', () => {
    it('appends a counter when taken or reserved', () => {
        expect(uniqueHandle('posts', new Set(['posts']))).toBe('posts_2');
        expect(uniqueHandle('posts', new Set(['posts', 'posts_2']))).toBe('posts_3');
        expect(uniqueHandle('create', new Set(), ['create'])).toBe('create_2');
        expect(uniqueHandle('fresh', new Set(['posts']))).toBe('fresh');
    });
});

describe('handleProblem', () => {
    it('reports the first problem', () => {
        expect(handleProblem('', opts)).toBe('required');
        expect(handleProblem('Bad', opts)).toBe('create_handle_invalid');
        expect(handleProblem('a'.repeat(21), opts)).toBe('create_handle_invalid');
        expect(handleProblem('create', opts)).toBe('create_handle_reserved');
        expect(handleProblem('posts', opts)).toBe('create_handle_taken');
        expect(handleProblem('fine_1', opts)).toBeNull();
    });
});

describe('mapCreateErrors', () => {
    it('maps fields, collections.N and definition errors', () => {
        const out = mapCreateErrors({
            message: 'x',
            errors: {
                handle: ['taken'],
                'collections.1': ['unknown'],
                'definition.metadata.plain_language_site': ['missing site'],
            },
            definition_errors: [{ path: 'metadata.plain_language_site', message: 'missing site' }],
        });
        expect(out.fields).toEqual({ handle: ['taken'], collections: ['unknown'] });
        expect(out.general).toEqual([{ path: 'metadata.plain_language_site', message: 'missing site' }]);
    });

    it('falls back to definition.* keys and the message', () => {
        expect(mapCreateErrors({ errors: { 'definition.places': ['bad'] } }).general)
            .toEqual([{ path: 'places', message: 'bad' }]);
        expect(mapCreateErrors({ message: 'boom' }).general).toEqual([{ path: '', message: 'boom' }]);
    });
});

describe('content_fields', () => {
    const form = (patch = {}) => ({
        from: 'blank',
        template: 'postmanager',
        copy_of: 'news',
        label: ' News ',
        handle: ' news_2 ',
        keepCollections: true,
        collections: ['pages'],
        contentFields: [],
        ...patch,
    });

    it('offers the picker for blank, and for a source only with changed collections', () => {
        expect(picksContentFields(form())).toBe(true);
        expect(picksContentFields(form({ from: 'example' }))).toBe(false);
        expect(picksContentFields(form({ from: 'copy', keepCollections: false }))).toBe(true);
    });

    it('blank: sends a picked list in order, omits an empty one', () => {
        expect(buildCreatePayload(form())).toEqual({ handle: 'news_2', label: 'News', from: 'blank', collections: ['pages'] });
        expect(buildCreatePayload(form({ contentFields: ['sections', 'intro'] })).content_fields).toEqual(['sections', 'intro']);
    });

    it('template/copy: omitted while the source collections stay, always sent once they change', () => {
        const kept = buildCreatePayload(form({ from: 'example', contentFields: ['sections'] }));
        expect(kept).toEqual({ handle: 'news_2', label: 'News', from: 'example', template: 'postmanager' });
        expect(buildCreatePayload(form({ from: 'copy', keepCollections: false })))
            .toEqual({ handle: 'news_2', label: 'News', from: 'copy', copy_of: 'news', collections: ['pages'], content_fields: [] });
        expect(buildCreatePayload(form({ from: 'copy', keepCollections: false, contentFields: ['intro'] })).content_fields).toEqual(['intro']);
    });

    it('maps content_fields errors onto the picker', () => {
        const out = mapCreateErrors({
            message: 'x',
            errors: {
                'content_fields.1': ['duplicate'],
                content_fields: ['unknown field'],
            },
            definition_errors: [
                { path: 'metadata.content_fields', message: 'unknown field' },
                { path: 'places', message: 'other' },
            ],
        });
        expect(out.fields).toEqual({ content_fields: ['duplicate', 'unknown field'] });
        expect(out.general).toEqual([{ path: 'places', message: 'other' }]);
    });
});
