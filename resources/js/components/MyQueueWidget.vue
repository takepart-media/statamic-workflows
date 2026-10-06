<template>
    <!-- "Meine Warteschlange": everything assigned to the current user, across
         every active workflow, most urgent first — with a tab row to narrow it
         to one workflow.

         The shell is the CP's own `Widget`: the canonical dashboard card, header
         included. Nothing here re-draws a card. -->
    <Widget :title="__('workflows::messages.my_queue')">
        <!-- The filter. A tab row rather than a select, because the counts are
             the point: they are visible at a glance without opening anything,
             and they are the only place the card admits how much work there
             really is (only `limit` rows per view ever travel).

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

            <!-- One panel per tab, each rendering its own slice. `Tabs` unmounts
                 the hidden ones, so only the active slice exists in the DOM. -->
            <TabContent v-for="tab in allTabs" :key="tab.key" :name="tab.key">
                <template v-if="rowsFor(tab.key).length">
                    <PersonalWidgetRow v-for="row in rowsFor(tab.key)" :key="row.url" :row="row" with-rail with-meta />
                </template>
                <Description v-else class="py-6 text-center">
                    {{ __('workflows::messages.widget_no_assigned') }}
                </Description>
            </TabContent>
        </Tabs>

        <!-- Follows the active tab, so it is computed here rather than shipped;
             see `hidden` below. -->
        <template #footer>
            <PersonalWidgetFooter v-if="hidden.length" :entries="hidden" />
        </template>
    </Widget>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Badge, Description, TabContent, TabList, TabTrigger, Tabs, Widget } from '@statamic/cms/ui';
import PersonalWidgetRow from './PersonalWidgetRow.vue';
import PersonalWidgetFooter from './PersonalWidgetFooter.vue';

/**
 * Dashboard widget `workflows_my_queue`. A Vue component rather than Blade for
 * the tab row: filtering by workflow has to be instant, and a server-rendered
 * card would have reloaded the whole dashboard to answer a question the payload
 * already contains.
 *
 * The payload is deliberately more than any single view needs — per workflow its
 * `limit` most urgent rows, merged and pre-sorted by (urgency, title) in
 * MyQueue::payload(). That is what lets this component do no sorting at all:
 * "Alle" is the first `limit` of `rows`, a workflow tab is a filter over it. The
 * counts in `tabs` are the FULL totals, so the footer can subtract.
 */
const props = defineProps({
    // [{key, label, color, count, url}] — one per workflow that has rows, in
    // workflow (= nav) order. `url` is the manager page, used by the footer.
    tabs: { type: Array, default: () => [] },
    // Rows from PersonalWorkItems, pre-sorted; see the note above.
    rows: { type: Array, default: () => [] },
    // Rows per view. The widget's `limit` config.
    limit: { type: Number, default: 7 },
});

// 'all' rather than the first workflow: the card's job on a dashboard is the
// cross-workflow view, and the per-workflow ones are the drill-down.
const activeTab = ref('all');

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

/**
 * The rows one tab shows. A function rather than a computed because every tab
 * panel asks for its own slice; the active one is `visible` below.
 */
function rowsFor(key) {
    return key === 'all'
        ? props.rows.slice(0, props.limit)
        // Already at most `limit` long, and already in urgency order — the
        // server trimmed per workflow before merging.
        : props.rows.filter((row) => row.workflow === key);
}

const visible = computed(() => rowsFor(activeTab.value));

/**
 * The footer breakdown, per workflow: its total minus however many of its rows
 * the current view happens to show, carrying that workflow's manager URL so the
 * "+N" can link to where the hidden rows are. Computed against `visible` rather
 * than against `limit`, because the two differ per tab — on "Alle" a workflow may
 * contribute three of the seven rows, on its own tab all seven.
 */
const hidden = computed(() => {
    const shown = visible.value.reduce((counts, row) => {
        counts[row.workflow] = (counts[row.workflow] ?? 0) + 1;
        return counts;
    }, {});

    return props.tabs
        .filter((tab) => activeTab.value === 'all' || tab.key === activeTab.value)
        .map((tab) => ({
            key: tab.key,
            label: tab.label,
            color: tab.color,
            url: tab.url,
            count: tab.count - (shown[tab.key] ?? 0),
        }))
        .filter((entry) => entry.count > 0);
});
</script>
