<template>
    <!-- Guarded: a service the server did not report on gets no line at all,
         rather than a line making claims about an undefined variable. -->
    <p
        v-if="status"
        class="flex items-center gap-1.5 text-xs"
        :class="status.set ? 'text-green-600 dark:text-green-500' : 'text-red-600 dark:text-red-500'"
    >
        <!-- `checkmark`, not `check` — the latter does not exist in Statamic's
             icon set (resources/svg/icons) and renders as a blank. -->
        <Icon :name="status.set ? 'checkmark' : 'warning-diamond'" class="size-3.5 shrink-0" />
        <span class="font-mono">{{ status.name }}</span>

        <!-- Copies the variable NAME, not any value — the value never reaches
             the client. What this saves is retyping the name into `.env`, which
             is exactly the step a "missing" line is asking for. -->
        <button
            type="button"
            class="shrink-0 rounded p-0.5 text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
            :title="copied ? __('workflows::messages.copied') : __('workflows::messages.copy_env_name', { name: status.name })"
            @click="copy"
        >
            <Icon :name="copied ? 'clipboard-check' : 'clipboard'" class="size-3.5" />
        </button>

        <span>{{ status.set ? __('workflows::messages.env_set') : __('workflows::messages.env_missing') }}</span>
        <span v-if="status.plan" class="text-gray-500 dark:text-gray-400">
            · {{ __(`workflows::messages.deepl_plan_${status.plan}`) }}
        </span>
    </p>
</template>

<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { Icon } from '@statamic/cms/ui';

/**
 * Says whether a translation service's env variable actually holds a value.
 *
 * Read-only by design: credentials are not editable in the CP because the
 * settings editor writes a committed YAML. This line exists so a missing `.env`
 * entry is visible here instead of first surfacing as a failed API call.
 *
 * The value never reaches the client — `status` carries only the variable name,
 * a boolean, and (for DeepL) the plan derived from the key's `:fx` suffix. See
 * WorkflowController::envStatus().
 */
const props = defineProps({
    // {name, set, plan}
    status: { type: Object, default: null },
});

const copied = ref(false);
let resetTimer = null;

async function copy() {
    try {
        await navigator.clipboard.writeText(props.status.name);
    } catch (e) {
        // Denied permission, or an insecure origin where the Clipboard API is
        // unavailable. Say so instead of flashing a success state for something
        // that did not happen — the name is right there to select by hand.
        window.Statamic?.$toast?.error(__('workflows::messages.copy_failed'));

        return;
    }

    copied.value = true;
    clearTimeout(resetTimer);
    resetTimer = setTimeout(() => (copied.value = false), 1500);
}

onBeforeUnmount(() => clearTimeout(resetTimer));
</script>
