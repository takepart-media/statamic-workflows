<template>
    <div class="p-6">
        <!-- Statamic resolves the browser title as `title || bladeTitle`, so a
             page without a Head shows the layout's "Here ‹ Statamic" fallback. -->
        <Head :title="title" />

        <header class="mb-6 flex items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold dark:text-white">{{ title }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('workflows::messages.signed_in_as', { name: currentUser }) }}</p>
            </div>
            <!-- Opens the "New post" modal: the title is asked for, the collection
                 too where the workflow owns more than one. The confirm button is
                 the workflow CTA — see the modal. -->
            <Button
                v-if="createUrl"
                variant="primary"
                icon="add-entry"
                :text="__('workflows::messages.new_post')"
                @click="openCreate"
            />
        </header>

        <section class="mb-8">
            <h2 class="mb-3 text-lg font-semibold dark:text-white">{{ __('workflows::messages.my_queue') }}</h2>
            <WorkflowListing
                :url="listingUrl"
                :columns="myColumns"
                :additional-parameters="{ ...base, mine: 1 }"
                :show-toolbar="false"
                @open-summary="openSummary"
            />
        </section>

        <section class="mb-8">
            <h2 class="mb-3 text-lg font-semibold dark:text-white">{{ __('workflows::messages.my_created') }}</h2>
            <WorkflowListing
                :url="listingUrl"
                :columns="myColumns"
                :additional-parameters="{ ...base, created_by_me: 1 }"
                :show-toolbar="false"
                @open-summary="openSummary"
            />
        </section>

        <section>
            <!-- Named by the server: a localization manager lists the posts it can
                 work on, not "all posts" — see WorkflowController::workflow(). -->
            <h2 class="mb-3 text-lg font-semibold dark:text-white">{{ listingHeading || __('workflows::messages.all_posts') }}</h2>

            <!-- One tab per governed collection, so entries of different
                 collections never share a table. `unmount-on-hide` must stay
                 false: the default unmounts the hidden tab's Listing, which
                 would drop its search, filters, sort and page on every switch.
                 A dedicated preferences prefix keeps each tab's column setup
                 separate instead of all tabs fighting over one key. -->
            <Tabs v-if="hasCollectionTabs" v-model="activeCollection" :unmount-on-hide="false">
                <TabList class="mb-3">
                    <TabTrigger
                        v-for="collection in collections"
                        :key="collection.handle"
                        :name="collection.handle"
                        :text="collection.title"
                    />
                </TabList>
                <TabContent
                    v-for="collection in collections"
                    :key="collection.handle"
                    :name="collection.handle"
                >
                    <WorkflowListing
                        :url="listingUrl"
                        :columns="columns"
                        :filters="filters"
                        :additional-parameters="{ ...base, collection: collection.handle }"
                        :preferences-prefix="`workflows.${workflowName}.${collection.handle}`"
                        :show-toolbar="true"
                        @open-summary="openSummary"
                    />
                </TabContent>
            </Tabs>

            <!-- A single (or no) collection has nothing to split — render the
                 plain listing so those managers look exactly as before. -->
            <WorkflowListing
                v-else
                :url="listingUrl"
                :columns="columns"
                :filters="filters"
                :additional-parameters="base"
                :show-toolbar="true"
                @open-summary="openSummary"
            />
        </section>

        <!-- The "New post" modal. ONE confirm button, and it says what it does:
             with a coupled transition (metadata.create_transition, delivered as
             `createCta`) it carries that transition's label — "Entwurf erstellen" —
             and the server creates the post AND runs the step in one request;
             without one it says "Anlegen" and only creates. Two buttons would ask
             the editor a question the workflow author already answered.

             A <form> so Enter in the title field submits; the ghost Cancel is
             type="button" so it does not. Closing is refused while saving — the
             request has left, and a second click on "new post" would make two. -->
        <Modal v-if="createUrl" :open="createModal.open" @update:open="(v) => v || closeCreate()">
            <ModalTitle class="mb-1 flex items-center gap-2 border-b border-gray-200 pb-3 dark:border-gray-700!">
                <Icon name="add-entry" class="size-4" />
                <span class="text-lg font-medium text-gray-900 dark:text-white">{{ __('workflows::messages.new_post') }}</span>
            </ModalTitle>
            <form id="wf-create-post" class="space-y-4" @submit.prevent="submitCreate">
                <Field :label="__('workflows::messages.post_title')">
                    <Input v-model="createModal.title" :placeholder="__('workflows::messages.post_title')" focus />
                </Field>
                <!-- The noun comes from Statamic's own translation so it reads
                     like the sidenav („Sammlung" in German). -->
                <Field v-if="createCollections.length > 1" :label="__('workflows::messages.select_collection', { collection: __('Collection') })">
                    <Select adaptive-width v-model="createModal.collection" :options="createCollections" />
                </Field>
                <!-- The coupled transition's manual assignment, asked right here —
                     the same checkbox list the detail page's button opens
                     (WorkflowInstanceDetail's picker), so the CTA can run in the
                     same request. The candidates are the server's
                     (createCta.pickable_assignees); an empty list is shown as such
                     and blocks the button rather than creating a post nobody
                     could be handed. -->
                <Field v-if="createCta?.needs_pick" :label="__('workflows::messages.pick_assignee_title')" :instructions="__('workflows::messages.pick_assignee_prompt')">
                    <ul v-if="createCta.pickable_assignees?.length" class="divide-y divide-gray-100 rounded-md border border-gray-200 dark:divide-gray-800 dark:border-gray-700!">
                        <li v-for="user in createCta.pickable_assignees" :key="user.id">
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-200">
                                <input
                                    type="checkbox"
                                    :value="user.id"
                                    v-model="createModal.assignees"
                                    class="size-4 rounded border-gray-300 text-blue-600"
                                />
                                <Badge v-if="user.site_allocation" :text="user.site_allocation" color="blue" size="sm" />
                                <span class="min-w-0 flex-1 truncate">{{ user.name }}</span>
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
                    <span v-if="createCta?.needs_pick && createModal.assignees.length" class="mr-auto text-xs text-gray-500 dark:text-gray-400">
                        {{ __('workflows::messages.pick_assignee_count', { count: createModal.assignees.length }) }}
                    </span>
                    <Button type="button" variant="ghost" :text="__('workflows::messages.cancel')" :disabled="createModal.saving" @click="closeCreate" />
                    <Button
                        type="submit"
                        form="wf-create-post"
                        variant="primary"
                        icon="add-entry"
                        :text="createCta?.label ?? __('workflows::messages.create')"
                        :disabled="!canSubmit"
                        :loading="createModal.saving"
                    />
                </div>
            </template>
        </Modal>

    </div>
</template>

<script setup>
import { computed, ref, getCurrentInstance } from 'vue';
import { Badge, Button, Field, Icon, Input, Modal, ModalTitle, Select, TabContent, TabList, TabTrigger, Tabs } from '@statamic/cms/ui';
import { Head } from '@statamic/cms/inertia';
import WorkflowListing from '../components/WorkflowListing.vue';
import { registerStateColors, registerStateLabels } from '../support/states.js';

const props = defineProps({
    currentUser: { type: String, default: '' },
    workflow: { type: Object, default: null },
    workflowName: { type: String, default: null },
    // Heading of the big listing. Null on the cross-workflow queue, which has no
    // single workflow to name it after.
    listingHeading: { type: String, default: null },
    listingUrl: { type: String, required: true },
    columns: { type: Array, default: () => [] },
    myColumns: { type: Array, default: () => [] },
    filters: { type: Array, default: () => [] },
    createUrl: { type: String, default: null },
    // Collections this workflow governs as [{handle, title}], in YAML order —
    // one tab each in the "all posts" listing.
    collections: { type: Array, default: () => [] },
    // Collections the workflow OWNS as [{value, label}], i.e. the ones a new
    // entry may be created in. Empty when the user can't create.
    createCollections: { type: Array, default: () => [] },
    // The transition the confirm button runs after creating, as
    // {name, label, needs_pick, pickable_assignees} — or null, in which case the
    // button only creates. See WorkflowController::createCta().
    createCta: { type: Object, default: null },
    // Place handle => Badge color, from each place's `metadata.color`.
    stateColors: { type: Object, default: () => ({}) },
    stateLabels: { type: Object, default: () => ({}) },
});

registerStateColors(props.stateColors);
registerStateLabels(props.stateLabels);

const title = computed(() => props.workflow?.label ?? 'Workflow');

// Scope the server-side listing to a single workflow when on a manager page.
const base = computed(() => (props.workflowName ? { workflow: props.workflowName } : {}));

// Splitting only pays off from two collections up. The bare /cp/workflows queue
// has no workflow (and thus no collections) and falls into the same branch.
const hasCollectionTabs = computed(() => props.collections.length > 1);

// `Tabs` runs controlled — its `modelValue` defaults to null and is forwarded
// straight to reka-ui, so without an explicit model no tab would ever be active.
// Seed it with the first collection.
const activeCollection = ref(props.collections[0]?.handle ?? '');

const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

// A row title leads to the instance's own page. It used to open a slide-over
// Stack that fetched the summary payload over JSON and rendered
// WorkflowInstanceDetail inside it; the summary is a page now, so the click is a
// plain navigation. `row.url` comes from InstanceUrl, which already resolves the
// nav-friendly route per workflow — including the purpose-built translation view,
// which the Stack never could mount.
function openSummary(row) {
    if (row?.url) window.location.href = row.url;
}

// The "New post" modal's state. `collection` starts on the first owned one,
// which is also what a single-collection workflow always sends.
const createModal = ref({ open: false, title: '', collection: props.createCollections[0]?.value ?? '', assignees: [], saving: false });

// A title, and — when the coupled transition asks who gets the post — at least
// one pick; the server refuses an empty pick with 422 before creating anything.
const canSubmit = computed(() =>
    createModal.value.title.trim() !== ''
    && (!props.createCta?.needs_pick || createModal.value.assignees.length > 0)
    && !createModal.value.saving,
);

function openCreate() {
    createModal.value = { open: true, title: '', collection: props.createCollections[0]?.value ?? '', assignees: [], saving: false };
}

function closeCreate() {
    if (createModal.value.saving) return;
    createModal.value.open = false;
}

// Creates the post with the typed title and — when the workflow couples one —
// runs the transition the button was labelled with. Slug and site are the
// server's business: the slug comes from the title, the site from the
// collection. Assignees come from the workflow metadata and then from the
// transition's own user_assignment.
//
// Two toasts are possible, and the second one matters: the server creates the
// post even when the coupled transition cannot run (no candidates, a gate),
// and then says so with the reason. A manual pick is asked for in the modal
// and validated before anything is created, so it never ends up here. Hiding that behind a
// plain "created" would leave the post in the initial place with nobody told.
async function submitCreate() {
    if (!canSubmit.value) return;
    createModal.value.saving = true;

    const payload = {
        // The server resolves the allowed collections from this workflow — it no
        // longer assumes a collection named `posts`. Empty means "the first one
        // you own", which is the only option below two collections.
        workflow: props.workflowName,
        collection: createModal.value.collection ?? '',
        title: createModal.value.title.trim(),
        // Only meaningful for a manual assignment; the server ignores it otherwise.
        assignees: props.createCta?.needs_pick ? [...createModal.value.assignees] : [],
    };

    try {
        let data;
        if (axios) {
            const res = await axios.post(props.createUrl, payload);
            data = res?.data?.data;
        } else {
            const res = await fetch(props.createUrl, {
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

        // Back to the queue, for everyone: the CTA has usually handed the post
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
