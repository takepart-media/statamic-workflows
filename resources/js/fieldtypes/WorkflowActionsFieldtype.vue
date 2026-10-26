<template>
    <!-- Position anchor. A Teleport renders only comment nodes at its own
         location, so there is no element to walk up from — this span gives
         hijackHeader() something to run .closest() against, to detect that we
         are inside an inline publish form (nested Stack) and must not touch
         the header of the page behind it. -->
    <span ref="anchor" style="display:none" aria-hidden="true" />

    <!-- Panel + State-Badge get teleported into a slot we inject next to the
         native Save/Publish buttons in the entry-editor header.
         `teleportTarget` is part of the v-if on purpose: a Teleport with
         `:disabled="true"` renders its content AT ITS OWN LOCATION, not
         nowhere — which would drop the badge and transition buttons into the
         middle of the form. So we render nothing until the header slot exists,
         and never at all inside an inline publish form, where hijackHeader()
         deliberately leaves the target null. -->
    <Teleport v-if="ready && teleportTarget" :to="teleportTarget">
        <Badge
            v-if="currentStateLabel"
            :text="currentStateLabel"
            :color="stateBadgeColor"
            size="sm"
        />
        <Button
            v-for="action in actions"
            :key="action.transition.name"
            :variant="action.variant || 'primary'"
            :disabled="!action.can_apply || pending === action.transition.name"
            :title="action.reason || ''"
            :text="action.transition.label"
            @click="apply(action)"
        />
        <span v-if="actions.length === 0" class="text-sm text-gray-400">{{ __('workflows::messages.no_actions') }}</span>
    </Teleport>

    <!-- Assignee picker: opens for a MANUAL `user_assignment` action. Multi-select. Lives at
         the root, no teleport needed.

         Nothing in here scrolls on its own: Statamic's modal content is already
         a capped scroller, and a second one inside it makes the outer content
         taller than its cap. The modal then opens scrolled (autofocus jumps to
         the first checkbox) and the prompt disappears under the pinned
         heading. -->
    <Modal
        v-if="picker.action"
        :open="!!picker.action"
        @update:open="(v) => v || cancelPicker()"
    >
        <!-- Plain ModalTitle — see the picker in TranslationInstanceDetail for
             why `.wf-modal-head` is deliberately absent here. -->
        <ModalTitle>
            <span class="text-lg font-medium text-gray-900 dark:text-white">{{ __('workflows::messages.pick_assignee_title') }}</span>
        </ModalTitle>

        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('workflows::messages.pick_assignee_prompt') }}
        </p>

        <!-- Off = hand over without the step's mail. Offered only when the step
             mails at all (`sends_mail`, the same answer the send uses). -->
        <label v-if="picker.action.sends_mail" class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <Switch size="xs" v-model="picker.notify" />
            {{ __('workflows::messages.pick_assignee_notify') }}
        </label>

        <ul v-if="picker.action.pickable_assignees?.length" class="divide-y divide-gray-100 rounded-md border border-gray-200 dark:divide-gray-800 dark:border-gray-700!">
            <li v-for="user in picker.action.pickable_assignees" :key="user.id">
                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-200">
                    <input
                        type="checkbox"
                        :value="user.id"
                        v-model="picker.selected"
                        class="size-4 rounded border-gray-300 text-blue-600"
                    />
                    <Badge
                        v-if="user.site_allocation"
                        :text="user.site_allocation"
                        color="blue"
                        size="sm"
                    />
                    <span class="min-w-0 flex-1 truncate">{{ user.name }}</span>
                    <span
                        v-if="user.notified && picker.notify"
                        class="shrink-0 text-xs text-gray-500 dark:text-gray-400"
                        :title="__('workflows::messages.pick_assignee_notified_hint')"
                    >{{ __('workflows::messages.pick_assignee_notified') }}</span>
                </label>
            </li>
        </ul>
        <p v-else class="py-6 text-center text-sm text-gray-400 dark:text-gray-500">
            {{ __('workflows::messages.no_users_found') }}
        </p>

        <template #footer>
            <div class="flex items-center justify-end gap-2 px-2 pt-3">
                <span v-if="picker.selected.length" class="mr-auto text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.pick_assignee_count', { count: picker.selected.length }) }}
                </span>
                <Button variant="ghost" :text="__('workflows::messages.cancel')" @click="cancelPicker" />
                <Button
                    variant="primary"
                    :text="__('workflows::messages.confirm')"
                    :disabled="!picker.selected.length || picker.saving"
                    @click="confirmPicker"
                />
            </div>
        </template>
    </Modal>

    <!-- Target-site picker: opens for transitions that declare
         `needs_site_pick` (e.g. start_translation). Two-panel Splitter
         layout — left shows the source site the entry lives in, right
         is a single-select radio list of the locales still missing a
         localization. On confirm the backend creates the localization
         and applies the transition against the new copy. -->
    <Modal
        v-if="sitePicker.action"
        :open="!!sitePicker.action"
        @update:open="(v) => v || cancelSitePicker()"
    >
        <ModalTitle class="wf-modal-head">
            <span class="text-lg font-medium text-gray-900 dark:text-white">{{ __('workflows::messages.transition_site_pick_title') }}</span>
        </ModalTitle>

        <SplitterGroup direction="horizontal" class="min-h-[16rem]">
            <!-- Source column: the version the texts come from. Context only,
                 unless the workflow allows a per-run pick
                 (`translation_source_pick`) — then it is a radio list of the
                 versions this post has. -->
            <SplitterPanel :default-size="40" :min-size="25">
                <div class="flex h-full flex-col gap-2 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('workflows::messages.transition_site_pick_source_label') }}
                    </p>
                    <ul
                        v-if="sitePicker.action.needs_source_pick"
                        class="max-h-72 divide-y divide-gray-100 overflow-auto rounded-md border border-gray-200 dark:divide-gray-800 dark:border-gray-700!"
                    >
                        <li v-for="site in sitePicker.action.source_sites" :key="site.value">
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-200">
                                <input
                                    type="radio"
                                    name="workflow-source-site"
                                    :value="site.value"
                                    v-model="sitePicker.source"
                                    class="size-4 border-gray-300 text-blue-600"
                                />
                                <span class="min-w-0 flex-1 truncate">{{ site.label }}</span>
                                <span class="font-mono text-xs text-gray-400">{{ site.value }}</span>
                            </label>
                        </li>
                    </ul>
                    <div v-else class="flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700! dark:bg-gray-800!">
                        <span class="font-medium text-gray-800 dark:text-gray-100">
                            {{ sitePicker.action.source_site?.label || sitePicker.action.source_site?.value }}
                        </span>
                        <span class="font-mono text-xs text-gray-400">{{ sitePicker.action.source_site?.value }}</span>
                    </div>
                </div>
            </SplitterPanel>
            <SplitterResizeHandle />
            <!-- Target column: single-select radio list. -->
            <SplitterPanel :default-size="60" :min-size="40">
                <div class="flex h-full flex-col gap-2 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('workflows::messages.transition_site_pick_target_label') }}
                    </p>
                    <ul
                        v-if="sitePicker.action.pickable_sites?.length"
                        class="max-h-72 divide-y divide-gray-100 overflow-auto rounded-md border border-gray-200 dark:divide-gray-800 dark:border-gray-700!"
                    >
                        <li v-for="site in sitePicker.action.pickable_sites" :key="site.value">
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-200">
                                <input
                                    type="radio"
                                    name="workflow-target-site"
                                    :value="site.value"
                                    v-model="sitePicker.selected"
                                    class="size-4 border-gray-300 text-blue-600"
                                />
                                <span class="min-w-0 flex-1 truncate">{{ site.label }}</span>
                                <span class="font-mono text-xs text-gray-400">{{ site.value }}</span>
                            </label>
                        </li>
                    </ul>
                    <p v-else class="rounded-md border border-dashed border-gray-200 py-6 text-center text-sm text-gray-400 dark:border-gray-700! dark:text-gray-500">
                        {{ __('workflows::messages.transition_site_pick_no_targets') }}
                    </p>
                </div>
            </SplitterPanel>
        </SplitterGroup>

        <template #footer>
            <div class="flex justify-end gap-2 px-2 pt-3">
                <!-- The confirm request translates the whole entry before it
                     creates anything, so it takes seconds rather than being
                     instant. Saying so beats a button that just sits there. -->
                <p v-if="sitePicker.saving" class="mr-auto text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.translation_running') }}
                </p>
                <Button variant="ghost" :text="__('workflows::messages.cancel')" @click="cancelSitePicker" />
                <Button
                    variant="primary"
                    :text="__('workflows::messages.transition_site_pick_confirm')"
                    :loading="sitePicker.saving"
                    :disabled="!sitePicker.selected || sitePicker.saving"
                    @click="confirmSitePicker"
                />
            </div>
        </template>
    </Modal>
</template>

<script setup>
// Header takeover for workflow-managed entries: the panel's transition buttons
// replace Statamic's native Save + Publish/Revision buttons in the header
// (top-right). Each click runs a three-step sequence — trigger the native save
// (Statamic's SavePipeline handles validation + working-copy write), create a
// revision (best-effort, only if the entry's collection has revisions on and
// the license supports it), then apply the workflow transition. If save
// validation fails, we abort before touching the workflow state.
//
// The takeover uses DOM injection because Statamic's PublishForm.vue offers
// no slot the addon can fill and no way to override the component. The
// selectors are coupled to Statamic's current markup — see hijackHeader.
import { computed, getCurrentInstance, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Badge, Button, Icon, Modal, ModalTitle, SplitterGroup, SplitterPanel, SplitterResizeHandle, Switch } from '@statamic/cms/ui';
import { registerStateColors, stateColor } from '../support/states.js';

const props = defineProps({
    value: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
    meta: { type: Object, default: () => ({}) },
    handle: { type: String, default: '' },
    readOnly: { type: Boolean, default: false },
});

const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

const anchor = ref(null);
const actions = ref([]);
const currentState = ref(null);
const currentStateLabel = ref(null);
// Whether the current workflow place allows publishing right now. Initialised
// from the fieldtype's preProcess payload; refreshed by every loadActions()
// call so the header adjusts to workflow transitions without a reload.
const allowPublish = ref(!! props.value?.allow_publish);
// Whether this workflow governs publishing at all (any place with
// `allow_publish`). A localization workflow does not — it has no publish place,
// and the server-side publish sync bows out for the same reason. Kept apart from
// `allowPublish` because "not in this state" and "not this workflow's business"
// must not lead to the same conclusion.
const gatesPublishing = ref(!! props.value?.gates_publishing);
// Super admins and the `admins` group keep Statamic's own publish flow, whatever
// the workflow says. The flag was shipped from the server all along and simply
// never read here — which is how the documented exception quietly stopped being
// true.
const isPrivileged = !! props.value?.is_privileged;
const pending = ref(null);
const loaded = ref(false);
const teleportTarget = ref(null);
// `selected` is a list — the assignee picker is multi-select.
const picker = ref({ action: null, selected: [], notify: true, saving: false });
// Target-site picker: opens when a transition declares `needs_site_pick`
// (typical: start_translation). Single-select, similar shape to `picker`
// but distinct so the two flows never step on each other.
const sitePicker = ref({ action: null, selected: null, source: null, saving: false });

// State kept for the native-button-hiding side-effect so we can restore on
// unmount. We track the wrappers we hid + their previous inline display.
const hiddenNodes = [];
// The Save button lives inside a `[data-ui-button-group]` wrapper (non-Pro
// case). Once we hide the chevron sibling, the Save button still carries
// `data-ui-group-target="true"` which — via `[data-ui-group-target]
// { border-radius: 0 }` in Statamic's CSS — zeroes all four corners. We
// strip both attributes so the button's base `rounded-lg` class takes
// effect again and the Save renders with default rounded corners.
let buttonGroupAttrRestore = null;
let groupTargetAttrRestore = null;

// Statamic renders its native Save ("Entwurf speichern") button as
// variant="primary", which competes visually with the workflow transition
// buttons we teleport next to it. Recolouring it to the `filled` variant makes
// the workflow action the obvious primary choice and saving the quiet one.
//
// This is a class swap, not a component prop — the button is Statamic's own
// Vue component. It works because both variants' utilities are already compiled
// into Statamic's CSS bundle (Button.vue declares them and `filled` is used by
// Filters/Branch/BlueprintsFieldtype), so we only ever reuse existing classes.
const SAVE_PRIMARY_CLASSES = [
    'bg-linear-to-b', 'from-primary/90', 'to-primary', 'hover:bg-primary-hover',
    'text-white', 'disabled:text-white', 'dark:disabled:text-white',
    // `border` goes too — `filled` is borderless, and leaving the bare width
    // class without primary's colour would paint a border in the text colour.
    'border', 'border-primary-border', 'shadow-ui-md',
    'inset-shadow-2xs', 'inset-shadow-white/25',
    '[&_svg]:text-white', '[&_svg]:opacity-60',
];
const SAVE_FILLED_CLASSES = [
    'bg-gray-950/5', 'hover:bg-gray-950/10', 'hover:text-gray-900',
    'dark:hover:text-white', 'dark:bg-white/4', 'dark:hover:bg-white/20',
    '[&_svg]:opacity-70',
];
let saveVariantRestore = null;
let slotEl = null;
// The native Save button lives in the first Header wrapper — we keep it
// visible (redakteur can trigger a plain save) but still need a direct
// handle to trigger it from `runTransition()`'s save-first sequence.
let nativeSaveBtn = null;

const ready = computed(() => loaded.value && props.value);

// The same resolution every other state badge in the CP uses: the place's own
// `metadata.color` from the settings, then the built-in per-handle map, then
// neutral. This was a hardcoded switch over five handle names, which had two
// consequences worth naming: a place the author coloured amber in the settings
// still rendered grey here, and a place the author invented rendered grey no matter
// what. The colours arrive with the fieldtype payload and are registered on mount.
const stateBadgeColor = computed(() => stateColor(currentState.value));

async function loadActions() {
    if (!axios || !props.value?.actions_url) {
        loaded.value = true;
        return;
    }
    try {
        const res = await axios.get(props.value.actions_url);
        actions.value = res.data.data ?? [];
        currentState.value = res.data.current_state ?? null;
        currentStateLabel.value = res.data.current_state_label ?? null;
        allowPublish.value = !! res.data.allow_publish;
        gatesPublishing.value = !! res.data.gates_publishing;
    } catch (e) {
        window.Statamic?.$toast?.error(__('workflows::messages.actions_load_error'));
    } finally {
        loaded.value = true;
    }
}

// Called on a transition button click. Transitions with `needs_site_pick`
// open the target-site modal first; `needs_pick` opens the assignee modal;
// everything else goes straight into the save+revise+apply sequence.
function apply(action) {
    if (action.needs_site_pick) {
        // `source` starts on the server's preselection; a choice only where allowed.
        sitePicker.value = { action, selected: null, source: action.source_site?.value ?? null, saving: false };
        return;
    }
    if (action.needs_pick) {
        picker.value = { action, selected: [], notify: true, saving: false };
        return;
    }
    runTransition(action, {});
}

function cancelPicker() {
    if (picker.value.saving) return;
    picker.value = { action: null, selected: [], notify: true, saving: false };
}

async function confirmPicker() {
    if (!picker.value.selected.length) return;
    picker.value.saving = true;
    await runTransition(picker.value.action, { assignees: picker.value.selected, notify: picker.value.notify });
    // On success we reload; on failure runTransition resets the saving flag.
}

function cancelSitePicker() {
    if (sitePicker.value.saving) return;
    sitePicker.value = { action: null, selected: null, source: null, saving: false };
}

// The request behind this does the whole machine translation before it creates
// anything, so a failure means there is no entry to clean up — see
// WorkflowController::applyTransition().
async function confirmSitePicker() {
    if (!sitePicker.value.selected) return;
    sitePicker.value.saving = true;
    // `source_site` only where the workflow allows a per-run pick.
    await runTransition(sitePicker.value.action, {
        target_site: sitePicker.value.selected,
        ...(sitePicker.value.action.needs_source_pick ? { source_site: sitePicker.value.source } : {}),
    });
}

// Full sequence: native save → wait for entry.saved hook (or skip if nothing
// to save) → best-effort revision → workflow transition → redirect to the
// workflow instance summary (History + Actions), not a plain reload.
async function runTransition(action, payload) {
    if (pending.value) return;
    pending.value = action.transition.name;

    try {
        // Contract: SAVE first, always. If the form is dirty and the save
        // request 422s, we abort (Statamic already flagged the fields).
        // If the form is clean, we proceed against the last persisted state.
        const saveResult = await triggerNativeSave();

        if (saveResult === 'invalid') {
            window.Statamic?.$toast?.error(__('workflows::messages.save_first_failed'));
            return;
        }

        // Record this transition as a revision if the collection allows it.
        // Silent no-op otherwise (no Pro, revisions off).
        if (props.value?.revisions_enabled && props.value?.revisions_url) {
            try {
                await axios.post(props.value.revisions_url, { message: action.transition.label });
            } catch (e) {
                // Non-fatal — the workflow move is more important than logging
                // the revision. Log to console so it's diagnosable.
                console.warn('[workflows] revision create failed', e?.response?.data ?? e);
            }
        }

        const url = props.value.apply_url_template.replace('__NAME__', encodeURIComponent(action.transition.name));
        const response = await axios.post(url, payload);

        // A submitted plain-language rewrite finishes minutes later — the entry we
        // are about to open still shows the source text. Same toast, because the
        // CP's toast API only has success/error.
        const pendingNote = response?.data?.data?.pending_jobs
            ? ` ${__('workflows::messages.translation_plain_submitted')}`
            : '';
        window.Statamic?.$toast?.success(
            `${__('workflows::messages.applied', { action: action.transition.label })}${pendingNote}`,
        );

        // Silence Statamic's dirty-form navigation guard before we redirect.
        // Its composable binds `window.onbeforeunload = () => ''` while any
        // field is marked dirty (see composables/dirty-state.js:56); Vue's
        // reactivity may not have flushed the "clean" flag by the time we
        // navigate away, so the browser would otherwise prompt the user
        // even though the changes ARE persisted.
        window.onbeforeunload = null;

        // Transitions that create a new subject (e.g. start_translation
        // localizing into a target site) return an entry-specific `url` in
        // the response — jump the user straight to the new copy so they can
        // start working on it. Otherwise fall back to the workflow queue.
        const nextUrl = response?.data?.data?.url;
        if (nextUrl) {
            window.location.href = nextUrl;
        } else if (props.value?.workflow_url) {
            window.location.href = props.value.workflow_url;
        } else {
            window.location.reload();
        }
    } catch (e) {
        const message = e?.response?.data?.message || __('workflows::messages.transition_error');
        window.Statamic?.$toast?.error(message);
    } finally {
        pending.value = null;
        picker.value.saving = false;
        sitePicker.value.saving = false;
    }
}

// ALWAYS trigger Statamic's native Save when a transition button is clicked
// — that is the user's contract: save first, then everything else. We click
// the (hidden) native Save button and wait briefly for the `entry.saved`
// hook. Returns:
//   'saved'       — native save completed
//   'nothing'     — form was clean (disabled button) OR no save button
//                    reachable; the transition can proceed against the
//                    already-persisted entry state
//   'invalid'     — dirty form + save request 422'd; Statamic has already
//                    surfaced the errors inline and we must abort the
//                    transition so no invalid state advances
function triggerNativeSave() {
    return new Promise((resolve) => {
        const saveBtn = findNativeSaveButton();

        if (!saveBtn) {
            resolve('nothing');
            return;
        }

        // Track whether the click actually engaged the SavePipeline. If the
        // button was disabled (form clean) Statamic's `save()` early-returns
        // without emitting `entry.saved` and without hitting the network.
        // We detect that case and treat it as "nothing to save".
        const wasClean = saveBtn.disabled;

        let done = false;
        const finish = (result) => {
            if (done) return;
            done = true;
            window.Statamic?.$hooks?.off?.('entry.saved', onSaved);
            resolve(result);
        };
        const onSaved = () => finish('saved');
        window.Statamic?.$hooks?.on?.('entry.saved', onSaved);

        // If the form was clean, no request will ever fire — give it a very
        // short window then proceed. If dirty, allow a full save round-trip.
        setTimeout(() => finish(wasClean ? 'nothing' : 'invalid'), wasClean ? 400 : 8000);

        // Click regardless of disabled state — Statamic's own save() guards
        // on canSave, so a disabled click is a harmless no-op.
        saveBtn.click();
    });
}

// Statamic's PublishForm renders one Save (variant="default") + one
// Publish/Revision (variant="primary") Button, each inside a SaveButtonOptions
// wrapper. hijackHeader() keeps the Save wrapper visible and caches its
// button in `nativeSaveBtn`; the Publish wrapper is hidden. Never look up
// via querySelector on the container — our Teleport slot lives alongside
// and would win the naive lookup.
function findNativeSaveButton() {
    return nativeSaveBtn;
}

// Identify the entry-editor's Header primitive — the app-chrome also renders
// a <header> at the page top; we must NOT touch that one. The distinguishing
// property: the entry-editor Header contains an <h1> (Header.vue markup).
function findEntryEditorHeader() {
    const candidates = Array.from(document.querySelectorAll('header[data-ui-header]'));
    return candidates.find((h) => h.querySelector('h1')) ?? candidates[candidates.length - 1] ?? null;
}

// Locate the entry-editor Header and:
//   1) Identify Statamic's Save button (the first Button in the action
//      container — PublishForm.vue always renders it before the Publish
//      button-options wrapper).
//   2) Hide every OTHER [data-ui-button-group] — those are the Publish /
//      Revision wrappers. NEVER hide the wrapper that contains Save.
//   3) Insert `#workflow-header-slot` (a display:contents fragment) so our
//      Teleport'd Buttons render as siblings of Save with the header's gap.
//
// Publish-wrappers are hidden only where this workflow actually owns publishing
// and the current place withholds it — see hidesPublishing(). If the workflow
// allows publishing here (metadata.allow_publish on the place), Statamic's Publish
// button stays visible, symmetric to syncPublishedFlag's server-side semantic.
//
// Rationale for the "first-button = Save" heuristic: without Statamic Pro
// SaveButtonOptions wraps the Save-Draft button in <div data-ui-button-group>
// too, so filtering by "not inside a button-group" (the old approach) hid
// Save. Anchoring on the first Button is stable across Pro/non-Pro because
// PublishForm.vue orders Save before Publish unconditionally.
// Whether to take Statamic's publish flow away from this editor. All three
// conditions have to hold, and each one alone used to be wrong:
//
//   1. the workflow governs publishing at all — a localization workflow has no
//      `allow_publish` place, so publishing is not its call. Hiding the button
//      there left a finished translation with no way to go live: no transition
//      sets the published flag either (syncPublishedFlag bows out on the same
//      condition);
//   2. the current place withholds it — that is the actual gate;
//   3. the user is not privileged. Super admins and the `admins` group keep the
//      native flow, as the fieldtype has always documented.
function hidesPublishing() {
    return gatesPublishing.value && !allowPublish.value && !isPrivileged;
}

function hijackHeader() {
    if (!props.value) return;

    // Inside an inline publish form (Statamic's InlineEditForm renders one in a
    // nested Stack — e.g. "Bearbeiten" on the workflow summary), there is no
    // entry-editor header of our own to hijack. findEntryEditorHeader() queries
    // the whole document, so it would latch onto the header of the page BEHIND
    // the stack and inject our buttons into the wrong level. Bail out: the
    // workflow actions live in the summary's own footer in that context.
    if (anchor.value?.closest('[data-ui-stack-content]')) return;

    const header = findEntryEditorHeader();
    if (!header) return;

    // Header.vue wraps `#actions` slot in an outer flex div. PublishForm.vue
    // fills that slot with ItemActions ("..." menu), an optional badge, then
    // an INNER flex div holding the save-button-options. `:scope > div.flex`
    // returns the OUTER div — its first button is the ItemActions trigger,
    // NOT Save. We want the innermost save-buttons container: the last DIV
    // child of the outer wrapper.
    const outer = header.querySelector(':scope > div.flex, :scope > div[class*="flex"]');
    if (!outer) return;

    const childDivs = Array.from(outer.children).filter((el) => el.tagName === 'DIV');
    const actionContainer = childDivs[childDivs.length - 1] ?? outer;
    if (!actionContainer) return;

    const allButtons = Array.from(actionContainer.querySelectorAll('button'));
    nativeSaveBtn = allButtons[0] ?? null;
    const saveGroup = nativeSaveBtn?.closest('[data-ui-button-group]') ?? null;

    if (hidesPublishing()) {
        for (const w of Array.from(header.querySelectorAll('[data-ui-button-group]'))) {
            if (w === saveGroup) continue;
            hiddenNodes.push({ el: w, previous: w.style.display });
            w.style.display = 'none';
        }
    }

    // If the Save button is inside a `[data-ui-button-group]` (non-Pro case:
    // SaveButtonOptions wraps Save + chevron together), the group's border
    // overrides shave the Save button's right-side border-radius. We can't
    // safely edit Statamic's compiled Tailwind classes, so remove the group
    // wrapper attribute entirely — the button then styles as a standalone
    // primary button with default rounded corners. Also hide the chevron
    // sibling button that no longer needs to open the "After Saving" menu.
    if (saveGroup && nativeSaveBtn) {
        for (const btn of Array.from(saveGroup.querySelectorAll('button'))) {
            if (btn === nativeSaveBtn) continue;
            hiddenNodes.push({ el: btn, previous: btn.style.display });
            btn.style.display = 'none';
        }

        buttonGroupAttrRestore = { el: saveGroup, previous: saveGroup.getAttribute('data-ui-button-group') };
        saveGroup.removeAttribute('data-ui-button-group');

        // The button itself carries `data-ui-group-target="true"` which
        // triggers `[data-ui-group-target] { border-radius: 0 }` in
        // Statamic's compiled CSS. Strip it so the base `rounded-lg` wins.
        groupTargetAttrRestore = { el: nativeSaveBtn, previous: nativeSaveBtn.getAttribute('data-ui-group-target') };
        nativeSaveBtn.removeAttribute('data-ui-group-target');
    }

    // Demote the native Save button from `primary` to `filled` so the workflow
    // transitions read as the primary action. Only touch classes that are
    // actually present / absent, and remember exactly what we changed so
    // restoreHeader() can put the button back untouched.
    if (nativeSaveBtn) {
        saveVariantRestore = { el: nativeSaveBtn, removed: [], added: [] };

        for (const cls of SAVE_PRIMARY_CLASSES) {
            if (nativeSaveBtn.classList.contains(cls)) {
                nativeSaveBtn.classList.remove(cls);
                saveVariantRestore.removed.push(cls);
            }
        }

        for (const cls of SAVE_FILLED_CLASSES) {
            if (! nativeSaveBtn.classList.contains(cls)) {
                nativeSaveBtn.classList.add(cls);
                saveVariantRestore.added.push(cls);
            }
        }
    }

    // Teleport target lives in the OUTER flex-wrap container (Header.vue's
    // `.flex.flex-wrap`), as a sibling of the save-buttons wrapper. That way
    // the badge + transition buttons flow along the outer flex row with the
    // parent's wrap behaviour — never sharing the inner no-wrap grid with
    // Save. Fixes the "Save is clipped/squeezed at narrow viewports" bug.
    const teleportHost = outer;
    slotEl = document.createElement('span');
    slotEl.id = 'workflow-header-slot';
    slotEl.style.display = 'contents';
    teleportHost.appendChild(slotEl);

    teleportTarget.value = '#workflow-header-slot';
}

function restoreHeader() {
    for (const { el, previous } of hiddenNodes) {
        el.style.display = previous;
    }
    hiddenNodes.length = 0;
    if (buttonGroupAttrRestore) {
        if (buttonGroupAttrRestore.previous === null) {
            buttonGroupAttrRestore.el.removeAttribute('data-ui-button-group');
        } else {
            buttonGroupAttrRestore.el.setAttribute('data-ui-button-group', buttonGroupAttrRestore.previous);
        }
        buttonGroupAttrRestore = null;
    }
    if (groupTargetAttrRestore) {
        if (groupTargetAttrRestore.previous === null) {
            groupTargetAttrRestore.el.removeAttribute('data-ui-group-target');
        } else {
            groupTargetAttrRestore.el.setAttribute('data-ui-group-target', groupTargetAttrRestore.previous);
        }
        groupTargetAttrRestore = null;
    }
    if (saveVariantRestore) {
        for (const cls of saveVariantRestore.added) {
            saveVariantRestore.el.classList.remove(cls);
        }
        for (const cls of saveVariantRestore.removed) {
            saveVariantRestore.el.classList.add(cls);
        }
        saveVariantRestore = null;
    }
    if (slotEl && slotEl.parentElement) {
        slotEl.parentElement.removeChild(slotEl);
    }
    slotEl = null;
    nativeSaveBtn = null;
    teleportTarget.value = null;
}

// Hide Statamic's "Sites" card when a localization workflow governs the entry.
// That workflow owns which sites an entry lives in — via transfer() — and the
// card's real function is CREATING a localization by clicking a site that has
// none yet, which would bypass it entirely.
//
// Done in the DOM because there is no seam: the card is registered LOCALLY on
// PublishForm (`components: { LocalizationsCard }`), so it cannot be overridden
// through Statamic.component(), and it renders behind a `localizations.length > 1`
// computed fed straight from the controller's view data.
//
// The panel is found by matching its header against Statamic's own translated
// heading, which the server hands over as `sites_label` — so this follows the CP
// locale instead of hardcoding "Sites". If nothing matches, the lock silently
// does not apply rather than hiding the wrong panel.
let hiddenSitesPanel = null;

function hideSitesCard() {
    if (!props.value?.lock_sites || hiddenSitesPanel) return;

    // Same reason hijackHeader() bails here: the query spans the whole document,
    // so inside a nested Stack we would hide the card of the page BEHIND it.
    if (anchor.value?.closest('[data-ui-stack-content]')) return;

    const label = (props.value.sites_label || '').trim();
    if (!label) return;

    const header = Array.from(document.querySelectorAll('[data-ui-panel-header]'))
        .find((el) => el.textContent.trim() === label);

    if (!header) {
        console.warn('[workflows] Sites card not found — leaving it visible.', { label });
        return;
    }

    const panel = header.parentElement;
    if (!panel) return;

    panel.dataset.workflowsHidden = 'sites';
    panel.style.display = 'none';
    hiddenSitesPanel = panel;
}

function restoreSitesCard() {
    if (!hiddenSitesPanel) return;

    hiddenSitesPanel.style.removeProperty('display');
    delete hiddenSitesPanel.dataset.workflowsHidden;
    hiddenSitesPanel = null;
}

onMounted(async () => {
    if (!props.value) {
        loaded.value = true;
        return;
    }
    // Before the first render of the badge: the Inertia pages do this from their
    // own props, and this screen is not one of them — the fieldtype has its own
    // payload and would otherwise leave the module-level map empty.
    registerStateColors(props.value.state_colors);
    await loadActions();
    // Statamic mounts the header before this fieldtype in most cases; wait a
    // frame anyway so a query is safe even on slower renders.
    requestAnimationFrame(() => {
        hijackHeader();
        hideSitesCard();
    });
});

// Clicking another site in the Sites card swaps the localization in place: the
// publish form refetches that version's edit payload and replaces `values`
// wholesale — no page load. Our payload rides along, because Statamic parents
// the blueprint to the version being shown before running preProcess(), so a
// changed entry id or workflow here means exactly one thing: the user switched
// sites, and the panel is now describing the previous version.
//
// The badge and buttons come from the refs loadActions() fills, and the URLs are
// read from `props.value` at call time, so refetching covers the panel itself.
// The header takeover is redone as well rather than left to the allowPublish
// watcher below: that one only fires when the two versions gate publishing
// differently, while the slot we inject into Statamic's header is a foreign
// child of a subtree Vue re-renders on the switch — re-running it is what makes
// the panel's survival deterministic instead of dependent on patch order.
const panelKey = computed(() => `${props.value?.entry_id ?? ''}|${props.value?.workflow ?? ''}`);

watch(panelKey, async (next, previous) => {
    if (!next || next === previous) return;

    actions.value = [];
    currentState.value = null;
    currentStateLabel.value = null;

    await loadActions();

    restoreHeader();
    requestAnimationFrame(hijackHeader);
});

// When a workflow transition flips the verdict (e.g. reaching a place with
// metadata.allow_publish=true), tear down the previous hijack and re-run it so
// Statamic's Publish button gets re-shown or -hidden accordingly. Watching the
// verdict rather than `allowPublish` alone: `gatesPublishing` arrives with the
// same refresh, and a change there flips the outcome just as much.
watch(hidesPublishing, () => {
    restoreHeader();
    requestAnimationFrame(hijackHeader);
});

onBeforeUnmount(() => {
    restoreHeader();
    restoreSitesCard();
});
</script>
