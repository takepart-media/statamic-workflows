import { describe, expect, it } from 'vitest';
import { layoutMargin, locateQuote } from '../anchors.js';

describe('locateQuote', () => {
    const text = 'Der Hund bellt. Der Hund schläft.';

    it('takes the exact spot when the quote is still there', () => {
        expect(locateQuote(text, 'Hund', 20)).toEqual([20, 24]);
    });

    it('finds the occurrence nearest to a shifted offset', () => {
        expect(locateQuote(text, 'Hund', 18)).toEqual([20, 24]);
        expect(locateQuote(text, 'Hund', 2)).toEqual([4, 8]);
    });

    it('takes the first occurrence without an offset', () => {
        expect(locateQuote(text, 'Hund')).toEqual([4, 8]);
    });

    it('gives up when the quote is gone', () => {
        expect(locateQuote(text, 'Katze', 4)).toBeNull();
        expect(locateQuote(text, '', 0)).toBeNull();
    });
});

describe('layoutMargin', () => {
    it('keeps cards level with their anchors when nothing overlaps', () => {
        expect(layoutMargin([{ id: 1, top: 0, height: 50 }, { id: 2, top: 100, height: 50 }])).toEqual({ 1: 0, 2: 100 });
    });

    it('pushes overlapping cards down', () => {
        expect(layoutMargin([{ id: 2, top: 20, height: 50 }, { id: 1, top: 0, height: 50 }], null, 10)).toEqual({ 1: 0, 2: 60 });
    });

    it('keeps the active card at its anchor and moves the ones above it up', () => {
        const items = [
            { id: 'a', top: 100, height: 40 },
            { id: 'b', top: 110, height: 40 },
            { id: 'c', top: 120, height: 40 },
        ];

        expect(layoutMargin(items, 'b', 10)).toEqual({ a: 60, b: 110, c: 160 });
    });

    it('lays out downwards when moving up would leave the top', () => {
        const items = [
            { id: 'a', top: 0, height: 40 },
            { id: 'b', top: 10, height: 40 },
        ];

        expect(layoutMargin(items, 'b', 10)).toEqual({ a: 0, b: 50 });
    });
});
