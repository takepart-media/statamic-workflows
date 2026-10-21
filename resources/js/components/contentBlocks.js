/**
 * Pure helpers for ContentBlocks.vue — the read-only content preview of an
 * entry (blocks from EntryContentPreview / ContentWalker on the server).
 *
 * Block shape: { uuid, container, path, depth, handle, label, kind, html?, items? }
 *   kind 'html'   → rendered HTML (Bard text, Bard without sets, Markdown)
 *   kind 'fields' → a structural node (Bard set, Replicator/Grid row, Group)
 *                   with scalar `items`: text | code | video | media
 *                   Item shape: { kind, label, handle?, field?, ... }
 *
 * The view is a reading flow, not a tree: `depth` stays in the data (containers,
 * hashes) but is ignored for display, and structural nodes without own values
 * are dropped — unless somebody commented on them.
 */

/** Short text values get a subtle "Label: value"; longer ones read as body copy. */
export const SHORT_TEXT_MAX = 80;

const HEADING_PATTERN = /(headline|heading|title|titel|überschrift|ueberschrift)/i;

/** What an editor calls the passage — drawer title, anchor entry. */
export function blockTitle(block) {
    return block?.label || block?.handle || '';
}

/** The DOM id comments jump to. */
export function passageId(block) {
    return `passage-${block?.uuid}`;
}

/** A structural node that only frames its children (no own items). */
export function isHeaderOnly(block) {
    return block?.kind === 'fields' && !(block.items || []).length;
}

/**
 * The blocks the reading flow shows: structural nodes without own items are
 * noise for an editor and go — except when they carry comments, which would
 * otherwise become unreachable. `commentCount(uuid)` comes from the page.
 */
export function readingBlocks(blocks, commentCount = () => 0) {
    return (blocks || []).filter((block) => {
        if (!block) return false;
        if (!isHeaderOnly(block)) return true;

        return (Number(commentCount(block.uuid)) || 0) > 0;
    });
}

/** Items of a fields block in a form the template can switch on. */
export function blockItems(block) {
    if (block?.kind !== 'fields') return [];

    return (block.items || []).filter((item) => item && ['text', 'code', 'video', 'media'].includes(item.kind));
}

/** Multi-line or long text, or a textarea field: body copy. */
export function isLongText(item) {
    if (item?.kind !== 'text') return false;
    if (item.field === 'textarea') return true;

    const text = String(item.text ?? '');

    return text.includes('\n') || text.length > SHORT_TEXT_MAX;
}

/** A single-line text whose field reads as a headline (by handle or label). */
export function isHeadingItem(item) {
    if (item?.kind !== 'text' || isLongText(item)) return false;

    return HEADING_PATTERN.test(item.handle || '') || HEADING_PATTERN.test(item.label || '');
}

/**
 * How an item renders in the reading flow:
 *   heading   → headline, no label
 *   paragraph → body copy, no label
 *   labelled  → short value with a subtle "Label:" (button text, caption, …)
 *   media / video → no label; code keeps its label (it is not reading copy)
 */
export function itemDisplay(item) {
    switch (item?.kind) {
        case 'text':
            if (isHeadingItem(item)) return 'heading';

            return isLongText(item) ? 'paragraph' : 'labelled';
        case 'code':
        case 'video':
        case 'media':
            return item.kind;
        default:
            return null;
    }
}

/** The subtle label shown in front of / above an item, or '' for none. */
export function itemLabel(item) {
    const display = itemDisplay(item);

    return display === 'labelled' || display === 'code' ? item.label || item.handle || '' : '';
}

/** Image preview for a media asset: the CP thumbnail, else the file itself. */
export function assetThumb(asset) {
    if (!asset?.isImage) return null;

    return asset.thumb || asset.url || null;
}

/** Stable v-for key for a block. */
export function blockKey(block, index, prefix = '') {
    return `${prefix}${block?.uuid || index}`;
}

/** localStorage key for the super-admin "technical details" switch. */
export const TECHNICAL_STORAGE_KEY = 'workflows.content.technical';

/** Read the remembered switch state; storage may be unavailable. */
export function readTechnical(storage = globalThis.localStorage) {
    try {
        return storage?.getItem(TECHNICAL_STORAGE_KEY) === '1';
    } catch (e) {
        return false;
    }
}

/** Remember the switch state; failures (private mode, quota) are ignored. */
export function writeTechnical(value, storage = globalThis.localStorage) {
    try {
        storage?.setItem(TECHNICAL_STORAGE_KEY, value ? '1' : '0');
    } catch (e) {
        /* non-critical */
    }
}

// ---------------------------------------------------------------------------
// Technische Baumansicht – testweise (User-Entscheidung ausstehend).
// Used only when `technical` is on: the structure tree instead of the reading
// flow. Remove this helper together with the marked branch in ContentBlocks.vue.
// ---------------------------------------------------------------------------

/**
 * All blocks (structural ones without own items included) annotated for the
 * tree: `indent` = depth relative to the shallowest block, `pathTail` = the
 * block's path relative to its nearest shallower predecessor (its parent),
 * e.g. `content_right` or `content_right.content`; the full path otherwise.
 */
export function technicalTree(blocks) {
    const list = (blocks || []).filter(Boolean);
    const depths = list.map((block) => Number(block.depth) || 0);
    const base = depths.length ? Math.min(...depths) : 0;
    const stack = [];

    return list.map((block, i) => {
        const depth = depths[i];
        while (stack.length && stack[stack.length - 1].depth >= depth) stack.pop();

        const parent = stack[stack.length - 1];
        const path = String(block.path ?? '');
        const prefix = parent?.path ? `${parent.path}.` : null;
        const pathTail = prefix && path.startsWith(prefix) ? path.slice(prefix.length) : path;

        stack.push({ depth, path });

        return { block, indent: depth - base, pathTail };
    });
}
