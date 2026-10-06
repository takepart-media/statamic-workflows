<template>
    <!-- One row of a personal dashboard card. Two sources of styling, on purpose:

         - The row's own furniture — urgency rail, mono site chip, workflow dot,
           meta text — is the design spec's markup. A pass that replaced all of it
           with kit `Badge`s produced a visibly different card (taller, flatter, no
           urgency signal), so it is back.
         - The STATE is the kit's `Badge`, bound exactly as the workflow manager
           listings bind it. A state has to look identical everywhere it appears,
           and the managers are where it is read most.

         Only the TITLE is a link; the row itself is not. The two spec-coloured
         elements never see a hex — they bind a `wf-*` class built from a
         settings-driven colour name (see ../support/widgetColors.js); the state
         hands the same kind of name to `Badge`'s `color` prop instead.

         Shared by both cards, which differ in two switches — the queue shows an
         urgency rail and a meta line, "Created by me" shows neither (its order
         already says "recent", so a second time reference beside every title
         would repeat it). Everything else about the two rows has to stay
         identical, which is exactly what a shared component enforces.

         A `placeholder` row is one of the invisible fillers that hold a short
         last page at a full page's height (see support/pagination.js):
         `invisible` keeps its box and hides everything in it, border included;
         `inert` and `aria-hidden` keep its copied link out of the tab order and
         the accessibility tree. -->
    <div
        class="flex items-center border-b border-gray-100 px-[18px] py-[12px] hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50"
        :class="[withRail ? 'gap-[14px]' : 'gap-[12px]', { invisible: placeholder }]"
        :inert="placeholder || null"
        :aria-hidden="placeholder ? 'true' : null"
    >
        <!-- Stretched to the row's full height rather than given one: the row is
             as tall as its content, and the rail should follow. The negative
             vertical margin bleeds it 2px into the row padding so it reads as a
             marker on the row, not as a glyph inside it. -->
        <span
            v-if="withRail"
            class="w-[3px] self-stretch rounded-[3px] my-[-2px]"
            :class="railClass(row.state_color)"
            aria-hidden="true"
        ></span>

        <div class="flex min-w-0 flex-1 flex-col gap-[5px]">
            <a
                :href="row.url"
                class="truncate text-[13.5px] font-medium text-[#1c60d8] hover:underline dark:text-blue-400"
            >{{ row.title }}</a>

            <!-- Three parts, and only the last one may shrink: the site code and
                 the workflow label are identifiers that mean nothing cut in half,
                 while the meta text degrades gracefully to an ellipsis. That is
                 what `flex-none` on the first two plus `min-w-0` on the third
                 buys. -->
            <div class="flex min-w-0 items-center gap-[8px] overflow-hidden">
                <span v-if="row.site_code" class="wf-site-code flex-none">{{ row.site_code }}</span>
                <span class="flex flex-none items-center gap-[6px] whitespace-nowrap text-[12px] text-gray-500 dark:text-gray-400">
                    <span class="size-[6px] rounded-full" :class="dotClass(row.workflow_color)" aria-hidden="true"></span>
                    {{ row.workflow_label }}
                </span>
                <!-- The middot is a separator, not a word — it carries no meaning
                     to translate, so it stays in the markup rather than becoming a
                     lang key. -->
                <span v-if="withMeta && row.meta" class="min-w-0 truncate text-[12px] text-gray-400 dark:text-gray-500">· {{ row.meta }}</span>
            </div>
        </div>

        <!-- The state is the CP's own `Badge`, bound exactly as the workflow
             manager listings bind it (see WorkflowListing.vue): the state a row
             is in has to look the same wherever it is shown, and the managers are
             where people read it most. `state_color` already carries a Statamic
             Badge colour name, so it goes straight into `:color`.

             Omitted, not blanked, where the entry's version is not part of the
             workflow yet: PersonalWorkItems hands back an empty label for that,
             and an empty badge would be a coloured smudge. -->
        <Badge
            v-if="row.state_label"
            :text="row.state_label"
            :color="row.state_color"
            size="sm"
            class="flex-none"
        />

        <!-- Per-row actions, after the state. Only the Watched card fills it (its
             remove button); the other cards pass nothing, so their rows render
             exactly as before. -->
        <slot name="actions" />
    </div>
</template>

<script setup>
import { Badge } from '@statamic/cms/ui';
import { dotClass, railClass } from '../support/widgetColors';

defineProps({
    // One row from PersonalWorkItems: {title, url, workflow_label,
    // workflow_color, state_label, state_color, site_code, meta, ...}.
    row: { type: Object, required: true },
    // The urgency rail — queue only, where the rows are work waiting on the
    // reader and the order is by urgency.
    withRail: { type: Boolean, default: false },
    // The "seit 3 Tagen" / "heute 09:14" text. Only the queue asks the server
    // for it, so only the queue can show it.
    withMeta: { type: Boolean, default: false },
    // An invisible stand-in that only takes up a row's height — a copy of a real
    // row, so it is exactly as tall as one.
    placeholder: { type: Boolean, default: false },
});
</script>
