// Icons Statamic's set does not ship. Statamic's <Icon> (and every `icon` prop
// that reaches it) renders a name starting with `<svg` as sanitised markup, so
// these go straight into `:icon` without registering a set.

// Statamic's `bookmark` with the diagonal stroke of its `eye-slash`: "stop
// watching". Same 14×14 box and stroke style, so it sits next to the stock icons.
export const BOOKMARK_SLASH = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">'
    + '<path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M2.167 12.673c.029.48.4.88.87.821 1.27-.16 2.597-.971 3.963-1.873 1.366.902 2.693 1.713 3.963 1.873.47.06.841-.34.87-.821a88 88 0 0 0 .025-10.394A1.53 1.53 0 0 0 10.564.86C9.477.69 8.369.5 7 .5S4.523.69 3.436.861A1.53 1.53 0 0 0 2.142 2.28a88 88 0 0 0 .025 10.394"/>'
    + '<path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M0.5 0.5 13.5 13.5"/>'
    + '</svg>';
