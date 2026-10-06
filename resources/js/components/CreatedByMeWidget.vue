<template>
    <!-- "Von mir erstellt": what the current user brought into a workflow, newest
         edit first. Same shell (the CP's `Widget`) and the same rows as the queue
         beside it; no tab row, no meta line — see the script note. -->
    <Widget :title="__('workflows::messages.my_created')">
        <!-- The subline is the card's sort order, stated: without it a list in no
             obvious sequence looks arbitrary. `Widget` has no subtitle prop, so it
             opens the body — bordered like the queue's tab row, so the two cards
             stay the same height below their headers. -->
        <Description class="border-b border-gray-200 px-4.5 py-2 dark:border-gray-700">
            {{ __('workflows::messages.widget_created_subline') }}
        </Description>

        <div v-if="pageItems.length" class="flex-1">
            <PersonalWidgetRow v-for="row in pageItems" :key="row.url" :row="row" />
        </div>
        <Description v-else class="flex-1 py-6 text-center">
            {{ __('workflows::messages.widget_no_created') }}
        </Description>

        <!-- Hides itself while everything fits on one page. -->
        <template #footer>
            <WidgetPagination :meta="meta" @page-selected="setPage" />
        </template>
    </Widget>
</template>

<script setup>
import { Description, Widget } from '@statamic/cms/ui';
import PersonalWidgetRow from './PersonalWidgetRow.vue';
import WidgetPagination from './WidgetPagination.vue';
import { useClientPagination } from '../support/pagination';

/**
 * Dashboard widget `workflows_created_by_me`. A component widget for symmetry
 * rather than for interactivity — its only client state is the current page: the
 * rows arrive complete and sorted, `limit` rows per page. What it buys is the
 * shared row
 * component: this card and the queue sit next to each other, and their rows have
 * to be pixel-identical.
 *
 * The meta line is not rendered here. It would restate the sort key ("last
 * edited") once per row.
 */
const props = defineProps({
    // Rows from PersonalWorkItems, newest edit first — all of them.
    rows: { type: Array, default: () => [] },
    // Rows per page. The widget's `limit` config.
    limit: { type: Number, default: 5 },
});

const { pageItems, meta, setPage } = useClientPagination(() => props.rows, () => props.limit);
</script>
