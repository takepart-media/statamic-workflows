<template>
    <!-- The bar lives in a Card: the frame IS part of the design — white panel,
         rounded, hairline ring, soft shadow — and Statamic's Card already is
         exactly that, so there is no reason to rebuild it here. -->
    <Card>
        <nav
            :aria-label="__('workflows::messages.progress_label')"
            :class="vertical ? 'flex flex-col' : 'relative flex w-full items-start'"
        >
            <!-- ONE rail for the whole bar, drawn behind the dots.
                 It used to be two half-segments per cell, and that is what made the
                 line look broken: `rounded-full` put a cap on both ends of every
                 half, so each junction pinched, and adjacent cells round their
                 widths independently, which adds a sub-pixel gap on top. A single
                 absolutely positioned element cannot break.
                 Inset by half a cell on each side so it starts and ends at the
                 CENTRE of the first and last dot instead of running off the edge. -->
            <span
                v-if="!vertical && steps.main.length > 1"
                :class="['pointer-events-none absolute', RAIL_H, RAIL]"
                :style="railStyle"
                aria-hidden="true"
            />
            <!-- The walked part of the rail, laid over the grey one in the theme's
                 primary colour. A second element rather than per-segment classes
                 because the rail below is deliberately ONE element — see its note —
                 and a fill that stops at the current dot is the same idea a
                 progress track has always had: solid behind you, grey ahead. -->
            <span
                v-if="!vertical && fillIndex > 0"
                :class="['pointer-events-none absolute', RAIL_H, PRIMARY_BG]"
                :style="fillStyle"
                aria-hidden="true"
            />

            <div
                v-for="(step, i) in steps.main"
                :key="step.handle"
                :class="vertical ? 'flex gap-3' : 'relative flex min-w-0 flex-1 flex-col items-center'"
            >
                <!-- ============ vertical: rail runs down this column ============
                     Per-row here rather than one absolute element: the rows have
                     different heights (labels wrap), so there is no fixed offset
                     the last dot's centre could be measured from. Continuous
                     anyway — the pieces are `flex-1` inside adjoining rows with no
                     gap and no rounded caps. -->
                <div v-if="vertical" class="flex flex-col items-center self-stretch">
                    <!-- Every marker is the same thing: a thin 18px ring inside a
                         24px box, differing only in colour and in what sits inside
                         it. The finished one is the `check-circle` icon whole — its
                         own circle IS the ring, at exactly 18px when the svg is
                         24px — so a tick, a dot and nothing at all read as three
                         states of one part rather than three different parts.
                         The card-coloured fill is not decoration: the rail runs
                         behind these, and a hollow ring would have the line
                         crossing through its middle.
                         Duplicated in the horizontal branch below rather than
                         extracted: hoisting it would mean a second component file
                         or building the SVG in `h()`, both of which cost more than
                         the lines they save. -->
                    <svg
                        v-if="isDone(i)"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        :class="['relative shrink-0 rounded-full', DOT_SIZE, CARD_BG, PRIMARY_DONE]"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span
                        v-else
                        :class="['relative grid shrink-0 place-items-center', DOT_SIZE]"
                        :aria-current="isCurrent(step.handle) ? 'step' : undefined"
                    >
                        <span :class="['grid place-items-center rounded-full', RING_SIZE, RING_BORDER, CARD_BG, ringClass(step.handle)]">
                            <!-- A dot in the middle only where the subject actually
                                 is. An unreached step is an empty ring — the same
                                 shape, nothing in it. -->
                            <span v-if="isCurrent(step.handle)" :class="['rounded-full', PIP_SIZE, pipClass(step.handle)]" />
                        </span>
                    </span>

                    <!-- Coloured per piece here, unlike the horizontal fill: the
                         rail is already one element per row (see the note above),
                         so the walked part is simply the pieces below a walked
                         step. -->
                    <span
                        v-if="i < steps.main.length - 1 || anchorIndex === i"
                        :class="[RAIL_W, 'flex-1', i < fillIndex ? PRIMARY_BG : RAIL]"
                        style="min-height: 1.25rem"
                    />
                </div>

                <!-- ============ horizontal: dot sits on the shared rail ============ -->
                <!-- See the note in the vertical branch for why this is a copy. -->
                <svg
                    v-else-if="isDone(i)"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="1.5"
                    stroke="currentColor"
                    :class="['relative shrink-0 rounded-full', DOT_SIZE, CARD_BG, PRIMARY_DONE]"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span
                    v-else
                    :class="['relative grid shrink-0 place-items-center', DOT_SIZE]"
                    :aria-current="isCurrent(step.handle) ? 'step' : undefined"
                >
                    <span :class="['grid place-items-center rounded-full', RING_SIZE, RING_BORDER, CARD_BG, ringClass(step.handle)]">
                        <span v-if="isCurrent(step.handle)" :class="['rounded-full', PIP_SIZE, pipClass(step.handle)]" />
                    </span>
                </span>

                <div :class="vertical ? 'min-w-0 pt-0.5 pb-5' : 'mt-2 w-full min-w-0 px-1 text-center'">
                    <p :class="['text-sm leading-tight', labelClass(step.handle)]">{{ step.label }}</p>

                    <!-- The branch, drawn off the step it was taken from. Rendered
                         inside that step's cell so it cannot shift the main line's
                         spacing — which is the whole point of a stub rather than a
                         step. -->
                    <div v-if="anchorIndex === i" :class="vertical ? 'mt-2' : 'mt-2 flex flex-col items-center'">
                        <span v-if="!vertical" :class="['h-4', RAIL_W, RAIL]" />
                        <div :class="['flex items-center gap-2', vertical ? '' : 'mt-1']">
                            <span v-if="vertical" :class="[RAIL_H, 'w-4 shrink-0', RAIL]" />
                            <span :class="['grid shrink-0 place-items-center', DOT_SIZE]" aria-current="step">
                                <span :class="['grid place-items-center rounded-full', RING_SIZE, RING_BORDER, CARD_BG, branchRing]">
                                    <span :class="['rounded-full', PIP_SIZE, branchPip]" />
                                </span>
                            </span>
                            <p :class="['text-sm leading-tight', LABEL_CURRENT]">{{ steps.branch.label }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- A branch with no anchor: the current state is not a place this
                 workflow declares — a leftover row from a place that was removed
                 while a subject still sat in it. Drawn apart, with a note, because
                 the alternative is a subject that appears to be nowhere at all. -->
            <div
                v-if="steps.branch && anchorIndex === null"
                :class="vertical ? 'mt-1 flex flex-col gap-1' : 'flex shrink-0 flex-col items-center ps-6'"
            >
                <div class="flex items-center gap-2">
                    <span :class="['grid shrink-0 place-items-center', DOT_SIZE]" aria-current="step">
                        <span :class="['grid place-items-center rounded-full', RING_SIZE, RING_BORDER, CARD_BG, branchRing]">
                            <span :class="['rounded-full', PIP_SIZE, branchPip]" />
                        </span>
                    </span>
                    <p :class="['text-sm leading-tight', LABEL_CURRENT]">{{ steps.branch.label }}</p>
                </div>
                <p :class="['text-xs', LABEL_IDLE, vertical ? '' : 'mt-1 text-center']">
                    {{ __('workflows::messages.progress_unknown_place') }}
                </p>
            </div>
        </nav>

        <!-- Not a step: the subject has no state in this workflow at all (an
             unpublished post in a localization manager). Nothing is coloured above
             and this says why — what the badge used to do with `state_none`. -->
        <p v-if="steps.current === null" :class="['text-xs', LABEL_IDLE]">
            {{ __('workflows::messages.state_none') }}
        </p>
    </Card>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Card } from '@statamic/cms/ui';
import { stateColor } from '../support/states.js';
import { placeColorClasses } from '../support/placeColors.js';

/**
 * The workflow's places as a progress bar — replaces the single state badge that
 * used to sit in the summary header.
 *
 * The server decides WHAT is a step and what hangs off the line (see
 * Workflow\ProgressSteps); this decides how it looks.
 *
 * ONLY THE CURRENT STEP CARRIES COLOUR. Everything else — every other dot, the
 * whole rail, the other labels — is one quiet grey. It used to colour every
 * reached step in its own place colour, and on a workflow that configures none
 * (the post manager configures none) that produced three arbitrary-looking
 * colours: `stateColor()` falls back to a built-in per-handle map that happens to
 * know `in_review`, `medical_review` and `published` and nothing else. The result
 * read as meaning something it did not. One colour, one meaning: you are here.
 */
const props = defineProps({
    // {main: [{handle, label}], branch: {handle, label, after}|null, current: handle|null}
    steps: { type: Object, required: true },
    orientation: { type: String, default: 'horizontal' },
});

// The quiet greys, in one place because they have to agree: the rail and an idle
// dot are the same family a shade apart, so the dot reads as a bead ON the line
// rather than a break IN it. Light and dark are picked against the Card's own
// surfaces (white / gray-850) rather than mirrored blindly.
const RAIL = 'bg-gray-200 dark:bg-gray-700!';

/**
 * The theme's primary colour, for the walked path and the steps behind it.
 *
 * Referenced as the CSS variable rather than a `primary` utility, because no such
 * utility exists: Statamic ships `--color-primary: var(--theme-color-primary)` as a
 * theme variable but no `.text-primary`/`.bg-primary` class — checked against both
 * of its stylesheets. The variable itself is emitted inline by the CP head partial
 * per `:root` and `:root.dark`, so it already follows the user's theme AND the
 * colour mode; that is why these two carry no `dark:` variant.
 */
const PRIMARY_BG = 'bg-[var(--theme-color-primary)]';

// A finished step is drawn in the same hue as the rail it completed, let through a
// little — the line reads as the live thing, the ticks as settled. A STROKE colour:
// the tick icon is `fill="none"`, so its circle and check are both `currentColor`.
const PRIMARY_DONE = 'text-[var(--theme-color-primary)]/85';

// Stroke widths, as numbers AND as the classes that realise them — kept next to
// each other because railStyle() below has to centre the rail on the dot, and the
// two drifting apart is exactly the kind of off-by-a-pixel nobody spots.
// 2px rather than 4: at 4 the rail and the halo read as a border around the bar
// instead of a line through it.
const DOT_PX = 24; // size-6
const RAIL_PX = 2; // h-0.5 / w-0.5
const RAIL_H = 'h-0.5';
const RAIL_W = 'w-0.5';
const HALO = 'ring-2';
const RING_IDLE = 'border-gray-300 dark:border-gray-600!';
const LABEL_IDLE = 'font-medium text-gray-400 dark:text-gray-500!';
const LABEL_CURRENT = 'font-semibold text-gray-900 dark:text-gray-100!';

/**
 * Marker geometry, all of it derived from the tick icon rather than chosen freely.
 *
 * The `check-circle` glyph draws its circle at r=9 inside a 24 viewBox, so at
 * `size-6` its visible ring is 18px across with a 1.5px stroke. Every other marker
 * copies exactly that: an 18px circle with a 1.5px border, centred in the same 24px
 * box. That box is also what railStyle() measures against, which is why DOT_PX stays
 * 24 even though nothing is 24px wide any more.
 *
 * These were filled 24px discs once, and the tick icon among them looked like a
 * different species — a thin outline beside solid blobs. Matching the discs to the
 * icon rather than the icon to the discs keeps the shape the icon set gives us.
 */
const DOT_SIZE = 'size-6';
const RING_SIZE = 'size-[18px]';
const RING_BORDER = 'border-[1.5px]';
// The dot in the middle of the current marker — "you are here" inside the ring.
const PIP_SIZE = 'size-2';

// The Card's own surface. Fills every marker so the rail cannot be seen running
// through it — a hollow ring on a line looks like a bead threaded on it only if the
// line stops at its edge.
//
// The dark half is the CSS VARIABLE, not `dark:bg-gray-850`, and that is the whole
// bug this line once had. `bg-white dark:bg-gray-850` is the CP's own panel pairing,
// so it looks like the obvious thing to write — but 850 is not a Tailwind shade. The
// CP invents it (`--color-gray-850: var(--theme-color-gray-850)` in its own theme),
// while this addon builds against stock `tailwindcss/theme.css`, which stops at 800
// and 900. The utility therefore never got emitted: verified against the built CSS,
// where every other class in this file is present and this one alone was missing.
//
// Nothing failed loudly. The markers simply kept `bg-white` in dark mode and turned
// into solid white discs on the dark card — the ring's border and the rail behind it
// buried under an opaque fill, with light mode looking perfect the whole time.
//
// An arbitrary value always compiles, whatever the theme knows, and PRIMARY_BG below
// leans on the same property. `--theme-color-gray-850` is emitted by the CP per
// `:root` and `:root.dark`, so it also follows the user's chosen theme.
const CARD_BG = 'bg-white dark:bg-[var(--theme-color-gray-850)]!';

// Vertical when asked for, and always below `sm` — a horizontal bar of seven
// labelled steps is unreadable on a phone. An explicit `vertical` is never
// overridden the other way: a caller that put it in a side column meant it.
const narrow = ref(false);
let query = null;
const onChange = (e) => (narrow.value = e.matches);

onMounted(() => {
    query = window.matchMedia('(max-width: 639px)');
    narrow.value = query.matches;
    query.addEventListener('change', onChange);
});

onBeforeUnmount(() => query?.removeEventListener('change', onChange));

const vertical = computed(() => props.orientation === 'vertical' || narrow.value);

/**
 * Where the shared rail starts and ends.
 *
 * Every cell is `flex-1`, so a dot sits at the centre of its cell — half a cell
 * in from each end of the bar. Inset by exactly that and the rail spans dot centre
 * to dot centre. `top` is computed from the two widths rather than written down, so
 * changing the stroke cannot leave the rail a pixel off the dot's middle.
 */
const railStyle = computed(() => {
    const inset = `${50 / props.steps.main.length}%`;

    return { left: inset, right: inset, top: `${(DOT_PX - RAIL_PX) / 2}px` };
});

/**
 * The walked part of the rail: from the first dot's centre to the current one's.
 *
 * Both ends fall out of the same geometry railStyle() uses — cell k's centre sits
 * at `(k + 0.5) * 100/n` percent, the rail starts at `50/n`, so the fill is exactly
 * `k * 100/n` percent wide. At k = 0 that is zero, which is right: standing on the
 * first step, nothing has been walked yet.
 */
const fillStyle = computed(() => {
    const n = props.steps.main.length;

    return {
        left: `${50 / n}%`,
        width: `${(fillIndex.value * 100) / n}%`,
        top: `${(DOT_PX - RAIL_PX) / 2}px`,
    };
});

// Where the branch attaches. null covers both "no branch" and "a branch whose
// anchor this workflow does not contain" — the template tells the two apart by
// also checking `steps.branch`.
const anchorIndex = computed(() => {
    if (!props.steps.branch?.after) return null;
    const i = props.steps.main.findIndex((s) => s.handle === props.steps.branch.after);

    return i === -1 ? null : i;
});

function isCurrent(handle) {
    return props.steps.current === handle;
}

/**
 * How far the filled part of the rail reaches: the subject's position ON THE MAIN
 * LINE.
 *
 * Not simply the index of `current`: standing on a branch, the position on the line
 * is the step the branch was taken from. Without that a failed post would draw as
 * if it had never started.
 */
const fillIndex = computed(() => {
    if (props.steps.current === null) return -1;

    const i = props.steps.main.findIndex((s) => s.handle === props.steps.current);

    return i !== -1 ? i : (anchorIndex.value ?? -1);
});

/**
 * The last step that counts as FINISHED — a tick rather than a dot.
 *
 * One index short of the fill when the subject stands on the line: the step you are
 * on is not behind you, so it keeps its dot and its halo.
 *
 * On a branch it is the fill itself, i.e. the anchor is ticked. The subject really
 * did pass through it and then turned off, and the alternative looked broken: the
 * primary rail ran up to the anchor and ended on a pale grey dot that was
 * indistinguishable from the steps nobody has reached.
 */
const doneUntil = computed(() =>
    props.steps.main.findIndex((s) => s.handle === props.steps.current) !== -1
        ? fillIndex.value - 1
        : fillIndex.value,
);

function isDone(i) {
    return i <= doneUntil.value;
}

// The current step's colour — the map (one entry per Badge colour, literal class
// strings for Tailwind's scanner) lives in support/placeColors.js.
function colorFor(handle) {
    return placeColorClasses(stateColor(handle));
}

// The ring's border: the place's own colour plus a halo of the same hue for the step
// the subject is on, the quiet grey for one it has not reached.
function ringClass(handle) {
    if (! isCurrent(handle)) return RING_IDLE;

    const c = colorFor(handle);

    return `${c.border} ${HALO} ${c.ring}`;
}

function pipClass(handle) {
    return colorFor(handle).dot;
}

function labelClass(handle) {
    return isCurrent(handle) ? LABEL_CURRENT : LABEL_IDLE;
}

// The branch marker is always the current one — the branch is only drawn when the
// subject stands in it. Null-safe although both use sites sit behind a `v-if`: a
// computed that assumes a prop is set is a trap for the next edit, and not assuming
// it costs one `?.`.
const branchRing = computed(() => {
    const c = colorFor(props.steps.branch?.handle);

    return `${c.border} ${HALO} ${c.ring}`;
});

const branchPip = computed(() => colorFor(props.steps.branch?.handle).dot);
</script>
