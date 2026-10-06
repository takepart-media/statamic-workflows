<template>
    <Listing
        :url="url"
        :columns="columns"
        :filters="showToolbar ? filters : []"
        :additional-parameters="additionalParameters"
        :preferences-prefix="preferencesPrefix"
        :per-page="perPage"
        :allow-bulk-actions="false"
        :allow-presets="false"
    >
        <template #default>
            <!-- Renders nothing; exists to reach the ListingContext from inside. -->
            <ListingRefresh :token="refreshToken" />

            <div v-if="showToolbar" class="relative mb-3 flex items-center gap-2 sm:gap-3 min-h-16">
                <div class="flex flex-1 items-center gap-2 sm:gap-3">
                    <ListingSearch />
                    <ListingFilters />
                </div>
                <ListingCustomizeColumns />
            </div>

            <!-- `bare` drops the Panel shell for a host that already is a card (the
                 dashboard widget); table and pagination stay. -->
            <component :is="bare ? 'div' : Panel" class="relative">
                <!-- Single scroll container (both axes) so the table header can stick. -->
                <div :class="bare ? 'max-h-[28rem] overflow-auto' : 'max-h-[calc(100vh-16rem)] overflow-auto'">
                    <GroupedListingTable sticky-header>
                        <!-- One cell slot per column, rendered by the column's type. -->
                        <template v-for="col in columns" :key="col.field" #[`cell-${col.field}`]="{ row }">
                            <a
                                v-if="col.type === 'title'"
                                :href="row.url"
                                class="text-blue-600 hover:underline dark:text-blue-400"
                                @click="onTitleClick($event, row)"
                            >{{ row.title }}</a>
                            <!-- The overview's workflow column: the workflow's dot and
                                 label, linked to its manager page where it has one. -->
                            <component
                                :is="row.manager_url ? 'a' : 'span'"
                                v-else-if="col.type === 'workflow'"
                                :href="row.manager_url || undefined"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap text-gray-700 dark:text-gray-300"
                                :class="{ 'hover:underline': row.manager_url }"
                            >
                                <span class="size-[6px] flex-none rounded-full" :class="dotClass(row.workflow_color)" aria-hidden="true"></span>
                                {{ row.workflow_label }}
                            </component>
                            <!-- A site the row's workflow does not manage (overview
                                 only, where `sites` says which it does): no state can
                                 exist there, so the cell stays empty rather than
                                 showing the "not started" dash. -->
                            <template v-else-if="col.type === 'state' && row.sites && !row.sites.includes(col.field)"></template>
                            <a
                                v-else-if="col.type === 'state' && row[col.field] && row.urls?.[col.field]"
                                :href="row.urls[col.field]"
                                :title="__('workflows::messages.open_workflow_detail')"
                            >
                                <Badge :text="humanize(row[col.field])" :color="stateColor(row[col.field])" size="sm" />
                            </a>
                            <Badge
                                v-else-if="col.type === 'state' && row[col.field]"
                                :text="humanize(row[col.field])"
                                :color="stateColor(row[col.field])"
                                size="sm"
                            />
                            <span v-else-if="col.type === 'state'" class="text-gray-400 dark:text-gray-500">—</span>
                            <template v-else>{{ row[col.field] }}</template>

                            <!-- Since when the version sits in this state (entered_at),
                                 small and muted right behind the badge — before the
                                 preview button below. -->
                            <span
                                v-if="col.type === 'state' && row[col.field] && row.entered_at?.[col.field]"
                                class="ms-1.5 text-2xs text-gray-400 tabular-nums dark:text-gray-500"
                                :title="__('workflows::messages.state_since', { state: humanize(row[col.field]), date: row.entered_at[col.field] })"
                            >{{ row.entered_at[col.field] }}</span>

                            <!-- "Live preview" of this site-version, in its own window. A
                                 sibling of the chain above so it renders IN ADDITION to
                                 the cell's content: on the pivot the button sits beside
                                 the site's state badge (one per version), on the flat
                                 lists beside the title (one row is one version there).
                                 `.stop` keeps the click off the title link. -->
                            <Button
                                v-if="previewUrl(row, col)"
                                :icon="previewPresentation(previewMode(row, col)).icon"
                                variant="ghost"
                                size="xs"
                                icon-only
                                inset
                                class="ms-1 align-middle"
                                :aria-label="previewPresentation(previewMode(row, col)).text"
                                :title="previewPresentation(previewMode(row, col)).title"
                                @click.stop="openPreviewWindow(previewUrl(row, col), `${row.id}-${col.field}`)"
                            />

                            <!-- „veraltet": der Beitrag wurde überarbeitet, seit
                                 diese Fassung freigegeben wurde. Ein eigenes Zeichen
                                 neben dem State und kein State, weil sich nichts
                                 bewegt hat — der Workflow der Fassung ist fertig, ihr
                                 Inhalt ist nur nicht mehr der aktuelle. Geschwister
                                 der Kette oben, damit es ZUSÄTZLICH rendert. -->
                            <span
                                v-if="col.type === 'state' && row.stale?.[col.field]"
                                class="ms-1 inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 align-middle text-2xs font-medium text-amber-700 dark:bg-amber-900! dark:text-amber-200!"
                                :title="__('workflows::messages.stale_marker_hint')"
                            >{{ __('workflows::messages.stale_marker') }}</span>

                            <!-- Comment flag: total count in grey, plus `+N` in amber
                                 when some of them are unread by the current user.
                                 Deliberately a sibling of the chain above rather than
                                 a branch in it, so it renders IN ADDITION to whatever
                                 the cell already shows. Which numbers it picks up
                                 depends on the listing kind — see commentTotal(). -->
                            <span
                                v-if="commentTotal(row, col)"
                                class="ms-1 inline-flex items-center gap-1 rounded-full bg-gray-100 px-1.5 py-0.5 align-middle text-2xs font-medium text-gray-500 dark:bg-gray-700! dark:text-gray-300!"
                                :title="commentUnread(row, col) ? __('workflows::messages.new_comments_hint') : __('workflows::messages.comments')"
                            >
                                <Icon name="mail-chat-bubble-text" class="size-3" />
                                {{ commentTotal(row, col) }}
                                <template v-if="commentUnread(row, col)">
                                    <span class="text-gray-300 dark:text-gray-500!">|</span>
                                    <span class="font-semibold text-amber-600 dark:text-amber-400!">+{{ commentUnread(row, col) }}</span>
                                </template>
                            </span>
                        </template>
                    </GroupedListingTable>
                </div>
                <component :is="bare ? 'div' : PanelFooter" :class="bare ? 'px-4 py-2' : null">
                    <ListingPagination />
                </component>
            </component>
        </template>
    </Listing>

</template>

<script setup>
import {
    Listing,
    ListingSearch,
    ListingFilters,
    ListingCustomizeColumns,
    ListingPagination,
    Panel,
    PanelFooter,
    Badge,
    Button,
    Icon,
} from '@statamic/cms/ui';
import { defineComponent, inject, watch } from 'vue';
import GroupedListingTable from './GroupedListingTable.vue';
import { stateColor, humanize } from '../support/states.js';
import { dotClass } from '../support/widgetColors.js';
import { openPreviewWindow, previewPresentation } from '../support/preview.js';

/**
 * Re-runs the listing's request when `token` changes.
 *
 * Statamic's <Listing> owns the fetch. It does refetch when its `url` or its
 * `additionalParameters` change, but a re-request with unchanged parameters
 * (rows mutated server-side) needs the `refresh` function it puts on its
 * ListingContext. That context is provided by Listing itself, which means a
 * parent cannot inject it. Hence this renderless child: it sits inside the
 * default slot, where the injection resolves, and turns a prop change into a
 * refresh() call.
 */
const ListingRefresh = defineComponent({
    props: { token: { type: Number, default: 0 } },
    setup(props) {
        const { refresh } = inject('ListingContext', {});

        watch(() => props.token, () => {
            if (typeof refresh === 'function') refresh();
        });

        return () => null;
    },
});

defineProps({
    url: { type: String, required: true },
    columns: { type: Array, default: () => [] },
    filters: { type: Array, default: () => [] },
    additionalParameters: { type: Object, default: () => ({}) },
    showToolbar: { type: Boolean, default: true },
    // Namespace for the per-user column/per-page preferences Statamic's Listing
    // stores. Several listings share one page here, so without a distinct prefix
    // they would all write to the same preference key.
    preferencesPrefix: { type: String, default: undefined },
    // Increment to make the listing re-request without a page reload. No caller
    // needs it since the summary became a page of its own — navigating there and
    // back re-requests anyway — but the mechanism stays for callers that render a
    // listing beside something that mutates rows in place.
    refreshToken: { type: Number, default: 0 },
    // Kept for backwards compatibility — was a modal chooser between summary
    // vs. entry-editor. Now every title click goes to the summary page, and the
    // Edit button there jumps to the editor. Prop remains so existing usage keeps
    // working, but is no longer consulted here.
    promptOnRowClick: { type: Boolean, default: false },
    // Rows per page, forwarded to Listing. Undefined keeps Listing's own default
    // (the CP's `paginationSize`).
    perPage: { type: Number, default: undefined },
    // Without the Panel shell, for a host that already draws a card (the
    // dashboard widget). Toolbar, table and pagination are unchanged.
    bare: { type: Boolean, default: false },
});

const emit = defineEmits(['open-summary']);

// The two listing kinds carry the comment numbers differently, and the cell has to
// read whichever applies. The pivot has one column per site and keys its maps by
// site handle (= the state column's `field`), so the flag lands on that site's
// state cell and says which site-version was commented on. The flat listings ("My
// Queue", "Created by me") are already one row per site-version, so they carry
// plain numbers and the flag goes on the title. A row only ever has one of the two
// shapes, so the missing one reads as 0 and the flag stays hidden.
function commentTotal(row, col) {
    return (col.type === 'title' ? row.comment_count : row.comment_counts?.[col.field]) ?? 0;
}

function commentUnread(row, col) {
    return (col.type === 'title' ? row.new_comment_count : row.new_comments?.[col.field]) ?? 0;
}

// Same split as the comment numbers: the pivot carries one preview URL per site
// under the state column's field, the flat lists a single one on the row.
function previewUrl(row, col) {
    if (col.type === 'state') return row.preview_urls?.[col.field] ?? null;
    if (col.type === 'title') return row.preview_url ?? null;
    return null;
}

function previewMode(row, col) {
    return col.type === 'state' ? row.preview_modes?.[col.field] : row.preview_mode;
}

// Every title click navigates to the row's summary page. Middle-click / cmd-click
// / "open in new tab" continue to hit the anchor's href, so deep-links to
// /workflows/instances/{id} keep working.
function onTitleClick(event, row) {
    if (!row) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) return;
    event.preventDefault();
    emit('open-summary', row);
}
</script>
