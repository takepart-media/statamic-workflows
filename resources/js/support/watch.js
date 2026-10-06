import { ref } from 'vue';

/**
 * The bookmark button on both detail pages: one `watching` flag, flipped
 * optimistically and put back with a toast when the request fails. POST starts
 * watching, DELETE on the same URL stops (WorkflowController::watch/unwatch).
 *
 * `axios` is the CP's instance when the page has one — it carries the CSRF
 * token; the fetch fallback reads it from the meta tag like the widgets do.
 */
export function useWatch(initial, url, axios) {
    const watching = ref(Boolean(initial));
    const busy = ref(false);

    async function send(method) {
        if (axios) {
            return axios[method === 'POST' ? 'post' : 'delete'](url);
        }
        const response = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });
        if (!response.ok) throw new Error(String(response.status));
        return response;
    }

    async function toggle() {
        if (busy.value || !url) return;
        const next = !watching.value;
        watching.value = next;
        busy.value = true;
        try {
            await send(next ? 'POST' : 'DELETE');
        } catch (e) {
            watching.value = !next;
            window.Statamic?.$toast?.error(__('workflows::messages.watch_error'));
        } finally {
            busy.value = false;
        }
    }

    return { watching, busy, toggle };
}
