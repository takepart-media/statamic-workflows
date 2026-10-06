<template>
    <div class="wf-editor-page">
        <Head :title="`${title} ‹ ${__('workflows::messages.settings_index_title')}`" />

        <!-- Where this page sits (Settings › Workflows › <workflow>) and the way
             to the workflow's own queue. Kept to one slim row: everything else —
             name, on/off, save, problems — lives in the editor's toolbar, and
             every pixel up here is one the canvas does not get. -->
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <nav :aria-label="__('workflows::messages.editor_breadcrumb_label')" class="min-w-0">
                <ol class="flex min-w-0 items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                    <li>{{ __('workflows::messages.settings') }}</li>
                    <li aria-hidden="true">›</li>
                    <li>
                        <Link :href="indexUrl" class="hover:text-gray-900 hover:underline dark:hover:text-gray-100">
                            {{ __('workflows::messages.settings_index_title') }}
                        </Link>
                    </li>
                    <li aria-hidden="true">›</li>
                    <li aria-current="page" class="truncate font-medium text-gray-900 dark:text-gray-100">{{ title }}</li>
                </ol>
            </nav>
            <!-- Only while the workflow runs and has a manager page of its own
                 (`metadata.nav.slug`) — see editSettings(). -->
            <Button
                v-if="queueUrl"
                size="sm"
                variant="ghost"
                icon="external-link"
                :text="__('workflows::messages.queue')"
                @click="router.visit(queueUrl)"
            />
        </div>

        <!-- Lazy chunk: Vue Flow and dagre are fetched only on this page, so the
             addon's main bundle (loaded on every CP page) stays small. -->
        <WorkflowEditor v-bind="editorProps" />
    </div>
</template>

<script setup>
import { computed, defineAsyncComponent, h, useAttrs } from 'vue';
import { Button } from '@statamic/cms/ui';
import { Head, Link, router } from '@statamic/cms/inertia';

defineOptions({ inheritAttrs: false });

const WorkflowEditor = defineAsyncComponent({
    loader: () => import('../editor/components/WorkflowEditor.vue'),
    loadingComponent: { render: () => h('p', { class: 'p-6 text-sm text-gray-500' }, __('workflows::messages.editor_loading')) },
    errorComponent: { render: () => h('p', { class: 'p-6 text-sm text-red-600' }, __('workflows::messages.editor_load_failed')) },
    delay: 150,
});

// Only what the page itself shows. Everything editSettings() sends goes on to
// the editor untouched (it declares what it reads — see WorkflowEditor.vue),
// so a new editor prop needs no change here.
const props = defineProps({
    workflow: { type: String, required: true },
    definition: { type: Object, required: true },
    // Settings › Workflows.
    indexUrl: { type: String, required: true },
    // The workflow's own queue; null while it is inactive or has no nav slug.
    queueUrl: { type: String, default: null },
});

const attrs = useAttrs();
const editorProps = computed(() => ({ ...attrs, workflow: props.workflow, definition: props.definition }));

// The stored nav label (what the index lists), else the handle. The editor's
// toolbar shows the label as it is being edited.
const title = computed(() => props.definition?.metadata?.nav?.label?.trim?.() || props.workflow);
</script>
