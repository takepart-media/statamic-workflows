<template>
    <div ref="root" class="wf-editor" :style="{ height }">
        <Toolbar
            ref="toolbar"
            v-model:minimap="minimap"
            v-model:show-system="showSystem"
            :has-system-steps="hasSystemSteps"
            :saving="saving"
            :dirty="api.dirty.value"
            :problems="problems"
            :summary="validation.summary.value"
            :validating="!!validateUrl && serverValidation.stale.value"
            :panel-collapsed="panelCollapsed"
            :can-undo="api.history.canUndo.value"
            :can-redo="api.history.canRedo.value"
            :undo-action="historyLabel(api.history.undoTag.value)"
            :redo-action="historyLabel(api.history.redoTag.value)"
            :workflow-name="workflowName"
            :workflow-handle="workflow"
            :active="active"
            :active-busy="togglingActive"
            :can-toggle-active="!!activeUrl"
            :settings-open="settingsOpen"
            @update:active="setActive"
            @open-settings="toggleSettings"
            @undo="undo"
            @redo="redo"
            @zoom-in="canvas?.zoomIn()"
            @zoom-out="canvas?.zoomOut()"
            @fit="canvas?.fit()"
            @auto-layout="autoLayout"
            @add-place="addPlace"
            @add-transition="addTransition"
            @save="save()"
            @select-problem="selectProblem"
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
                            :reconnect-rule="api.canReconnect"
                            @select="select"
                            @nodes-moved="({ positions }) => api.moveNodes(positions)"
                            @positions="({ positions, touched }) => api.setPositions(positions, { touched })"
                            @connect="onConnect"
                            @connect-refused="({ reason }) => refuse(reason)"
                            @connect-drop="onConnectDrop"
                            @edge-disconnect="({ edge }) => disconnect([edge])"
                            @edge-reconnect="onReconnect"
                            @pane-dblclick="({ position }) => createAt('place', position)"
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
                                    <ContextItem icon="trash" variant="destructive" :text="__('workflows::messages.delete')" @select="deleteNodes([target.id], { delay: MENU_CLOSE_DELAY })" />
                                </template>
                                <!-- A transition -->
                                <template v-else-if="target.kind === 'node' && target.type === 'transition'">
                                    <ContextItem icon="eye" :text="__('workflows::messages.editor_open')" @select="select(target.id)" />
                                    <ContextItem icon="plus" :text="__('workflows::messages.editor_add_next')" @select="openAddMenuFor(target.id, { delay: MENU_CLOSE_DELAY })" />
                                    <ContextSeparator />
                                    <ContextItem icon="trash" variant="destructive" :text="__('workflows::messages.delete')" @select="deleteNodes([target.id], { delay: MENU_CLOSE_DELAY })" />
                                </template>
                                <!-- A line -->
                                <template v-else-if="target.kind === 'edge'">
                                    <ContextItem icon="eye" :text="__('workflows::messages.editor_open')" @select="select(target.edge.transition)" />
                                    <ContextSeparator />
                                    <ContextItem icon="trash" variant="destructive" :text="__('workflows::messages.editor_disconnect')" @select="disconnect([target.edge])" />
                                </template>
                                <!-- Several nodes (selection box) -->
                                <template v-else-if="target.kind === 'selection'">
                                    <ContextItem icon="trash" variant="destructive" :text="__('workflows::messages.delete')" @select="deleteNodes(target.ids, { delay: MENU_CLOSE_DELAY })" />
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
                            :problems="problems"
                            @collapse="panelCollapsed = true"
                            @open-settings="toggleSettings"
                            @select-problem="selectProblem"
                        />
                    </SplitterPanel>
                </template>
            </SplitterGroup>
        </div>

        <WorkflowSettingsStack
            ref="settingsStack"
            v-model:open="settingsOpen"
            :title="`${__('workflows::messages.editor_kind_workflow')} — ${workflowName}`"
            :dirty="api.dirty.value"
            :saving="saving"
            :save-title="`${__('workflows::messages.save')} (${shortcutLabel('S')})`"
            @save="save()"
            @closed="settingsClosed"
            @reset-positions="resetPositions"
        />

        <AddNodeMenu
            v-model:open="addMenu.open"
            :x="addMenu.x"
            :y="addMenu.y"
            :title="addMenu.title"
            :items="addMenuItems"
            :group-labels="addMenuGroups"
            @pick="pickAddMenu"
        />

        <DeletePlaceDialog
            v-model:open="deletion.open"
            :places="deletion.places"
            :transitions="deletion.transitions"
            :refs="deletion.refs"
            :target-options="deletion.targetOptions"
            @confirm="confirmDelete"
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

        <!-- Saving with errors the client already sees: the server decides,
             but the author should know before asking it. -->
        <ConfirmationModal
            :open="saveConfirm.open"
            :title="__('workflows::messages.editor_save_with_errors_title', { count: saveConfirm.count })"
            :button-text="__('workflows::messages.editor_save_anyway')"
            @update:open="(v) => (saveConfirm.open = v)"
            @confirm="saveAnyway"
        >
            <p class="text-sm">{{ __('workflows::messages.editor_save_with_errors_body') }}</p>
        </ConfirmationModal>

        <!-- Screen readers hear what happened without the focus moving: save
             results, undo/redo, validation counts. -->
        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true">{{ liveMessage }}</div>
    </div>
</template>

<script setup>
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';
import { Button, ConfirmationModal, ContextItem, ContextSeparator, SplitterGroup, SplitterPanel, SplitterResizeHandle } from '@statamic/cms/ui';
import { humanize } from '../../support/states.js';
import { ACTION_TYPES, PLACE_ACTION_TYPES, SHARED_ACTION_TYPES } from '../model/constants.js';
import { keyOf, placeIdOf } from '../model/editorModel.js';
import { placeReferences } from '../model/refs.js';
import { besideAnchor, centredAt } from '../model/canvasActions.js';
import { placeDisplayName } from '../model/labels.js';
import { useWorkflowModel } from '../composables/useWorkflowModel.js';
import { useSaveErrors } from '../composables/useSaveErrors.js';
import { useServerValidation } from '../composables/useServerValidation.js';
import { useValidation } from '../composables/useValidation.js';
import { focusPath } from '../composables/useFocusRequest.js';
import { tabForPath } from '../model/validationPaths.js';
import { shortcutLabel, useShortcuts } from '../composables/useShortcuts.js';
import { useLeaveGuard } from '../composables/useLeaveGuard.js';
import AddNodeMenu from './AddNodeMenu.vue';
import Canvas from './Canvas.vue';
import Toolbar from './Toolbar.vue';
import NodePanel from './panels/NodePanel.vue';
import WorkflowSettingsStack from './settings/WorkflowSettingsStack.vue';
import CollectionRemovalDialog from './shared/CollectionRemovalDialog.vue';
import DeletePlaceDialog from './shared/DeletePlaceDialog.vue';
import { EDITOR_CONTEXT } from './context.js';

// The page (WorkflowEditorPage.vue) hands over editSettings()'s whole prop set;
// only what is declared here is used, the rest must not end up as attributes on
// the root element.
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
    // Space kept free below the editor (the host page's bottom padding), px.
    bottomGap: { type: Number, default: 24 },
});

// saved: {definition, version, stateUsage} after a save (or a reload after a
//        conflict).
// dirty-change: whether there are unsaved changes.
// Neither is needed by the page today (the editor owns its state and guards
// leaving on its own); they are there for a host that wants to know.
const emit = defineEmits(['saved', 'dirty-change']);

// Statamic CP exposes axios via Vue's global properties, not as a bare import.
const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

// --- model ---

const modelCtx = {
    get labelLocales() { return props.labelLocales; },
    get reservedPlaces() { return props.reservedPlaces; },
    get reservedTransitions() { return props.reservedTransitions; },
    get customActions() { return props.customActions; },
};

const api = useWorkflowModel(props.definition, modelCtx, {
    history: { limit: 100, debounce: 400, onRestore: afterRestore },
});
const version = ref(props.version);
const stateUsage = reactive({ ...(props.stateUsage ?? {}) });
const saveErrors = useSaveErrors();

// --- live validation ---
// The client's rules on every edit, the server's dry run 800ms after the last
// one (never during a save); one merged list for canvas, panels and toolbar.
const saving = ref(false);
const serverValidation = useServerValidation(api, {
    url: () => props.validateUrl,
    axios,
    version: () => version.value,
    saving: () => saving.value,
});
const validation = useValidation(api, {
    t: (key, replace) => __(`workflows::messages.${key}`, replace),
    get reservedPlaces() { return props.reservedPlaces; },
    get reservedTransitions() { return props.reservedTransitions; },
}, { server: serverValidation });

watch(() => api.dirty.value, (dirty) => emit('dirty-change', dirty), { immediate: true });
useLeaveGuard(() => api.dirty.value);
onBeforeUnmount(() => api.history.stop());

// --- announcements (aria-live) ---

const liveMessage = ref('');
let liveTimer = null;
// Emptied first, so the same text twice in a row is read twice.
function announce(text) {
    liveMessage.value = '';
    clearTimeout(liveTimer);
    if (text) liveTimer = setTimeout(() => (liveMessage.value = text), 50);
}
onBeforeUnmount(() => clearTimeout(liveTimer));

// Changed problem counts, once the author pauses — not on every keystroke.
let countsTimer = null;
let announcedCounts = `${validation.summary.value.errors}/${validation.summary.value.warnings}`;
watch(() => validation.summary.value, ({ errors, warnings }) => {
    clearTimeout(countsTimer);
    countsTimer = setTimeout(() => {
        const key = `${errors}/${warnings}`;
        if (key === announcedCounts) return;
        announcedCounts = key;
        announce(`${__('workflows::messages.editor_problems')}: ${__('workflows::messages.editor_problems_count', { errors, warnings })}`);
    }, 1500);
});
onBeforeUnmount(() => clearTimeout(countsTimer));

// --- undo / redo ---

// A history step's name ("Löschen", "Verschieben" …).
const historyLabel = (tag) => (tag ? __(`workflows::messages.editor_history_${tag}`) : '');

function undo() {
    const tag = api.undo();
    if (tag) announce(__('workflows::messages.editor_undo_action', { action: historyLabel(tag) }));
}

function redo() {
    const tag = api.redo();
    if (tag) announce(__('workflows::messages.editor_redo_action', { action: historyLabel(tag) }));
}

// After a step back/forward the model is another one: a menu built for the old
// one closes, a selection of something that is gone is dropped.
function afterRestore() {
    addMenu.open = false;
    deletion.open = false;
    const id = selectedId.value;
    if (id && !api.findPlace(id) && !api.findTransition(id) && !api.findDeletion(id)) select(null);
}

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
// A problem's spot the panel of a node should show (useFocusRequest.js usePathRequest()).
const pathRequest = ref(null);
let pathSeq = 0;

/**
 * @param {string|null} id
 * @param {object} [options]
 * @param {string} [options.tab]    the place tab to open
 * @param {string} [options.focus]  a field of the node's panel to focus ('label')
 * @param {number} [options.delay]  ms before focusing (after a menu closed)
 * @param {string} [options.path]   a problem's path to show (tab + `data-path` focus)
 */
function select(id, { tab = 'general', focus = null, delay = 0, path = null } = {}) {
    placeTab.value = path ? tabForPath(path) : tab;
    selectedId.value = id ?? null;
    focusRequest.value = id && focus ? { id, field: focus, delay } : null;
    pathRequest.value = id && path ? { id, path, seq: ++pathSeq } : null;
    if (id && panelCollapsed.value) panelCollapsed.value = false;
    // A node to look at (e.g. a save error) — the stack would cover it.
    if (id && settingsOpen.value) settingsOpen.value = false;
}

// --- the workflow itself: name, active, settings ---

// The nav label as it is being edited, else the handle.
const workflowName = computed(() => api.model.workflow.metadata?.nav?.label?.trim() || props.workflow);

// Active/inactive is not part of the definition: it switches right away, like
// on the settings index.
const active = ref(!!props.active);
const togglingActive = ref(false);

async function setActive(value) {
    if (!axios || !props.activeUrl || togglingActive.value) return;
    togglingActive.value = true;
    try {
        await axios.post(props.activeUrl, { active: value });
        active.value = value;
        const message = __(value ? 'workflows::messages.workflow_activated' : 'workflows::messages.workflow_deactivated');
        Statamic.$toast.success(message);
        announce(message);
    } catch (e) {
        Statamic.$toast.error(e?.response?.data?.message || __('workflows::messages.workflow_active_error'));
    } finally {
        togglingActive.value = false;
    }
}

// The settings stack (toolbar button, Ctrl/⌘+,, the overview's button).
const toolbar = ref(null);
const settingsStack = ref(null);
const settingsOpen = ref(false);

function toggleSettings() {
    addMenu.open = false;
    settingsOpen.value = !settingsOpen.value;
}

// Back to the button that opens it — after the stack's focus trap has let go.
function settingsClosed() {
    setTimeout(() => toolbar.value?.focusSettings(), 50);
}

provide(EDITOR_CONTEXT, {
    get customActions() { return props.customActions; },
    options: props,
    api,
    get labelCtx() { return labelCtx.value; },
    ruleCtx,
    stateUsage,
    get workflowName() { return workflowName.value; },
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
    // Delete nodes the way the canvas does (dialog, migration targets).
    requestDelete: (ids) => deleteNodes(ids),
    announce,
    validation,
    get pathRequest() { return pathRequest.value; },
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

// An end of a line dragged onto another node: the line moves there (one undo
// step). The canvas has vetted it; a refusal here means the model moved on.
function onReconnect({ edge, source, target, position }) {
    const result = api.reconnect(edge, source, target, { position, labelOf: placeName });
    if (!result.ok) {
        refuse(result.reason);
        return;
    }
    if (result.kind === 'same') return;
    if (result.created) {
        select(result.transition, { focus: 'label' });
    } else if (selectedId.value !== result.transition) {
        select(result.transition);
    }
}

// Remove lines: a `from` entry, or a transition's `to` — all in one undo step.
function disconnect(edges) {
    let refused = null;
    api.batch('disconnect', () => {
        for (const edge of edges) {
            const result = api.disconnect(edge);
            if (!result.ok && result.reason !== 'unknown') refused = result.reason;
        }
    });
    if (refused) refuse(refused);
}

// A new lone place / transition at a point of the canvas (double-click, pane
// context menu).
function createAt(kind, point, { delay = 0 } = {}) {
    const position = centredAt(point, kind);
    const id = kind === 'place' ? api.addPlace({ position }) : api.addTransition({ position });
    select(id, { focus: 'label', delay });
}

const usageOf = (id) => {
    const place = api.findPlace(id);
    return place && !place.isNew ? Number(stateUsage[place.handle] ?? 0) : 0;
};

// --- deleting ---

const deletion = reactive({ open: false, places: [], transitions: [], refs: [], targetOptions: [] });

/**
 * Delete nodes — from the canvas (Delete key, context menu) and the panels
 * (EDITOR_CONTEXT.requestDelete).
 * Always through the dialog: it asks, lists what loses its link and, for places
 * that still hold entries, wants a target. The start place can't go at all.
 */
function deleteNodes(ids, { delay = 0 } = {}) {
    const transitions = ids.filter((id) => api.findTransition(id));
    const places = ids.filter((id) => api.findPlace(id));
    const initial = places.find((id) => id === api.model.workflow.initial_place) ?? null;
    const removable = places.filter((id) => id !== initial);

    if (initial) Statamic.$toast.error(__('workflows::messages.editor_delete_initial_blocked'));
    if (!transitions.length && !removable.length) return;

    const gone = new Set([...transitions, ...removable]);
    deletion.places = removable.map((id) => {
        const place = api.findPlace(id);
        return { id, name: placeName(id), handle: keyOf(place), usage: usageOf(id), isNew: !!place.isNew };
    });
    deletion.transitions = transitions.map((id) => ({ id, name: transitionName(id) }));
    deletion.refs = removable.flatMap((id) => linksOf(id, gone));
    deletion.targetOptions = api.model.places
        .filter((p) => !gone.has(p.id))
        .map((p) => ({ value: p.id, label: `${placeName(p.id)} (${keyOf(p)})` }));
    // From a context menu: once it has closed and handed the focus back.
    if (delay) {
        setTimeout(() => (deletion.open = true), delay);
    } else {
        deletion.open = true;
    }
}

// What loses its link when place `id` goes (refs.js), minus what is deleted
// along with it.
function linksOf(id, gone) {
    const place = api.findPlace(id);
    const name = placeName(id);
    const transitionByKey = new Map(api.model.transitions.map((t) => [keyOf(t), t.id]));
    return placeReferences(api.view.value, keyOf(place)).flatMap((ref, i) => {
        const key = `${id}:${ref.role}:${i}`;
        if (ref.owner?.kind === 'transition') {
            const tid = transitionByKey.get(ref.owner.handle);
            if (!tid || gone.has(tid)) return [];
            if (ref.role === 'transition_from') return [{ key, icon: 'arrow-right', text: `${name} → ${transitionName(tid)}` }];
            if (ref.role === 'transition_to') return [{ key, icon: 'arrow-right', text: `${transitionName(tid)} → ${name}` }];
        }
        return [{ key, icon: 'link', text: `${__(ref.label)}: ${name}` }];
    });
}

// The dialog said yes: everything in ONE undo step.
function confirmDelete({ targets = {} } = {}) {
    const transitions = deletion.transitions.map((t) => t.id);
    const places = deletion.places.map((p) => p.id);
    deletion.open = false;
    api.batch('delete', () => {
        transitions.forEach((id) => api.removeTransition(id));
        places.forEach((id) => api.removePlace(id, { usage: usageOf(id), migrateTo: targets[id] ?? null }));
    });
    // One saved place: show its pending deletion (target, restore), like before.
    const saved = places.filter((id) => api.findDeletion(id));
    select(transitions.length + places.length === 1 && saved.length === 1 ? saved[0] : null);
    announce(__('workflows::messages.editor_deleted', { count: transitions.length + places.length }));
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

// "Arrange automatically" — one undo step (setPositions touched).
function autoLayout() {
    canvas.value?.autoLayout();
}

// Forget the stored positions and arrange anew — together one undo step.
function resetPositions() {
    api.batch('layout', () => {
        api.resetPositions();
        canvas.value?.autoLayout();
    });
}

// --- keyboard ---

// Delete on the canvas: the selected lines, else the selected nodes.
function deleteSelection() {
    const targets = canvas.value?.deleteTargets();
    if (!targets || (!targets.nodes.length && !targets.edges.length)) return false;
    if (targets.edges.length) {
        disconnect(targets.edges);
    } else {
        deleteNodes(targets.nodes);
    }
}

// The node arrow keys / Enter act on: the focused one, else the selected one
// (focus on the empty canvas or nowhere).
function keyboardNode() {
    const focused = canvas.value?.focusedNode();
    if (focused) return focused;
    const el = document.activeElement;
    const free = !el || el === document.body || canvas.value?.element()?.contains(el);
    return free && selectedId.value && canvas.value?.isModelNode(selectedId.value) ? selectedId.value : null;
}

useShortcuts({
    root: () => root.value,
    canvas: () => canvas.value?.element() ?? null,
    // The settings stack is teleported out of the editor, and covers the canvas.
    scopes: () => [settingsStack.value?.element()],
    modal: () => settingsOpen.value,
    // The dialogs handle their own keys.
    enabled: () => !deletion.open && !conflict.open && !collectionRemoval.open && !saveConfirm.open,
    handlers: {
        save: () => {
            save();
        },
        undo: () => {
            undo();
        },
        redo: () => {
            redo();
        },
        settings: () => {
            toggleSettings();
        },
        escape: () => {
            // The stack closes itself on Esc (its own global binding) — the
            // canvas behind it stays as it is.
            if (settingsOpen.value) return;
            if (addMenu.open) {
                addMenu.open = false;
            } else if (selectedId.value) {
                select(null);
            } else if (!panelCollapsed.value) {
                panelCollapsed.value = true;
            } else {
                return false;
            }
        },
        delete: () => deleteSelection(),
        'add-menu': () => {
            const id = selectedId.value;
            if (!id || !canvas.value?.isModelNode(id)) return false;
            openAddMenuFor(id);
        },
        open: () => {
            const id = canvas.value?.focusedNode();
            if (!id) return false;
            select(id);
        },
        nudge: ({ dx, dy }) => {
            const id = keyboardNode();
            if (!id) return false;
            api.nudge(id, dx, dy);
        },
        fit: () => {
            canvas.value?.fit();
        },
        'reset-zoom': () => {
            canvas.value?.resetZoom();
        },
        'zoom-in': () => {
            canvas.value?.zoomIn();
        },
        'zoom-out': () => {
            canvas.value?.zoomOut();
        },
        'auto-layout': () => {
            autoLayout();
        },
    },
});

// The merged problem list (toolbar, overview).
//   [{ level, message, nodeId?, path?, section?, displayPath? }]
const problems = computed(() => validation.problems.value);

// Show where a problem is: its node (right tab, the field focused) or the
// settings section holding the workflow-level field.
function selectProblem(problem) {
    if (!problem) return;
    const id = problem.nodeId;
    if (id && (api.findPlace(id) || api.findTransition(id) || api.findDeletion(id))) {
        select(id, { path: problem.path });
        canvas.value?.reveal(id);
        setTimeout(() => focusPath(root.value, problem.path), 80);
        return;
    }
    if (problem.section) {
        const wasOpen = settingsOpen.value;
        addMenu.open = false;
        settingsOpen.value = true;
        settingsStack.value?.show(problem.section, problem.path, { now: wasOpen });
    }
}

// --- saving ---

const collectionRemoval = reactive({ open: false, collections: [], affectedCount: 0 });
const conflict = reactive({ open: false, definition: null, version: null });
const saveConfirm = reactive({ open: false, count: 0 });

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
    return ids;
}

/**
 * @param {object} [options]
 * @param {boolean} [options.ignoreErrors]  the author confirmed saving despite
 *        the errors the client sees (the server still decides)
 */
async function save({ confirmCollectionRemoval = false, force = false, ignoreErrors = false } = {}) {
    if (saving.value || !axios) return;

    // A new node without a usable handle can't be written — stop and show it.
    const problems = api.handleProblems();
    if (problems.length) {
        saveFailed(problems.some((p) => p.problem === 'missing')
            ? __('workflows::messages.settings_dangling_handles')
            : __(`workflows::messages.editor_handle_${problems[0].problem}`));
        select(problems[0].id);
        canvas.value?.reveal(problems[0].id);
        return;
    }

    // Errors the client already sees: ask first. A follow-up save (collection
    // removal, overwrite) was already asked about.
    const clientErrors = validation.clientErrors.value.length;
    if (clientErrors && !ignoreErrors && !confirmCollectionRemoval && !force) {
        saveConfirm.count = clientErrors;
        saveConfirm.open = true;
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
        const ids = adopt(result.definition, result.version, { snapshot });
        applyStateUsage(result.state_usage);
        saveErrors.clear();
        serverValidation.saved(ids);
        conflict.open = false;
        collectionRemoval.open = false;

        Statamic.$toast.success(__('workflows::messages.settings_saved'));
        announce(__('workflows::messages.settings_saved'));
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

// A failed save: toast for the eyes, the live region for screen readers.
function saveFailed(message) {
    Statamic.$toast.error(message);
    announce(message);
}

function handleSaveError(e) {
    const status = e?.response?.status;
    const data = e?.response?.data ?? {};

    // The file changed underneath the editor.
    if (status === 409 || data.conflict) {
        conflict.definition = data.definition ?? null;
        conflict.version = data.version ?? null;
        conflict.open = true;
        saveFailed(data.message || __('workflows::messages.settings_version_conflict'));
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
        serverValidation.applySave(saveErrors.state.errors);
        saveFailed(data.message || __('workflows::messages.settings_save_error'));
        const pending = problem ? api.model.deletions.find((d) => d.handle === problem.place) : null;
        const target = pending?.id ?? (problem ? placeIdOf(api.model, problem.place) : null);
        if (target) select(target, { tab: 'connections' });
        return;
    }

    if (status === 422) {
        saveErrors.set(data.message, data.errors);
        serverValidation.applySave(saveErrors.state.errors);
        saveFailed(data.message || data.errors?.[0]?.message || __('workflows::messages.settings_save_error'));
        return;
    }

    // Loud console output so the concrete failure can go into a bug report.
    console.error('[workflows] graph editor save failed', { status, body: data, err: e });
    saveFailed(
        data.message
        || (status ? `${status} ${e.response.statusText || ''}`.trim() : null)
        || __('workflows::messages.settings_save_error'),
    );
}

function saveAnyway() {
    saveConfirm.open = false;
    save({ ignoreErrors: true });
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
    serverValidation.clear();
    serverValidation.revalidate();
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
