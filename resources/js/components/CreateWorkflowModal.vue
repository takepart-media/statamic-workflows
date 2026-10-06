<template>
    <!-- "Create workflow" — CP counterpart of `php artisan workflows:make`
         (WorkflowScaffolder). Blank, from a shipped example, or as a copy of
         an existing workflow; the new one always starts INACTIVE and opens in
         its editor right after.

         Mounted by the index only while open (v-if), so every open starts from
         a clean form. A <form> so Enter submits from any text field; Esc and
         the backdrop close via the kit Modal, refused while the request runs. -->
    <Modal :open="true" :before-close="() => !saving" @update:open="(v) => v || close()">
        <ModalTitle class="mb-1 flex items-center gap-2 border-b border-gray-200 pb-3 dark:border-gray-700!">
            <Icon name="plus" class="size-4" />
            <span class="text-lg font-medium text-gray-900 dark:text-white">{{ __('workflows::messages.create_workflow') }}</span>
        </ModalTitle>

        <form :id="formId" class="space-y-5 py-3" novalidate @submit.prevent="submit">
            <!-- Name and handle first: the modal focuses the first field. -->
            <div class="grid gap-4 sm:grid-cols-2">
                <Field :id="ids.label" :label="t('create_label_label', 'Name')" :errors="labelErrors">
                    <Input
                        :id="ids.label"
                        v-model="form.label"
                        :placeholder="t('create_label_placeholder', 'e.g. Press release')"
                        maxlength="120"
                        autocomplete="off"
                        focus
                        @update:model-value="onLabelInput"
                    />
                </Field>

                <!-- Derived from the name until edited by hand; from then on
                     the name no longer touches it. Clearing the field hands it
                     back to the name. -->
                <Field
                    :id="ids.handle"
                    :label="__('workflows::messages.handle')"
                    :instructions="t('create_handle_hint', 'File name and key of every state row — cannot be changed later.')"
                    instructions-below
                    required
                    :errors="handleErrors"
                >
                    <Input
                        :id="ids.handle"
                        v-model="form.handle"
                        class="font-mono"
                        :maxlength="create.handle_max"
                        autocomplete="off"
                        spellcheck="false"
                        :aria-invalid="handleErrors.length ? 'true' : null"
                        @update:model-value="onHandleInput"
                    />
                </Field>
            </div>

            <!-- Where the definition comes from. Radio cards, not a select:
                 three options with an explanation each scan better as cards. -->
            <Field :label="t('create_from_label', 'Start from')" :errors="fieldErrors.from">
                <div role="radiogroup" :aria-label="t('create_from_label', 'Start from')" class="grid gap-2 sm:grid-cols-3">
                    <button
                        v-for="option in sourceOptions"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.from === option.value"
                        :disabled="option.disabled"
                        :class="cardClass(form.from === option.value, option.disabled)"
                        @click="pickSource(option.value)"
                    >
                        <span class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100!">
                            <Icon :name="option.icon" class="size-4 text-gray-500 dark:text-gray-400" />
                            {{ option.label }}
                        </span>
                        <span class="text-xs text-gray-600 dark:text-gray-300!">{{ option.hint }}</span>
                    </button>
                </div>
            </Field>

            <!-- Template: every shipped example, installed ones included — an
                 example is a template here, created under a NEW handle, so
                 having it installed already is no reason to hide it. -->
            <Field
                v-if="form.from === 'example'"
                :label="t('create_template_label', 'Template')"
                :errors="fieldErrors.template"
            >
                <div role="radiogroup" :aria-label="t('create_template_label', 'Template')" class="grid gap-2" :class="create.templates.length > 1 ? 'sm:grid-cols-2' : ''">
                    <button
                        v-for="tpl in create.templates"
                        :key="tpl.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.template === tpl.value"
                        :class="cardClass(form.template === tpl.value)"
                        @click="pickTemplate(tpl)"
                    >
                        <span class="flex w-full items-center gap-2">
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100!">{{ tpl.label }}</span>
                            <Badge
                                v-if="tpl.installed"
                                class="ml-auto"
                                size="sm"
                                :text="t('create_template_installed', 'already installed')"
                            />
                        </span>
                        <span class="font-mono text-2xs text-gray-500 dark:text-gray-400!">{{ tpl.value }}</span>
                        <span
                            v-if="tpl.description && tpl.description !== tpl.label"
                            class="text-xs text-gray-600 dark:text-gray-300!"
                        >{{ tpl.description }}</span>
                    </button>
                </div>
                <p v-if="!create.templates.length" class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.install_no_types') }}
                </p>
            </Field>

            <Field
                v-if="form.from === 'copy'"
                :id="ids.copyOf"
                :label="t('create_copy_of_label', 'Copy of')"
                :errors="fieldErrors.copy_of"
            >
                <Select
                    :id="ids.copyOf"
                    v-model="form.copy_of"
                    :options="copyOptions"
                    :placeholder="t('create_copy_of_placeholder', 'Choose a workflow…')"
                    @update:model-value="pickCopy"
                />
            </Field>

            <Field :id="ids.collections" :label="__('workflows::messages.install_collections_label')" :errors="fieldErrors.collections">
                <template v-if="create.collections.length">
                    <!-- Example/copy: by default the source's own collections
                         are kept, and nothing is sent — the server takes them
                         from the template or the copied workflow. Unticking
                         reveals the picker; whatever it holds then (even
                         nothing) replaces the source's list. -->
                    <Checkbox
                        v-if="form.from !== 'blank'"
                        v-model="form.keepCollections"
                        :label="t('create_collections_keep', 'Keep the source\'s collections')"
                        :description="keepCollectionsHint"
                    />
                    <Select
                        v-if="form.from === 'blank' || !form.keepCollections"
                        :id="ids.collections"
                        v-model="form.collections"
                        :class="form.from !== 'blank' ? 'mt-2' : ''"
                        :options="collectionOptions"
                        multiple
                        close-on-select
                        clearable
                        :placeholder="__('workflows::messages.install_no_collections')"
                    />
                </template>
                <p v-else class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.install_no_project_collections') }}
                </p>
            </Field>

            <!-- Unlike the old installer there is no backfill here: the new
                 workflow is inactive, and the seeder only seeds active ones. -->
            <div class="flex gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900 dark:border-blue-900! dark:bg-blue-950/40! dark:text-blue-200!">
                <Icon name="info-square" class="mt-0.5 size-4 shrink-0" />
                <span>{{ __('workflows::messages.create_inactive_hint') }}</span>
            </div>

            <!-- Problems with the definition itself (a template that does not
                 validate in this project, e.g. an example naming a site the
                 project lacks) and anything else without a field. -->
            <div
                v-if="generalErrors.length"
                role="alert"
                class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900! dark:bg-red-950/40! dark:text-red-200!"
            >
                <p class="font-medium">{{ t('create_definition_errors', 'The new workflow would not be valid:') }}</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    <li v-for="(error, i) in generalErrors" :key="i">
                        <code v-if="error.path" class="font-mono text-2xs">{{ error.path }}</code>
                        <span v-if="error.path"> — </span>{{ error.message }}
                    </li>
                </ul>
            </div>
        </form>

        <template #footer>
            <div class="flex justify-end gap-2 px-2 pt-3">
                <Button type="button" variant="ghost" :text="__('workflows::messages.cancel')" :disabled="saving" @click="close" />
                <Button
                    type="submit"
                    :form="formId"
                    variant="primary"
                    icon="plus"
                    :text="__('workflows::messages.create')"
                    :loading="saving"
                    :disabled="!canSubmit"
                />
            </div>
        </template>
    </Modal>
</template>

<script setup>
import { computed, getCurrentInstance, reactive, ref } from 'vue';
import { Badge, Button, Checkbox, Field, Icon, Input, Modal, ModalTitle, Select } from '@statamic/cms/ui';
import { router } from '@statamic/cms/inertia';
import { handleProblem, mapCreateErrors, slugifyHandle, uniqueHandle } from './createWorkflowForm.js';

const props = defineProps({
    // settingsIndex's `create` block: {submit_url, templates, workflows,
    // collections, handle_pattern, handle_max, reserved_handles}.
    create: { type: Object, required: true },
    // The index rows, for the copied workflow's current collections (hint only).
    rows: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

// Unique per mount, for the label/for pairs and the footer's form= link.
const formId = `wf-create-${getCurrentInstance()?.uid ?? Date.now()}`;
const ids = {
    label: `${formId}-label`,
    handle: `${formId}-handle`,
    copyOf: `${formId}-copy-of`,
    collections: `${formId}-collections`,
};

// A lang key that does not exist yet comes back as the key itself — fall back
// to the English text rather than showing `workflows::messages.…`.
function t(key, fallback, params = {}) {
    const full = `workflows::messages.${key}`;
    const out = __(full, params);
    if (out !== full) return out;

    return Object.entries(params).reduce((s, [k, v]) => s.replaceAll(`:${k}`, v), fallback);
}

const form = reactive({
    from: 'blank',
    template: props.create.templates[0]?.value ?? '',
    copy_of: '',
    label: '',
    handle: '',
    handleTouched: false,
    keepCollections: true,
    collections: [],
});

const saving = ref(false);
// Server-side errors per field; cleared field by field as the user edits.
const serverErrors = reactive({});
const generalErrors = ref([]);

const takenHandles = computed(() => new Set(props.create.workflows.map((w) => String(w.handle).toLowerCase())));
const reserved = computed(() => (props.create.reserved_handles ?? []).map((h) => String(h).toLowerCase()));

const sourceOptions = computed(() => [
    { value: 'blank', icon: 'page-ghost', label: t('create_from_blank', 'Blank'), hint: t('create_from_blank_hint', 'Draft and published — a minimal start to build on.') },
    { value: 'example', icon: 'template-theme-design-layout', label: t('create_from_example', 'Template'), hint: t('create_from_example_hint', 'One of the shipped examples, under a new handle.'), disabled: !props.create.templates.length },
    { value: 'copy', icon: 'duplicate', label: t('create_from_copy', 'Copy of'), hint: t('create_from_copy_hint', 'Duplicate an existing workflow of this project.'), disabled: !props.create.workflows.length },
]);

const copyOptions = computed(() => props.create.workflows.map((w) => ({ value: w.handle, label: `${w.label} (${w.handle})` })));
const collectionOptions = computed(() => props.create.collections.map((h) => ({ value: h, label: h })));

const keepCollectionsHint = computed(() => {
    if (form.from === 'copy' && form.copy_of) {
        const row = props.rows.find((r) => r.handle === form.copy_of);
        if (row) {
            return row.collections?.length
                ? t('create_collections_keep_copy', 'Currently: :collections', { collections: row.collections.join(', ') })
                : t('create_collections_keep_none', 'The source has no collections.');
        }
    }

    return t('create_collections_keep_hint', 'Untick to choose different ones — an empty choice means none.');
});

function cardClass(active, disabled = false) {
    return [
        'flex flex-col items-start gap-1 rounded-lg border p-3 text-left transition-colors focus-visible:outline-2 focus-visible:outline-blue-500',
        disabled ? 'cursor-not-allowed opacity-50' : '',
        active
            ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500 dark:border-blue-400! dark:bg-blue-950/40!'
            : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700! dark:bg-gray-800! dark:hover:bg-gray-700/50!',
    ];
}

function clearServer(...keys) {
    keys.forEach((k) => delete serverErrors[k]);
    generalErrors.value = [];
}

// --- Handle derivation -------------------------------------------------------

function deriveHandle() {
    if (form.handleTouched) return;
    const base = slugifyHandle(form.label, props.create.handle_max);
    form.handle = uniqueHandle(base, takenHandles.value, reserved.value, props.create.handle_max);
}

function onLabelInput() {
    clearServer('label');
    if (!form.handleTouched) clearServer('handle');
    deriveHandle();
}

function onHandleInput(value) {
    clearServer('handle');
    // Emptying the field gives it back to the name.
    form.handleTouched = String(value ?? '') !== '';
    if (!form.handleTouched) deriveHandle();
}

// A source with a name of its own fills an empty name field, so picking a
// template is enough to get a sensible (and unique) handle.
function suggestLabel(label) {
    if (form.label.trim() === '' && label) {
        form.label = label;
        deriveHandle();
    }
}

function pickSource(value) {
    if (form.from === value) return;
    form.from = value;
    form.keepCollections = true;
    form.collections = [];
    clearServer('from', 'template', 'copy_of', 'collections');
    if (value === 'example') {
        const tpl = props.create.templates.find((x) => x.value === form.template);
        suggestLabel(tpl?.label);
    }
}

function pickTemplate(tpl) {
    form.template = tpl.value;
    clearServer('template');
    suggestLabel(tpl.label);
}

function pickCopy(handle) {
    clearServer('copy_of');
    const source = props.create.workflows.find((w) => w.handle === handle);
    suggestLabel(source?.label);
}

// --- Validation ---------------------------------------------------------------

const handleClientProblem = computed(() => handleProblem(form.handle, {
    pattern: props.create.handle_pattern,
    max: props.create.handle_max,
    reserved: reserved.value,
    taken: takenHandles.value,
}));

const handleErrors = computed(() => {
    if (serverErrors.handle?.length) return serverErrors.handle;
    const problem = handleClientProblem.value;
    // "Required" is only said once the user has had a go at it — an empty form
    // should not open in red.
    if (!problem || (problem === 'required' && !form.handleTouched && form.label === '')) return [];
    if (problem === 'required') return [t('create_handle_required', 'A handle is required.')];

    return [__(`workflows::messages.${problem}`, { handle: form.handle, max: props.create.handle_max })];
});

const labelErrors = computed(() => {
    if (serverErrors.label?.length) return serverErrors.label;
    if (form.label.trim().length > 120) return [__('workflows::messages.create_label_too_long', { max: 120 })];

    return [];
});

const fieldErrors = computed(() => ({
    from: serverErrors.from ?? [],
    template: serverErrors.template ?? [],
    copy_of: serverErrors.copy_of ?? [],
    collections: serverErrors.collections ?? [],
}));

const sourceReady = computed(() => {
    if (form.from === 'example') return !!form.template;
    if (form.from === 'copy') return !!form.copy_of;

    return true;
});

const canSubmit = computed(() => !saving.value
    && handleClientProblem.value === null
    && labelErrors.value.length === 0
    && sourceReady.value);

// --- Submit -----------------------------------------------------------------

function payload() {
    const body = { handle: form.handle.trim(), label: form.label.trim(), from: form.from };
    if (form.from === 'example') body.template = form.template;
    if (form.from === 'copy') body.copy_of = form.copy_of;
    // Omitted = keep the template's/copy's own list; a blank one starts empty.
    if (form.from === 'blank' || !form.keepCollections) body.collections = [...form.collections];

    return body;
}

async function post(body) {
    if (axios) {
        try {
            const res = await axios.post(props.create.submit_url, body);

            return { ok: true, data: res?.data?.data ?? null };
        } catch (e) {
            if (e?.response?.status === 422) return { ok: false, body: e.response.data };
            throw e;
        }
    }

    const res = await fetch(props.create.submit_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => null);
    if (res.status === 422) return { ok: false, body: json };
    if (!res.ok) throw Object.assign(new Error('request failed'), { response: { data: json } });

    return { ok: true, data: json?.data ?? null };
}

async function submit() {
    if (!canSubmit.value) return;
    saving.value = true;
    Object.keys(serverErrors).forEach((k) => delete serverErrors[k]);
    generalErrors.value = [];

    try {
        const result = await post(payload());

        if (!result.ok) {
            const { fields, general } = mapCreateErrors(result.body);
            Object.assign(serverErrors, fields);
            generalErrors.value = general;
            // Pinned to its field when there is one; the toast covers the case
            // where that field is not on screen (e.g. `from`).
            Statamic.$toast.error(result.body?.message || __('workflows::messages.create_error'));
            saving.value = false;

            return;
        }

        Statamic.$toast.success(__('workflows::messages.create_success'));
        const url = result.data?.edit_url;
        // The modal stays in its saving state until the editor has loaded.
        if (url) {
            try {
                router.visit(url);
            } catch {
                window.location.href = url;
            }
        } else {
            router.reload();
        }
    } catch (e) {
        Statamic.$toast.error(e?.response?.data?.message || __('workflows::messages.create_error'));
        saving.value = false;
    }
}

function close() {
    if (saving.value) return;
    emit('close');
}
</script>
