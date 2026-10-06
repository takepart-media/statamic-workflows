<template>
    <div ref="root" class="wf-editor" :style="{ height }">
        <Toolbar
            v-model:minimap="minimap"
            v-model:show-system="showSystem"
            :has-system-steps="hasSystemSteps"
            :form-view-label="formViewLabel"
            :saving="saving"
            :dirty="api.dirty.value"
            :errors="saveErrors.state.errors"
            :panel-collapsed="panelCollapsed"
            @zoom-in="canvas?.zoomIn()"
            @zoom-out="canvas?.zoomOut()"
            @fit="canvas?.fit()"
            @auto-layout="canvas?.autoLayout()"
            @form-view="$emit('form-view')"
            @add-place="addPlace"
            @add-transition="addTransition"
            @save="save()"
            @select-error="selectError"
            @expand-panel="panelCollapsed = false"
        />
        <div ref="body" class="wf-editor-body">
            <!-- Rendered once the width is known: the panel's default size is a
                 percentage, and ~420px depends on how wide the editor is. -->
            <SplitterGroup v-if="bodyWidth" direction="horizontal" auto-save-id="workflows.editor.split">
                <SplitterPanel id="wf-editor-canvas" :order="1" :min-size="30">
                    <div class="wf-editor-canvas h-full">
                        <Canvas
                            ref="canvas"
                            :model="api.view.value"
                            :graph="graph"
                            :injected="injected"
                            :ctx="labelCtx"
                            :show-system="showSystem"
                            :show-minimap="minimap"
                            :selected="selectedId"
                            :connection-rule="api.canConnect"
                            @select="select"
                            @node-moved="({ id, position }) => api.setPosition(id, position)"
                            @positions="({ positions, touched }) => api.setPositions(positions, { touched })"
                            @connect="onConnect"
                            @connect-refused="({ reason }) => refuse(reason)"
                            @connect-drop="onConnectDrop"
                            @pane-dblclick="({ position }) => createAt('place', position)"
                            @delete="onDelete"
                            @add-menu="({ id }) => openAddMenuFor(id)"
                        >
                            <template #context-menu="{ target }">
                                <!-- A place -->
                                <template v-if="target.kind === 'node' && target.type === 'place'">
                                    <ContextItem icon="eye" :text="__('workflows::messages.editor_open')" @select="select(target.id)" />
                                    <ContextItem icon="plus" :text="__('workflows::messages.editor_add_next')" @select="openAddMenuFor(target.id, { delay: MENU_CLOSE_DELAY })" />
                                    <ContextItem
                                        v-if="api.model.workflow.initial_place !== target.id"
                                        icon="pin"
                                        :text="__('workflows::messages.editor_set_initial')"
                                        @select="api.setInitialPlace(target.id)"
                                    />
                                    <ContextSeparator />
                                    <ContextItem icon="trash" variant="destructive" :text="__('workflows::messages.delete')" @select="deleteNodes([target.id])" />
                                </template>
                                <!-- A transition -->
                                <template v-else-if="target.kind === 'node' && target.type === 'transition'">
                                    <ContextItem icon="eye" :text="__('workflows::messages.editor_open')" @select="select(target.id)" />
                                    <ContextItem icon="plus" :text="__('workflows::messages.editor_add_next')" @select="openAddMenuFor(target.id, { delay: MENU_CLOSE_DELAY })" />
                                    <ContextSeparator />
                                    <ContextItem icon="trash" variant="destructive" :text="__('workflows::messages.delete')" @select="deleteNodes([target.id])" />
                                </template>
                                <!-- A line -->
                                <template v-else-if="target.kind === 'edge'">
                                    <ContextItem icon="eye" :text="__('workflows::messages.editor_open')" @select="select(target.edge.transition)" />
                                    <ContextSeparator />
                                    <ContextItem icon="trash" variant="destructive" :text="__('workflows::messages.editor_disconnect')" @select="disconnect([target.edge])" />
                                </template>
                                <!-- Several nodes (selection box) -->
                                <template v-else-if="target.kind === 'selection'">
                                    <ContextItem icon="trash" variant="destructive" :text="__('workflows::messages.delete')" @select="deleteNodes(target.ids)" />
                                </template>
                                <!-- The empty canvas -->
                                <template v-else>
                                    <ContextItem icon="plus" :text="__('workflows::messages.editor_add_place_here')" @select="createAt('place', target.position, { delay: MENU_CLOSE_DELAY })" />
                                    <ContextItem icon="plus" :text="__('workflows::messages.editor_add_transition_here')" @select="createAt('transition', target.position, { delay: MENU_CLOSE_DELAY })" />
                                </template>
                            </template>
                        </Canvas>
                    </div>
                </SplitterPanel>
                <template v-if="!panelCollapsed">
                    <SplitterResizeHandle id="wf-editor-resize" />
                    <SplitterPanel id="wf-editor-panel" :order="2" :default-size="panelSize.default" :min-size="panelSize.min" :max-size="70">
                        <NodePanel
                            :selected-id="selectedId"
                            :place-tab="placeTab"
                            @collapse="panelCollapsed = true"
                            @reset-positions="resetPositions"
                        />
                    </SplitterPanel>
                </template>
            </SplitterGroup>
        </div>

        <AddNodeMenu
            v-model:open="addMenu.open"
            :x="addMenu.x"
            :y="addMenu.y"
            :title="addMenu.title"
            :items="addMenuItems"
            :group-labels="addMenuGroups"
            @pick="pickAddMenu"
        />

        <CollectionRemovalDialog
            v-model:open="collectionRemoval.open"
            :collections="collectionRemoval.collections"
            :affected-count="collectionRemoval.affectedCount"
            :collection-options="collectionOptions"
            :busy="saving"
            @confirm="confirmCollectionRemoval"
        />

        <!-- 409: the file changed underneath the editor. Either take the stored
             version (local edits are lost) or overwrite it on purpose. -->
        <ConfirmationModal
            :open="conflict.open"
            :title="__('workflows::messages.editor_conflict_title')"
            :button-text="__('workflows::messages.editor_conflict_overwrite')"
            danger
            @update:open="(v) => (conflict.open = v)"
            @confirm="overwrite"
        >
            <div class="space-y-3">
                <p class="text-sm">{{ __('workflows::messages.settings_version_conflict') }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('workflows::messages.editor_conflict_body') }}</p>
                <Button icon="sync" :text="__('workflows::messages.editor_conflict_reload')" @click="reloadFromConflict" />
            </div>
        </ConfirmationModal>
    </div>
</template>

<script setup>
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';
import { Button, ConfirmationModal, ContextItem, ContextSeparator, SplitterGroup, SplitterPanel, SplitterResizeHandle } from '@statamic/cms/ui';
import { humanize } from '../../support/states.js';
import { ACTION_TYPES, PLACE_ACTION_TYPES, SHARED_ACTION_TYPES } from '../model/constants.js';
import { keyOf, placeIdOf, transitionIdOf } from '../model/editorModel.js';
import { besideAnchor, centredAt } from '../model/canvasActions.js';
import { placeDisplayName } from '../model/labels.js';
import { useWorkflowModel } from '../composables/useWorkflowModel.js';
import { useSaveErrors } from '../composables/useSaveErrors.js';
import AddNodeMenu from './AddNodeMenu.vue';
import Canvas from './Canvas.vue';
import Toolbar from './Toolbar.vue';
import NodePanel from './panels/NodePanel.vue';
import CollectionRemovalDialog from './shared/CollectionRemovalDialog.vue';
import { EDITOR_CONTEXT } from './context.js';

// The settings page hands over its whole prop set; only what is declared here is
// used, the rest must not end up as attributes on the root element.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    // The stored definition (editSettings() `definition`, or what the last save
    // returned). Read ONCE: from then on the editor owns its model.
    definition: { type: Object, default: () => ({}) },
    // Fingerprint of that definition, sent back with every save (409 on mismatch).
    version: { type: String, default: '' },
    workflow: { type: String, default: '' },
    slug: { type: String, default: '' },
    active: { type: Boolean, default: false },
    activeUrl: { type: String, default: '' },
    saveDefinitionUrl: { type: String, default: '' },
    validateUrl: { type: String, default: '' },
    usageUrl: { type: String, default: '' },
    // { places, transitions } DefinitionLoader adds at read time.
    injected: { type: Object, default: () => ({ places: {}, transitions: {} }) },
    stateUsage: { type: Object, default: () => ({}) },
    reservedPlaces: { type: Array, default: () => [] },
    reservedTransitions: { type: Array, default: () => [] },
    labelLocales: { type: Array, default: () => ['de', 'en'] },
    labelLocale: { type: String, default: 'de' },
    shippedPlaceLabels: { type: Object, default: () => ({}) },
    colorOptions: { type: Array, default: () => [] },
    variantOptions: { type: Array, default: () => [] },
    progressOrientationOptions: { type: Array, default: () => [] },
    collectionOptions: { type: Array, default: () => [] },
    collectionOwners: { type: Object, default: () => ({}) },
    groupOptions: { type: Array, default: () => [] },
    roleOptions: { type: Array, default: () => [] },
    userOptions: { type: Array, default: () => [] },
    assignmentCriteria: { type: Array, default: () => [] },
    customActions: { type: Object, default: () => ({ transition: [], place: [] }) },
    siteOptions: { type: Array, default: () => [] },
    postCreatorFallback: { type: Array, default: () => [] },
    assignerFallback: { type: Array, default: () => [] },
    envStatus: { type: Object, default: () => ({}) },
    mailStatus: { type: Object, default: null },
    reminderTime: { type: String, default: null },
    mailTypes: { type: Array, default: () => [] },
    // Text of the toolbar's "back to the form" button; empty → no button.
    formViewLabel: { type: String, default: '' },
    // Space kept free below the editor (the host page's bottom padding), px.
    bottomGap: { type: Number, default: 24 },
});

// saved: {definition, version, stateUsage} after a save (or a reload after a
//        conflict) — the host keeps its copy of the stored definition current.
// dirty-change: whether there are unsaved changes.
const emit = defineEmits(['form-view', 'saved', 'dirty-change']);

// Statamic CP exposes axios via Vue's global properties, not as a bare import.
const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

// --- model ---

const modelCtx = {
    get labelLocales() { return props.labelLocales; },
    get reservedPlaces() { return props.reservedPlaces; },
    get reservedTransitions() { return props.reservedTransitions; },
    get customActions() { return props.customActions; },
};

const api = useWorkflowModel(props.definition, modelCtx);
const version = ref(props.version);
const stateUsage = reactive({ ...(props.stateUsage ?? {}) });
const saveErrors = useSaveErrors();

watch(() => api.dirty.value, (dirty) => emit('dirty-change', dirty), { immediate: true });

// labels.js / nodeInfo.js context.
const labelCtx = computed(() => ({
    labelLocales: props.labelLocales,
    labelLocale: props.labelLocale,
    shippedPlaceLabels: props.shippedPlaceLabels,
    stateUsage,
    humanize,
}));

// actionRules.js context: the type lists with their labels — built-in ones from
// the language file, project ones translated by the server.
const builtInActionLabel = (scope, value) => __(`workflows::messages.${
    scope === 'place' && !SHARED_ACTION_TYPES.includes(value) ? 'place_action_type' : 'action_type'
}_${value}`);

const ruleCtx = {
    get actionTypeOptions() {
        return [
            ...ACTION_TYPES.map((value) => ({ value, label: builtInActionLabel('transition', value) })),
            ...(props.customActions?.transition ?? []).map(({ value, label }) => ({ value, label })),
        ];
    },
    get placeActionTypeOptions() {
        return [
            ...PLACE_ACTION_TYPES.map((value) => ({ value, label: builtInActionLabel('place', value) })),
            ...(props.customActions?.place ?? []).map(({ value, label }) => ({ value, label })),
        ];
    },
};

const graph = computed(() => api.graph(props.injected));

// --- selection ---

const selectedId = ref(null);
const placeTab = ref('general');
// A field the panel of a node should focus once it shows (useFocusRequest.js).
const focusRequest = ref(null);

/**
 * @param {string|null} id
 * @param {object} [options]
 * @param {string} [options.tab]    the place tab to open
 * @param {string} [options.focus]  a field of the node's panel to focus ('label')
 * @param {number} [options.delay]  ms before focusing (after a menu closed)
 */
function select(id, { tab = 'general', focus = null, delay = 0 } = {}) {
    placeTab.value = tab;
    selectedId.value = id ?? null;
    focusRequest.value = id && focus ? { id, field: focus, delay } : null;
    if (id && panelCollapsed.value) panelCollapsed.value = false;
}

provide(EDITOR_CONTEXT, {
    get customActions() { return props.customActions; },
    options: props,
    api,
    get labelCtx() { return labelCtx.value; },
    ruleCtx,
    stateUsage,
    select,
    get focusRequest() { return focusRequest.value; },
    takeFocus(id, field) {
        const request = focusRequest.value;
        if (!request || request.id !== id || request.field !== field) return false;
        focusRequest.value = null;
        return true;
    },
    // The "+" beside a node's output.
    openAddMenu: (id) => openAddMenuFor(id),
});

const hasSystemSteps = computed(() =>
    Object.keys(props.injected?.places ?? {}).length + Object.keys(props.injected?.transitions ?? {}).length > 0,
);

// --- viewer preferences, per browser ---
// Storage can be unavailable (private mode, blocked site data) — then they
// simply don't stick.
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
const panelCollapsed = ref(prefs.panelCollapsed === true);
watch([minimap, showSystem, panelCollapsed], () => {
    try {
        window.localStorage.setItem(PREFS_KEY, JSON.stringify({
            minimap: minimap.value,
            showSystem: showSystem.value,
            panelCollapsed: panelCollapsed.value,
        }));
    } catch {
        // not persisted — fine
    }
});

// --- size ---
// Full available height: from wherever the editor starts down to the bottom of
// the viewport. Re-measured when anything above it changes size and on resize.
const root = ref(null);
const body = ref(null);
const canvas = ref(null);
const top = ref(0);
const bodyWidth = ref(0);
const height = computed(() => `calc(100dvh - ${Math.max(0, Math.round(top.value))}px - ${props.bottomGap}px)`);

// The panel's size in percent of the editor: ~420px by default, never under
// ~300px (both capped, so a narrow screen still shows some canvas).
const panelSize = computed(() => {
    const width = bodyWidth.value || 1200;
    const min = Math.min(45, Math.max(15, (300 / width) * 100));
    return { default: Math.max(min, Math.min(60, (420 / width) * 100)), min };
});

function measure() {
    if (!root.value) return;
    const scroller = document.scrollingElement ?? document.documentElement;
    top.value = root.value.getBoundingClientRect().top + (scroller?.scrollTop ?? 0);
    if (!bodyWidth.value && body.value) bodyWidth.value = body.value.clientWidth;
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

// --- adding from the toolbar ---

function addPlace() {
    select(api.addPlace(), { focus: 'label' });
}

// A selected place is the natural source of a new step — unless that would
// break the start coupling (a first transition leaves the initial place).
function addTransition() {
    let from = selectedId.value && api.findPlace(selectedId.value) ? selectedId.value : null;
    if (from && !api.checkAddAction({ kind: 'place', id: from }, { action: 'transition_new_place' }).ok) from = null;
    select(api.addTransition({ from }), { focus: 'label' });
}

// --- editing on the canvas ---

// How long a context menu needs to close (and hand focus back to the canvas)
// before something else may take the focus.
const MENU_CLOSE_DELAY = 200;

// A place's name as the canvas shows it — for "<A> → <B>" labels and the menus.
const placeName = (id) => {
    const place = api.findPlace(id);
    return place ? placeDisplayName(api.view.value, keyOf(place), labelCtx.value) : id;
};
const transitionName = (id) => {
    const transition = api.findTransition(id);
    return transition ? (transition.metadata?.label || keyOf(transition)) : id;
};

// Why something was refused, as a toast.
function refuse(reason) {
    Statamic.$toast.error(__(`workflows::messages.editor_connect_${reason || 'unknown'}`));
}

// A line drawn between two handles (or dropped onto a node's body).
function onConnect({ source, target, position }) {
    const result = api.connect(source, target, { position, labelOf: placeName });
    if (!result.ok) {
        if (result.reason !== 'exists') refuse(result.reason);
        return;
    }
    // place → place: the new transition in between, ready to be named.
    if (result.created) {
        select(result.transition, { focus: 'label' });
    } else if (selectedId.value !== result.transition) {
        select(result.transition);
    }
}

// Remove lines: a `from` entry, or a transition's `to`.
function disconnect(edges) {
    let refused = null;
    for (const edge of edges) {
        const result = api.disconnect(edge);
        if (!result.ok && result.reason !== 'unknown') refused = result.reason;
    }
    if (refused) refuse(refused);
}

// A new lone place / transition at a point of the canvas (double-click, pane
// context menu).
function createAt(kind, point, { delay = 0 } = {}) {
    const position = centredAt(point, kind);
    const id = kind === 'place' ? api.addPlace({ position }) : api.addTransition({ position });
    select(id, { focus: 'label', delay });
}

// Delete/Backspace on the canvas.
function onDelete({ nodes, edges }) {
    if (edges.length) {
        disconnect(edges);
    } else {
        deleteNodes(nodes);
    }
}

const usageOf = (id) => {
    const place = api.findPlace(id);
    return place && !place.isNew ? Number(stateUsage[place.handle] ?? 0) : 0;
};

/**
 * Delete nodes the way the panel does. Places that still hold entries need a
 * migration target first: their panel opens on the delete section instead
 * (the full dialog is F4). The start place can't go at all.
 */
function deleteNodes(ids) {
    const transitions = ids.filter((id) => api.findTransition(id));
    const places = ids.filter((id) => api.findPlace(id));
    const initial = places.find((id) => id === api.model.workflow.initial_place) ?? null;
    const needsTarget = places.find((id) => id !== initial && usageOf(id) > 0) ?? null;
    const removable = places.filter((id) => id !== initial && id !== needsTarget && usageOf(id) === 0);

    const count = transitions.length + removable.length;
    if (count > 0) {
        const message = count > 1
            ? __('workflows::messages.editor_confirm_delete_nodes', { count })
            : transitions.length
                ? __('workflows::messages.confirm_delete_transition', { handle: keyOf(api.findTransition(transitions[0])) })
                : __('workflows::messages.confirm_delete_place', { handle: keyOf(api.findPlace(removable[0])) });
        if (!window.confirm(message)) return;

        transitions.forEach((id) => api.removeTransition(id));
        const savedPlaces = removable.filter((id) => !api.findPlace(id)?.isNew);
        removable.forEach((id) => api.removePlace(id, { usage: 0 }));
        // One saved place: show its pending deletion (with "restore"), like the panel.
        select(count === 1 && savedPlaces.length === 1 ? savedPlaces[0] : null);
    }

    if (needsTarget) {
        select(needsTarget, { tab: 'connections' });
        Statamic.$toast.error(__('workflows::messages.editor_delete_needs_target', { place: placeName(needsTarget) }));
    } else if (initial) {
        Statamic.$toast.error(__('workflows::messages.editor_delete_initial_blocked'));
    }
}

// --- the add menu ---

const addMenu = reactive({ open: false, x: 0, y: 0, title: '', source: null, anchor: null });

/**
 * Open the add menu.
 *
 * @param {{kind: string, id: string}|null} source  the node it adds after
 * @param {{x: number, y: number}} client           where it opens (viewport px)
 * @param {{x: number, y: number}} anchor           where new nodes go (flow coords)
 */
function openAddMenu(source, client, anchor) {
    const box = root.value?.getBoundingClientRect();
    const show = () => {
        addMenu.x = Math.round(client.x - (box?.left ?? 0));
        addMenu.y = Math.round(client.y - (box?.top ?? 0));
        addMenu.source = source;
        addMenu.anchor = anchor;
        addMenu.title = source?.kind === 'place'
            ? placeName(source.id)
            : source?.kind === 'transition' ? transitionName(source.id) : __('workflows::messages.editor_add_next');
        addMenu.open = true;
    };
    // Re-anchoring an open menu: close it first so it is placed afresh.
    if (addMenu.open) {
        addMenu.open = false;
        nextTick(show);
    } else {
        show();
    }
}

// Beside a node: the "+", Tab, the node's context menu.
function openAddMenuFor(id, { delay = 0 } = {}) {
    const kind = api.findPlace(id) ? 'place' : api.findTransition(id) ? 'transition' : null;
    const rect = kind ? canvas.value?.nodeRect(id) : null;
    if (!rect) return;
    const anchor = besideAnchor(rect);
    const client = canvas.value.flowToClient(anchor);
    if (delay) {
        setTimeout(() => openAddMenu({ kind, id }, client, anchor), delay);
    } else {
        openAddMenu({ kind, id }, client, anchor);
    }
}

// A line from an output dropped on the empty canvas: new nodes go where it ended.
function onConnectDrop({ source, client, position }) {
    const kind = api.findPlace(source) ? 'place' : api.findTransition(source) ? 'transition' : null;
    if (kind) openAddMenu({ kind, id: source }, client, position);
}

const ADD_ICONS = {
    transition_new_place: 'hierarchy',
    place: 'add-circle',
    transition: 'arrow-right',
    transition_to_place: 'arrow-right',
    target_new_place: 'add-circle',
    target_place: 'arrow-right',
};

const addMenuGroups = computed(() => ({
    create: '',
    connect: addMenu.source?.kind === 'transition'
        ? __('workflows::messages.editor_add_target_place')
        : __('workflows::messages.editor_add_transition_to_place'),
}));

const addMenuItems = computed(() => {
    if (!addMenu.open) return [];
    const source = addMenu.source;
    return api.addMenuEntries(source).map((entry) => {
        const check = api.checkAddAction(source, entry);
        const place = entry.target ? api.findPlace(entry.target) : null;
        return {
            key: entry.target ? `${entry.action}:${entry.target}` : entry.action,
            entry,
            group: entry.group,
            icon: ADD_ICONS[entry.action] ?? 'plus',
            text: place
                ? (entry.target === source?.id ? `${placeName(entry.target)} ↺` : placeName(entry.target))
                : __(`workflows::messages.editor_add_${entry.action}`),
            hint: place ? keyOf(place) : null,
            disabled: !check.ok,
            title: check.ok ? null : __(`workflows::messages.editor_connect_${check.reason}`),
        };
    });
});

function pickAddMenu(item) {
    const result = api.runAddAction(addMenu.source, item.entry, { anchor: addMenu.anchor, labelOf: placeName });
    if (!result.ok) {
        refuse(result.reason);
        return;
    }
    addMenu.open = false;
    // After the popover has closed and handed the focus back.
    select(result.select, { focus: result.created.length ? 'label' : null, delay: MENU_CLOSE_DELAY });
}

function resetPositions() {
    api.resetPositions();
    canvas.value?.autoLayout();
}

function selectError(error) {
    const handle = error?.node?.handle;
    if (!handle) return;
    const id = error.node.kind === 'transition'
        ? transitionIdOf(api.model, handle)
        : (placeIdOf(api.model, handle) ?? api.model.deletions.find((d) => d.handle === handle)?.id ?? null);
    if (id) {
        select(id);
        canvas.value?.reveal(id);
    }
}

// --- saving ---

const saving = ref(false);
const collectionRemoval = reactive({ open: false, collections: [], affectedCount: 0 });
const conflict = reactive({ open: false, definition: null, version: null });

function applyStateUsage(usage) {
    if (!usage || typeof usage !== 'object') return;
    for (const key of Object.keys(stateUsage)) delete stateUsage[key];
    Object.assign(stateUsage, usage);
}

// Take what the server stored; keep the selection across the id change of new
// nodes (`p~1` → `p:<handle>`).
function adopt(definition, newVersion, { snapshot = null } = {}) {
    const ids = api.applyServerDefinition(definition, { snapshot });
    if (newVersion) version.value = newVersion;
    const kept = selectedId.value ? (ids[selectedId.value] ?? selectedId.value) : null;
    selectedId.value = kept && (api.findPlace(kept) || api.findTransition(kept)) ? kept : null;
}

async function save({ confirmCollectionRemoval = false, force = false } = {}) {
    if (saving.value || !axios) return;

    // A new node without a usable handle can't be written — stop and show it.
    const problems = api.handleProblems();
    if (problems.length) {
        Statamic.$toast.error(problems.some((p) => p.problem === 'missing')
            ? __('workflows::messages.settings_dangling_handles')
            : __(`workflows::messages.editor_handle_${problems[0].problem}`));
        select(problems[0].id);
        canvas.value?.reveal(problems[0].id);
        return;
    }

    saving.value = true;
    const snapshot = api.handleSnapshot();
    const { definition, removed_places } = api.payload();
    const body = { version: version.value, definition, removed_places };
    if (confirmCollectionRemoval) body.confirm_collection_removal = true;
    if (force) body.force = true;

    try {
        const { data } = await axios.post(props.saveDefinitionUrl, body);
        const result = data?.data ?? {};
        adopt(result.definition, result.version, { snapshot });
        applyStateUsage(result.state_usage);
        saveErrors.clear();
        conflict.open = false;
        collectionRemoval.open = false;

        Statamic.$toast.success(__('workflows::messages.settings_saved'));
        if (result.seeded > 0) {
            Statamic.$toast.success(__('workflows::messages.states_seeded', { count: result.seeded }));
        }
        const moved = Object.values(result.migrated ?? {}).reduce((sum, n) => sum + (Number(n) || 0), 0);
        if (moved > 0) {
            Statamic.$toast.success(__('workflows::messages.editor_entries_migrated', { count: moved }));
        }
        emit('saved', { definition: result.definition, version: result.version, stateUsage: result.state_usage });
    } catch (e) {
        handleSaveError(e);
    } finally {
        saving.value = false;
    }
}

function handleSaveError(e) {
    const status = e?.response?.status;
    const data = e?.response?.data ?? {};

    // The file changed underneath the editor.
    if (status === 409 || data.conflict) {
        conflict.definition = data.definition ?? null;
        conflict.version = data.version ?? null;
        conflict.open = true;
        Statamic.$toast.error(data.message || __('workflows::messages.settings_version_conflict'));
        return;
    }

    // Dropping a collection needs an explicit yes — a prompt, not a failure.
    if (data.needs_collection_confirmation) {
        collectionRemoval.collections = data.removed_collections ?? [];
        collectionRemoval.affectedCount = data.affected_count ?? 0;
        collectionRemoval.open = true;
        return;
    }

    // A removed place still holds entries (more than when the page loaded, or
    // its target went away): refresh the counts and open that place.
    if (data.needs_migration) {
        const removals = Array.isArray(data.removals) ? data.removals : [];
        for (const removal of removals) api.setDeletionUsage(removal.place, removal.affected);
        const problem = removals.find((r) => r.error || r.target_error);
        saveErrors.set(data.message, removals
            .filter((r) => r.error || r.target_error)
            .map((r) => ({ path: `removed_places.${r.place}`, code: 'needs_migration', message: r.error || r.target_error, node: { kind: 'place', handle: r.place } })));
        Statamic.$toast.error(data.message || __('workflows::messages.settings_save_error'));
        const deletion = problem ? api.model.deletions.find((d) => d.handle === problem.place) : null;
        const target = deletion?.id ?? (problem ? placeIdOf(api.model, problem.place) : null);
        if (target) select(target, { tab: 'connections' });
        return;
    }

    if (status === 422) {
        saveErrors.set(data.message, data.errors);
        Statamic.$toast.error(data.message || data.errors?.[0]?.message || __('workflows::messages.settings_save_error'));
        return;
    }

    // Loud console output so the concrete failure can go into a bug report.
    console.error('[workflows] graph editor save failed', { status, body: data, err: e });
    Statamic.$toast.error(
        data.message
        || (status ? `${status} ${e.response.statusText || ''}`.trim() : null)
        || __('workflows::messages.settings_save_error'),
    );
}

async function confirmCollectionRemoval() {
    await save({ confirmCollectionRemoval: true });
}

async function overwrite() {
    conflict.open = false;
    await save({ force: true });
}

// Throw the local edits away and continue from what is stored now.
async function reloadFromConflict() {
    if (!conflict.definition) return;
    adopt(conflict.definition, conflict.version);
    saveErrors.clear();
    conflict.open = false;
    // Fresh counts for the version just taken over.
    if (axios && props.usageUrl) {
        try {
            const { data } = await axios.get(props.usageUrl);
            applyStateUsage(data?.state_usage);
            if (data?.version) version.value = data.version;
        } catch {
            // counts stay as they were — not worth an error
        }
    }
    emit('saved', { definition: conflict.definition, version: version.value, stateUsage: { ...stateUsage } });
}

defineExpose({
    save,
    isDirty: () => api.dirty.value,
});
</script>
