// Colour-name → CSS-class helpers for the rows and footers of the two personal
// dashboard cards (MyQueueWidget, CreatedByMeWidget), plus the colour-name → hex
// map the status row's rings need (see `colorHex` at the bottom, and the note
// there for why exactly one place in the addon is allowed to know a hex in JS).
// The classes themselves live
// in resources/css/addon.css — see the long note there for why they are classes
// and not inline styles, why they are the design's own hexes rather than the CP's
// `Badge`, and where the values come from.
//
// Shared rather than duplicated per card: both render the same workflow dot, and
// a palette that drifts between two cards sitting side by side on one dashboard
// is worse than no palette.
//
// The STATE is deliberately not in here. It is the kit's `Badge`, coloured through
// its own `color` prop — the same binding the workflow manager listings use, so a
// state looks identical wherever it is shown.

// The colour names addon.css actually paints. A name arrives from the settings
// — `metadata.color` on a place or a workflow — so it can be anything a human
// typed; anything unrecognised has to land somewhere visible rather than
// produce a class with no rule behind it and an invisible pill.
const NAMES = [
    'default',
    'blue',
    'sky',
    'cyan',
    'indigo',
    'purple',
    'violet',
    'fuchsia',
    'pink',
    'red',
    'rose',
    'orange',
    'amber',
    'yellow',
    'lime',
    'teal',
    'green',
    'emerald',
];

function known(color) {
    return NAMES.includes(color) ? color : 'default';
}

// The 3px urgency rail at the left edge of a queue row, in the STATE colour.
export function railClass(color) {
    return `wf-rail--${known(color)}`;
}

// The 6px dot in front of a workflow label (row) or a "+N" entry (footer), in
// the WORKFLOW colour. The status row (WorkflowStatusWidget) draws the same dot
// at 8px — the size is a utility on the element, the class only carries colour.
export function dotClass(color) {
    return `wf-dot--${known(color)}`;
}

/**
 * Colour name → hex, for the one place in the addon that cannot use a CSS
 * class: the doughnut rings of the status row. Chart.js paints into a canvas
 * and takes colours as VALUES — there is no element to hang `.wf-dot--blue` on.
 *
 * The legend squares beside each ring bind the same map through an inline
 * `style` rather than a class, and that is on purpose: a legend square IS the
 * ring's colour key: if the two came from different tables they could drift,
 * and a legend that disagrees with its chart is worse than no legend. This is
 * chart data expressed as a colour, not styling done by hand.
 *
 * The values are the STATUS palette and are close to, but not the same as, the
 * dot hexes in addon.css — `green`, `blue` and `orange` differ. That is
 * deliberate: these are read as filled areas of a ring, side by side with each
 * other, where the dots are 6px specks read one at a time. Both tables are
 * keyed on the same settings-driven colour NAMES, so nothing has to know which
 * of the two it is looking at.
 *
 * Grouped names (green/emerald, purple/violet, fuchsia/pink, amber/yellow) get
 * one value each: at ring scale, two hues one step apart read as one colour
 * plus a rendering artefact.
 */
const HEXES = {
    green: '#10b981',
    emerald: '#10b981',
    lime: '#84cc16',
    teal: '#14b8a6',
    cyan: '#06b6d4',
    blue: '#1c60d8',
    sky: '#38bdf8',
    indigo: '#6366f1',
    purple: '#8b5cf6',
    violet: '#8b5cf6',
    fuchsia: '#ec4899',
    pink: '#ec4899',
    default: '#d1d5db',
    gray: '#d1d5db',
    amber: '#f59e0b',
    yellow: '#f59e0b',
    orange: '#fb923c',
    red: '#ef4444',
    rose: '#f43f5e',
};

// Unknown names land on neutral grey, the same fallback `known()` applies to
// the class helpers: a typo'd `metadata.color` must not produce an invisible
// (or `undefined`-black) segment.
export function colorHex(color) {
    return HEXES[color] ?? HEXES.default;
}
