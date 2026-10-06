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

        <div v-if="rows.length" class="flex-1">
            <PersonalWidgetRow v-for="row in rows" :key="row.url" :row="row" />
        </div>
        <Description v-else class="flex-1 py-6 text-center">
            {{ __('workflows::messages.widget_no_created') }}
        </Description>

        <!-- Per workflow, what the limit cut — already aggregated server-side,
             unlike the queue's, whose footer has to follow the active tab. -->
        <template #footer>
            <PersonalWidgetFooter v-if="more.length" :entries="more" />
        </template>
    </Widget>
</template>

<script setup>
import { Description, Widget } from '@statamic/cms/ui';
import PersonalWidgetRow from './PersonalWidgetRow.vue';
import PersonalWidgetFooter from './PersonalWidgetFooter.vue';

/**
 * Dashboard widget `workflows_created_by_me`. A component widget for symmetry
 * rather than for interactivity — it has no client state at all: the rows arrive
 * sorted and trimmed, the footer aggregate arrives computed
 * (PresentsPersonalEntries::overflow()). What it buys is the shared row
 * component: this card and the queue sit next to each other, and their rows have
 * to be pixel-identical.
 *
 * The meta line is not rendered here. It would restate the sort key ("last
 * edited") once per row.
 */
defineProps({
    // Rows from PersonalWorkItems, newest edit first, already cut to `limit`.
    rows: { type: Array, default: () => [] },
    // [{count, label, color, url}] per workflow with hidden rows; `url` is that
    // workflow's manager page, null where it has none.
    more: { type: Array, default: () => [] },
});
</script>
