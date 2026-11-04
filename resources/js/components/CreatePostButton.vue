<template>
    <!-- Opens the "New post" modal: the title, then the workflow the post is
         created in (read-only when only one is on offer), the collection where
         that workflow owns more than one. The confirm button is the workflow
         CTA — see the modal. -->
    <Button
        v-if="creates.length"
        variant="primary"
        icon="add-entry"
        :size="size"
        :text="text ?? __('workflows::messages.new_post')"
        @click="openCreate"
    />

    <!-- The "New post" modal. ONE confirm button, and it says what it does:
         with a coupled transition (metadata.create_transition, delivered as
         `createCta`) it carries that transition's label — "Entwurf erstellen" —
         and the server creates the post AND runs the step in one request;
         without one it says "Anlegen" and only creates. Two buttons would ask
         the editor a question the workflow author already answered.

         A <form> so Enter in the title field submits; the ghost Cancel is
         type="button" so it does not. Closing is refused while saving — the
         request has left, and a second click on "new post" would make two.

         The form id is per instance: the submit button finds its form by id,
         and a page may hold more than one of these. -->
    <Modal v-if="creates.length" :open="createModal.open" @update:open="(v) => v || closeCreate()">
        <ModalTitle class="mb-1 flex items-center gap-2 border-b border-gray-200 pb-3 dark:border-gray-700!">
            <Icon name="add-entry" class="size-4" />
            <span class="text-lg font-medium text-gray-900 dark:text-white">{{ text ?? __('workflows::messages.new_post') }}</span>
        </ModalTitle>
        <form :id="formId" class="space-y-4" @submit.prevent="submitCreate">
            <Field :label="__('workflows::messages.post_title')">
                <Input v-model="createModal.title" :placeholder="__('workflows::messages.post_title')" focus />
            </Field>
            <!-- Exactly one workflow, always: a single select, never cleared. With
                 only one on offer it is shown read-only, so the editor still sees
                 where the post goes. -->
            <Field :label="__('workflows::messages.post_workflow')" :instructions="__('workflows::messages.post_workflow_hint')">
                <Select
                    adaptive-width
                    :model-value="createModal.workflow"
                    :options="workflowChoices"
                    :read-only="workflowLocked"
                    @update:model-value="selectWorkflow"
                />
            </Field>
            <!-- The noun comes from Statamic's own translation so it reads
                 like the sidenav („Sammlung" in German). -->
            <Field v-if="(selected?.createCollections ?? []).length > 1" :label="__('workflows::messages.select_collection', { collection: __('Collection') })">
                <Select adaptive-width v-model="createModal.collection" :options="selected?.createCollections ?? []" />
            </Field>
            <!-- The coupled transition's manual assignment, asked right here —
                 the same checkbox list the detail page's button opens
                 (WorkflowInstanceDetail's picker), so the CTA can run in the
                 same request. The candidates are the server's
                 (createCta.pickable_assignees); an empty list is shown as such
                 and blocks the button rather than creating a post nobody
                 could be handed. -->
            <Field v-if="selected?.createCta?.needs_pick" :label="__('workflows::messages.pick_assignee_title')" :instructions="__('workflows::messages.pick_assignee_prompt')">
                <!-- An automatic assignment redirected here because it would have handed the
                     post back to the clicker alone (SelfAssignmentGuard) — say why. -->
                <Alert v-if="selected.createCta.pick_hint" variant="warning" class="mb-2">
                    <p class="text-sm">{{ selected.createCta.pick_hint }}</p>
                </Alert>
                <!-- Off = hand over without the step's mail. Offered only when the step
                     mails at all (`sends_mail`, the same answer the send uses). -->
                <label v-if="selected.createCta.sends_mail" class="mb-2 flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <Switch size="xs" v-model="createModal.notify" />
                    {{ __('workflows::messages.pick_assignee_notify') }}
                </label>
                <ul v-if="selected.createCta.pickable_assignees?.length" class="divide-y divide-gray-100 rounded-md border border-gray-200 dark:divide-gray-800 dark:border-gray-700!">
                    <li v-for="user in selected.createCta.pickable_assignees" :key="user.id">
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-200">
                            <input
                                type="checkbox"
                                :value="user.id"
                                v-model="createModal.assignees"
                                class="size-4 rounded border-gray-300 text-blue-600"
                            />
                            <Badge v-if="user.site_allocation" :text="user.site_allocation" color="blue" size="sm" />
                            <span class="min-w-0 flex-1 truncate">{{ user.name }}</span>
                            <span
                                v-if="user.notified && createModal.notify && createModal.assignees.includes(user.id)"
                                class="shrink-0 text-xs text-gray-500 dark:text-gray-400"
                                :title="__('workflows::messages.pick_assignee_notified_hint')"
                            >{{ __('workflows::messages.pick_assignee_notified') }}</span>
                        </label>
                    </li>
                </ul>
                <p v-else class="py-4 text-center text-sm text-gray-400 dark:text-gray-500">
                    {{ __('workflows::messages.no_users_found') }}
                </p>
            </Field>
        </form>
        <template #footer>
            <div class="flex items-center justify-end gap-2 px-2 pt-3">
                <span v-if="selected?.createCta?.needs_pick && createModal.assignees.length" class="mr-auto text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.pick_assignee_count', { count: createModal.assignees.length }) }}
                </span>
                <Button type="button" variant="ghost" :text="__('workflows::messages.cancel')" :disabled="createModal.saving" @click="closeCreate" />
                <Button
                    type="submit"
                    :form="formId"
                    variant="primary"
                    icon="add-entry"
                    :text="selected?.createCta?.label ?? __('workflows::messages.create')"
                    :disabled="!canSubmit"
                    :loading="createModal.saving"
                />
            </div>
        </template>
    </Modal>
</template>

<script setup>
import { computed, ref, getCurrentInstance } from 'vue';
import { Alert, Badge, Button, Field, Icon, Input, Modal, ModalTitle, Select, Switch } from '@statamic/cms/ui';
import { defaultWorkflow, entryFor, isWorkflowLocked, workflowFields, workflowOptions } from './createPostState.js';

/**
 * The one "New post" button and the modal behind it — shared by the dashboard's
 * "all posts" widget (every workflow the user may create in) and the workflow
 * manager page (just its own). Renders nothing when `creates` is empty, i.e.
 * when the user cannot create anywhere.
 */
const props = defineProps({
    // Per workflow the user may create in, from WorkflowController::createOptions():
    // [{workflow, label, createUrl, createCta, createCollections}].
    //   createCollections  the collections the workflow OWNS, as [{value, label}]
    //   createCta          the transition the confirm button runs after creating,
    //                      {name, label, needs_pick, pickable_assignees} — or null,
    //                      in which case the button only creates
    creates: { type: Array, default: () => [] },
    // The workflow the page is filtered to (the overview's workflow switcher),
    // preselected when the user may create in it. Null = the first one.
    preferred: { type: String, default: null },
    // Button (and modal title) label. Defaults to "New post".
    text: { type: String, default: null },
    // Forwarded to the kit's Button.
    size: { type: String, default: undefined },
});

const instance = getCurrentInstance();
const formId = `wf-create-post-${instance?.uid ?? 0}`;

const axios = instance?.appContext?.config?.globalProperties?.$axios;

const workflowChoices = computed(() => workflowOptions(props.creates));
const workflowLocked = computed(() => isWorkflowLocked(props.creates));

function freshModal(open) {
    const workflow = defaultWorkflow(props.creates, props.preferred);

    return { open, title: '', workflow, ...workflowFields(entryFor(props.creates, workflow)), saving: false };
}

const createModal = ref(freshModal(false));

// The chosen workflow's entry — its collections, its CTA, its endpoint.
const selected = computed(() => entryFor(props.creates, createModal.value.workflow));

// Another workflow means other collections and other candidates: reset what
// belonged to the old one. The title stays.
function selectWorkflow(workflow) {
    if (workflowLocked.value || !entryFor(props.creates, workflow)) return;
    createModal.value = { ...createModal.value, workflow, ...workflowFields(entryFor(props.creates, workflow)) };
}

// A title, a workflow, and — when the coupled transition asks who gets the
// post — at least one pick; the server refuses an empty pick with 422 before
// creating anything.
const canSubmit = computed(() =>
    createModal.value.title.trim() !== ''
    && selected.value !== null
    && (!selected.value.createCta?.needs_pick || createModal.value.assignees.length > 0)
    && !createModal.value.saving,
);

function openCreate() {
    createModal.value = freshModal(true);
}

function closeCreate() {
    if (createModal.value.saving) return;
    createModal.value.open = false;
}

// Creates the post with the typed title in the chosen workflow and — when the
// workflow couples one — runs the transition the button was labelled with. Slug
// and site are the server's business: the slug comes from the title, the site
// from the collection. Assignees come from the workflow metadata and then from
// the transition's own user_assignment.
//
// Two toasts are possible, and the second one matters: the server creates the
// post even when the coupled transition cannot run (no candidates, a gate),
// and then says so with the reason. A manual pick is asked for in the modal
// and validated before anything is created, so it never ends up here. Hiding that behind a
// plain "created" would leave the post in the initial place with nobody told.
async function submitCreate() {
    if (!canSubmit.value) return;
    const entry = selected.value;
    createModal.value.saving = true;

    const payload = {
        // The server checks this workflow again (may this user create in it?)
        // and resolves the allowed collections from it.
        workflow: entry.workflow,
        collection: createModal.value.collection ?? '',
        title: createModal.value.title.trim(),
        // Only meaningful for a manual assignment; the server ignores it otherwise.
        assignees: entry.createCta?.needs_pick ? [...createModal.value.assignees] : [],
        // The mail toggle exists only next to the picker; without it, mail as usual.
        notify: entry.createCta?.needs_pick ? createModal.value.notify : true,
    };

    try {
        let data;
        if (axios) {
            const res = await axios.post(entry.createUrl, payload);
            data = res?.data?.data;
        } else {
            const res = await fetch(entry.createUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload),
            });
            if (!res.ok) throw new Error('request failed');
            data = (await res.json())?.data;
        }

        window.Statamic?.$toast?.success(__('workflows::messages.post_created'));

        const transition = data?.transition;
        if (transition?.name && !transition.applied) {
            window.Statamic?.$toast?.error(__('workflows::messages.create_transition_skipped', {
                label: transition.label,
                reason: transition.reason ?? '',
            }));
        }

        // Back to the listing, for everyone: the CTA has usually handed the post
        // on already, and the listing is where the creator sees where it went.
        // The page reloads, so the modal stays in its saving state until it does.
        window.location.reload();
    } catch (e) {
        createModal.value.saving = false;
        const message = e?.response?.data?.message;
        window.Statamic?.$toast?.error(message || __('workflows::messages.post_create_error'));
    }
}
</script>
