<template>
    <!-- "Alle Beiträge": the manager pages' pivot listing, across every scoped
         workflow at once — one row per post, a workflow column, and one state
         column per site any of those workflows manages. A button group above
         the table narrows it to one workflow; it opens on the authoring one.

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
            <!-- One button per scoped workflow (its dot, as in the workflow
                 column), single-select, plus "All" for the mixed table. Pointless
                 with a single workflow in scope, so it only shows from two up. -->
            <ButtonGroup v-if="workflowOptions.length > 1" class="mb-3">
                <Button
                    v-for="option in workflowOptions"
                    :key="option.value"
                    size="sm"
                    :variant="active === option.value ? 'pressed' : 'default'"
                    :aria-pressed="active === option.value"
                    @click="active = option.value"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <span class="size-[6px] flex-none rounded-full" :class="dotClass(option.color)" aria-hidden="true"></span>
                        {{ option.label }}
                    </span>
                </Button>
                <Button
                    size="sm"
                    :variant="active === ALL ? 'pressed' : 'default'"
                    :aria-pressed="active === ALL"
                    :text="__('workflows::messages.all_workflows')"
                    @click="active = ALL"
                />
            </ButtonGroup>

            <!-- Keyed by the selection, so a switch remounts the listing rather
                 than only changing its parameters: Listing would refetch on its
                 own, but keep sending the old column visibility (the endpoint
                 then hides the site columns the new scope adds), keep the old
                 cell slots, filters and page. Remounted it starts on page 1 with
                 exactly the columns and filters of the new scope — at the cost of
                 the search term, as with the manager page's collection tabs. -->
            <WorkflowListing
                :key="active"
                :url="listingUrl"
                :columns="view.columns"
                :filters="view.filters"
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
import { computed, ref } from 'vue';
import { Widget, ButtonGroup, Button } from '@statamic/cms/ui';
import CreatePostButton from './CreatePostButton.vue';
import WorkflowListing from './WorkflowListing.vue';
import { registerStateColors, registerStateLabels } from '../support/states.js';
import { dotClass } from '../support/widgetColors.js';

/**
 * Dashboard widget `workflows_overview`. Everything but `perPage` and
 * `workflows` is WorkflowController::overviewProps() as-is; the rows come from
 * the regular listing endpoint with `all_workflows=1`, so search, filters, sort
 * and paging are the manager pages' own.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    // Columns / filters of the whole scope — the "All" button's table.
    columns: { type: Array, default: () => [] },
    filters: { type: Array, default: () => [] },
    // [{value, label, color, columns, filters}] — one button per scoped
    // workflow, with the columns / filters of the table narrowed to it.
    workflowOptions: { type: Array, default: () => [] },
    // The workflow whose button is active on load (the authoring workflow, or
    // the widget's `default_workflow` config). Not remembered across loads.
    defaultWorkflow: { type: String, default: null },
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

// The "All" button's value. Not a workflow name, which never starts with `*`.
const ALL = '*';

const active = ref(
    props.workflowOptions.length > 1 && props.workflowOptions.some((option) => option.value === props.defaultWorkflow)
        ? props.defaultWorkflow
        : ALL
);

const view = computed(
    () => props.workflowOptions.find((option) => option.value === active.value) ?? { columns: props.columns, filters: props.filters }
);

// One workflow narrows the endpoint's scope to it — rows, site columns and
// state filter alike. "All" sends the widget's own list, or nothing for every
// active workflow.
const parameters = computed(() => {
    const workflows = active.value === ALL ? props.workflows ?? [] : [active.value];

    return {
        all_workflows: 1,
        ...(workflows.length ? { workflows: workflows.join(',') } : {}),
    };
});

// Same as on a manager page: a title click is a plain navigation to the
// instance's own page (`row.url`).
function openSummary(row) {
    if (row?.url) window.location.href = row.url;
}
</script>
