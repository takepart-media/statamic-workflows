<template>
    <!-- What the row limit is hiding, broken down per workflow ("● +3 Beiträge
         ● +1 Übersetzungen"). Per workflow rather than as one "+N" because the
         list above mixes workflows, so a bare number would have nowhere to go
         and nothing to say.

         Dot plus plain text, not a `Badge`: the dot is the same 6px marker the
         rows carry, which is what ties the footer to the list above it — a row of
         badges down here read as a second, competing legend.

         Each part links to that workflow's manager page — the listing where the
         hidden rows actually are, and where "Meine Warteschlange" / "Von mir
         erstellt" exist as full sections. A workflow without `metadata.nav` has
         no such page: `url` is null there and the part renders as plain text
         rather than as a dead link. -->
    <footer
        class="flex items-center gap-[14px] border-t border-gray-100 bg-[#fcfcfd] px-[18px] py-[11px] text-[12px] text-gray-500 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-400"
    >
        <component
            v-for="(entry, index) in entries"
            :is="entry.url ? 'a' : 'span'"
            :key="entry.key ?? index"
            :href="entry.url || undefined"
            :title="entry.url ? __('workflows::messages.widget_more_in', { workflow: entry.label }) : undefined"
            class="flex items-center gap-[8px]"
            :class="entry.url ? 'hover:underline' : ''"
        >
            <span class="size-[6px] rounded-full" :class="dotClass(entry.color)" aria-hidden="true"></span>
            +{{ entry.count }} {{ entry.label }}
        </component>
    </footer>
</template>

<script setup>
import { dotClass } from '../support/widgetColors';

/**
 * The footer of both personal dashboard cards. Shared because the two are meant
 * to be indistinguishable, and because the link/plain-text switch is the kind of
 * detail that silently drifts when it exists twice.
 *
 * Only the source of `entries` differs: CreatedByMe gets them pre-aggregated
 * from PresentsPersonalEntries::overflow(), MyQueue computes them client-side
 * because its footer has to follow the active tab.
 */
defineProps({
    // [{count, label, color, url, key?}] — one per workflow with hidden rows.
    // The caller only renders the footer when this is non-empty.
    entries: { type: Array, default: () => [] },
});
</script>
