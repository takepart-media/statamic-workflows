<template>
    <table
        v-if="items.length > 0"
        :data-size="dataSize"
        class="data-table"
        :class="stickyHeader ? 'overflow-x-visible!' : ''"
        data-table
    >
        <thead class="wf-listing-head" :class="stickyHeader ? 'sticky top-0 z-20' : ''">
            <tr>
                <template v-for="column in visibleColumns" :key="column.field">
                    <th
                        v-if="column.code"
                        :data-column="column.field"
                        scope="col"
                        class="text-left!"
                    >
                        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-900 dark:text-gray-400">
                            <span
                                class="rounded bg-gray-100 px-1 py-0.5 font-mono text-2xs font-semibold tracking-wide text-gray-600 dark:bg-gray-800! dark:text-gray-300!"
                                :title="column.label"
                            >{{ column.code }}</span>
                            {{ column.label }}
                        </span>
                    </th>
                    <ListingHeaderCell
                        v-else
                        :column="column"
                        :data-column="column.field"
                    />
                </template>
            </tr>
        </thead>

        <ListingTableBody>
            <template v-for="(slot, slotName) in cellSlots" :key="slotName" #[slotName]="slotProps">
                <component :is="slot" v-bind="slotProps" />
            </template>
        </ListingTableBody>
    </table>

    <div v-else>
        <div class="text-center text-gray-500 text-sm py-4">
            {{ __('No items found') }}
        </div>
    </div>
</template>

<script setup>
import { computed, inject, useSlots } from 'vue';
import { ListingTableBody, ListingHeaderCell } from '@statamic/cms/ui';

// Read the live listing state the table body also renders from, so the header
// always matches the body's columns (the body renders one cell per
// `visibleColumns`). The context is provided by the surrounding <Listing> under
// the string key 'ListingContext' (Statamic's createContext convention) and is
// refreshed from the server response on every fetch — unlike the initial
// Inertia `columns` prop, which can go stale on SPA navigation.
defineProps({
    stickyHeader: { type: Boolean, default: false },
});

const { visibleColumns, items } = inject('ListingContext');

// Mirrors Statamic Table.vue's data-size sizing based on the column count.
const dataSize = computed(() => {
    const n = visibleColumns.value.length;
    if (n <= 4) return 'sm';
    if (n <= 8) return 'md';
    if (n >= 12) return 'lx';
    return 'xl';
});

const slots = useSlots();
const cellSlots = computed(() =>
    Object.keys(slots)
        .filter((name) => name.startsWith('cell-'))
        .reduce((acc, name) => ((acc[name] = slots[name]), acc), {}),
);
</script>
