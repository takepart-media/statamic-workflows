<template>
    <!-- "Meine Warteschlange": everything assigned to the current user, across
         every active workflow, most urgent first — with a tab row to narrow it
         to one workflow.

         The shell is the CP's own `Widget`: the canonical dashboard card, header
         included. Nothing here re-draws a card. `h-full` lets it fill its grid
         cell, which the dashboard row stretches to its tallest card. -->
    <!-- `id` + `tabindex="-1"`: the target of the stat row's "In meiner
         Warteschlange" card (support/dashboardLinks.js), which scrolls here
         and moves the focus onto the card without adding it to the tab order. -->
    <Widget :id="MY_QUEUE_ANCHOR" tabindex="-1" :title="__('workflows::messages.my_queue')" class="h-full scroll-mt-4">
        <!-- The filter. A tab row rather than a select, because the counts are
             the point: they are visible at a glance without opening anything,
             and they say how much work there is before anyone pages.

             Statamic's own `Tabs`/`TabList`/`TabTrigger`/`TabContent` — the same
             set the publish form uses, so the keyboard behaviour and the DOM
             structure are the CP's, not ours. The look (indicator colour, list
             margin/padding, trigger padding) is retuned in addon.css via the
             `wf-widget-tabs` marker and the components' own `data-ui-tabs-*`
             hooks, so every correction lives in one place. -->
        <Tabs v-model="activeTab" class="wf-widget-tabs flex min-h-0 flex-1 flex-col">
            <TabList>
                <TabTrigger v-for="tab in allTabs" :key="tab.key" :name="tab.key">
                    <span class="flex items-center gap-1.5">
                        {{ tab.label }}
                        <!-- The counter as a Badge, coloured only through its
                             `color` prop: blue while the tab is the active one,
                             neutral otherwise. -->
                        <Badge
                            :text="String(tab.count)"
                            :color="tab.key === activeTab ? 'blue' : 'default'"
                            size="sm"
                        />
                    </span>
                </TabTrigger>
            </TabList>

            <!-- One panel per tab. `Tabs` unmounts the hidden ones, so only the
                 active panel exists — which is why every panel can simply
                 render the active tab's current page. -->
            <TabContent v-for="tab in allTabs" :key="tab.key" :name="tab.key" class="flex flex-1 flex-col">
                <template v-if="pageItems.length">
                    <PersonalWidgetRow v-for="row in pageItems" :key="row.url" :row="row" with-rail with-meta />
                    <!-- Invisible stand-ins up to a full page, so a short page
                         or a smaller tab does not shrink the card. -->
                    <PersonalWidgetRow
                        v-for="(row, index) in fillers"
                        :key="`filler-${index}`"
                        :row="row"
                        with-rail
                        with-meta
                        placeholder
                    />
                </template>
                <Description v-else class="flex flex-1 items-center justify-center py-6 text-center">
                    {{ __('workflows::messages.widget_no_assigned') }}
                </Description>
            </TabContent>
        </Tabs>

        <!-- Pages the active tab. On a tab that fits one page it only holds its
             place (invisibly) while "Alle" is paged, else it is gone. -->
        <template #footer>
            <WidgetPagination :meta="meta" :reserve="reserved" @page-selected="setPage" />
        </template>
    </Widget>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { Badge, Description, TabContent, TabList, TabTrigger, Tabs, Widget } from '@statamic/cms/ui';
import PersonalWidgetRow from './PersonalWidgetRow.vue';
import WidgetPagination from './WidgetPagination.vue';
import { useClientPagination } from '../support/pagination';
import { MY_QUEUE_ANCHOR, useLinkTarget } from '../support/dashboardLinks.js';

/**
 * Dashboard widget `workflows_my_queue`. A Vue component rather than Blade for
 * the tab row: filtering by workflow has to be instant, and a server-rendered
 * card would have reloaded the whole dashboard to answer a question the payload
 * already contains.
 *
 * The payload is the user's WHOLE queue, pre-sorted by (urgency, title) in
 * MyQueue::payload(). That is what lets this component do no sorting and no
 * fetching at all: "Alle" pages through `rows`, a workflow tab pages through a
 * filter over it, `limit` rows per page.
 */
const props = defineProps({
    // [{key, label, color, count}] — one per workflow that has rows, in
    // workflow (= nav) order.
    tabs: { type: Array, default: () => [] },
    // Rows from PersonalWorkItems, pre-sorted; see the note above.
    rows: { type: Array, default: () => [] },
    // Rows per page. The widget's `limit` config.
    limit: { type: Number, default: 7 },
});

// 'all' rather than the first workflow: the card's job on a dashboard is the
// cross-workflow view, and the per-workflow ones are the drill-down.
const activeTab = ref('all');

useLinkTarget(MY_QUEUE_ANCHOR);

const allTabs = computed(() => [
    {
        key: 'all',
        label: __('workflows::messages.widget_tab_all'),
        // Summed here rather than shipped: it is the same number as the sum of
        // the tabs, and two sources for one figure eventually disagree.
        count: props.tabs.reduce((total, tab) => total + tab.count, 0),
    },
    ...props.tabs,
]);

/** The active tab's rows, all of them — the pager slices. */
const tabRows = computed(() =>
    activeTab.value === 'all' ? props.rows : props.rows.filter((row) => row.workflow === activeTab.value),
);

// The card keeps a full page's height on EVERY tab as soon as "Alle" — the
// superset, so the longest tab — needs a second page: switching to a tab with
// three rows then changes the rows, not the card's height or where its pager
// sits. A queue that fits one page altogether reserves nothing.
const { pageItems, meta, setPage, reserved, fillers } = useClientPagination(tabRows, () => props.limit, {
    reserve: () => props.rows.length > props.limit,
});

// Each tab starts on its first page: page 3 of "Alle" means nothing on a tab
// that may only have one.
watch(activeTab, () => setPage(1));
</script>
