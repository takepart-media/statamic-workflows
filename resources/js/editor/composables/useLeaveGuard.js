// "You have unsaved changes" — before the tab closes or reloads, and before an
// Inertia visit takes the CP somewhere else.
//
// Statamic 6 has its own registry for this (`$dirty`, also behind the edit forms):
// a name in it makes the CP confirm Inertia visits and back/forward navigation,
// honouring the user's "confirm dirty navigation" preference. When it is there
// the editor registers with it and leaves the Inertia side to the CP — a second
// router listener would ask twice. Without it (older CP, tests) the editor asks
// itself. The browser's own beforeunload prompt is always armed while dirty:
// losing a graph to an accidental reload is the one case worth being stubborn
// about.

import { getCurrentInstance, onBeforeUnmount, onMounted, watch } from 'vue';
import { router } from '@statamic/cms/inertia';

/**
 * @param {() => boolean} isDirty
 * @param {object} [options]
 * @param {string} [options.name]     the name in Statamic's dirty registry
 * @param {() => string} [options.message]
 */
export function useLeaveGuard(isDirty, {
    name = 'workflows-graph-editor',
    message = () => __('workflows::messages.editor_leave_unsaved'),
} = {}) {
    const globals = getCurrentInstance()?.appContext?.config?.globalProperties;
    const registry = globals?.$dirty ?? globalThis.Statamic?.$dirty ?? null;
    const hasRegistry = !!(registry && typeof registry.add === 'function' && typeof registry.remove === 'function');

    function onBeforeUnload(event) {
        if (!isDirty()) return;
        event.preventDefault();
        // Older browsers want a returnValue; the text itself is never shown.
        event.returnValue = '';
    }

    let offBefore = null;

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);
        if (!hasRegistry && router?.on) {
            offBefore = router.on('before', (event) => {
                const visit = event?.detail?.visit;
                if (visit?.prefetch || !isDirty()) return;
                // Returning false cancels the visit.
                return window.confirm(message());
            });
        }
    });

    const stopWatch = hasRegistry
        ? watch(isDirty, (dirty) => (dirty ? registry.add(name) : registry.remove(name)), { immediate: true })
        : null;

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        offBefore?.();
        offBefore = null;
        stopWatch?.();
        if (hasRegistry) registry.remove(name);
    });
}
