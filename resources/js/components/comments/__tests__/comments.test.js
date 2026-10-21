import { describe, expect, it } from 'vitest';
import {
    absoluteTime,
    adjacentThread,
    buildThreads,
    filterMentionables,
    filterShowing,
    filterThreads,
    insertMention,
    mentionedIds,
    mentionQueryAt,
    openCommentCount,
    openHasNew,
    relativeTime,
    removeComments,
    sidebarRows,
    threadCounts,
    threadOf,
    tokenizeBody,
    upsertComment,
} from '../comments.js';

const c = (id, extra = {}) => ({
    id,
    parent_id: null,
    at: '2026-10-21T10:00:00Z',
    body: '',
    mentions: [],
    ref: null,
    resolved: null,
    is_new: false,
    ...extra,
});

const ref = (uuid) => ({ uuid, handle: `h-${uuid}` });

describe('buildThreads', () => {
    const comments = [
        c('r-b', { ref: ref('b'), at: '2026-10-21T09:00:00Z' }),
        c('r-a2', { ref: ref('a'), at: '2026-10-21T11:00:00Z' }),
        c('r-a1', { ref: ref('a'), at: '2026-10-21T08:00:00Z' }),
        c('g1', { at: '2026-10-21T12:00:00Z' }),
        c('gone', { ref: ref('zzz') }),
        c('rep2', { parent_id: 'r-a1', ref: ref('a'), at: '2026-10-21T09:30:00Z' }),
        c('rep1', { parent_id: 'r-a1', ref: ref('a'), at: '2026-10-21T09:00:00Z', is_new: true }),
        c('orphan', { parent_id: 'missing', ref: ref('b'), at: '2026-10-21T10:00:00Z' }),
    ];

    it('groups replies under their root, sorted by time', () => {
        const threads = buildThreads(comments, ['a', 'b']);
        const a1 = threads.find((t) => t.id === 'r-a1');
        expect(a1.replies.map((r) => r.id)).toEqual(['rep1', 'rep2']);
        expect(a1.comments.map((r) => r.id)).toEqual(['r-a1', 'rep1', 'rep2']);
        expect(a1.isNew).toBe(true);
        expect(a1.uuid).toBe('a');
        expect(a1.handle).toBe('h-a');
    });

    it('sorts general first, then document order, missing passages last', () => {
        const ids = buildThreads(comments, ['a', 'b']).map((t) => t.id);
        expect(ids).toEqual(['g1', 'r-a1', 'r-a2', 'r-b', 'orphan', 'gone']);
    });

    it('follows the block order, not the time', () => {
        const ids = buildThreads(comments, ['b', 'a']).map((t) => t.id);
        expect(ids.slice(0, 4)).toEqual(['g1', 'r-b', 'orphan', 'r-a1']);
    });

    it('marks resolved roots', () => {
        const threads = buildThreads([c('x', { resolved: { at: '2026-10-21T10:00:00Z', by: { id: 1, name: 'A' } } })]);
        expect(threads[0].resolved).toBe(true);
    });

    it('copes with empty input', () => {
        expect(buildThreads(null)).toEqual([]);
    });
});

describe('filters and counts', () => {
    const threads = buildThreads([
        c('o1', { ref: ref('a'), is_new: true }),
        c('o1r', { parent_id: 'o1', ref: ref('a') }),
        c('r1', { ref: ref('a'), resolved: { at: 'x', by: { id: 1, name: 'A' } }, is_new: true }),
        c('o2', { ref: ref('b') }),
    ], ['a', 'b']);

    it('filters open / resolved / all', () => {
        expect(filterThreads(threads, 'open').map((t) => t.id)).toEqual(['o1', 'o2']);
        expect(filterThreads(threads, 'resolved').map((t) => t.id)).toEqual(['r1']);
        expect(filterThreads(threads, 'all')).toHaveLength(3);
    });

    it('counts per filter', () => {
        expect(threadCounts(threads)).toEqual({ open: 2, resolved: 1, all: 3 });
    });

    it('counts and flags only open threads per passage', () => {
        expect(openCommentCount(threads, 'a')).toBe(2);
        expect(openCommentCount(threads, 'b')).toBe(1);
        expect(openCommentCount(threads, 'c')).toBe(0);
        expect(openHasNew(threads, 'a')).toBe(true);
        expect(openHasNew(threads, 'b')).toBe(false);
    });

    it('finds the thread of a reply', () => {
        expect(threadOf(threads, 'o1r').id).toBe('o1');
        expect(threadOf(threads, 'nope')).toBeNull();
    });

    it('picks a filter that shows a thread', () => {
        const open = threads.find((t) => t.id === 'o1');
        const resolved = threads.find((t) => t.id === 'r1');
        expect(filterShowing(resolved, 'open')).toBe('all');
        expect(filterShowing(open, 'resolved')).toBe('open');
        expect(filterShowing(open, 'open')).toBe('open');
        expect(filterShowing(resolved, 'all')).toBe('all');
    });
});

describe('sidebarRows', () => {
    const threads = buildThreads([c('g'), c('a', { ref: ref('a') }), c('c', { ref: ref('c') })], ['a', 'b', 'c']);

    it('returns thread rows without a composer', () => {
        expect(sidebarRows(threads, ['a', 'b', 'c']).map((r) => r.key)).toEqual(['t-g', 't-a', 't-c']);
    });

    it('places the composer at the passage position', () => {
        expect(sidebarRows(threads, ['a', 'b', 'c'], 'b').map((r) => r.key)).toEqual(['t-g', 't-a', 'c-b', 't-c']);
    });

    it('places the composer after the passage\'s own threads', () => {
        expect(sidebarRows(threads, ['a', 'b', 'c'], 'a').map((r) => r.key)).toEqual(['t-g', 't-a', 'c-a', 't-c']);
    });

    it('appends the composer for the last passage or an unknown one', () => {
        expect(sidebarRows(threads, ['a', 'b', 'c'], 'c').pop().key).toBe('c-c');
        expect(sidebarRows(threads, ['a', 'b', 'c'], 'x').pop().key).toBe('c-x');
    });
});

describe('adjacentThread', () => {
    const ids = ['a', 'b', 'c'];

    it('moves forward and backward without wrapping', () => {
        expect(adjacentThread(ids, 'a', 1)).toBe('b');
        expect(adjacentThread(ids, 'c', 1)).toBeNull();
        expect(adjacentThread(ids, 'b', -1)).toBe('a');
        expect(adjacentThread(ids, 'a', -1)).toBeNull();
    });

    it('starts at either end without a current thread', () => {
        expect(adjacentThread(ids, null, 1)).toBe('a');
        expect(adjacentThread(ids, 'gone', -1)).toBe('c');
        expect(adjacentThread([], null, 1)).toBeNull();
    });
});

describe('relativeTime', () => {
    const now = Date.parse('2026-10-21T12:00:00Z');

    it('formats short relative times in German', () => {
        expect(relativeTime('2026-10-21T11:59:50Z', now, 'de')).toBe('jetzt');
        expect(relativeTime('2026-10-21T11:55:00Z', now, 'de')).toBe('vor 5 Min.');
        expect(relativeTime('2026-10-21T09:00:00Z', now, 'de')).toBe('vor 3 Std.');
        expect(relativeTime('2026-10-20T12:00:00Z', now, 'de')).toBe('gestern');
        expect(relativeTime('2026-10-16T12:00:00Z', now, 'de')).toBe('vor 5 Tagen');
    });

    it('formats in English', () => {
        expect(relativeTime('2026-10-21T11:55:00Z', now, 'en')).toBe('5 min. ago');
    });

    it('returns empty for invalid dates', () => {
        expect(relativeTime(null, now, 'de')).toBe('');
        expect(absoluteTime('nope', 'de')).toBe('');
    });

    it('formats an absolute time', () => {
        expect(absoluteTime('2026-10-21T12:00:00Z', 'de')).toMatch(/2026/);
    });
});

describe('mentions', () => {
    const users = [
        { id: 1, name: 'Anna Schmidt' },
        { id: 2, name: 'Ann Berg' },
        { id: 3, name: 'Bernd Anders' },
    ];

    it('detects the query being typed', () => {
        expect(mentionQueryAt('Hallo @An', 9)).toEqual({ start: 6, query: 'An' });
        expect(mentionQueryAt('@', 1)).toEqual({ start: 0, query: '' });
        expect(mentionQueryAt('mail@example', 12)).toBeNull();
        expect(mentionQueryAt('@Anna\nweiter', 12)).toBeNull();
        expect(mentionQueryAt('@ x', 3)).toBeNull();
        expect(mentionQueryAt('no at', 5)).toBeNull();
    });

    it('filters users, prefix matches first', () => {
        expect(filterMentionables(users, 'an').map((u) => u.id)).toEqual([2, 1, 3]);
        expect(filterMentionables(users, 'schm').map((u) => u.id)).toEqual([1]);
        expect(filterMentionables(users, '', 2)).toHaveLength(2);
    });

    it('inserts a mention token', () => {
        expect(insertMention('Hi @An x', 3, 6, users[0])).toEqual({ text: 'Hi @Anna Schmidt x', caret: 17 });
        expect(insertMention('@', 0, 1, users[1])).toEqual({ text: '@Ann Berg ', caret: 10 });
    });

    it('keeps only ids still in the body', () => {
        expect(mentionedIds('Hi @Anna Schmidt and @Ann Berg', users)).toEqual([1, 2]);
        expect(mentionedIds('Hi @Anna Schmidtke', users)).toEqual([]);
        expect(mentionedIds('mail@Ann Berg', users)).toEqual([]);
        expect(mentionedIds('', users)).toEqual([]);
    });

    it('tokenizes a body into text and mention segments', () => {
        expect(tokenizeBody('Hi @Anna Schmidt, see @Ann Berg.', users)).toEqual([
            { type: 'text', text: 'Hi ' },
            { type: 'mention', text: '@Anna Schmidt', id: 1 },
            { type: 'text', text: ', see ' },
            { type: 'mention', text: '@Ann Berg', id: 2 },
            { type: 'text', text: '.' },
        ]);
        expect(tokenizeBody('plain', [])).toEqual([{ type: 'text', text: 'plain' }]);
        expect(tokenizeBody('@Ann Bergmann', users)).toEqual([{ type: 'text', text: '@Ann Bergmann' }]);
    });
});

describe('list updates', () => {
    it('upserts and removes', () => {
        const list = [c('a', { body: 'x' })];
        expect(upsertComment(list, c('a', { body: 'y' }))[0].body).toBe('y');
        expect(upsertComment(list, c('b'))).toHaveLength(2);
        expect(removeComments([c('a'), c('b'), c('c')], ['a', 'c']).map((x) => x.id)).toEqual(['b']);
    });
});
