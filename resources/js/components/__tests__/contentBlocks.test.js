import { describe, expect, it } from 'vitest';
import {
    assetThumb,
    blockItems,
    blockKey,
    blockTitle,
    isHeaderOnly,
    isHeadingItem,
    isLongText,
    itemDisplay,
    itemLabel,
    passageId,
    readingBlocks,
    readTechnical,
    SHORT_TEXT_MAX,
    TECHNICAL_STORAGE_KEY,
    writeTechnical,
} from '../contentBlocks.js';

describe('blockTitle / passageId / blockKey', () => {
    it('prefers the label over the handle', () => {
        expect(blockTitle({ label: 'Kapitel', handle: 'chapter' })).toBe('Kapitel');
        expect(blockTitle({ handle: 'chapter' })).toBe('chapter');
        expect(blockTitle(null)).toBe('');
    });

    it('builds the comment anchor id', () => {
        expect(passageId({ uuid: 'abc.content:0' })).toBe('passage-abc.content:0');
    });

    it('keys by uuid, falling back to the index', () => {
        expect(blockKey({ uuid: 'x' }, 3, 's-')).toBe('s-x');
        expect(blockKey({}, 3)).toBe('3');
    });
});

describe('isHeaderOnly / blockItems', () => {
    it('detects fields blocks without own items', () => {
        expect(isHeaderOnly({ kind: 'fields', items: [] })).toBe(true);
        expect(isHeaderOnly({ kind: 'fields' })).toBe(true);
        expect(isHeaderOnly({ kind: 'fields', items: [{ kind: 'text', text: 'a' }] })).toBe(false);
        expect(isHeaderOnly({ kind: 'html', html: '<p>a</p>' })).toBe(false);
    });

    it('keeps only known item kinds', () => {
        const block = { kind: 'fields', items: [{ kind: 'text' }, { kind: 'bogus' }, null, { kind: 'media' }, { kind: 'code' }, { kind: 'video' }] };
        expect(blockItems(block).map((i) => i.kind)).toEqual(['text', 'media', 'code', 'video']);
        expect(blockItems({ kind: 'html' })).toEqual([]);
    });
});

describe('readingBlocks', () => {
    const blocks = [
        { uuid: 'sec', kind: 'fields', items: [] },
        { uuid: 'row', kind: 'fields' },
        { uuid: 'text', kind: 'html', html: '<p>a</p>' },
        { uuid: 'teaser', kind: 'fields', items: [{ kind: 'text', text: 'Hi' }] },
        null,
    ];

    it('drops structural blocks without own items', () => {
        expect(readingBlocks(blocks).map((b) => b.uuid)).toEqual(['text', 'teaser']);
    });

    it('keeps a structural block that carries comments', () => {
        const count = (uuid) => (uuid === 'row' ? 2 : 0);
        expect(readingBlocks(blocks, count).map((b) => b.uuid)).toEqual(['row', 'text', 'teaser']);
    });

    it('copes with missing input', () => {
        expect(readingBlocks(null)).toEqual([]);
        expect(readingBlocks(undefined, () => 1)).toEqual([]);
    });
});

describe('item display rules', () => {
    it('detects headings by handle or label', () => {
        expect(isHeadingItem({ kind: 'text', handle: 'headline', text: 'Hello' })).toBe(true);
        expect(isHeadingItem({ kind: 'text', handle: 'x', label: 'Überschrift', text: 'Hello' })).toBe(true);
        expect(isHeadingItem({ kind: 'text', label: 'Titel', text: 'Hello' })).toBe(true);
        expect(isHeadingItem({ kind: 'text', handle: 'button_text', label: 'Button', text: 'Go' })).toBe(false);
        expect(isHeadingItem({ kind: 'media', handle: 'title' })).toBe(false);
        // A long or textarea "title" is body copy, not a headline.
        expect(isHeadingItem({ kind: 'text', handle: 'title', field: 'textarea', text: 'x' })).toBe(false);
    });

    it('treats textarea, multi-line and long text as body copy', () => {
        expect(isLongText({ kind: 'text', field: 'textarea', text: 'short' })).toBe(true);
        expect(isLongText({ kind: 'text', text: 'a\nb' })).toBe(true);
        expect(isLongText({ kind: 'text', text: 'x'.repeat(SHORT_TEXT_MAX + 1) })).toBe(true);
        expect(isLongText({ kind: 'text', text: 'Jetzt spenden' })).toBe(false);
        expect(isLongText({ kind: 'code', code: 'a\nb' })).toBe(false);
    });

    it('labels only short values and code', () => {
        const button = { kind: 'text', handle: 'button', label: 'Button', text: 'Jetzt spenden' };
        expect(itemDisplay(button)).toBe('labelled');
        expect(itemLabel(button)).toBe('Button');

        const headline = { kind: 'text', handle: 'headline', label: 'Headline', text: 'Hello' };
        expect(itemDisplay(headline)).toBe('heading');
        expect(itemLabel(headline)).toBe('');

        const body = { kind: 'text', field: 'textarea', label: 'Text', text: 'Body' };
        expect(itemDisplay(body)).toBe('paragraph');
        expect(itemLabel(body)).toBe('');

        expect(itemDisplay({ kind: 'media', label: 'Bild' })).toBe('media');
        expect(itemLabel({ kind: 'media', label: 'Bild' })).toBe('');
        expect(itemLabel({ kind: 'video', label: 'Video' })).toBe('');
        expect(itemLabel({ kind: 'code', label: 'Embed' })).toBe('Embed');
        expect(itemDisplay({ kind: 'bogus' })).toBe(null);
    });
});

describe('technical details switch storage', () => {
    function memory() {
        const data = {};
        return { getItem: (k) => (k in data ? data[k] : null), setItem: (k, v) => { data[k] = String(v); }, data };
    }

    it('round-trips the switch state', () => {
        const store = memory();
        expect(readTechnical(store)).toBe(false);
        writeTechnical(true, store);
        expect(store.data[TECHNICAL_STORAGE_KEY]).toBe('1');
        expect(readTechnical(store)).toBe(true);
        writeTechnical(false, store);
        expect(readTechnical(store)).toBe(false);
    });

    it('survives unavailable storage', () => {
        const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } };
        expect(readTechnical(broken)).toBe(false);
        expect(() => writeTechnical(true, broken)).not.toThrow();
        expect(readTechnical(null)).toBe(false);
    });
});

describe('assetThumb', () => {
    it('uses the thumbnail, then the url, for images only', () => {
        expect(assetThumb({ isImage: true, thumb: '/t', url: '/u' })).toBe('/t');
        expect(assetThumb({ isImage: true, url: '/u' })).toBe('/u');
        expect(assetThumb({ isImage: false, url: '/u.pdf' })).toBe(null);
        expect(assetThumb(null)).toBe(null);
    });
});
