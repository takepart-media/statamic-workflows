<template>
    <div class="max-w-page mx-auto">
        <Head :title="__('workflows::messages.settings_index_title')" />

        <Header :title="__('workflows::messages.settings_index_title')" icon="workflow">
            <template #actions>
                <!-- Blank, from a shipped example, or as a copy — the shipped
                     examples are templates in there, which replaces the old
                     "install workflow" modal. -->
                <Button
                    icon="plus"
                    variant="primary"
                    :text="__('workflows::messages.create_workflow')"
                    @click="createOpen = true"
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

        <CreateWorkflowModal
            v-if="createOpen"
            :create="create"
            :rows="workflows"
            @close="createOpen = false"
        />
    </div>
</template>

<script setup>
import { ref, getCurrentInstance } from 'vue';
import { Badge, Header, Listing, Icon, ConfirmationModal, DropdownItem, Button } from '@statamic/cms/ui';
import { Head, router } from '@statamic/cms/inertia';
import CreateWorkflowModal from '../components/CreateWorkflowModal.vue';

const props = defineProps({
    workflows: { type: Array, required: true },
    initialColumns: { type: Array, required: true },
    create: { type: Object, required: true },
    // Still sent by settingsIndex for the install endpoint; the page no longer
    // uses it (examples are templates in the create modal). Declared so it
    // does not fall through as an attribute onto the root element.
    install: { type: Object, default: null },
});

// Statamic exposes axios via Vue's global properties, not as an importable module.
const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

const createOpen = ref(false);

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
