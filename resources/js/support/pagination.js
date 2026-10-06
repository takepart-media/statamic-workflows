import { computed, ref, unref, watch } from 'vue';

/**
 * Client-side paging over a list that is already complete in the browser — the
 * three personal dashboard cards (MyQueue, Watched, CreatedByMe), whose payload
 * is the user's whole list (PersonalWorkItems returns it untruncated anyway).
 *
 * `items` and `perPage` may be refs, getters or plain values. Returns the
 * current `page` (a ref, 1-based), the slice it shows (`pageItems`) and `meta`
 * in the shape of a Laravel resource `meta` — what the kit `Pagination` takes
 * as `resourceMeta`, so the card's pager is the Listing's own component.
 *
 * The page is clamped whenever the list shrinks (an unwatched row) so the pager
 * never points past the end: the last page emptying steps back one page.
 *
 * `fillers` is what keeps a paged card from jumping: while the card is
 * `reserved`, it is the rows the current page is short of a full one — copies
 * of the page's first row, which the card renders as invisible placeholders
 * (PersonalWidgetRow's `placeholder`). A copy of a real row rather than a
 * computed min-height, because the copy is exactly as tall as the rows it
 * stands in for, whatever the font, the badge or the zoom level. The short last
 * page therefore keeps a full page's height and the pager stays where it was.
 * `reserved` defaults to "there is more than one page"; a card whose list
 * changes under the pager (MyQueue's tabs) passes its own `reserve`. Nothing is
 * reserved while everything fits on one page.
 */
export function useClientPagination(items, perPage, { reserve } = {}) {
    const page = ref(1);

    const list = computed(() => resolve(items) ?? []);
    const size = computed(() => Math.max(1, Number(resolve(perPage)) || 1));
    const lastPage = computed(() => Math.max(1, Math.ceil(list.value.length / size.value)));

    watch(lastPage, (last) => {
        if (page.value > last) page.value = last;
    });

    const pageItems = computed(() => {
        const start = (page.value - 1) * size.value;
        return list.value.slice(start, start + size.value);
    });

    const meta = computed(() => {
        const total = list.value.length;
        const from = total ? (page.value - 1) * size.value + 1 : 0;
        return {
            current_page: page.value,
            last_page: lastPage.value,
            per_page: size.value,
            total,
            from,
            to: total ? Math.min(from + size.value - 1, total) : 0,
        };
    });

    const reserved = computed(() => (reserve === undefined ? lastPage.value > 1 : Boolean(resolve(reserve))));

    const fillers = computed(() => {
        const missing = reserved.value ? size.value - pageItems.value.length : 0;
        const template = pageItems.value[0];
        return missing > 0 && template ? Array.from({ length: missing }, () => template) : [];
    });

    function setPage(next) {
        page.value = Math.min(Math.max(1, Number(next) || 1), lastPage.value);
    }

    return { page, pageItems, meta, setPage, reserved, fillers };
}

function resolve(source) {
    return typeof source === 'function' ? source() : unref(source);
}
