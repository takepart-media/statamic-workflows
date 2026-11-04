<template>
    <div class="p-6">
        <!-- Statamic resolves the browser title as `title || bladeTitle`, so a
             page without a Head shows the layout's "Here ‹ Statamic" fallback. -->
        <Head :title="title" />

        <header class="mb-6 flex items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold dark:text-white">{{ title }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('workflows::messages.signed_in_as', { name: currentUser }) }}</p>
            </div>
            <CreatePostButton :creates="creates" />
        </header>

        <!-- The anchor of the dashboard's "In meiner Warteschlange" card when
             the "My queue" card is not on the dashboard (QueueStats). -->
        <section id="workflows-my-queue" tabindex="-1" class="mb-8 scroll-mt-4">
            <h2 class="mb-3 text-lg font-semibold dark:text-white">{{ __('workflows::messages.my_queue') }}</h2>
            <WorkflowListing
                :url="listingUrl"
                :columns="myColumns"
                :additional-parameters="{ ...base, mine: 1 }"
                :show-toolbar="false"
                @open-summary="openSummary"
            />
        </section>

        <section class="mb-8">
            <h2 class="mb-3 text-lg font-semibold dark:text-white">{{ __('workflows::messages.my_created') }}</h2>
            <WorkflowListing
                :url="listingUrl"
                :columns="myColumns"
                :additional-parameters="{ ...base, created_by_me: 1 }"
                :show-toolbar="false"
                @open-summary="openSummary"
            />
        </section>

        <section>
            <!-- Named by the server: a localization manager lists the posts it can
                 work on, not "all posts" — see WorkflowController::workflow(). -->
            <h2 class="mb-3 text-lg font-semibold dark:text-white">{{ listingHeading || __('workflows::messages.all_posts') }}</h2>

            <!-- One tab per governed collection, so entries of different
                 collections never share a table. `unmount-on-hide` must stay
                 false: the default unmounts the hidden tab's Listing, which
                 would drop its search, filters, sort and page on every switch.
                 A dedicated preferences prefix keeps each tab's column setup
                 separate instead of all tabs fighting over one key. -->
            <Tabs v-if="hasCollectionTabs" v-model="activeCollection" :unmount-on-hide="false">
                <TabList class="mb-3">
                    <TabTrigger
                        v-for="collection in collections"
                        :key="collection.handle"
                        :name="collection.handle"
                        :text="collection.title"
                    />
                </TabList>
                <TabContent
                    v-for="collection in collections"
                    :key="collection.handle"
                    :name="collection.handle"
                >
                    <WorkflowListing
                        :url="listingUrl"
                        :columns="columns"
                        :filters="filters"
                        :additional-parameters="{ ...base, collection: collection.handle }"
                        :preferences-prefix="`workflows.${workflowName}.${collection.handle}`"
                        :show-toolbar="true"
                        @open-summary="openSummary"
                    />
                </TabContent>
            </Tabs>

            <!-- A single (or no) collection has nothing to split — render the
                 plain listing so those managers look exactly as before. -->
            <WorkflowListing
                v-else
                :url="listingUrl"
                :columns="columns"
                :filters="filters"
                :additional-parameters="base"
                :show-toolbar="true"
                @open-summary="openSummary"
            />
        </section>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { TabContent, TabList, TabTrigger, Tabs } from '@statamic/cms/ui';
import { Head } from '@statamic/cms/inertia';
import CreatePostButton from '../components/CreatePostButton.vue';
import WorkflowListing from '../components/WorkflowListing.vue';
import { registerStateColors, registerStateLabels } from '../support/states.js';

const props = defineProps({
    currentUser: { type: String, default: '' },
    workflow: { type: Object, default: null },
    workflowName: { type: String, default: null },
    // Heading of the big listing. Null on the cross-workflow queue, which has no
    // single workflow to name it after.
    listingHeading: { type: String, default: null },
    listingUrl: { type: String, required: true },
    columns: { type: Array, default: () => [] },
    myColumns: { type: Array, default: () => [] },
    filters: { type: Array, default: () => [] },
    // Collections this workflow governs as [{handle, title}], in YAML order —
    // one tab each in the "all posts" listing.
    collections: { type: Array, default: () => [] },
    // This workflow's "new post" entry, as a one-item list (or empty when the
    // user can't create) — see CreatePostButton.
    creates: { type: Array, default: () => [] },
    // Place handle => Badge color, from each place's `metadata.color`.
    stateColors: { type: Object, default: () => ({}) },
    stateLabels: { type: Object, default: () => ({}) },
});

registerStateColors(props.stateColors);
registerStateLabels(props.stateLabels);

const title = computed(() => props.workflow?.label ?? 'Workflow');

// Scope the server-side listing to a single workflow when on a manager page.
const base = computed(() => (props.workflowName ? { workflow: props.workflowName } : {}));

// Splitting only pays off from two collections up. The bare /cp/workflows queue
// has no workflow (and thus no collections) and falls into the same branch.
const hasCollectionTabs = computed(() => props.collections.length > 1);

// `Tabs` runs controlled — its `modelValue` defaults to null and is forwarded
// straight to reka-ui, so without an explicit model no tab would ever be active.
// Seed it with the first collection.
const activeCollection = ref(props.collections[0]?.handle ?? '');

// A row title leads to the instance's own page. It used to open a slide-over
// Stack that fetched the summary payload over JSON and rendered
// WorkflowInstanceDetail inside it; the summary is a page now, so the click is a
// plain navigation. `row.url` comes from InstanceUrl, which already resolves the
// nav-friendly route per workflow — including the purpose-built translation view,
// which the Stack never could mount.
function openSummary(row) {
    if (row?.url) window.location.href = row.url;
}
</script>
