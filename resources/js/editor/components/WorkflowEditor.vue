<template>
    <div ref="root" class="wf-editor" :style="{ height }">
        <Toolbar
            v-model:minimap="minimap"
            v-model:show-system="showSystem"
            :has-system-steps="hasSystemSteps"
            :form-view-label="formViewLabel"
            @zoom-in="canvas?.zoomIn()"
            @zoom-out="canvas?.zoomOut()"
            @fit="canvas?.fit()"
            @auto-layout="canvas?.autoLayout()"
            @form-view="$emit('form-view')"
        />
        <div class="wf-editor-canvas">
            <Canvas
                ref="canvas"
                :model="model"
                :injected="injected"
                :ctx="ctx"
                :show-system="showSystem"
                :show-minimap="minimap"
            />
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';
import { humanize } from '../../support/states.js';
import { normalize } from '../model/normalize.js';
import Canvas from './Canvas.vue';
import Toolbar from './Toolbar.vue';
import { EDITOR_CONTEXT } from './context.js';

// The settings page hands over its whole prop set; only what is declared here is
// used, the rest must not end up as attributes on the root element.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    // The stored definition, as editSettings() sends it.
    definition: { type: Object, default: () => ({}) },
    // The live editor model (normalize()) when a host page already holds one —
    // the form view, so the graph shows its unsaved edits. Without it the editor
    // normalizes `definition` itself.
    model: { type: Object, default: null },
    // { places, transitions } DefinitionLoader adds at read time.
    injected: { type: Object, default: () => ({ places: {}, transitions: {} }) },
    stateUsage: { type: Object, default: () => ({}) },
    labelLocales: { type: Array, default: () => ['de', 'en'] },
    labelLocale: { type: String, default: 'de' },
    shippedPlaceLabels: { type: Object, default: () => ({}) },
    customActions: { type: Object, default: () => ({ transition: [], place: [] }) },
    // Text of the toolbar's "back to the form" button; empty → no button.
    formViewLabel: { type: String, default: '' },
    // Space kept free below the editor (the host page's bottom padding), px.
    bottomGap: { type: Number, default: 24 },
});

defineEmits(['form-view']);

const ownModel = props.model ? null : reactive(normalize(props.definition, { labelLocales: props.labelLocales }));
const model = computed(() => props.model ?? ownModel);

const ctx = computed(() => ({
    labelLocales: props.labelLocales,
    labelLocale: props.labelLocale,
    shippedPlaceLabels: props.shippedPlaceLabels,
    stateUsage: props.stateUsage,
    humanize,
}));

provide(EDITOR_CONTEXT, {
    get customActions() { return props.customActions; },
});

const hasSystemSteps = computed(() =>
    Object.keys(props.injected?.places ?? {}).length + Object.keys(props.injected?.transitions ?? {}).length > 0,
);

// Viewer preferences, per browser. Storage can be unavailable (private mode,
// blocked site data) — then they simply don't stick.
const PREFS_KEY = 'workflows.editor.prefs';
function readPrefs() {
    try {
        return JSON.parse(window.localStorage.getItem(PREFS_KEY) ?? '{}') ?? {};
    } catch {
        return {};
    }
}
const prefs = readPrefs();
const minimap = ref(prefs.minimap === true);
const showSystem = ref(prefs.showSystem !== false);
watch([minimap, showSystem], () => {
    try {
        window.localStorage.setItem(PREFS_KEY, JSON.stringify({ minimap: minimap.value, showSystem: showSystem.value }));
    } catch {
        // not persisted — fine
    }
});

// Full available height: from wherever the editor starts down to the bottom of
// the viewport. Re-measured when anything above it changes size (a banner, the
// sticky header wrapping) and on window resize.
const root = ref(null);
const canvas = ref(null);
const top = ref(0);
const height = computed(() => `calc(100dvh - ${Math.max(0, Math.round(top.value))}px - ${props.bottomGap}px)`);

function measure() {
    if (!root.value) return;
    const scroller = document.scrollingElement ?? document.documentElement;
    top.value = root.value.getBoundingClientRect().top + (scroller?.scrollTop ?? 0);
}

let observer = null;
onMounted(() => {
    measure();
    window.addEventListener('resize', measure);
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(() => measure());
        observer.observe(document.body);
        if (root.value?.parentElement) observer.observe(root.value.parentElement);
    }
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', measure);
    observer?.disconnect();
});
</script>
