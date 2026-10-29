/**
 * Where a comment sits: the marked text inside a passage, and the comment's
 * place in the margin next to it (Word-style).
 *
 * The pure parts — locateQuote(), layoutMargin() — are unit-tested in
 * __tests__/anchors.test.js. The DOM helpers below them work on the text nodes
 * of one `.fw-prose` element; offsets are characters of its `textContent`,
 * which `<mark>` wrappers do not change.
 */

/**
 * The `[start, end)` of `quote` in `text`, or null when it is gone.
 *
 * The stored offset is a hint: the exact spot wins, otherwise the occurrence
 * nearest to it (text was added or removed before it). Without any occurrence
 * the comment falls back to its whole passage — the server already flags that
 * the passage changed (`content_stale`).
 */
export function locateQuote(text, quote, offset = null) {
    const haystack = String(text ?? '');
    const needle = String(quote ?? '');
    if (!needle) return null;

    const hint = Number.isInteger(offset) && offset >= 0 ? offset : null;
    if (hint !== null && haystack.startsWith(needle, hint)) return [hint, hint + needle.length];

    let best = null;
    for (let i = haystack.indexOf(needle); i !== -1; i = haystack.indexOf(needle, i + 1)) {
        if (hint === null) return [i, i + needle.length];
        if (best === null || Math.abs(i - hint) < Math.abs(best - hint)) best = i;
    }

    return best === null ? null : [best, best + needle.length];
}

/**
 * Word-style margin layout: every card wants to sit level with its anchor
 * (`top`); overlapping cards are pushed down. The active card keeps its exact
 * place and the ones above it move up to make room — as long as that does not
 * push the first one above 0, in which case everything is laid out downwards.
 *
 * items: [{ id, top, height }] in any order. Returns { [id]: top }.
 */
export function layoutMargin(items, activeId = null, gap = 8) {
    const list = (items || [])
        .filter((item) => item && item.id != null)
        .map((item) => ({ id: item.id, want: Math.max(0, Number(item.top) || 0), height: Math.max(0, Number(item.height) || 0) }))
        .sort((a, b) => a.want - b.want);

    const placed = new Array(list.length);
    const down = (from, floor) => {
        let next = floor;
        for (let i = from; i < list.length; i++) {
            placed[i] = Math.max(list[i].want, next);
            next = placed[i] + list[i].height + gap;
        }
    };

    const pivot = activeId == null ? -1 : list.findIndex((item) => String(item.id) === String(activeId));

    if (pivot === -1) {
        down(0, 0);
    } else {
        placed[pivot] = list[pivot].want;
        down(pivot + 1, placed[pivot] + list[pivot].height + gap);

        let ceiling = placed[pivot] - gap;
        for (let i = pivot - 1; i >= 0; i--) {
            placed[i] = Math.min(list[i].want, ceiling - list[i].height);
            ceiling = placed[i] - gap;
        }

        if (placed[0] < 0) down(0, 0);
    }

    return Object.fromEntries(list.map((item, i) => [item.id, placed[i]]));
}

// ---------------------------------------------------------------------------
// DOM
// ---------------------------------------------------------------------------

function textNodes(root) {
    const walker = root.ownerDocument.createTreeWalker(root, 4 /* NodeFilter.SHOW_TEXT */);
    const nodes = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) nodes.push(node);

    return nodes;
}

/** Character position of a DOM point (`node`, `offset`) within `root`'s text. */
export function textOffset(root, node, offset) {
    const range = root.ownerDocument.createRange();
    range.selectNodeContents(root);
    range.setEnd(node, offset);

    return range.toString().length;
}

/**
 * The selection as `{ prose, quote, offset }` when it is non-empty and lies
 * inside ONE element matching `selector` (a passage's `.fw-prose`) within
 * `scope`; null otherwise.
 */
export function selectionIn(scope, selector) {
    const selection = scope?.ownerDocument?.getSelection?.();
    if (!selection || selection.isCollapsed || !selection.rangeCount) return null;

    const range = selection.getRangeAt(0);
    const start = range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentElement;
    const prose = start?.closest?.(selector);
    if (!prose || !scope.contains(prose) || !prose.contains(range.endContainer)) return null;

    const raw = range.toString();
    const quote = raw.trim();
    if (!quote) return null;

    // Trimmed whitespace moves the start forward.
    const lead = raw.length - raw.trimStart().length;

    return { prose, quote, offset: textOffset(prose, range.startContainer, range.startOffset) + lead, rect: range.getBoundingClientRect() };
}

/** Remove every `<mark data-comment-thread>` inside `root`, keeping the text. */
export function clearMarks(root) {
    for (const mark of root.querySelectorAll('mark[data-comment-thread]')) {
        mark.replaceWith(...mark.childNodes);
    }
    root.normalize();
}

/**
 * Wrap `[start, end)` of `root`'s text in `<mark>` elements — one per text node
 * it crosses, so markup (bold, links, list items) stays intact. Returns the
 * created marks.
 */
export function wrapRange(root, start, end, { thread, className }) {
    const marks = [];
    let position = 0;

    for (const node of textNodes(root)) {
        const nodeStart = position;
        position += node.data.length;

        const from = Math.max(start, nodeStart);
        const to = Math.min(end, position);
        if (from >= to) continue;

        // Cut the node down to exactly [from, to): first the head, then the tail.
        let target = node;
        if (from > nodeStart) target = target.splitText(from - nodeStart);
        if (to < position) target.splitText(to - from);

        const mark = root.ownerDocument.createElement('mark');
        mark.className = className;
        mark.dataset.commentThread = String(thread);
        target.replaceWith(mark);
        mark.appendChild(target);
        marks.push(mark);

        if (position >= end) break;
    }

    return marks;
}
