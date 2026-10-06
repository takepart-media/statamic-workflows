<template>
    <!-- "Alle Beiträge": the manager pages' pivot listing, across every scoped
         workflow at once — one row per post, a workflow column, and one state
         column per site any of those workflows manages.

         The shell is the CP's own `Widget`; the listing renders `bare` inside it,
         so the card is not drawn twice. -->
    <Widget :title="__('workflows::messages.all_posts')">
        <!-- One "new post" button per workflow the user may create in. Named
             after its workflow as soon as there is more than one, since a bare
             "Neuer Beitrag" twice says nothing about where the post goes. -->
        <template v-if="creates.length" #actions>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <CreatePostButton
                    v-for="create in creates"
                    :key="create.workflow"
                    size="sm"
                    :create-url="create.createUrl"
                    :create-collections="create.createCollections ?? []"
                    :create-cta="create.createCta ?? null"
                    :workflow-name="create.workflow"
                    :text="creates.length > 1 ? __('workflows::messages.new_post_in', { label: create.label }) : null"
                />
            </div>
        </template>

        <div class="min-w-0 flex-1 px-4 pt-2">
            <WorkflowListing
                :url="listingUrl"
                :columns="columns"
                :filters="filters"
                :additional-parameters="parameters"
                preferences-prefix="workflows.overview"
                :per-page="perPage"
                :show-toolbar="true"
                bare
                @open-summary="openSummary"
            />
        </div>
    </Widget>
</template>

<script setup>
import { computed } from 'vue';
import { Widget } from '@statamic/cms/ui';
import CreatePostButton from './CreatePostButton.vue';
import WorkflowListing from './WorkflowListing.vue';
import { registerStateColors, registerStateLabels } from '../support/states.js';

/**
 * Dashboard widget `workflows_overview`. Everything but `perPage` and
 * `workflows` is WorkflowController::overviewProps() as-is; the rows come from
 * the regular listing endpoint with `all_workflows=1`, so search, filters, sort
 * and paging are the manager pages' own.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    columns: { type: Array, default: () => [] },
    filters: { type: Array, default: () => [] },
    // Place handle => Badge color / label, across all workflows.
    stateColors: { type: Object, default: () => ({}) },
    stateLabels: { type: Object, default: () => ({}) },
    // [{workflow, label, createUrl, createCta, createCollections}] — one per
    // workflow the current user may create a post in.
    creates: { type: Array, default: () => [] },
    // Rows per page. The widget's `per_page` config.
    perPage: { type: Number, default: 15 },
    // The widget's `workflows` config, or null for every active workflow. Sent
    // along so the listing spans the same workflows the columns were built for.
    workflows: { type: Array, default: null },
});

// The state Badges resolve their colours and labels through this registry, the
// same one a manager page seeds.
registerStateColors(props.stateColors);
registerStateLabels(props.stateLabels);

const parameters = computed(() => ({
    all_workflows: 1,
    ...(props.workflows?.length ? { workflows: props.workflows.join(',') } : {}),
}));

// Same as on a manager page: a title click is a plain navigation to the
// instance's own page (`row.url`).
function openSummary(row) {
    if (row?.url) window.location.href = row.url;
}
</script>
