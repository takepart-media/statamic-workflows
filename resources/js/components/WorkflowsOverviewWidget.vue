<template>
    <!-- "Alle Beiträge": the manager pages' pivot listing, across every scoped
         workflow at once — one row per post, a workflow column, and one state
         column per site any of those workflows manages. A button group above
         the table narrows it to one workflow; it opens on the authoring one.

         The shell is the CP's own `Widget`; the listing renders `bare` inside it,
         so the card is not drawn twice. `h-full`: fills its grid cell, which
         the dashboard row stretches to its tallest card. -->
    <!-- `id` + `tabindex="-1"`: the target of the stat row's cards 2-4
         (support/dashboardLinks.js), which filter this table, scroll here and
         move the focus onto the card without adding it to the tab order. -->
    <Widget :id="OVERVIEW_ANCHOR" tabindex="-1" :title="__('workflows::messages.all_posts')" class="h-full scroll-mt-4">
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

        <div class="flex min-w-0 flex-1 flex-col px-4 pt-2">
            <!-- One button per scoped workflow (its dot, as in the workflow
                 column), single-select, plus "All" for the mixed table. Pointless
                 with a single workflow in scope, so it only shows from two up.
                 `pressed` and `default` are the same box (same border, padding,
                 weight — only the fill differs), so the active state does not
                 move the group or the buttons beside it.

                 While a stat card filters the table, its removable chip sits in
                 the same row, beside the buttons rather than above the table, so
                 applying a card's filter moves nothing below it. -->
            <div v-if="workflowOptions.length > 1 || preset" class="mb-3 flex flex-wrap items-center gap-2">
                <ButtonGroup v-if="workflowOptions.length > 1">
                    <Button
                        v-for="option in workflowOptions"
                        :key="option.value"
                        size="sm"
                        :variant="active === option.value ? 'pressed' : 'default'"
                        :aria-pressed="active === option.value"
                        @click="select(option.value)"
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
                        @click="select(ALL)"
                    />
                </ButtonGroup>

                <!-- The card's filter as a chip, removable: a `Badge` rendered as a
                     button, so the whole chip is the "remove" control and says so
                     to a screen reader. Removing it returns to the unfiltered
                     table of the selected workflow. -->
                <Badge
                    v-if="preset"
                    as="button"
                    type="button"
                    color="blue"
                    icon-append="x"
                    class="cursor-pointer"
                    :text="__('workflows::messages.overview_preset_active', { label: preset.label })"
                    :aria-label="__('workflows::messages.overview_preset_clear', { label: preset.label })"
                    @click="clearPreset()"
                />
            </div>

            <!-- The stage. Never shorter than the tallest listing it has shown
                 (`floor`), so a button click — a remount, a narrower table,
                 fewer rows — never pulls the card, or the dashboard below it,
                 up. -->
            <div ref="stage" class="relative flex flex-1 flex-col" :style="floor ? { minHeight: `${floor}px` } : null">
                <!-- Keyed by the selection (and the stat card's filter, if any —
                     see stageKey()), so a switch remounts the listing rather
                     than only changing its parameters: Listing would refetch on its
                     own, but keep sending the old column visibility (the endpoint
                     then hides the site columns the new scope adds), keep the old
                     cell slots, filters and page. Remounted it starts on page 1 with
                     exactly the columns and filters of the new scope — at the cost of
                     the search term, as with the manager page's collection tabs.

                     A remount starts with Listing's skeleton, though, so the new
                     listing is first mounted BEHIND the current one: absolutely
                     positioned (no height of its own), invisible and inert, while
                     the current one stays in place, dimmed. When the new one's first
                     request completes the two swap in a single frame — the table
                     changes, the layout does not. -->
                <WorkflowListing
                    v-for="key in stages"
                    :key="key"
                    :url="listingUrl"
                    :columns="scopes[selectionOf(key)].columns"
                    :filters="scopes[selectionOf(key)].filters"
                    :additional-parameters="parametersFor(key)"
                    preferences-prefix="workflows.overview"
                    :per-page="perPage"
                    :show-toolbar="true"
                    bare
                    :class="stageClass(key)"
                    :inert="key === pending || null"
                    :aria-hidden="key === pending ? 'true' : null"
                    :aria-busy="key === shown && pending ? 'true' : null"
                    @request-completed="loaded(key)"
                    @open-summary="openSummary"
                />
            </div>
        </div>
    </Widget>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Badge, Widget, ButtonGroup, Button } from '@statamic/cms/ui';
import CreatePostButton from './CreatePostButton.vue';
import WorkflowListing from './WorkflowListing.vue';
import { registerStateColors, registerStateLabels } from '../support/states.js';
import { dotClass } from '../support/widgetColors.js';
import { OVERVIEW_ANCHOR, activePreset, clearPreset, useLinkTarget } from '../support/dashboardLinks.js';

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

// Columns, filters and endpoint parameters per selection, built once: each
// listing gets the same objects on every render, so none of them refetches
// because a parameter object was merely recreated. One workflow narrows the
// endpoint's scope to it — rows, site columns and state filter alike. "All"
// sends the widget's own list, or nothing for every active workflow.
const scopes = computed(() =>
    Object.fromEntries(
        [...props.workflowOptions.map((option) => option.value), ALL].map((key) => {
            const view = props.workflowOptions.find((option) => option.value === key) ?? {
                columns: props.columns,
                filters: props.filters,
            };
            const workflows = key === ALL ? props.workflows ?? [] : [key];

            return [
                key,
                {
                    columns: view.columns,
                    filters: view.filters,
                    parameters: {
                        all_workflows: 1,
                        ...(workflows.length ? { workflows: workflows.join(',') } : {}),
                    },
                },
            ];
        }),
    ),
);

// The stat row's filter (QueueStats cards 2-4 via support/dashboardLinks.js):
// {preset, label, workflows} or null. Sent as the listing's `preset` parameter
// on top of the selection's own.
const preset = computed(() => activePreset());

// A stage is a selection plus the preset it is filtered by: `postmanager|`,
// `*|awaiting_approval`. Both are part of the listing's identity, so either
// change goes through the same background-load swap.
function stageKey(selection, presetName) {
    return `${selection}|${presetName ?? ''}`;
}

function selectionOf(key) {
    return key.slice(0, key.lastIndexOf('|'));
}

// Parameters per stage, built once each — Listing refetches when it is handed
// a new object, and a stage's parameters never change.
const parameterCache = new Map();

function parametersFor(key) {
    if (!parameterCache.has(key)) {
        const presetName = key.slice(key.lastIndexOf('|') + 1);
        parameterCache.set(key, {
            ...scopes.value[selectionOf(key)].parameters,
            ...(presetName ? { preset: presetName } : {}),
        });
    }

    return parameterCache.get(key);
}

// `active` is the pressed button and follows a click at once; `shown` is the
// stage on screen; `pending` the one loading behind it, if any. A click back
// to the shown stage simply drops the pending one, and a third click while
// one is pending replaces it.
const shown = ref(stageKey(active.value, null));
const pending = ref(null);
let fallback = null;

const stages = computed(() => (pending.value ? [shown.value, pending.value] : [shown.value]));

/**
 * A workflow button. A card's filter survives the switch where it still means
 * something — "Alle" or one of the workflows the preset draws from — and is
 * dropped otherwise: "failed translations" over the post manager is always an
 * empty table, and an empty table under an active chip reads as a bug.
 */
function select(key) {
    active.value = key;

    if (preset.value && key !== ALL && !preset.value.workflows.includes(key)) {
        // The watcher below then shows the unfiltered stage.
        clearPreset();

        return;
    }

    show(stageKey(key, preset.value?.preset));
}

// A card was clicked (or the chip removed). A preset that draws from exactly
// one of this widget's workflows selects that workflow's button, one spanning
// several selects "Alle"; removing it keeps the selection.
watch(preset, (next) => {
    if (next && props.workflowOptions.length > 1) {
        const options = props.workflowOptions.filter((option) => next.workflows.includes(option.value));
        active.value = options.length === 1 ? options[0].value : ALL;
    }

    show(stageKey(active.value, next?.preset));
});

function show(key) {
    pending.value = key === shown.value ? null : key;
    clearTimeout(fallback);
    // Listing reports only a SUCCESSFUL request. A failed one (Listing toasts
    // the error itself) must not leave the button pressed over the old table
    // for good, so the new listing is shown after a while regardless.
    if (pending.value) fallback = setTimeout(() => loaded(key), 10000);
}

function loaded(key) {
    if (key !== pending.value) return;
    clearTimeout(fallback);
    shown.value = key;
    pending.value = null;
}

function stageClass(key) {
    if (key === pending.value) return 'invisible absolute inset-x-0 top-0';
    return ['flex flex-1 flex-col transition-opacity', pending.value ? 'pointer-events-none opacity-50' : null];
}

useLinkTarget(OVERVIEW_ANCHOR);

// The height floor of the stage: the tallest it has been since the widget
// mounted. A ResizeObserver on the stage sees every growth (it cannot shrink
// below the floor it is held at), and the pending listing is absolutely
// positioned, so only what is on screen counts.
const stage = ref(null);
const floor = ref(0);
let observer = null;

onMounted(() => {
    if (typeof ResizeObserver === 'undefined' || !stage.value) return;
    observer = new ResizeObserver(() => {
        if (stage.value) floor.value = Math.max(floor.value, Math.ceil(stage.value.getBoundingClientRect().height));
    });
    observer.observe(stage.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    clearTimeout(fallback);
});

// Same as on a manager page: a title click is a plain navigation to the
// instance's own page (`row.url`).
function openSummary(row) {
    if (row?.url) window.location.href = row.url;
}
</script>
