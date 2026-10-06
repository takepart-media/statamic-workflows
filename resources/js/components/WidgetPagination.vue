<template>
    <!-- The pager of the personal dashboard cards. The kit `Pagination` — the
         component the "Alle Beiträge" card's `ListingPagination` renders — with
         the Listing's own defaults (totals line plus page-number buttons), in the
         same `px-4 py-2` wrapper the bare listing uses. Nothing at all when the
         list fits on one page: a "1–3 of 3" line would only restate the card.

         Unless the card `reserve`s its paged height (MyQueue, on a tab that fits
         one page while "Alle" does not): then the pager is still rendered, but
         invisible and inert, and with a second page faked into its meta so it
         draws its page buttons — exactly as tall as the real one, so switching
         tabs does not move the card's bottom edge. -->
    <div
        v-if="paged || reserve"
        class="px-4 py-2"
        :class="{ invisible: !paged }"
        :inert="!paged || null"
        :aria-hidden="paged ? null : 'true'"
    >
        <Pagination
            :resource-meta="paged ? meta : { ...meta, last_page: 2 }"
            :per-page="meta.per_page"
            show-totals
            show-page-links
            :show-per-page-selector="false"
            :scroll-to-top="false"
            @page-selected="$emit('page-selected', $event)"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Pagination } from '@statamic/cms/ui';

/**
 * Shared by MyQueue, Watched and CreatedByMe; the paging itself is
 * `useClientPagination` (support/pagination.js), which builds `meta`.
 *
 * Two of the Listing's defaults are switched off on purpose:
 * - the per-page selector: its options are the CP's global listing sizes
 *   (paginationSizeOptions), which have nothing to do with a card's `limit` —
 *   the configured 5 or 7 would not even be among them. The page size is the
 *   widget config.
 * - scroll-to-top: it scrolls the WINDOW, i.e. jumps the dashboard to its top
 *   when paging a card further down.
 */
const props = defineProps({
    // Laravel-resource-shaped meta: {current_page, last_page, per_page, total, from, to}.
    meta: { type: Object, required: true },
    // Keep the pager's height while there is only one page (see the template).
    reserve: { type: Boolean, default: false },
});

const paged = computed(() => props.meta.last_page > 1);

defineEmits(['page-selected']);
</script>
