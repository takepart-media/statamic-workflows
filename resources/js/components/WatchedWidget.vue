<template>
    <!-- "Beobachtet": the posts the current user bookmarked on a detail page,
         latest edit first. Same shell and rows as "My posts" next to it,
         plus a button per row to stop watching. `h-full`: fills its grid cell,
         which the dashboard row stretches to its tallest card. -->
    <Widget :title="__('workflows::messages.widget_watched_title')" class="h-full">
        <Description class="border-b border-gray-200 px-4.5 py-2 dark:border-gray-700">
            {{ __('workflows::messages.widget_watched_subline') }}
        </Description>

        <div v-if="pageItems.length" class="flex-1">
            <PersonalWidgetRow v-for="row in pageItems" :key="row.unwatch_url" :row="row">
                <template #actions>
                    <Button
                        icon="bookmark"
                        variant="ghost"
                        size="xs"
                        icon-only
                        class="flex-none"
                        :disabled="busy[row.unwatch_url]"
                        :aria-label="__('workflows::messages.unwatch')"
                        :title="__('workflows::messages.unwatch')"
                        @click="unwatch(row)"
                    />
                </template>
            </PersonalWidgetRow>
            <!-- Invisible stand-ins up to a full page, so a short last page (or
                 an unwatched row on it) does not shrink the card. They carry the
                 same button as a real row, so they are the same height. -->
            <PersonalWidgetRow v-for="(row, index) in fillers" :key="`filler-${index}`" :row="row" placeholder>
                <template #actions>
                    <Button icon="bookmark" variant="ghost" size="xs" icon-only class="flex-none" disabled />
                </template>
            </PersonalWidgetRow>
        </div>
        <Description v-else class="flex flex-1 items-center justify-center py-6 text-center">
            {{ __('workflows::messages.widget_no_watched') }}
        </Description>

        <!-- Hides itself while everything fits on one page. -->
        <template #footer>
            <WidgetPagination :meta="meta" @page-selected="setPage" />
        </template>
    </Widget>
</template>

<script setup>
import { computed, getCurrentInstance, reactive, ref } from 'vue';
import { Button, Description, Widget } from '@statamic/cms/ui';
import PersonalWidgetRow from './PersonalWidgetRow.vue';
import WidgetPagination from './WidgetPagination.vue';
import { useClientPagination } from '../support/pagination';

/**
 * Dashboard widget `workflows_watched`. The rows arrive complete, sorted and with
 * their own `unwatch_url` (Watched::component()); the client state is which rows
 * were removed here, so they disappear without reloading the dashboard, and the
 * current page. Paging runs over what is left: a removed row pulls the next one
 * up into the page, and emptying the last page steps back to the one before
 * (useClientPagination clamps).
 */
const props = defineProps({
    // Rows from PersonalWorkItems, newest edit first — all of them — each with
    // `unwatch_url`.
    rows: { type: Array, default: () => [] },
    // Rows per page. The widget's `limit` config.
    limit: { type: Number, default: 5 },
});

const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

const removed = ref(new Set());
const busy = reactive({});

const visible = computed(() => props.rows.filter((row) => !removed.value.has(row.unwatch_url)));

const { pageItems, meta, setPage, fillers } = useClientPagination(visible, () => props.limit);

async function unwatch(row) {
    const url = row.unwatch_url;
    busy[url] = true;
    try {
        if (axios) {
            await axios.delete(url);
        } else {
            const response = await fetch(url, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            });
            if (!response.ok) throw new Error(String(response.status));
        }
        removed.value = new Set([...removed.value, url]);
    } catch (e) {
        window.Statamic?.$toast?.error(__('workflows::messages.watch_error'));
    } finally {
        busy[url] = false;
    }
}
</script>
