/**
 * Pure helpers for the Word-style comment margin (CommentsMargin.vue and
 * friends). No Vue, no DOM, no `__()` — everything here is unit-tested in
 * __tests__/comments.test.js and everything stateful lives in useComments.js.
 *
 * Comment shape (server contract):
 *   { id, parent_id|null, at, edited_at|null,
 *     author: { id|null, name, avatar|null, initials },
 *     body, mentions: [{ id, name }], ref: { uuid, handle, quote, offset }|null,
 *     resolved: { at, by: { id, name } }|null   (roots only),
 *     can_edit, can_delete, can_resolve, is_new, content_stale, container_changed }
 *
 * A thread is a root comment (parent_id null) plus its replies. Replies inherit
 * the root's ref; resolved/reopened is a property of the root.
 */

export const FILTERS = ['open', 'resolved', 'all'];

function time(iso) {
    const t = Date.parse(iso ?? '');

    return Number.isNaN(t) ? 0 : t;
}

function byTime(a, b) {
    return time(a.at) - time(b.at) || String(a.id).localeCompare(String(b.id));
}

/** The passage uuid a comment (or thread root) belongs to, or null for general ones. */
export function refUuid(comment) {
    return comment?.ref?.uuid || null;
}

/**
 * Group a flat comment list into threads, sorted in DOCUMENT ORDER:
 *   1. passage-less threads ("general", e.g. QA notes) first,
 *   2. then by the position of their passage in `blockOrder` (uuids of the page),
 *   3. passages no longer on the page last,
 *   4. ties (same passage) by the root's time.
 * Replies whose root is missing are promoted to roots so nothing disappears.
 */
export function buildThreads(comments, blockOrder = []) {
    const list = (comments || []).filter(Boolean);
    const ids = new Set(list.map((c) => c.id));
    const position = new Map((blockOrder || []).map((uuid, i) => [uuid, i]));
    const roots = [];
    const replies = new Map();

    for (const c of list) {
        if (c.parent_id != null && ids.has(c.parent_id)) {
            if (!replies.has(c.parent_id)) replies.set(c.parent_id, []);
            replies.get(c.parent_id).push(c);
        } else {
            roots.push(c);
        }
    }

    const threads = roots.map((root) => {
        const own = (replies.get(root.id) || []).slice().sort(byTime);
        const uuid = refUuid(root);
        const comments = [root, ...own];

        return {
            id: root.id,
            root,
            replies: own,
            comments,
            uuid,
            handle: root.ref?.handle || null,
            resolved: !!root.resolved,
            isNew: comments.some((c) => !!c.is_new),
            lastAt: comments.reduce((max, c) => Math.max(max, time(c.at)), 0),
        };
    });

    const rank = (thread) => {
        if (!thread.uuid) return -1;

        return position.has(thread.uuid) ? position.get(thread.uuid) : Number.MAX_SAFE_INTEGER;
    };

    return threads.sort((a, b) => rank(a) - rank(b) || byTime(a.root, b.root));
}

/** Threads matching a filter: `open` | `resolved` | `all`. */
export function filterThreads(threads, filter = 'open') {
    if (filter === 'resolved') return (threads || []).filter((t) => t.resolved);
    if (filter === 'all') return (threads || []).slice();

    return (threads || []).filter((t) => !t.resolved);
}

/** Thread counts per filter, for the toggle labels. */
export function threadCounts(threads) {
    const all = (threads || []).length;
    const resolved = (threads || []).filter((t) => t.resolved).length;

    return { open: all - resolved, resolved, all };
}

/** Comments in OPEN threads of a passage — the number on its gutter button. */
export function openCommentCount(threads, uuid) {
    return (threads || [])
        .filter((t) => !t.resolved && t.uuid === uuid)
        .reduce((sum, t) => sum + t.comments.length, 0);
}

/** Whether an OPEN thread of a passage carries an unread comment. */
export function openHasNew(threads, uuid) {
    return (threads || []).some((t) => !t.resolved && t.uuid === uuid && t.isNew);
}

/** Thread containing a comment id, or null. */
export function threadOf(threads, commentId) {
    if (commentId == null) return null;
    const id = String(commentId);

    return (threads || []).find((t) => t.comments.some((c) => String(c.id) === id)) || null;
}

/** The filter to switch to so `thread` becomes visible, or the current one if it already is. */
export function filterShowing(thread, current) {
    if (!thread || current === 'all') return current;
    if (thread.resolved && current === 'open') return 'all';
    if (!thread.resolved && current === 'resolved') return 'open';

    return current;
}

/**
 * The rows of the sidebar, in order: thread cards, plus — while composing a
 * new comment for a passage — a composer row placed at that passage's position
 * in document order (after its existing threads, or between its neighbours'
 * threads when it has none yet).
 *
 * Returns [{ type: 'thread', key, thread } | { type: 'composer', key, uuid }].
 */
export function sidebarRows(threads, blockOrder = [], composerUuid = null) {
    const rows = (threads || []).map((thread) => ({ type: 'thread', key: `t-${thread.id}`, thread }));

    if (!composerUuid) return rows;

    const composer = { type: 'composer', key: `c-${composerUuid}`, uuid: composerUuid };
    const position = new Map((blockOrder || []).map((uuid, i) => [uuid, i]));
    const target = position.has(composerUuid) ? position.get(composerUuid) : Number.MAX_SAFE_INTEGER;

    // Index of the first thread that sits AFTER the target passage.
    let insertAt = rows.length;
    for (let i = 0; i < rows.length; i++) {
        const uuid = rows[i].thread.uuid;
        if (!uuid) continue; // general threads always come first
        const p = position.has(uuid) ? position.get(uuid) : Number.MAX_SAFE_INTEGER;
        if (p > target) {
            insertAt = i;
            break;
        }
    }

    rows.splice(insertAt, 0, composer);

    return rows;
}

/**
 * Previous / next thread id in `ids` (the visible order). No wrap-around, like
 * Word: null at either end. Without a current one, `next` starts at the first
 * and `prev` at the last.
 */
export function adjacentThread(ids, currentId, direction = 1) {
    const list = ids || [];
    if (!list.length) return null;

    const i = list.indexOf(currentId);
    if (i === -1) return direction > 0 ? list[0] : list[list.length - 1];

    const next = i + (direction > 0 ? 1 : -1);

    return next >= 0 && next < list.length ? list[next] : null;
}

// ---------------------------------------------------------------------------
// Time
// ---------------------------------------------------------------------------

const UNITS = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
];

/**
 * "vor 5 Min." / "5 min. ago" — Intl.RelativeTimeFormat in the CP locale,
 * short style. Under 45 seconds it is "jetzt" / "now".
 */
export function relativeTime(iso, now = Date.now(), locale = undefined) {
    const t = time(iso);
    if (!t) return '';

    const seconds = Math.round((t - now) / 1000);
    const abs = Math.abs(seconds);

    let rtf;
    try {
        rtf = new Intl.RelativeTimeFormat(locale || undefined, { numeric: 'auto', style: 'short' });
    } catch (e) {
        rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto', style: 'short' });
    }

    if (abs < 45) return rtf.format(0, 'second');

    for (const [unit, size] of UNITS) {
        if (abs >= size * (unit === 'minute' ? 0.75 : 1)) {
            const value = Math.round(seconds / size);

            // `numeric: 'auto'` would turn ±1 day into "gestern"/"morgen": fine.
            return rtf.format(value === 0 ? Math.sign(seconds) : value, unit);
        }
    }

    return rtf.format(Math.round(seconds / 60), 'minute');
}

/** Absolute date + time for the title attribute. */
export function absoluteTime(iso, locale = undefined) {
    const t = time(iso);
    if (!t) return '';

    try {
        return new Date(t).toLocaleString(locale || undefined, { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) {
        return new Date(t).toLocaleString();
    }
}

// ---------------------------------------------------------------------------
// Mentions
// ---------------------------------------------------------------------------

const MENTION_QUERY_MAX = 40;

/**
 * The mention being typed at `caret`: the `@` must start the text or follow
 * whitespace / an opening bracket, and the query up to the caret has no line
 * break. Returns { start, query } (start = index of the `@`) or null.
 */
export function mentionQueryAt(text, caret) {
    const value = String(text ?? '');
    const end = Math.max(0, Math.min(caret ?? value.length, value.length));
    const at = value.lastIndexOf('@', end - 1);
    if (at === -1) return null;

    const before = at === 0 ? '' : value[at - 1];
    if (before && !/[\s(\[{"'„]/.test(before)) return null;

    const query = value.slice(at + 1, end);
    if (query.length > MENTION_QUERY_MAX || /[\n\r@]/.test(query) || query.startsWith(' ')) return null;

    return { start: at, query };
}

/** Users whose name matches `query` (case-insensitive), prefix matches first. */
export function filterMentionables(users, query, limit = 8) {
    const q = String(query ?? '').trim().toLowerCase();
    const list = (users || []).filter((u) => u && u.name);

    if (!q) return list.slice(0, limit);

    const scored = [];
    for (const u of list) {
        const name = u.name.toLowerCase();
        const words = name.split(/\s+/);
        if (name.startsWith(q)) scored.push([0, u]);
        else if (words.some((w) => w.startsWith(q))) scored.push([1, u]);
        else if (name.includes(q)) scored.push([2, u]);
    }

    return scored
        .sort((a, b) => a[0] - b[0] || a[1].name.localeCompare(b[1].name))
        .slice(0, limit)
        .map(([, u]) => u);
}

/**
 * Replace the typed `@query` (from `start` to `caret`) with `@Name ` and
 * return the new text and caret position.
 */
export function insertMention(text, start, caret, user) {
    const value = String(text ?? '');
    const token = `@${user.name} `;
    const tail = value.slice(caret).replace(/^ /, '');
    const next = value.slice(0, start) + token + tail;

    return { text: next, caret: start + token.length };
}

function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function mentionPattern(name) {
    // `@Name` not followed by a further word character — so "@Ann" does not
    // match inside "@Anna".
    return new RegExp(`(^|[^\\w@])@${escapeRegExp(name)}(?![\\p{L}\\p{N}_])`, 'u');
}

/** Ids of the `candidates` ({id, name}) whose `@Name` still appears in `body`. */
export function mentionedIds(body, candidates) {
    const text = String(body ?? '');
    const seen = new Set();
    const ids = [];

    for (const c of candidates || []) {
        if (!c || c.id == null || !c.name || seen.has(c.id)) continue;
        if (mentionPattern(c.name).test(text)) {
            seen.add(c.id);
            ids.push(c.id);
        }
    }

    return ids;
}

/**
 * Split a body into text and mention segments for rendering (no v-html):
 *   [{ type: 'text', text } | { type: 'mention', text: '@Name', id }]
 * Longer names win, so "@Anna Maria" beats "@Anna".
 */
export function tokenizeBody(body, mentions) {
    const text = String(body ?? '');
    const names = (mentions || [])
        .filter((m) => m && m.name)
        .slice()
        .sort((a, b) => b.name.length - a.name.length);

    if (!names.length || !text.includes('@')) return text ? [{ type: 'text', text }] : [];

    const alternation = names.map((m) => escapeRegExp(m.name)).join('|');
    const pattern = new RegExp(`(^|[^\\w@])@(${alternation})(?![\\p{L}\\p{N}_])`, 'gu');
    const byName = new Map(names.map((m) => [m.name, m]));
    const segments = [];
    let last = 0;
    let match;

    while ((match = pattern.exec(text))) {
        const start = match.index + match[1].length;
        if (start > last) segments.push({ type: 'text', text: text.slice(last, start) });
        segments.push({ type: 'mention', text: `@${match[2]}`, id: byName.get(match[2])?.id ?? null });
        last = start + 1 + match[2].length;
        pattern.lastIndex = last;
    }

    if (last < text.length) segments.push({ type: 'text', text: text.slice(last) });

    return segments;
}

// ---------------------------------------------------------------------------
// List updates
// ---------------------------------------------------------------------------

/** Replace a comment by id, or append it. Returns a new array. */
export function upsertComment(list, comment) {
    if (!comment || comment.id == null) return (list || []).slice();

    const out = (list || []).slice();
    const i = out.findIndex((c) => c.id === comment.id);
    if (i === -1) out.push(comment);
    else out[i] = { ...out[i], ...comment };

    return out;
}

/** Drop comments by id (a deleted root takes its replies — the server lists them). */
export function removeComments(list, ids) {
    const drop = new Set(ids || []);

    return (list || []).filter((c) => !drop.has(c.id));
}
