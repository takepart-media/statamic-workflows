 // Shared helpers for rendering workflow states consistently across pages.

// Map workflow states to Statamic Badge colors. Server-side mirror:
// DefinitionLoader::STATE_COLORS (the dashboard widgets resolve the same
// states in PHP) — keep the two in sync.
const STATE_COLORS = {
    draft: 'default',
    in_review: 'blue',
    linguistic_review: 'blue',
    expert_review: 'amber',
    queued: 'default',
    script_generation: 'purple',
    voiceover: 'purple',
    rendering: 'purple',
    review: 'amber',
    source_ready: 'default',
    in_translation: 'blue',
    published: 'green',
    pending_revision: 'orange',
    approved: 'green',
    done: 'green',
    failed: 'red',
};

// Colors configured per place via `metadata.color` in the workflow YAML, keyed
// by place handle. Seeded once per page from the `stateColors` Inertia prop —
// keeping it module-level means child components (VersionListing, WorkflowListing)
// resolve colors without prop drilling, and stateColor() keeps its signature.
let configuredColors = {};

export function registerStateColors(colors) {
    configuredColors = colors && typeof colors === 'object' ? colors : {};
}

// The place names, from the `stateLabels` prop — ALREADY RESOLVED by the server
// (DefinitionLoader::placeLabels() → stateLabel(), in the CP's locale): the author's
// name for this language, else the language file, else another name the author
// gave. Taking them as they are keeps badge and filter chip in agreement without
// repeating that precedence here. See humanize().
let configuredLabels = {};

export function registerStateLabels(labels) {
    configuredLabels = labels && typeof labels === 'object' ? labels : {};
}

export function stateColor(state) {
    // Configured wins, then the built-in defaults, then neutral grey. Places
    // without an explicit color keep the colors they have always had.
    return configuredColors[state] ?? STATE_COLORS[state] ?? 'default';
}

export function humanize(value) {
    if (!value) return '';

    // The server's resolution first — it already put the author's own name for
    // this language above the language file.
    if (configuredLabels[value]) {
        return configuredLabels[value];
    }

    // A place no workflow names (a shipped or leftover handle): the language file.
    const key = `workflows::messages.state_${value}`;
    const translated = typeof __ === 'function' ? __(key) : key;
    if (translated && translated !== key) {
        return translated;
    }

    // Last resort: the handle, title-cased. Only reached for a place with neither —
    // typically a leftover state row whose place was removed.
    return value
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}
