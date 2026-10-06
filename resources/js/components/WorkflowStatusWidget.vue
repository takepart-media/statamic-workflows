<template>
    <!-- "Inhalte nach Status": one card per active workflow, each showing that
         workflow's state distribution as a doughnut plus a legend.

         `grid-cols-3` at every width, because three is what the reference
         project has and the row is meant to be read across; a fourth workflow
         wraps onto a second line of the same grid rather than squeezing the
         first. `items-stretch` keeps the cards the same height when one legend
         has more states than another.

         Each card is the CP's own `Card`, so the surface (radius, ring, shadow,
         dark mode) is the CP's — the same shell the stat row above uses. The
         only hand-built things in here are the workflow dot, the legend rows and
         the ring itself. -->
    <div class="grid grid-cols-3 gap-5 items-stretch">
        <Card v-for="card in cards" :key="card.workflow" class="flex min-w-0 flex-col">
            <!-- Header: whose distribution this is. The rule below it is drawn
                 inside `Card`'s own horizontal padding rather than bleeding to
                 the card's edges — the alternative was `inset` plus re-building
                 the kit's padding by hand on both halves, and an inset rule is
                 the cheaper deviation from the spec. -->
            <div class="flex min-w-0 items-center gap-2 border-b border-gray-100 pb-3 dark:border-gray-800">
                <!-- The workflow's own colour, the same dot the queue rows and
                     footers carry, at 8px instead of 6px: here it identifies a
                     whole card rather than annotating a line of text. Colour
                     from a `wf-dot--*` class, size from a utility — the classes
                     carry no size. -->
                <span class="size-[8px] shrink-0 rounded-full" :class="dotClass(card.color)" aria-hidden="true"></span>

                <div class="min-w-0 flex-1">
                    <!-- Kit `Heading`, so the card titles match the rest of the
                         dashboard; it renders `font-medium` where the spec asked
                         for semibold, and the kit wins that. `Heading` is a flex
                         container, hence the inner span: `truncate` has to sit on
                         the flex ITEM to ellipsis a long workflow name. -->
                    <Heading size="base" class="min-w-0">
                        <span class="truncate">{{ card.label }}</span>
                    </Heading>
                    <!-- Says what the numbers below are, once per card rather
                         than once for the row: the cards can wrap onto a second
                         line, and a heading over the row would then sit above
                         only some of them. -->
                    <Subheading size="sm" class="min-w-0">
                        <span class="truncate">{{ __('workflows::messages.widget_status_subline') }}</span>
                    </Subheading>
                </div>
            </div>

            <div class="flex flex-1 items-center gap-4 pt-2">
                <!-- The ring. A fixed 116px box because Chart.js sizes the canvas
                     to its parent (`responsive` + `maintainAspectRatio: false`),
                     so the parent is what decides how big the ring is. `relative`
                     to carry the total in its middle. -->
                <div class="relative size-[116px] shrink-0">
                    <canvas :ref="(el) => setCanvas(card.workflow, el)"></canvas>

                    <!-- The total, centred in the hole. The wrapper is
                         `pointer-events-none` so it never eats a hover the
                         segments below want; the link inside takes its own
                         events back, and it only covers the hole, where there is
                         no arc to hover anyway.

                         A plain link to the workflow's manager page, not a
                         stack: the total is the whole workflow, and "all of it"
                         is a page that filters and sorts, not a list of 300
                         titles in a slide-over. A workflow without
                         `metadata.nav` has no such page and keeps a plain
                         number. -->
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <component
                            :is="card.manager_url ? 'a' : 'div'"
                            :href="card.manager_url || undefined"
                            :title="card.manager_url ? __('workflows::messages.widget_open_manager') : undefined"
                            class="flex flex-col items-center"
                            :class="card.manager_url ? 'pointer-events-auto cursor-pointer hover:underline' : ''"
                        >
                            <Heading size="2xl" :text="String(card.total)" />
                            <Subheading size="sm">{{ __('workflows::messages.widget_status_entries') }}</Subheading>
                        </component>
                    </div>
                </div>

                <!-- The legend, and the only place the individual numbers are
                     readable — the ring shows proportions, the legend shows
                     counts. No percentages here: they are in the tooltip, and a
                     count plus a percentage per row is two numbers where the
                     reader wanted one. -->
                <div class="flex min-w-0 flex-1 flex-col gap-[6px]">
                    <!-- Every row is a button opening the posts in that state —
                         the same thing clicking its slice of the ring does, and
                         the reason the ring is worth clicking at all: a
                         proportion nobody can follow to the work is a picture.

                         A bare `<button>`, not a kit `Button`: every size
                         variant of that one pins a height (`h-6` at `xs`
                         upwards) and brings its own padding, which would space
                         the legend out to twice the ring's height. The
                         affordance is the addon's usual "this opens something
                         you can read" — pointer plus underline on hover, as on
                         the queue rows and the stat row's figures. Only the
                         LABEL underlines (`group-hover`); underlining the count
                         with it would read as a second link. -->
                    <button
                        v-for="segment in card.segments"
                        :key="segment.state"
                        type="button"
                        class="group flex min-w-0 cursor-pointer items-center gap-2 text-left"
                        :aria-label="__('workflows::messages.widget_stats_show_posts')"
                        @click="openSegment(card, segment)"
                    >
                        <!-- Bound as an inline colour, unlike every other
                             coloured element in the addon: this square is the
                             ring's colour key and has to come from the very same
                             table the canvas is painted from (see colorHex()). -->
                        <span
                            class="size-[8px] shrink-0 rounded-sm"
                            :style="{ backgroundColor: colorHex(segment.color) }"
                            aria-hidden="true"
                        ></span>
                        <span class="min-w-0 flex-1 truncate text-[12px] text-gray-600 group-hover:underline dark:text-gray-400">{{ segment.label }}</span>
                        <span class="flex-none text-[12px] font-semibold text-gray-700 dark:text-gray-300">{{ segment.count }}</span>
                    </button>

                    <!-- A workflow nothing has entered yet. The card is never
                         hidden for it (see the widget's PHP docblock), so the
                         legend says why it is empty instead of leaving a blank
                         column beside a grey ring. -->
                    <span
                        v-if="!card.segments.length"
                        class="text-[12px] text-gray-500 dark:text-gray-400"
                    >{{ __('workflows::messages.widget_status_empty') }}</span>
                </div>
            </div>
        </Card>

        <!-- ONE stack for the whole row, not one per legend row: only one state
             can be open at a time, and a `Stack` per segment would mean a dozen
             of them mounted at once on a three-workflow dashboard. It has no
             `#trigger` — the kit's `Stack` renders the trigger only when the
             slot exists, and here the openers are the legend buttons and the
             ring itself, so it is driven by `v-model:open` instead. -->
        <Stack
            v-model:open="stackOpen"
            :title="stackTitle"
            size="narrow"
            show-close-button
        >
            <!-- Loading. The kit's `Skeleton` (a pulsing gradient block that
                 takes its size from the classes it is given) rather than a
                 spinner: the shape of what is coming is a list, and a list of
                 grey bars says so while a spinner says only "wait". -->
            <div v-if="stackLoading" class="space-y-3 py-2">
                <Skeleton v-for="n in 6" :key="n" class="h-4 w-full" />
            </div>

            <!-- The posts, in exactly the markup the stat row's stacks use
                 (QueueStatsWidget.vue): a bare truncating `<a>` plus a neutral
                 site `Badge` on multisite projects. The two stacks open from
                 neighbouring cards of the same dashboard and would be read as
                 one thing done twice if they differed. -->
            <div v-else-if="stackItems.length" class="divide-y divide-gray-100 dark:divide-gray-800">
                <div v-for="item in stackItems" :key="item.url" class="flex items-center gap-2 py-2">
                    <a
                        :href="item.url"
                        class="min-w-0 flex-1 truncate text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
                    >{{ item.title }}</a>

                    <!-- Neutral: the site is an identifier, not a status. -->
                    <Badge
                        v-if="item.site_code"
                        :text="item.site_code"
                        color="default"
                        size="sm"
                        class="flex-none"
                    />
                </div>
            </div>

            <!-- Loaded and empty. Possible without the counts being wrong: the
                 state row can outlive its entry, and SubjectLinks names nothing
                 it cannot open. -->
            <Description v-else class="py-6 text-center">
                {{ __('workflows::messages.widget_status_empty') }}
            </Description>

            <!-- The pager. The CP's own `Pagination`, fed the endpoint's `meta`
                 straight through — it is a Laravel resource `meta`, which is
                 what the component's `resourceMeta` prop is documented to take,
                 so the totals line and the prev/next controls are the CP's and
                 not ours (their strings come from Statamic's own translations,
                 not from this addon's).

                 `scroll-to-top` OFF: the component's own default scrolls the
                 WINDOW, and this list lives in a slide-over that scrolls itself
                 — paging would jump the dashboard behind the panel instead.
                 `show-page-links` and `show-per-page-selector` are also both
                 forced off: the kit component defaults BOTH to true, so leaving
                 them unset draws page-number links and a per-page dropdown
                 alongside the totals line. The per-page selector in particular
                 reads the CP's global pagination sizes, which have nothing to do
                 with a stack, and 25 is the size this panel is built around. The
                 desired footer is totals plus prev/next only. -->
            <template v-if="stackMeta" #footer-start>
                <!-- `wf-status-pager` marker: the kit `Pagination` has no prop
                     for the spacing of its own prev/next buttons, so that gap is
                     bumped in addon.css instead (see `.wf-status-pager` there),
                     the same restyle-via-marker-class approach as
                     `.wf-widget-tabs` above. -->
                <div class="wf-status-pager">
                    <Pagination
                        :resource-meta="stackMeta"
                        :per-page="PER_PAGE"
                        show-totals
                        :show-page-links="false"
                        :show-per-page-selector="false"
                        :scroll-to-top="false"
                        @page-selected="loadPage"
                    />
                </div>
            </template>

            <!-- The way OUT of the panel, opposite the pager: the pages walk
                 this one state, the manager page is where the reader goes to do
                 something about it (filters, sorting, bulk actions). Omitted
                 entirely for a workflow without `metadata.nav` — there is no
                 page to send anyone to. -->
            <template v-if="stackManagerUrl" #footer-end>
                <a
                    :href="stackManagerUrl"
                    class="truncate text-xs text-blue-600 hover:underline dark:text-blue-400"
                >{{ __('workflows::messages.widget_stack_open_manager') }}</a>
            </template>
        </Stack>
    </div>
</template>

<script setup>
import { computed, getCurrentInstance, nextTick, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Badge, Card, Description, Heading, Pagination, Skeleton, Stack, Subheading } from '@statamic/cms/ui';
import { ArcElement, Chart, DoughnutController, Tooltip } from 'chart.js';
import { colorHex, dotClass } from '../support/widgetColors';

/**
 * Dashboard widget `workflows_status`. A Vue component rather than Blade for two
 * reasons: the ring is a canvas that only exists once the browser has run, and
 * the card shell is the CP's Vue kit (`Card`/`Heading`/`Subheading`), which has
 * no Blade twin.
 *
 * The component computes almost nothing. Labels, colour NAMES, counts, totals
 * and the segment ORDER all arrive finished from WorkflowStatus::component(),
 * so "what does this ring show" is decided in exactly one place. The two things
 * decided here are the ones the server cannot know: the hex behind a colour name
 * (a canvas takes values, not classes) and the percentage in the tooltip.
 *
 * The posts behind a segment are the one thing NOT in the payload: they are
 * fetched from `segment.links_url` when a segment is actually clicked and kept
 * for the life of the page. See WorkflowController::stateSubjects() for why the
 * dashboard must not carry them.
 *
 * Tree-shaken Chart.js registration — controller, arc and tooltip only. Not the
 * `chart.js/auto` bundle: that pulls every controller, scale and plugin the
 * library has, and a doughnut needs no scales at all. Bundled through the
 * addon's existing Vite build, so there is no CDN and no `Statamic::script()`
 * (the addon already ships one bundle, and this rides in it).
 */
Chart.register(DoughnutController, ArcElement, Tooltip);

const props = defineProps({
    // [{workflow, label, color, total, segments: [{state, label, color, count}]}]
    // — one per active workflow, in definition order, segments already sorted.
    // See WorkflowStatus::card().
    cards: { type: Array, default: () => [] },
});

// The empty ring: one full segment in grey, no tooltip. Two values because a
// light grey that reads as "nothing here" on white reads as a bright ring on the
// dark card.
const EMPTY_LIGHT = '#e5e7eb';
const EMPTY_DARK = '#374151';

// canvas element per workflow, filled by the template's ref callback, and the
// live Chart instance per workflow. Keyed by workflow rather than by index so a
// re-render cannot pair a chart with the wrong canvas.
const canvases = new Map();
const charts = new Map();

// The open stack, and what it is showing. One stack for the whole row (see the
// template), so this is the widget's only piece of client state that is not a
// chart.
const stackOpen = ref(false);
const stackTitle = ref('');
const stackLoading = ref(false);
// The segment's endpoint URL and the page of it being shown. Together they are
// the cache key; kept apart so paging can move one without touching the other.
const stackUrl = ref(null);
const stackPage = ref(1);

/**
 * Must match SubjectLinks::PER_PAGE. The server decides the page size — this is
 * only what the pager DISPLAYS as its width, and the component wants it as a
 * prop. If the two ever disagree, the server wins and the totals line lies, so
 * they do not get to disagree.
 */
const PER_PAGE = 25;

/**
 * Fetched pages, keyed by url + page number, for the life of the widget. A
 * state's posts do not change while somebody reads a dashboard, so paging back
 * and forth — or re-opening a segment — should not spend a request to say the
 * same thing again.
 *
 * A REACTIVE map, not a plain one: the computeds below read it, and the write
 * that matters happens after the fetch resolves — with a plain Map nothing
 * would have changed as far as Vue is concerned and the stack would stay on its
 * empty state. `reactive()` tracks Map reads and writes.
 */
const linkCache = reactive(new Map());

const cacheKey = computed(() => `${stackUrl.value}|${stackPage.value}`);
const stackItems = computed(() => linkCache.get(cacheKey.value)?.items ?? []);
// null until the first page has arrived, which is also how the template decides
// whether to render a pager at all — there is nothing to page through while the
// panel is still loading its first answer.
const stackMeta = computed(() => linkCache.get(cacheKey.value)?.meta ?? null);
const stackManagerUrl = computed(() => linkCache.get(cacheKey.value)?.manager_url ?? null);

// Statamic exposes axios via Vue's global properties, not as an importable
// module. Same lookup as QueueStatsWidget.vue and the settings pages.
const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

/**
 * Open the posts sitting in one state — from a legend row or from the ring
 * segment itself, which are the same act and therefore the same function.
 *
 * The title names both halves, because the stack can be opened from any of the
 * three cards and "Veröffentlicht" alone would not say whose. The middot is a
 * separator, not a word: nothing to translate, so it stays in the markup (same
 * convention as PersonalWidgetRow).
 */
async function openSegment(card, segment) {
    stackTitle.value = `${card.label} · ${segment.label}`;
    stackUrl.value = segment.links_url;
    // Always back to page one: the stack was opened for a state, not resumed —
    // and the previous state's page number means nothing here.
    stackPage.value = 1;
    stackOpen.value = true;

    await fetchPage();
}

/**
 * The pager's `page-selected`. Only the page moves; the URL, the title and the
 * open panel all stay as they are.
 */
async function loadPage(page) {
    stackPage.value = page;

    await fetchPage();
}

/**
 * Load whatever `stackUrl` + `stackPage` currently point at, unless it is
 * already cached.
 */
async function fetchPage() {
    const key = cacheKey.value;

    if (linkCache.has(key)) {
        return;
    }

    stackLoading.value = true;

    try {
        // `&`, not `?`: `links_url` is built server-side and always arrives
        // carrying `?workflow=…&state=…`.
        const data = await request('get', `${stackUrl.value}&page=${stackPage.value}`);

        linkCache.set(key, {
            items: data?.items ?? [],
            // Straight through to the pager: the server's paginator is the only
            // thing that knows how many pages there are.
            meta: data?.meta ?? null,
            manager_url: data?.manager_url ?? null,
        });
    } catch (e) {
        // Closed rather than left open on an error: an empty panel with a
        // title is a promise the widget cannot keep, and the toast says what
        // happened. Nothing is cached, so the next click retries.
        stackOpen.value = false;
        window.Statamic?.$toast?.error(
            e?.response?.data?.message || __('workflows::messages.widget_status_load_error'),
        );
    } finally {
        stackLoading.value = false;
    }
}

// The addon's usual shape (see QueueStatsWidget.vue): axios where the CP
// provides it — it carries the CSRF token and the CP's error handling — and a
// hand-rolled fetch where it does not. This endpoint answers with a bare
// {items, more} rather than a `data` envelope, so the payload is returned as it
// arrives.
async function request(method, url) {
    if (axios) {
        const res = await axios({ method, url });

        return res?.data ?? null;
    }

    const res = await fetch(url, {
        method: method.toUpperCase(),
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
    });

    if (!res.ok) throw new Error('request failed');

    return await res.json();
}

// Re-reads the theme when the CP switches it. The CP toggles `dark` on
// <html> live (no reload), and a canvas cannot express "white in light mode,
// gray-850 in dark" the way a CSS class can — the border between segments and
// the empty ring's grey are baked into the chart and have to be repainted.
// A plain binding, not a `ref`: nothing renders it.
let themeObserver = null;

function setCanvas(workflow, el) {
    if (el) {
        canvases.set(workflow, el);
    } else {
        canvases.delete(workflow);
    }
}

onMounted(async () => {
    // One tick, so every ref callback has run and the 116px boxes have been laid
    // out — Chart.js reads the parent's size when it creates the canvas.
    await nextTick();

    props.cards.forEach((card) => {
        const canvas = canvases.get(card.workflow);

        if (canvas) {
            charts.set(card.workflow, build(card, canvas));
        }
    });

    themeObserver = new MutationObserver(repaint);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

onUnmounted(() => {
    // Chart.js keeps its instances in a global registry keyed by canvas and
    // installs resize listeners on each — a widget that is torn down without
    // destroying them leaks both.
    charts.forEach((chart) => chart.destroy());
    charts.clear();
    themeObserver?.disconnect();

    // The tooltip hangs off <body>, i.e. outside everything Vue unmounts here —
    // it has to be taken down by hand or it survives the widget.
    tooltipEl?.remove();
    tooltipEl = null;
    tooltipTitleEl = null;
    tooltipBodyEl = null;
});

function build(card, canvas) {
    const empty = card.total === 0;

    return new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: empty ? [''] : card.segments.map((segment) => segment.label),
            datasets: [{
                data: empty ? [1] : card.segments.map((segment) => segment.count),
                backgroundColor: empty
                    ? [emptyColor()]
                    : card.segments.map((segment) => colorHex(segment.color)),
                // The gap between segments is the card showing through, not a
                // line: read off the surface rather than hardcoded, so it is
                // right in both themes and stays right if the kit restyles
                // `Card`.
                borderColor: surfaceColor(canvas),
                borderWidth: 2,
                // Nothing to lift out of an empty ring — and a single segment
                // that grows on hover just wobbles.
                hoverOffset: empty ? 0 : 6,
            }],
        },
        options: {
            // A ring, not a pie: the hole is where the total goes.
            cutout: '68%',
            responsive: true,
            // The 116px box decides the size; the canvas must not insist on its
            // own aspect ratio inside it.
            maintainAspectRatio: false,
            // A slice opens the same stack its legend row does — the ring is
            // where the eye lands first, and a picture that cannot be followed
            // to the posts is only a picture. `elements` comes back in the
            // dataset's own order, which IS the segment order (the server sorts
            // once and the client draws both from that one array), so the index
            // is the segment.
            onClick: (event, elements) => {
                if (empty || elements.length === 0) {
                    return;
                }

                const segment = card.segments[elements[0].index];

                if (segment) {
                    openSegment(card, segment);
                }
            },
            // The cursor is the only thing that says the ring is clickable.
            // Written on the canvas rather than via a class, because the arc
            // under the pointer is a canvas fact — CSS cannot know whether the
            // cursor is over a slice or over the hole.
            onHover: (event, elements) => {
                const canvasEl = event.native?.target;

                if (canvasEl) {
                    canvasEl.style.cursor = !empty && elements.length > 0 ? 'pointer' : 'default';
                }
            },
            plugins: {
                // The legend is HTML beside the ring, not Chart.js's own: it has
                // to carry absolute counts and truncate long state labels, and
                // it has to look like the rest of the CP.
                legend: { display: false },
                tooltip: {
                    // Chart.js's own tooltip draws INTO the canvas, and this
                    // canvas is 116px square: the tooltip was clipped at its
                    // edge and disappeared behind the absolutely-positioned
                    // total in the ring's hole. So the canvas one is off and an
                    // HTML element is positioned over the page instead — the
                    // documented `external` handler.
                    enabled: false,
                    // "0 entries, 100 %" is not a fact about anything, and an
                    // absent handler is how the tooltip plugin does nothing.
                    external: empty ? undefined : externalTooltip,
                    // Still built by the tooltip plugin even with `enabled:
                    // false` — the external handler is handed the finished
                    // `title` and `body` below, so the percentage callback
                    // stays exactly where it was.
                    callbacks: {
                        // The percentage lives here and nowhere else — see the
                        // legend's note. Computed from the dataset rather than
                        // sent along, so it cannot disagree with the slice it
                        // labels.
                        label: (context) => {
                            const value = context.parsed;
                            const total = context.dataset.data.reduce((sum, n) => sum + n, 0);
                            const share = total === 0 ? 0 : Math.round((value / total) * 100);

                            return `${value} (${share} %)`;
                        },
                    },
                },
            },
        },
    });
}

/**
 * The one HTML tooltip, shared by all three rings.
 *
 * One element rather than one per chart: only one segment can be hovered at a
 * time, so a second element would only ever be an invisible duplicate — and
 * three elements are three things to remember to remove.
 *
 * Appended to `document.body`, because it has to escape BOTH the 116px ring box
 * (it is wider than 116px and would be clipped there) and the centred total
 * that sits on top of the canvas. `position: fixed` rather than absolute: the
 * caret coordinates are added to `getBoundingClientRect()`, which is
 * viewport-relative, and fixed positioning is the coordinate system that
 * matches it — with absolute we would have to add the scroll offsets back by
 * hand.
 *
 * Styled inline, not through a class in addon.css: the element lives outside
 * the widget's DOM and outside the CP's own, and the stylesheet's cascade notes
 * (the `addon-utilities` layer) do not reach it. The look is the one the canvas
 * tooltip had — gray-900, 6px radius, 10px padding, 11.5px text, no colour box.
 * The same dark tooltip in both themes, like the CP's own.
 */
let tooltipEl = null;
let tooltipTitleEl = null;
let tooltipBodyEl = null;

function tooltipElement() {
    if (tooltipEl) {
        return tooltipEl;
    }

    tooltipEl = document.createElement('div');

    Object.assign(tooltipEl.style, {
        position: 'fixed',
        // Above the CP's cards and the dashboard's own stacking contexts,
        // below its modals and stacks — nothing of ours can be open while a
        // ring is being hovered anyway.
        zIndex: '50',
        // It follows the cursor over the very segments it describes; catching
        // the pointer would make the chart flicker between hover states.
        pointerEvents: 'none',
        opacity: '0',
        transition: 'opacity 120ms ease',
        backgroundColor: '#111827',
        color: '#ffffff',
        borderRadius: '6px',
        padding: '10px',
        fontSize: '11.5px',
        lineHeight: '1.35',
        whiteSpace: 'nowrap',
        // Anchored bottom-centre on the caret, i.e. the tooltip sits above the
        // segment — the same place the canvas one appeared before it started
        // colliding with the total.
        transform: 'translate(-50%, -100%)',
    });

    tooltipTitleEl = document.createElement('div');
    tooltipTitleEl.style.fontWeight = '600';
    tooltipBodyEl = document.createElement('div');

    tooltipEl.append(tooltipTitleEl, tooltipBodyEl);
    document.body.appendChild(tooltipEl);

    return tooltipEl;
}

/**
 * Chart.js's `external` tooltip handler: called on every tooltip change with
 * the model the plugin would have drawn. Text via `textContent`, never
 * innerHTML — the title is a state label out of the project's settings.
 */
function externalTooltip({ chart, tooltip }) {
    const el = tooltipElement();

    // Hover left the ring. Hidden rather than removed: it is reused by the next
    // hover, on this chart or on either of its neighbours.
    if (tooltip.opacity === 0) {
        el.style.opacity = '0';

        return;
    }

    tooltipTitleEl.textContent = tooltip.title?.[0] ?? '';
    // The `label` callback's line — "12 (34 %)".
    tooltipBodyEl.textContent = tooltip.body?.[0]?.lines?.[0] ?? '';

    const canvas = chart.canvas.getBoundingClientRect();

    el.style.left = `${canvas.left + tooltip.caretX}px`;
    // 8px of air between the caret and the tooltip's bottom edge.
    el.style.top = `${canvas.top + tooltip.caretY - 8}px`;
    el.style.opacity = '1';
}

/**
 * The colour the ring is drawn ON: the first painted background above the
 * canvas. Walked up the tree rather than named, because the surface belongs to
 * the kit's `Card` (`bg-white dark:bg-gray-850`) and duplicating those two
 * values here would leave two places to fix when the kit changes one.
 */
function surfaceColor(el) {
    for (let node = el; node instanceof Element; node = node.parentElement) {
        const background = window.getComputedStyle(node).backgroundColor;

        // Anything but fully transparent. `rgba(0, 0, 0, 0)` is what every
        // browser reports for an unpainted element.
        if (background && background !== 'transparent' && !background.startsWith('rgba(0, 0, 0, 0)')) {
            return background;
        }
    }

    return '#ffffff';
}

function emptyColor() {
    return document.documentElement.classList.contains('dark') ? EMPTY_DARK : EMPTY_LIGHT;
}

/**
 * Theme switched: re-read the two colours that are baked into the canvas and
 * redraw. `'none'` skips the animation — this is a repaint, not a change of
 * data, and rings that spin up again on every theme toggle look like a reload.
 */
function repaint() {
    charts.forEach((chart, workflow) => {
        const dataset = chart.data.datasets[0];
        const card = props.cards.find((candidate) => candidate.workflow === workflow);

        dataset.borderColor = surfaceColor(chart.canvas);

        if (card && card.total === 0) {
            dataset.backgroundColor = [emptyColor()];
        }

        chart.update('none');
    });
}
</script>
