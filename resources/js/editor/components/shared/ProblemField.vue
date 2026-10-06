<template>
    <!-- A Field that knows its place in the definition: `path` (validationPaths.js)
         marks it as `data-path` — so a picked problem can be scrolled to and
         focused — and brings the live validation's errors for that path as the
         Field's error. The kit's Field does not pass attributes through, hence
         the wrapper element. -->
    <div :data-path="path">
        <Field v-bind="$attrs" :error="shownError">
            <slot />
        </Field>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import { Field } from '@statamic/cms/ui';
import { EDITOR_CONTEXT } from '../context.js';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    path: { type: String, required: true },
    // An error of the caller's own; wins over the live validation's.
    error: { type: String, default: undefined },
});

const editor = inject(EDITOR_CONTEXT, null);

const shownError = computed(() => props.error ?? editor?.validation?.errorFor(props.path));
</script>
