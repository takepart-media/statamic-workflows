<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('workflows::messages.settings_index_title')" />

        <Header :title="__('workflows::messages.settings_index_title')" icon="workflow">
            <template #actions>
                <!-- Nothing installable left is a normal end state, not an
                     error: every shipped type is already in the project and is
                     managed from its own row. -->
                <Button
                    icon="plus"
                    variant="primary"
                    :text="__('workflows::messages.install_workflow')"
                    :disabled="!installableTypes.length"
                    v-tooltip="installableTypes.length ? null : __('workflows::messages.install_all_installed')"
                    @click="openInstallModal"
                />
            </template>
        </Header>

        <Listing
            :items="workflows"
            :columns="initialColumns"
            :allow-bulk-actions="false"
            :allow-presets="false"
        >
            <template #cell-title="{ row }">
                <a :href="row.edit_url" class="flex items-center gap-2 font-medium">
                    <Icon :name="row.icon" class="size-4 text-gray-500 dark:text-gray-400" />
                    <span>{{ row.title }}</span>
                </a>
            </template>

            <template #cell-handle="{ row }">
                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ row.handle }}</span>
            </template>

            <template #cell-collections="{ row }">
                <span v-if="row.collections.length" class="font-mono text-xs">
                    {{ row.collections.join(', ') }}
                </span>
                <span v-else class="text-gray-400">—</span>
            </template>

            <template #cell-status="{ row }">
                <!-- "Inactive" alone doesn't say what is switched off, so the
                     badge carries the explanation on hover. -->
                <Badge
                    :text="row.active ? __('workflows::messages.workflow_active') : __('workflows::messages.workflow_inactive')"
                    :color="row.active ? 'green' : 'default'"
                    size="sm"
                    v-tooltip="row.active ? null : __('workflows::messages.workflow_deactivate_hint')"
                />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem
                    v-if="row.edit_url"
                    icon="cog"
                    :text="__('workflows::messages.settings')"
                    :href="row.edit_url"
                />
                <DropdownItem
                    v-if="row.queue_url"
                    icon="external-link"
                    :text="__('workflows::messages.queue')"
                    :href="row.queue_url"
                />
                <!-- Only present on active rows that govern collections — the
                     seeder would no-op otherwise (see seed_url server-side). -->
                <DropdownItem
                    v-if="row.seed_url"
                    icon="sync"
                    :text="__('workflows::messages.seed_states')"
                    @click="seedStates(row)"
                />
                <DropdownItem
                    icon="fieldtype-toggle"
                    :text="row.active ? __('workflows::messages.workflow_deactivate') : __('workflows::messages.workflow_activate')"
                    @click="setActive(row, !row.active)"
                />
                <DropdownItem
                    icon="trash"
                    variant="destructive"
                    :text="__('workflows::messages.workflow_delete')"
                    @click="askDelete(row)"
                />
            </template>
        </Listing>

        <!-- Deleting drops the YAML only; the DB rows keyed by workflow name
             survive, which the confirmation text spells out. -->
        <ConfirmationModal
            v-if="deleting"
            :open="true"
            :title="__('workflows::messages.workflow_delete_title')"
            :body-text="__('workflows::messages.workflow_delete_confirm', { workflow: deleting.title })"
            :button-text="__('workflows::messages.workflow_delete')"
            :danger="true"
            :busy="deleteBusy"
            @confirm="runDelete"
            @cancel="deleting = null"
        />

        <!-- Installer modal — CP counterpart of `php artisan workflows:install`.
             Picks a workflow type, attaches collections, optionally backfills.
             Same primitives on the backend (WorkflowInstaller). -->
        <Modal :open="modal.open" @update:open="(v) => v || closeInstallModal()">
            <ModalTitle class="wf-modal-head">
                <Icon name="plus" class="size-4" />
                {{ __('workflows::messages.install_workflow') }}
            </ModalTitle>
            <div class="space-y-4 py-3">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ __('workflows::messages.install_intro') }}
                </p>

                <Field :label="__('workflows::messages.install_type_label')">
                    <!-- Radio-card grid instead of a dropdown: a handful of
                         workflow types is more scannable as cards than as
                         select options. Only types the project doesn't have
                         yet ever land here — the server filters them out. -->
                    <div class="grid gap-2" :class="installableTypes.length > 1 ? 'sm:grid-cols-2' : ''">
                        <button
                            v-for="type in installableTypes"
                            :key="type.value"
                            type="button"
                            :class="[
                                'flex flex-col items-start gap-1 rounded-lg border p-3 text-left transition-colors',
                                form.type === type.value
                                    ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500 dark:border-blue-400! dark:bg-blue-950/40!'
                                    : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700! dark:bg-gray-800! dark:hover:bg-gray-700/50!',
                            ]"
                            @click="pickType(type.value)"
                        >
                            <!-- The label is what the workflow calls itself
                                 (metadata.nav.label), the handle below it is the
                                 file name and the key every state row carries —
                                 both matter, so both are shown. -->
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100!">
                                {{ type.label }}
                            </span>
                            <span class="font-mono text-2xs text-gray-500 dark:text-gray-400!">
                                {{ type.value }}
                            </span>
                            <span
                                v-if="type.description && type.description !== type.label"
                                class="text-xs text-gray-600 dark:text-gray-300!"
                            >{{ type.description }}</span>
                        </button>
                    </div>
                </Field>

                <Field :label="__('workflows::messages.install_collections_label')">
                    <Select
                        v-if="install.collections.length"
                        v-model="form.collections"
                        :options="collectionOptions"
                        multiple
                        close-on-select
                        clearable
                        :placeholder="__('workflows::messages.install_no_collections')"
                    />
                    <p v-else class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('workflows::messages.install_no_project_collections') }}
                    </p>
                </Field>

                <Field :label="__('workflows::messages.install_backfill_label')">
                    <Checkbox
                        v-model="form.backfill"
                        :disabled="form.collections.length === 0"
                        solo
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('workflows::messages.install_backfill_hint') }}
                    </p>
                </Field>
            </div>
            <!-- `#footer` renders outside the Modal's scrolling body, so the
                 buttons stay reachable however long the form gets. -->
            <template #footer>
                <div class="flex justify-end gap-2 px-2 pt-3">
                    <Button
                        variant="ghost"
                        :text="__('workflows::messages.cancel')"
                        :disabled="form.saving"
                        @click="closeInstallModal"
                    />
                    <Button
                        variant="primary"
                        icon="download"
                        :text="__('workflows::messages.install_run')"
                        :loading="form.saving"
                        :disabled="form.saving || !form.type"
                        @click="runInstall"
                    />
                </div>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { reactive, ref, computed, getCurrentInstance } from 'vue';
import { Badge, Header, Listing, Icon, ConfirmationModal, DropdownItem, Button, Modal, ModalTitle, Field, Select, Checkbox } from '@statamic/cms/ui';
import { Head, router } from '@statamic/cms/inertia';

const props = defineProps({
    workflows: { type: Array, required: true },
    initialColumns: { type: Array, required: true },
    install: { type: Object, required: true },
});

// Statamic exposes axios via Vue's global properties, not as an importable module.
const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

const modal = reactive({ open: false });
const form = reactive({
    type: props.install.types[0]?.value ?? '',
    collections: [],
    backfill: false,
    saving: false,
});

// Workflow types the project does not have yet. The server already filters
// these — an installed workflow is managed from its own row (settings, on/off,
// delete), never by running the installer over it again.
const installableTypes = computed(() => props.install.types);

const collectionOptions = computed(() =>
    props.install.collections.map((h) => ({ value: h, label: h })),
);

// Backfill only makes sense with attached collections — untick automatically
// when the collection list drops to zero so the payload can't send true+empty.
function syncBackfill() {
    if (form.collections.length === 0) {
        form.backfill = false;
    }
}

function pickType(type) {
    form.type = type;
    form.collections = [];
    form.backfill = false;
}

function openInstallModal() {
    if (!installableTypes.value.length) return;
    pickType(installableTypes.value[0].value);
    modal.open = true;
}

function closeInstallModal() {
    if (form.saving) return;
    modal.open = false;
}

async function runInstall() {
    syncBackfill();
    form.saving = true;

    try {
        const data = await request('post', props.install.submit_url, {
            type: form.type,
            collections: form.collections,
            backfill: form.backfill,
        });

        Statamic.$toast.success(__('workflows::messages.install_success'));

        // Same figure the per-row "backfill states" action reports.
        if ((data?.seeded ?? 0) > 0) {
            Statamic.$toast.success(__('workflows::messages.states_seeded', { count: data.seeded }));
        }

        modal.open = false;
        // Full reload so the listing (and installer state) reflects the new
        // definition without a manual refresh.
        router.reload();
    } catch (e) {
        Statamic.$toast.error(errorMessage(e, 'install_error'));
    } finally {
        form.saving = false;
    }
}

// The row actions below all hit an endpoint and then either reload the page or
// report their result — the shared helpers keep the fetch/axios split and the
// error-message fallback in one place.
function errorMessage(e, fallbackKey) {
    return e?.response?.data?.error
        || e?.response?.data?.message
        || __(`workflows::messages.${fallbackKey}`);
}

// Returns the endpoint's `data` envelope so callers don't have to know whether
// axios or the fetch fallback did the work.
async function request(method, url, payload = null) {
    if (axios) {
        const res = await axios({ method, url, data: payload });

        return res?.data?.data ?? null;
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

    return (await res.json())?.data ?? null;
}

// Switching a workflow off leaves its definition and history alone — it just
// stops appearing in the nav, the manager pages and the entry editor.
const togglingHandle = ref(null);

async function setActive(row, active) {
    if (togglingHandle.value) return;
    togglingHandle.value = row.handle;

    try {
        await request('post', row.active_url, { active });
        Statamic.$toast.success(__(
            active ? 'workflows::messages.workflow_activated' : 'workflows::messages.workflow_deactivated',
        ));
        router.reload();
    } catch (e) {
        Statamic.$toast.error(errorMessage(e, 'workflow_active_error'));
    } finally {
        togglingHandle.value = null;
    }
}

// Backfills the state rows of entries that predate the workflow. Idempotent, so
// it needs no confirmation — but it does need honest feedback, because "nothing
// happened" and "nothing was left to do" look identical otherwise. Nothing on
// this page changes, so there is nothing to reload afterwards.
const seedingHandle = ref(null);

async function seedStates(row) {
    if (seedingHandle.value) return;
    seedingHandle.value = row.handle;

    try {
        const data = await request('post', row.seed_url);
        const seeded = data?.seeded ?? 0;

        if (seeded > 0) {
            Statamic.$toast.success(__('workflows::messages.states_seeded', { count: seeded }));

            // Every entry landed in the initial place — a legitimate setup, but
            // rarely the one the operator pictured.
            if (data?.lacks_done_place) {
                Statamic.$toast.error(__('workflows::messages.states_seeded_no_done_place'));
            }
        } else {
            Statamic.$toast.success(__('workflows::messages.states_seeded_none'));
        }
    } catch (e) {
        Statamic.$toast.error(errorMessage(e, 'states_seed_error'));
    } finally {
        seedingHandle.value = null;
    }
}

const deleting = ref(null);
const deleteBusy = ref(false);

function askDelete(row) {
    deleting.value = row;
}

async function runDelete() {
    if (!deleting.value || deleteBusy.value) return;
    deleteBusy.value = true;

    try {
        await request('delete', deleting.value.delete_url);
        Statamic.$toast.success(__('workflows::messages.workflow_deleted'));
        deleting.value = null;
        router.reload();
    } catch (e) {
        Statamic.$toast.error(errorMessage(e, 'workflow_delete_error'));
    } finally {
        deleteBusy.value = false;
    }
}
</script>
