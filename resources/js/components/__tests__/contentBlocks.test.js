import { describe, expect, it } from 'vitest';
import { assetThumb, blockDepth, blockItems, blockKey, blockTitle, indentStyle, isHeaderOnly, MAX_INDENT_DEPTH, passageId } from '../contentBlocks.js';

describe('blockDepth / indentStyle', () => {
    it('normalises depth', () => {
        expect(blockDepth({ depth: 2 })).toBe(2);
        expect(blockDepth({})).toBe(0);
        expect(blockDepth({ depth: -1 })).toBe(0);
        expect(blockDepth({ depth: 'x' })).toBe(0);
        expect(blockDepth(null)).toBe(0);
    });

    it('indents nested blocks only, capped', () => {
        expect(indentStyle({ depth: 0 })).toEqual({});
        expect(indentStyle({ depth: 2 })).toEqual({ marginInlineStart: '2rem' });
        expect(indentStyle({ depth: 40 })).toEqual({ marginInlineStart: `${MAX_INDENT_DEPTH}rem` });
    });
});

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

describe('assetThumb', () => {
    it('uses the thumbnail, then the url, for images only', () => {
        expect(assetThumb({ isImage: true, thumb: '/t', url: '/u' })).toBe('/t');
        expect(assetThumb({ isImage: true, url: '/u' })).toBe('/u');
        expect(assetThumb({ isImage: false, url: '/u.pdf' })).toBe(null);
        expect(assetThumb(null)).toBe(null);
    });
});
