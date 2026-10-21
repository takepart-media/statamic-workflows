/**
 * Pure helpers for ContentBlocks.vue — the read-only content preview of an
 * entry (blocks from EntryContentPreview / ContentWalker on the server).
 *
 * Block shape: { uuid, container, path, depth, handle, label, kind, html?, items? }
 *   kind 'html'   → rendered HTML (Bard text, Bard without sets, Markdown)
 *   kind 'fields' → a structural node (Bard set, Replicator/Grid row, Group)
 *                   with scalar `items`: text | code | video | media
 * Rich children of a node follow as their own blocks one `depth` deeper.
 */

/** Indentation per nesting level, in rem. Capped so deep trees stay readable. */
export const INDENT_REM = 1;
export const MAX_INDENT_DEPTH = 6;

/** Depth as a safe non-negative integer. */
export function blockDepth(block) {
    const depth = Number(block?.depth ?? 0);

    return Number.isFinite(depth) && depth > 0 ? Math.floor(depth) : 0;
}

/**
 * Inline style for a block's indentation: nested blocks move in and get a
 * left rule, so the tree reads at a glance without nested boxes.
 */
export function indentStyle(block) {
    const depth = Math.min(blockDepth(block), MAX_INDENT_DEPTH);

    return depth === 0 ? {} : { marginInlineStart: `${depth * INDENT_REM}rem` };
}

/** What an editor calls the passage — drawer title, block header. */
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

/** Items of a fields block in a form the template can switch on. */
export function blockItems(block) {
    if (block?.kind !== 'fields') return [];

    return (block.items || []).filter((item) => item && ['text', 'code', 'video', 'media'].includes(item.kind));
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
