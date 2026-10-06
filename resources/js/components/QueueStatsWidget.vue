<template>
    <!-- The dashboard's top row: four key figures. Not a list of cards but a
         fixed row of four at EVERY width — `grid-cols-4` with no smaller step,
         because the server always sends exactly these four and a row that
         reflows to two would stop reading as one statement. The price is paid
         inside the cells instead: `min-w-0` plus truncation on the label, so a
         narrow viewport shortens the words rather than breaking the row.

         Every card is a LINK to the posts behind its figure — card 1 to the
         "My queue" card, cards 2-4 to "Alle Beiträge", filtered to what they
         count (see support/dashboardLinks.js). The failed-translations card
         may carry one more control, its retry button, and the two must not
         nest: the link is a sibling of the button, and only its `::after` is
         stretched over the whole card (the card is `relative`, the button
         `relative z-10` above the overlay). So the whole card is the click
         target, the button stays its own, and a screen reader meets two
         separate controls.

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
             and would otherwise lose to the CP's own sheet.

             The ring marks the card whose filter "Alle Beiträge" is showing. -->
        <Card
            v-for="(card, index) in rows"
            :key="card.label"
            class="relative min-w-0 py-3! space-y-1!"
            :class="isActive(card) ? 'ring-2! ring-blue-500! dark:ring-blue-400!' : null"
        >
            <!-- An `<a>` wherever the card has somewhere to go: the widget on
                 this dashboard (`#…`, handled in follow()), else the server's
                 fallback page. With neither it is a plain `div` — a link to
                 nowhere is worse than no link. -->
            <component
                :is="hrefFor(card) ? 'a' : 'div'"
                :href="hrefFor(card) || undefined"
                :aria-current="isActive(card) ? 'true' : undefined"
                class="group block min-w-0 space-y-1 focus-visible:outline-none"
                :class="hrefFor(card) ? STRETCHED_LINK : null"
                @click="follow($event, card)"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <!-- Purple-ish = my own work, blue = review, red = broken,
                         green = done. `shrink-0` because the label beside it
                         wraps before the dot may give up a pixel. -->
                    <span class="size-[7px] shrink-0 rounded-full" :class="DOT_CLASSES[card.color]"></span>
                    <!-- Truncated, not wrapped: four labels in one row at any
                         width means the longest one has to give, and half a
                         word on a second line would make its card taller than
                         its neighbours. -->
                    <Description class="min-w-0 truncate">{{ card.label }}</Description>
                </div>

                <!-- The figure. `Heading` from the kit rather than an inline
                     font-size: the kit's typography wins over the mockup's exact
                     px (see the note in the widget's PHP docblock). `xl`, one
                     step down from the largest the component offers, because
                     four of these share one row at every width. Underlined on
                     hover like the row titles of the list widgets — the
                     addon's "this opens something" affordance. -->
                <Heading size="xl" :text="card.value" :class="hrefFor(card) ? 'group-hover:underline' : null" />

                <!-- Where the link goes, for screen readers: the visible text
                     is a label and a bare number. -->
                <span v-if="hrefFor(card)" class="sr-only">— {{ hintFor(card) }}</span>
            </component>

            <!-- The note's place, in one of two shapes.

                 With an `action` the note IS the action, so it becomes a button
                 rather than gaining one beside it: "erneut starten" was already
                 an instruction, and printing it twice — once as advice, once as
                 a control — would read as two different things. `subtle` + `xs`
                 keeps it at the weight of the `Description` it replaces, and
                 `inset` strips the invisible padding a ghost-style button
                 carries so it stays flush with the label and the figure above.
                 `relative z-10` lifts it over the card's link overlay. -->
            <Button
                v-if="card.action"
                variant="subtle"
                size="xs"
                inset
                class="relative z-10"
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
import { Button, Card, Description, Heading } from '@statamic/cms/ui';
import { activePreset, applyPreset, hasLinkTarget, jumpTo } from '../support/dashboardLinks.js';

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
    // [{label, value, note, tone, color, action, link}] — four of them, in row
    // order. `action` is {url, label} or null; `link` is {target, fallback}
    // plus, on cards 2-4, {preset, workflows}. See QueueStats::card().
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
 * Where a card's link points: the widget on this dashboard when it is there,
 * else the server's fallback page (QueueStats: the manager page), else nowhere.
 * Reactive on the widget's presence, which is registered when it mounts — a
 * card renders before the widgets below it.
 */
function hrefFor(card) {
    const link = card.link;

    if (!link?.target) return null;

    return hasLinkTarget(link.target) ? `#${link.target}` : link.fallback || null;
}

function hintFor(card) {
    if (!hasLinkTarget(card.link.target)) return __('workflows::messages.widget_stats_open_manager');

    return card.link.preset
        ? __('workflows::messages.widget_stats_filter_overview')
        : __('workflows::messages.widget_stats_jump_to_queue');
}

function isActive(card) {
    return Boolean(card.link?.preset) && activePreset()?.preset === card.link.preset;
}

/**
 * An in-page link: set the overview's filter (cards 2-4), then scroll and move
 * the focus there. Only taken over when the target is actually on the page —
 * a fallback link is left to the browser, and so is a modified click.
 */
function follow(event, card) {
    const link = card.link;

    if (!link?.target || !hasLinkTarget(link.target)) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    if (link.preset) {
        applyPreset({ preset: link.preset, label: card.label, workflows: link.workflows });
    }

    if (jumpTo(link.target)) event.preventDefault();
}

/**
 * The card-wide click target: the link's `::after`, stretched over the
 * `relative` card. `rounded-xl` follows the card's own corners, so the focus
 * outline drawn on it does too.
 */
const STRETCHED_LINK = 'after:absolute after:inset-0 after:rounded-xl focus-visible:after:outline-2 focus-visible:after:outline-offset-2 focus-visible:after:outline-blue-500';

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
