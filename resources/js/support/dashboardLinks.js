import { onBeforeUnmount, onMounted, reactive } from 'vue';

// The links between the dashboard's widgets: the stat row's cards
// (QueueStatsWidget) point at the "My queue" card and at "Alle Beiträge", and
// the cards 2-4 also narrow that table to the posts they count. The widgets are
// separate components with no parent of ours in common — the dashboard mounts
// each one on its own — so they meet here, in one module-level reactive store,
// instead of through props or a window event: a store also answers the question
// an event cannot, "is the other widget there at all?", for a card that renders
// before it.
//
// The anchors are QueueStats::MY_QUEUE_ANCHOR / ::OVERVIEW_ANCHOR in PHP. They
// are real element ids, so the cards are real `<a href="#…">` links and still
// land on the widget with nothing of this module involved.

export const MY_QUEUE_ANCHOR = 'workflows-my-queue';
export const OVERVIEW_ANCHOR = 'workflows-overview';

const store = reactive({
    // anchor => how many widgets carrying it are mounted (a dashboard may well
    // hold one widget type twice; the first one in the DOM is the target).
    present: {},
    // The overview filter a card asked for: {preset, label, workflows} or null.
    // One for the whole page — two overview widgets follow the same card.
    preset: null,
});

/**
 * Registers the calling widget as the target `anchor` points at, for as long
 * as it is mounted.
 */
export function useLinkTarget(anchor) {
    onMounted(() => {
        store.present[anchor] = (store.present[anchor] ?? 0) + 1;
    });

    onBeforeUnmount(() => {
        store.present[anchor] = Math.max(0, (store.present[anchor] ?? 0) - 1);
    });
}

/** Whether a widget carrying `anchor` is on this page. Reactive. */
export function hasLinkTarget(anchor) {
    return (store.present[anchor] ?? 0) > 0;
}

/**
 * Scrolls the widget into view and moves the focus onto it, the way an
 * in-page link does for a keyboard or screen-reader user — but smoothly, unless
 * the reader asked the system for less motion. The target carries
 * `tabindex="-1"`, so it can take the focus without joining the tab order;
 * `preventScroll` keeps the focus from cutting the smooth scroll short.
 *
 * Returns false when there is no such element, so the caller can fall back.
 */
export function jumpTo(anchor) {
    const target = document.getElementById(anchor);

    if (!target) return false;

    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    target.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
    target.focus({ preventScroll: true });

    return true;
}

/** The overview filter currently asked for, or null. Reactive. */
export function activePreset() {
    return store.preset;
}

/**
 * Narrows the overview to a card's posts. `workflows` is what the preset can
 * draw from (StatsPresets::scope()): one name makes the overview select that
 * workflow, several select "Alle".
 */
export function applyPreset({ preset, label, workflows }) {
    store.preset = { preset, label, workflows: workflows ?? [] };
}

export function clearPreset() {
    store.preset = null;
}
