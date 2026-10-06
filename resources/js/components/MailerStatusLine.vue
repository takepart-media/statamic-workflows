<template>
    <!-- Guarded like EnvStatusLine: without a server report there is nothing to
         claim, and a line asserting something about an undefined mailer would be
         worse than no line. -->
    <div v-if="status" class="space-y-1">
        <p
            class="flex flex-wrap items-center gap-1.5 text-xs"
            :class="status.delivers ? 'text-green-600 dark:text-green-500' : 'text-amber-600 dark:text-amber-500'"
        >
            <!-- `checkmark` / `warning-diamond` — the two names that exist in
                 Statamic's icon set; `check` renders as a blank. -->
            <Icon :name="status.delivers ? 'checkmark' : 'warning-diamond'" class="size-3.5 shrink-0" />
            <span>{{ __('workflows::messages.mailer_current') }}</span>
            <span class="font-mono">{{ status.mailer || '—' }}</span>
            <span v-if="status.transport && status.transport !== status.mailer" class="text-gray-500 dark:text-gray-400">
                ({{ status.transport }})
            </span>
            <span>·</span>
            <span>{{ status.delivers
                ? __('workflows::messages.mailer_delivering')
                : __('workflows::messages.mailer_not_delivering') }}</span>
        </p>

        <!-- The sender is a second, independent way for the mail to go nowhere
             useful, so it gets its own line rather than being folded into the one
             above. Amber only when it is missing — an address that exists is a
             judgement call the admin can make by reading it. -->
        <p class="text-xs" :class="status.from ? 'text-gray-500 dark:text-gray-400' : 'text-amber-600 dark:text-amber-500'">
            {{ status.from
                ? __('workflows::messages.mailer_from', { address: status.from })
                : __('workflows::messages.mailer_from_missing') }}
        </p>
    </div>
</template>

<script setup>
import { Icon } from '@statamic/cms/ui';

/**
 * Says whether a `mail` action would actually reach anybody.
 *
 * Read-only, and it never sees a credential: the server ships the mailer handle,
 * its transport, a boolean and the from address — no host, no username, no
 * password. See WorkflowController::mailStatus().
 *
 * Green is not "correctly configured", it is "hands the message to something
 * outside this process". A working SMTP host with a wrong password looks the same
 * from here; that failure surfaces in the log when a transition runs. What this
 * line catches is the failure that surfaces NOWHERE — `MAIL_MAILER=log`, where
 * every transition succeeds and no mail is ever delivered.
 */
defineProps({
    // {env, mailer, transport, delivers, from}
    status: { type: Object, default: null },
});
</script>
