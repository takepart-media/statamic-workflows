<template>
    <!-- The dashboard's top row: four key figures. Not a list of cards but a
         fixed row of four at EVERY width — `grid-cols-4` with no smaller step,
         because the server always sends exactly these four and a row that
         reflows to two would stop reading as one statement. The price is paid
         inside the cells instead: `min-w-0` plus truncation on the label, so a
         narrow viewport shortens the words rather than breaking the row.

         Read-only but for two spots, and they are independent of each other: a
         card that arrives with `items` turns its VALUE into a stack listing the
         posts behind the figure, and a card that arrives with an `action` turns
         its NOTE into a button. The failed-translations card has both. -->

    <!-- Two `v-if`s per card rather than one "interactive card" branch, because
         the stack and the retry belong to different lines of the card and
         either can be absent on its own.

         Each card is Statamic's own `Card`, so the surface (radius, ring,
         shadow, dark mode) is the CP's and not ours — the same shell `Widget`
         puts around the list cards below. The only hand-built thing in here is
         the 7px colour dot: the kit has no component for it. -->
    <div class="grid grid-cols-4 gap-4">
        <!-- `py-3!`/`space-y-1!` tighten the card, and they are utilities
             because the kit has nothing gentler to offer: `Card` exposes exactly
             one padding switch, `inset`, and it is all-or-nothing (it drops
             `px-4 sm:px-4.5 py-5 space-y-2` AND the spacing between the lines,
             leaving the dot against the card's edge). So the horizontal padding
             stays the kit's and only the vertical rhythm is overridden. The
             bangs are not decorative — same reason as TONE_CLASSES below: the
             addon's utilities sit in the low-priority `addon-utilities` layer
             and would otherwise lose to the CP's own sheet. -->
        <Card v-for="(card, index) in rows" :key="card.label" class="min-w-0 py-3! space-y-1!">
            <div class="flex min-w-0 items-center gap-2">
                <!-- Purple-ish = my own work, blue = review, red = broken,
                     green = done. `shrink-0` because the label beside it wraps
                     before the dot may give up a pixel. -->
                <span class="size-[7px] shrink-0 rounded-full" :class="DOT_CLASSES[card.color]"></span>
                <!-- Truncated, not wrapped: four labels in one row at any width
                     means the longest one has to give, and half a word on a
                     second line would make its card taller than its neighbours. -->
                <Description class="min-w-0 truncate">{{ card.label }}</Description>
            </div>

            <!-- The figure. `Heading` from the kit rather than an inline
                 font-size: the kit's typography wins over the mockup's exact px
                 (see the note in the widget's PHP docblock). `xl`, one step down
                 from the largest the component offers (`base`/`lg`/`xl`/`2xl`),
                 because four of these now share one row at every width.

                 Where the server sent posts, that same figure opens a `Stack` —
                 the CP's slide-over panel. A popover sat here first and was the
                 wrong container: it has to fit beside the card it hangs off, so
                 thirty posts awaiting approval were unusable. `StackContent` is
                 `flex-1 overflow-auto`, i.e. the panel scrolls by itself and the
                 list can be as long as the cap allows.

                 The number IS the trigger — the figure and the posts behind it
                 are one thing, and a second control beside it would suggest they
                 are two.

                 The trigger is a bare `<button>` wrapping the unchanged
                 `Heading`, NOT a kit `Button`. `Stack` renders its trigger as
                 `<Primitive as-child @click="open">` — exactly like `Popover`
                 did: it adopts whatever element it is given and styles nothing,
                 so there is no kit trigger styling to inherit. A kit `Button`
                 would have brought its own: every size variant pins a height
                 (`h-6` at `xs` up to `h-10` at `base`), and an xl figure inside
                 a 24px button is clipped. So the affordance is the one the addon
                 already uses for "this opens something you can read": pointer
                 plus underline on hover, the same pair as the row titles in the
                 list widgets. -->
            <Stack v-if="card.items?.length" :title="card.label" size="narrow" show-close-button>
                <template #trigger>
                    <button
                        type="button"
                        class="cursor-pointer text-left hover:underline"
                        :aria-label="__('workflows::messages.widget_stats_show_posts')"
                    >
                        <Heading size="xl" :text="card.value" />
                    </button>
                </template>

                <!-- No `StackHeader`/`StackContent`/`StackFooter` assembled by
                     hand, although the kit exports them: `title` +
                     `show-close-button` build the header, the default slot is
                     wrapped in a `StackContent` automatically (`wrapSlot`), and
                     the `#footer-start` below makes the kit render its own
                     `StackFooter`. Fewer of our own decisions about a panel the
                     CP already knows how to lay out. -->
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    <div v-for="item in card.items" :key="item.url" class="flex items-center gap-2 py-2">
                        <!-- Same link treatment as the list widgets' rows: a bare
                             `<a>`, because a link is not a component in the kit,
                             and truncated because one long headline must not
                             widen the panel. -->
                        <a
                            :href="item.url"
                            class="min-w-0 flex-1 truncate text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
                        >{{ item.title }}</a>

                        <!-- Neutral: the site is an identifier, not a status.
                             Only present at all on a multisite project. -->
                        <Badge
                            v-if="item.site_code"
                            :text="item.site_code"
                            color="default"
                            size="sm"
                            class="flex-none"
                        />
                    </div>
                </div>

                <!-- What the cap hides, per workflow, pointing at the manager
                     page where those posts actually are — in the footer, because
                     it is the way OUT of this panel rather than another of its
                     rows. A workflow without `metadata.nav` has no such page:
                     its remainder is stated as plain text instead of as a dead
                     link. The `v-if` sits on the template so the kit sees no
                     `footer-start` slot at all and skips the footer entirely. -->
                <template v-if="card.more?.length" #footer-start>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <component
                            :is="entry.url ? 'a' : 'span'"
                            v-for="(entry, moreIndex) in card.more"
                            :key="moreIndex"
                            :href="entry.url || undefined"
                            :title="entry.url ? __('workflows::messages.widget_more_in', { workflow: entry.label }) : undefined"
                            class="truncate text-xs"
                            :class="entry.url ? 'text-blue-600 hover:underline dark:text-blue-400' : 'text-gray-600 dark:text-gray-400'"
                        >+{{ entry.count }} {{ entry.label }}</component>
                    </div>
                </template>
            </Stack>

            <Heading v-else size="xl" :text="card.value" />

            <!-- The note's place, in one of two shapes.

                 With an `action` the note IS the action, so it becomes a button
                 rather than gaining one beside it: "erneut starten" was already
                 an instruction, and printing it twice — once as advice, once as
                 a control — would read as two different things. `subtle` + `xs`
                 keeps it at the weight of the `Description` it replaces, and
                 `inset` strips the invisible padding a ghost-style button
                 carries so it stays flush with the label and the figure above.
                 -->
            <Button
                v-if="card.action"
                variant="subtle"
                size="xs"
                inset
                :text="card.action.label"
                :loading="pending === index"
                :disabled="pending !== null"
                @click="retry(card, index)"
            />

            <!-- Omitted, not blanked, when the server sends no note — an empty
                 line would leave the four cards different heights for no
                 reason, and `Card`'s own `space-y-2` already spaces what is
                 there. -->
            <Description v-else-if="card.note" :class="TONE_CLASSES[card.tone]">{{ card.note }}</Description>
        </Card>
    </div>
</template>

<script setup>
import { getCurrentInstance, ref } from 'vue';
import { Badge, Button, Card, Description, Heading, Stack } from '@statamic/cms/ui';

/**
 * Dashboard widget `workflows_queue_stats`. A Vue component rather than Blade
 * because `Card`/`Heading`/`Description` are the CP's Vue kit and have no Blade
 * twin — a server-rendered row would have had to re-draw all three by hand and
 * would drift from them at the next CP release.
 *
 * The component computes nothing: labels, values, notes and tones all arrive
 * finished from QueueStats::component(), including the translations, so there
 * is exactly one place where "what does this card count" is decided. The retry
 * below is the single exception, and even it decides nothing — it posts, and
 * re-prints the counts the server sends back.
 */
const props = defineProps({
    // [{label, value, note, tone, color, action, items, more}] — four of them,
    // in row order. `action` is {url, label} or null; `items` is up to
    // QueueStats::ITEM_CAP {title, url, site_code} and `more` the per-workflow
    // remainder {count, label, url}. Both lists are empty on the cards without
    // a stack. See QueueStats::card().
    cards: { type: Array, default: () => [] },
});

/**
 * A local copy, because the retry rewrites a card in place and props are
 * read-only. Seeded once: the dashboard hands this component its cards on load
 * and never revises them, so there is nothing to keep in sync — the only later
 * source of truth is the retry's own response.
 */
const rows = ref(props.cards.map((card) => ({ ...card })));

// The index of the card whose action is running, or null. An index rather than
// a boolean so the spinner sits on the button that was pressed, while the
// others merely go inactive — with one action in the row today that is the same
// thing, but a second one must not silently start spinning too.
const pending = ref(null);

// Statamic exposes axios via Vue's global properties, not as an importable
// module. Same lookup as WorkflowsSettingsIndex.vue and WorkflowQueue.vue.
const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

/**
 * Restart everything behind the card's action, then re-print the card from the
 * server's answer.
 *
 * The response carries `remaining_failed`, which is re-counted server-side
 * rather than derived from `retried` — a bulk run may legitimately skip
 * subjects, and subtracting client-side would show a number nobody could
 * reproduce by reloading. When nothing is left, the card loses its action and
 * its note along with it: an empty failure card has nothing to restart.
 */
async function retry(card, index) {
    if (pending.value !== null) return;

    pending.value = index;

    try {
        const data = await request('post', card.action.url);
        const remaining = Number(data?.remaining_failed ?? 0);

        rows.value[index] = {
            ...card,
            value: String(remaining),
            note: remaining === 0 ? '' : card.note,
            tone: remaining === 0 ? 'neutral' : card.tone,
            action: remaining === 0 ? null : card.action,
        };

        window.Statamic?.$toast?.success(
            __('workflows::messages.widget_stats_retry_done', { count: data?.retried ?? 0 }),
        );
    } catch (e) {
        window.Statamic?.$toast?.error(errorMessage(e, 'widget_stats_retry_error'));
    } finally {
        pending.value = null;
    }
}

function errorMessage(e, fallbackKey) {
    return e?.response?.data?.error
        || e?.response?.data?.message
        || __(`workflows::messages.${fallbackKey}`);
}

// The addon's usual shape (see WorkflowsSettingsIndex.vue): axios where the CP
// provides it — it carries the CSRF token and the CP's error handling — and a
// hand-rolled fetch where it does not. This endpoint answers with a bare object
// rather than a `data` envelope, so the payload is returned as it arrives.
async function request(method, url, payload = null) {
    if (axios) {
        const res = await axios({ method, url, data: payload });

        return res?.data ?? null;
    }

    const res = await fetch(url, {
        method: method.toUpperCase(),
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: payload === null ? undefined : JSON.stringify(payload),
    });

    if (!res.ok) throw new Error('request failed');

    return await res.json();
}

/**
 * The dot colours, spelled out as whole class names rather than built from the
 * `color` key: Tailwind scans this file as source, and a template string would
 * leave it nothing to find. All four exist in the CP's own build too, so the
 * dots match the badges elsewhere in the addon.
 */
const DOT_CLASSES = {
    indigo: 'bg-indigo-500',
    blue: 'bg-blue-500',
    red: 'bg-red-500',
    green: 'bg-green-500',
};

/**
 * The note's text colour. `neutral` deliberately maps to nothing — the note
 * then keeps `Description`'s own grey, which is what a footnote should be.
 *
 * The bangs are needed, not decorative: `Description` ships its own
 * `text-gray-600/90 dark:text-gray-400`, and the addon's utilities sit in the
 * low-priority `addon-utilities` layer, so a plain `text-red-700` would lose
 * the cascade to the CP's stylesheet. Same reason as the `text-xs!` in
 * PersonalWidgetRow.
 */
const TONE_CLASSES = {
    neutral: '',
    negative: 'text-red-700! dark:text-red-400!',
    positive: 'text-emerald-700! dark:text-emerald-400!',
};
</script>
