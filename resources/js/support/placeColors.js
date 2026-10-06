/**
 * The colour classes of a place, one entry per Badge colour — and the strings have
 * to be literal.
 *
 * The addon's Tailwind build uses `source(none)` with explicit `@source` globs
 * (resources/css/addon.css) and no safelist, so a class exists only if it appears
 * VERBATIM in a scanned file. Neither `border-${color}-500` nor
 * `var(--color-purple-500)` produces anything. Hence a map, not interpolation.
 *
 * Three parts per colour: the marker ring's `border`, the `dot` in its middle, and
 * the `ring` halo around it.
 *
 * Covers all 20 colours `stateColor()` can return — the 17 of
 * WorkflowDefinitionWriter::PLACE_COLORS plus `amber`, `red` and `yellow`, which a
 * project cannot pick but the built-in STATE_COLORS map in states.js does use.
 *
 * `!` on the dark variants because the addon's utilities sit in a lower cascade
 * layer than the CP stylesheet — the same workaround WorkflowHistory.vue uses.
 */
export const PLACE_COLOR_CLASSES = {
    default: { border: 'border-gray-500 dark:border-gray-400!', dot: 'bg-gray-500 dark:bg-gray-400!', ring: 'ring-gray-200 dark:ring-gray-700!' },
    black: { border: 'border-gray-900 dark:border-gray-100!', dot: 'bg-gray-900 dark:bg-gray-100!', ring: 'ring-gray-200 dark:ring-gray-700!' },
    white: { border: 'border-gray-400 dark:border-gray-300!', dot: 'bg-gray-400 dark:bg-gray-300!', ring: 'ring-gray-200 dark:ring-gray-700!' },
    blue: { border: 'border-blue-500 dark:border-blue-400!', dot: 'bg-blue-500 dark:bg-blue-400!', ring: 'ring-blue-100 dark:ring-blue-900!' },
    sky: { border: 'border-sky-500 dark:border-sky-400!', dot: 'bg-sky-500 dark:bg-sky-400!', ring: 'ring-sky-100 dark:ring-sky-900!' },
    cyan: { border: 'border-cyan-500 dark:border-cyan-400!', dot: 'bg-cyan-500 dark:bg-cyan-400!', ring: 'ring-cyan-100 dark:ring-cyan-900!' },
    teal: { border: 'border-teal-500 dark:border-teal-400!', dot: 'bg-teal-500 dark:bg-teal-400!', ring: 'ring-teal-100 dark:ring-teal-900!' },
    green: { border: 'border-green-500 dark:border-green-400!', dot: 'bg-green-500 dark:bg-green-400!', ring: 'ring-green-100 dark:ring-green-900!' },
    emerald: { border: 'border-emerald-500 dark:border-emerald-400!', dot: 'bg-emerald-500 dark:bg-emerald-400!', ring: 'ring-emerald-100 dark:ring-emerald-900!' },
    lime: { border: 'border-lime-500 dark:border-lime-400!', dot: 'bg-lime-500 dark:bg-lime-400!', ring: 'ring-lime-100 dark:ring-lime-900!' },
    orange: { border: 'border-orange-500 dark:border-orange-400!', dot: 'bg-orange-500 dark:bg-orange-400!', ring: 'ring-orange-100 dark:ring-orange-900!' },
    amber: { border: 'border-amber-500 dark:border-amber-400!', dot: 'bg-amber-500 dark:bg-amber-400!', ring: 'ring-amber-100 dark:ring-amber-900!' },
    yellow: { border: 'border-yellow-500 dark:border-yellow-400!', dot: 'bg-yellow-500 dark:bg-yellow-400!', ring: 'ring-yellow-100 dark:ring-yellow-900!' },
    red: { border: 'border-red-500 dark:border-red-400!', dot: 'bg-red-500 dark:bg-red-400!', ring: 'ring-red-100 dark:ring-red-900!' },
    rose: { border: 'border-rose-500 dark:border-rose-400!', dot: 'bg-rose-500 dark:bg-rose-400!', ring: 'ring-rose-100 dark:ring-rose-900!' },
    pink: { border: 'border-pink-500 dark:border-pink-400!', dot: 'bg-pink-500 dark:bg-pink-400!', ring: 'ring-pink-100 dark:ring-pink-900!' },
    fuchsia: { border: 'border-fuchsia-500 dark:border-fuchsia-400!', dot: 'bg-fuchsia-500 dark:bg-fuchsia-400!', ring: 'ring-fuchsia-100 dark:ring-fuchsia-900!' },
    purple: { border: 'border-purple-500 dark:border-purple-400!', dot: 'bg-purple-500 dark:bg-purple-400!', ring: 'ring-purple-100 dark:ring-purple-900!' },
    violet: { border: 'border-violet-500 dark:border-violet-400!', dot: 'bg-violet-500 dark:bg-violet-400!', ring: 'ring-violet-100 dark:ring-violet-900!' },
    indigo: { border: 'border-indigo-500 dark:border-indigo-400!', dot: 'bg-indigo-500 dark:bg-indigo-400!', ring: 'ring-indigo-100 dark:ring-indigo-900!' },
};

// The classes for a Badge colour, falling back to the neutral grey.
export function placeColorClasses(color) {
    return PLACE_COLOR_CLASSES[color] ?? PLACE_COLOR_CLASSES.default;
}
