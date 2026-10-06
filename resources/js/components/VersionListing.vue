<template>
    <!-- Statamic's own Listing, driven client-side via `items` rather than a `url`:
         a post has a handful of translations, they already travel in the page payload,
         and an endpoint for three rows would be a round trip for nothing. Sorting and
         filtering happen in the browser in that mode.

         Everything the toolbar offers is off — search, presets, customizing columns,
         bulk actions and row selection make no sense for a list this small and this
         fixed. `ListingTable` renders the head and body and forwards every `cell-*`
         slot to the body for us. -->
    <Listing
        :items="rows"
        :columns="columns"
        :allow-search="false"
        :allow-presets="false"
        :allow-bulk-actions="false"
        :allow-customizing-columns="false"
        :show-pagination-totals="false"
        :show-pagination-page-links="false"
        :show-pagination-per-page-selector="false"
    >
        <ListingTable>
            <template #cell-title="{ row }">
                <!-- A real anchor, so middle-click / cmd-click / "open in new tab"
                     work and a screen reader has something to announce. -->
                <a :href="row.url" class="font-medium text-blue-600 no-underline hover:underline">{{ row.title }}</a>
                <span
                    v-if="row.has_new_comments"
                    class="ms-2 rounded-full bg-amber-500 px-1.5 text-2xs font-semibold text-white"
                >{{ __('workflows::messages.new') }}</span>
            </template>

            <template #cell-siteName="{ row }">
                <span class="inline-flex items-center gap-2">
                    <span
                        v-if="row.code"
                        class="wf-site-code"
                    >{{ row.code }}</span>
                    {{ row.siteName }}
                </span>
            </template>

            <template #cell-state="{ row }">
                <!-- Empty where the version's workflow has not started for it — the
                     post is still unpublished. -->
                <Badge v-if="row.state" :color="stateColor(row.state)" :text="humanize(row.state)" size="sm" />
                <!-- Fertig in ihrem Workflow, aber der Beitrag ist seither
                     überarbeitet. Neben dem State, nicht anstelle: der Schritt
                     stimmt, der Inhalt ist alt. -->
                <span
                    v-if="row.stale"
                    class="ms-1 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 align-middle text-2xs font-medium text-amber-700 dark:bg-amber-900! dark:text-amber-200!"
                    :title="__('workflows::messages.stale_marker_hint')"
                >{{ __('workflows::messages.stale_marker') }}</span>
            </template>
        </ListingTable>
    </Listing>
</template>

<script setup>
import { computed, getCurrentInstance } from 'vue';
import { Badge, Listing, ListingTable } from '@statamic/cms/ui';
import { stateColor, humanize } from '../support/states.js';

/**
 * The TRANSLATIONS of the entry being viewed: title, site, state, one row each.
 * Shared by the post-manager summary and the translation detail view, so both
 * products read as one — title, site and state each get their own column.
 *
 * Each row links to that version's own workflow summary. Both the URL and the state
 * come from the server, resolved per version against the workflow that OWNS it — a
 * translated entry lives in the translation manager and has no state in the post
 * manager at all (see WorkflowController::entryVersions()).
 */
const props = defineProps({
    versions: { type: Array, default: () => [] },
});

// Column labels are DATA handed to the Listing, not template output, so they need
// the translator here in the script. Vue resolves a bare `__` in a template from the
// app's globalProperties, but nothing declares it inside a module — hence going
// through the app context, the same way this addon reaches `$axios`. Falls back to
// the key so a missing translator degrades to something readable.
const translate = getCurrentInstance()?.appContext?.config?.globalProperties?.__;
const t = (key) => (typeof translate === 'function' ? translate(key) : key);

const columns = [
    { field: 'title', label: t('workflows::messages.subject'), visible: true, sortable: true },
    { field: 'siteName', label: t('workflows::messages.site'), visible: true, sortable: true },
    { field: 'state', label: t('workflows::messages.state'), visible: true, sortable: true },
];

// The entry you are already looking at is not one of its own translations. The server
// marks it rather than omitting it, because the pills on the translation view need the
// full set to highlight the current one.
//
// `id` is what the Listing keys its rows by; the site handle is unique per version and
// is exactly that identity.
const rows = computed(() =>
    props.versions.filter((version) => !version.current).map((version) => ({ ...version, id: version.site }))
);
</script>
