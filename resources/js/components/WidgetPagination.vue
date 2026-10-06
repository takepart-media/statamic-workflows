<template>
    <!-- The pager of the personal dashboard cards. The kit `Pagination` — the
         component the "Alle Beiträge" card's `ListingPagination` renders — with
         the Listing's own defaults (totals line plus page-number buttons), in the
         same `px-4 py-2` wrapper the bare listing uses. Nothing at all when the
         list fits on one page: a "1–3 of 3" line would only restate the card. -->
    <div v-if="meta.last_page > 1" class="px-4 py-2">
        <Pagination
            :resource-meta="meta"
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
defineProps({
    // Laravel-resource-shaped meta: {current_page, last_page, per_page, total, from, to}.
    meta: { type: Object, required: true },
});

defineEmits(['page-selected']);
</script>
