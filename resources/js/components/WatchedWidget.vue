<template>
    <!-- "Beobachtet": the posts the current user bookmarked on a detail page,
         latest edit first. Same shell and rows as "Created by me" next to it,
         plus a button per row to stop watching. -->
    <Widget :title="__('workflows::messages.widget_watched_title')">
        <Description class="border-b border-gray-200 px-4.5 py-2 dark:border-gray-700">
            {{ __('workflows::messages.widget_watched_subline') }}
        </Description>

        <div v-if="visible.length" class="flex-1">
            <PersonalWidgetRow v-for="row in visible" :key="row.unwatch_url" :row="row">
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
        </div>
        <Description v-else class="flex-1 py-6 text-center">
            {{ __('workflows::messages.widget_no_watched') }}
        </Description>

        <template #footer>
            <PersonalWidgetFooter v-if="more.length" :entries="more" />
        </template>
    </Widget>
</template>

<script setup>
import { computed, getCurrentInstance, reactive, ref } from 'vue';
import { Button, Description, Widget } from '@statamic/cms/ui';
import PersonalWidgetRow from './PersonalWidgetRow.vue';
import PersonalWidgetFooter from './PersonalWidgetFooter.vue';

/**
 * Dashboard widget `workflows_watched`. The rows arrive sorted, trimmed and with
 * their own `unwatch_url` (Watched::component()); the only client state is which
 * rows were removed here, so they disappear without reloading the dashboard.
 * The footer aggregate is left as it came — a removed row does not pull a hidden
 * one up, that happens on the next load.
 */
const props = defineProps({
    // Rows from PersonalWorkItems, newest edit first, cut to `limit`, each with
    // `unwatch_url`.
    rows: { type: Array, default: () => [] },
    // [{count, label, color, url}] per workflow with hidden rows.
    more: { type: Array, default: () => [] },
});

const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

const removed = ref(new Set());
const busy = reactive({});

const visible = computed(() => props.rows.filter((row) => !removed.value.has(row.unwatch_url)));

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
