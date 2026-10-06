<template>
    <!-- Personal header card: the current user's name, then generic
         label/values rows built by UserHeader::lines() (groups, affiliation,
         responsibles). Rows are rendered generically, so a further line is
         appended over there and needs no change here. -->
    <!-- `wf-user-header-card` caps the card at half the row it occupies; the
         widget is configured full-row so the two list cards pair up below it.
         See the class in resources/css/addon.css for why the two differ. -->
    <div class="wf-user-header-card overflow-hidden rounded-xl bg-white shadow-ui-md ring ring-gray-200 dark:bg-gray-850 dark:ring-gray-700/80">
        <div class="flex h-full flex-col">
            <header class="flex min-h-[49px] items-center gap-3 border-b border-gray-200 px-4.5 py-2 dark:border-gray-700">
                <!-- The CP's own avatar component: image where the user has one,
                     otherwise initials on a name-derived gradient. Its default
                     size and squircle shape are the Statamic look — not
                     overridden here. -->
                <Avatar :user="user" />
                <h2 class="font-medium text-gray-800 dark:text-gray-200">{{ user.name }}</h2>
            </header>

            <div class="flex-1 px-4.5 py-3">
                <dl class="divide-y divide-gray-100 dark:divide-gray-800">
                    <div v-for="(line, index) in lines" :key="index" class="flex items-center gap-3 py-2">
                        <dt class="w-40 shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ line.label }}</dt>
                        <dd class="flex min-w-0 flex-1 flex-wrap gap-1.5">
                            <span
                                v-for="value in line.values"
                                :key="value"
                                class="rounded-full bg-gray-100 px-2 py-0.5 text-2xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-200"
                            >
                                {{ value }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Avatar } from '@statamic/cms/ui';

/**
 * Dashboard widget `workflows_user_header`, rendered as a Vue component rather
 * than server-side Blade for one reason: the avatar. Statamic's `Avatar` — the
 * same one the assignee lists use — cannot be reached from a Blade widget, and
 * a hand-rolled initials circle never quite matches it (shape, gradient,
 * fallback chain). Everything else is markup the widget could have emitted
 * itself; the label/values rows stay a plain PHP-built array.
 */
defineProps({
    // {id, name, avatar, initials} — the keys `Avatar` reads.
    user: { type: Object, required: true },
    // [{label, values: [...]}], from UserHeader::lines().
    lines: { type: Array, default: () => [] },
});
</script>
