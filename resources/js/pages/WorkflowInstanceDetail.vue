<template>
    <!-- A full CP page, not an overlay. Same page shell as the translation detail
         view (TranslationInstanceDetail) so the two summaries read as one product.
         The Stacks further down are real drawers — history and assignees. Comments
         sit in a margin next to the content instead. -->
    <div class="max-w-[1600px] p-6">
        <Head :title="instance.subject.title" />

        <!-- Sticky action bar: where you are in the workflow, and every move out of
             it. Deliberately NOT a second header — the title block below scrolls
             away, because it is orientation rather than action. See .wf-page-head. -->
        <div class="wf-page-head">
            <Button :href="queueUrl" icon="arrow-left" variant="ghost" size="sm" :text="__('workflows::messages.back')" />

            <!-- Only the workflow moves live here now. `gap-6` rather than
                 `space-x-6`: the bar wraps on narrow windows, and space-x puts its
                 margin on every child but the first — a wrapped row then starts
                 indented and gets no vertical spacing at all. -->
            <div class="ml-auto flex flex-wrap items-center justify-end gap-6">
                <Button
                    v-for="action in actions"
                    :key="action.transition.name"
                    :variant="action.variant || 'primary'"
                    size="sm"
                    :disabled="!action.can_apply || pending === action.transition.name"
                    :title="action.reason || ''"
                    :text="action.transition.label"
                    @click="apply(action)"
                />
            </div>
        </div>

        <header class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <!-- Title, then which version this is — and below it the whole
                     path, not just the current stop.

                     The state used to be a Badge here, with the site riding along
                     as its `append`. A badge names a position; on a page called
                     "summary" the question is the route: how many steps there are,
                     how many are behind you, whether you are still on the line at
                     all. So the state moved into WorkflowProgress and the site
                     kept only what it ever was — a qualifier on the title.

                     `.wf-site-code` rather than a second Badge on purpose: a Badge
                     carries state semantics and its own colour scale, and the site
                     is neither (see addon.css). -->
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold dark:text-white">{{ instance.subject.title }}</h1>
                    <span
                        v-if="instance.subject.code"
                        class="wf-site-code"
                        :title="instance.subject.site_name"
                    >{{ instance.subject.code }}</span>
                </div>
                <!-- Seit wann die Fassung im aktuellen Status steht — die Uhr, nach
                     der Erinnerung und Auto-Transition rechnen (entered_at). -->
                <p v-if="instance.entered_at" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.state_since', { state: humanize(instance.current_state), date: formatSince(instance.entered_at) }) }}
                </p>
                <!-- The two things you do with the entry itself, as opposed to the
                     workflow moves in the bar above: open it, or read its log.

                     "Edit" is plain navigation. It used to embed Statamic's
                     InlineEditForm in a nested Stack, which meant mirroring Statamic's
                     private publish-form prop map by hand — a coupling that broke on CP
                     internals (the nested form rendered with no active localization and
                     threw on `activeLocalization.status` / `.livePreviewUrl`). A link
                     cannot drift out of sync with a Statamic release. `cp_url` is null
                     unless the user may actually edit — Statamic's own entry permission
                     plus site access, see WorkflowController::canOpenEntryEditor(). -->
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <Button
                        v-if="instance.subject.cp_url"
                        :href="instance.subject.cp_url"
                        icon="pencil"
                        variant="filled"
                        size="sm"
                        :text="__('workflows::messages.edit')"
                    />
                    <!-- The page itself, in its own window: the public page for a
                         published post, otherwise a tokenised preview of the working
                         copy or draft (see EntryPreviewController::modeFor). `preview_url`
                         is null on the same terms as `cp_url`, plus "no frontend route". -->
                    <Button
                        v-if="instance.subject.preview_url"
                        :icon="preview.icon"
                        variant="ghost"
                        size="sm"
                        :text="preview.text"
                        :title="preview.title"
                        @click="openPreviewWindow(instance.subject.preview_url, instance.subject.id)"
                    />
                    <!-- Bookmark for the "Watched" dashboard card — personal, no mail,
                         no responsibility. Filled while watching, so the state reads at a
                         glance; then crossed out, because a click stops watching.
                         `bookmark`, not `eye`, because the eye is the preview. Being
                         assigned starts a watch on its own (WorkflowAssigneeStore). -->
                    <Button
                        v-if="watchUrl"
                        :icon="isWatching ? BOOKMARK_SLASH : 'bookmark'"
                        :variant="isWatching ? 'default' : 'ghost'"
                        size="sm"
                        :text="isWatching ? __('workflows::messages.watching') : __('workflows::messages.watch')"
                        :title="isWatching ? __('workflows::messages.unwatch') : null"
                        :disabled="watchBusy"
                        @click="toggleWatch"
                    />
                    <Button
                        v-if="canViewHistory"
                        icon="time-clock"
                        variant="ghost"
                        size="sm"
                        :text="__('workflows::messages.history_open')"
                        @click="openHistory"
                    />
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('workflows::messages.assigned_to') }}</span>
                    <div
                        v-if="assigneeNames.length"
                        class="flex items-center -space-x-2"
                        :title="assigneeNames.join(', ')"
                    >
                        <Avatar
                            v-for="(name, i) in visibleAssignees"
                            :key="i"
                            :user="{ name }"
                            class="ring-2 ring-white dark:ring-gray-900!"
                        />
                        <div
                            v-if="overflowAssignees"
                            class="flex size-7 items-center justify-center rounded-xl bg-gray-200 text-2xs font-medium text-gray-600 ring-2 ring-white dark:bg-gray-700! dark:text-gray-200! dark:ring-gray-900!"
                        >+{{ overflowAssignees }}</div>
                    </div>
                    <span v-else class="text-sm italic text-gray-400 dark:text-gray-500">{{ __('workflows::messages.no_assignees') }}</span>
                    <!-- Only for groups the workflow lets assign — `metadata.assigner_groups`.
                         The endpoint enforces the same rule; this just stops offering a
                         button that would come back 403. -->
                    <Button
                        v-if="canAssign"
                        icon="pencil"
                        variant="ghost"
                        size="sm"
                        :aria-label="__('workflows::messages.edit_assignees')"
                        :title="__('workflows::messages.edit_assignees')"
                        @click="openAssignees"
                    />
                </div>
            </div>
        </header>

        <!-- The progress bar and the passages it describes, laid out together:
             above the content when the bar runs horizontally, in a side column to
             its left when it runs vertically. The bar is about the whole entry, so
             it belongs to the body rather than the title block — and a side column
             only makes sense next to something tall, which the content is. -->
        <div :class="progressLayout.wrapper">
            <WorkflowProgress :steps="steps" :orientation="progressOrientation" :class="progressLayout.bar" />

            <section v-if="content.length" :class="progressLayout.content">
                <!-- Laid out on the same columns as content and margin below, so the
                     comments toggle sits right above the comment column (and at the
                     row's end while the column is hidden). -->
                <div
                    class="mb-2.5 items-center gap-x-6 gap-y-2"
                    :class="commentSidebar.open.value ? 'grid lg:grid-cols-[minmax(0,1fr)_20rem]' : 'flex'"
                >
                    <div class="flex items-center gap-3">
                        <h2 class="text-base font-semibold text-gray-700 dark:text-gray-200">{{ __('workflows::messages.content') }}</h2>
                        <!-- Super admins only: field handles + passage uuids, remembered
                             per browser. Off by default — editors read the content. -->
                        <label v-if="isSuper" class="flex cursor-pointer items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <Switch size="xs" v-model="technical" />
                            {{ __('workflows::messages.content_technical_details') }}
                        </label>
                    </div>
                    <!-- Shows / hides the Word-style comment margin. -->
                    <div class="ml-auto flex justify-end">
                        <Button
                            icon="mail-chat-bubble-text"
                            variant="ghost"
                            size="sm"
                            :text="commentSidebar.open.value ? __('workflows::messages.comments_hide') : __('workflows::messages.comments_show')"
                            @click="commentSidebar.toggle()"
                        />
                    </div>
                </div>
                <!-- Content and, Word-style, its comment margin beside it. -->
                <div class="grid gap-6" :class="{ 'lg:grid-cols-[minmax(0,1fr)_20rem]': commentSidebar.open.value }">
                    <ContentBlocks
                        :blocks="content"
                        commentable
                        :technical="isSuper && technical"
                        :comment-count="commentSidebar.commentCount"
                        :has-new="commentSidebar.hasNew"
                        :active-uuid="commentSidebar.activeUuid.value"
                        :whole-commented="commentSidebar.wholeCommented"
                        :marks="commentSidebar.marks.value"
                        @comment="commentSidebar.openForPassage"
                        @comment-selection="commentSidebar.openForSelection"
                        @mark="commentSidebar.openForMark"
                    />
                    <CommentsMargin v-if="commentSidebar.open.value" :comments="commentSidebar" />
                    <CommentsPopover :comments="commentSidebar" />
                </div>
            </section>
        </div>

        <!-- Summary, translations, admin override — three blocks in that order, each
             fenced off by a rule. A listing rather than pills here: in the post manager
             the translations are a work list, which site is how far along, so title,
             site and state each get their own column. The entry being viewed is
             filtered out inside; the translation view keeps the compact pills, where
             the versions are a place to jump between rather than the subject. -->
        <section v-if="totalVersions > 1" class="border-t border-gray-100 pt-6 dark:border-gray-800">
            <h2 class="mb-2.5 text-base font-semibold text-gray-700 dark:text-gray-200">{{ __('workflows::messages.versions') }}</h2>
            <VersionListing :versions="localVersions" />
        </section>

        <!-- History drawer, opened from the sticky bar. It is a log — you consult it
             when something looks off, and it used to push the page a long way down for
             everyone else. -->
        <Stack
            v-if="canViewHistory"
            :open="historyDrawer.open"
            @update:open="(v) => v || closeHistory()"
            :title="__('workflows::messages.history')"
            icon="time-clock"
            size="half"
        >
            <WorkflowHistory
                :history="history"
                :history-diff-url-template="historyDiffUrlTemplate"
                :row-class="row"
                @open-comment="openCommentFromHistory"
            />
        </Stack>

        <!-- Assignee editor drawer: assignment is owned by the workflow. -->
        <Stack
            :open="assigneeDrawer.open"
            @update:open="(v) => v || closeAssignees()"
            :title="__('workflows::messages.edit_assignees')"
            icon="users"
            size="narrow"
            inset
        >
            <div class="sticky top-0 z-10 mb-1 flex flex-col gap-2 border-b border-gray-200 bg-white px-6 py-4 dark:border-gray-700! dark:bg-gray-900!">
                <Input
                    v-model="assigneeSearch"
                    icon="magnifying-glass"
                    clearable
                    :placeholder="__('workflows::messages.search_users')"
                />
                <Select
                    v-if="groupOptions.length"
                    v-model="assigneeGroup"
                    :options="groupOptions"
                    clearable
                    :placeholder="__('workflows::messages.all_groups')"
                />
            </div>

            <ul v-if="filteredAssignableUsers.length" class="divide-y divide-gray-100 px-6 pb-4 dark:divide-gray-800">
                <li v-for="user in filteredAssignableUsers" :key="user.id">
                    <label class="flex cursor-pointer items-center gap-3 py-2.5 text-sm text-gray-700 dark:text-gray-200">
                        <input
                            type="checkbox"
                            :value="user.id"
                            v-model="assigneeDrawer.selected"
                            class="size-4 rounded border-gray-300 text-blue-600"
                        />
                        <Avatar :user="{ name: user.name }" class="size-6" />
                        <Badge
                            v-if="user.site_allocation"
                            :text="user.site_allocation"
                            color="blue"
                            size="sm"
                        />
                        <span class="min-w-0 flex-1 truncate">{{ user.name }}</span>
                        <span v-if="user.groups && user.groups.length" class="flex flex-wrap justify-end gap-1">
                            <Badge
                                v-for="group in user.groups.slice(0, MAX_GROUP_BADGES)"
                                :key="group"
                                :text="group"
                                color="default"
                                size="sm"
                            />
                            <Badge
                                v-if="user.groups.length > MAX_GROUP_BADGES"
                                :text="__('workflows::messages.more_groups', { count: user.groups.length - MAX_GROUP_BADGES })"
                                :title="user.groups.slice(MAX_GROUP_BADGES).join(', ')"
                                color="default"
                                size="sm"
                            />
                        </span>
                    </label>
                </li>
            </ul>
            <p v-else class="px-6 py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                {{ __('workflows::messages.no_users_found') }}
            </p>

            <template #footer-end>
                <Button
                    variant="primary"
                    size="sm"
                    :disabled="assigneeDrawer.saving"
                    :text="__('workflows::messages.save')"
                    @click="saveAssignees"
                />
            </template>
        </Stack>

        <!-- Assignee picker: opens for a MANUAL `user_assignment` action. Multi-select —
             handing a step to two people is a normal case, and the server
             accepts a list. Same UX as the fieldtype picker in the entry editor.

             Nothing in here scrolls on its own: Statamic's modal content is
             already a capped scroller, and a second one inside it makes the
             outer content taller than its cap. The modal then opens scrolled
             (autofocus jumps to the first checkbox) and the prompt disappears
             under the pinned heading. -->
        <Modal
            v-if="picker.action"
            :open="!!picker.action"
            @update:open="(v) => v || cancelPicker()"
        >
            <!-- Plain ModalTitle — see the picker in TranslationInstanceDetail
                 for why `.wf-modal-head` is deliberately absent here. -->
            <ModalTitle>
                <span class="text-lg font-medium text-gray-900 dark:text-white">{{ __('workflows::messages.pick_assignee_title') }}</span>
            </ModalTitle>

            <!-- An automatic assignment redirected here because it would have handed the
                 post back to the clicker alone (SelfAssignmentGuard) — say why. -->
            <Alert v-if="picker.action.pick_hint" variant="warning">
                <p class="text-sm">{{ picker.action.pick_hint }}</p>
            </Alert>

            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('workflows::messages.pick_assignee_prompt') }}
            </p>

            <!-- Off = hand over without the step's mail. Offered only when the step
                 mails at all (`sends_mail`, the same answer the send uses). -->
            <label v-if="picker.action.sends_mail" class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <Switch size="xs" v-model="picker.notify" />
                {{ __('workflows::messages.pick_assignee_notify') }}
            </label>

            <ul v-if="picker.action.pickable_assignees?.length" class="divide-y divide-gray-100 rounded-md border border-gray-200 dark:divide-gray-800 dark:border-gray-700!">
                <li v-for="user in picker.action.pickable_assignees" :key="user.id">
                    <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-200">
                        <input
                            type="checkbox"
                            :value="user.id"
                            v-model="picker.selected"
                            class="size-4 rounded border-gray-300 text-blue-600"
                        />
                        <Badge
                            v-if="user.site_allocation"
                            :text="user.site_allocation"
                            color="blue"
                            size="sm"
                        />
                        <span class="min-w-0 flex-1 truncate">{{ user.name }}</span>
                        <span
                            v-if="user.notified && picker.notify && picker.selected.includes(user.id)"
                            class="shrink-0 text-xs text-gray-500 dark:text-gray-400"
                            :title="__('workflows::messages.pick_assignee_notified_hint')"
                        >{{ __('workflows::messages.pick_assignee_notified') }}</span>
                    </label>
                </li>
            </ul>
            <p v-else class="py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                {{ __('workflows::messages.no_users_found') }}
            </p>

            <template #footer>
                <div class="flex items-center justify-end gap-2 px-2 pt-3">
                    <span v-if="picker.selected.length" class="mr-auto text-xs text-gray-500 dark:text-gray-400">
                        {{ __('workflows::messages.pick_assignee_count', { count: picker.selected.length }) }}
                    </span>
                    <Button variant="ghost" :text="__('workflows::messages.cancel')" @click="cancelPicker" />
                    <Button
                        variant="primary"
                        :text="__('workflows::messages.confirm')"
                        :disabled="!picker.selected.length || picker.saving"
                        @click="confirmPicker"
                    />
                </div>
            </template>
        </Modal>

        <!-- Target-site picker for `needs_site_pick` transitions
             (e.g. start_translation). Splitter layout: left panel shows the
             source site as context, right panel is a single-select radio
             list of the still-missing locales. On confirm the backend
             creates the localization and applies the transition to that new
             copy; performApply then follows `response.data.url` to the new
             entry. -->
        <Modal
            v-if="sitePicker.action"
            :open="!!sitePicker.action"
            @update:open="(v) => v || cancelSitePicker()"
        >
            <ModalTitle class="wf-modal-head">
                <Icon name="dictionary-language-book" class="size-4" />
                <span class="text-lg font-medium text-gray-900 dark:text-white">{{ __('workflows::messages.transition_site_pick_title') }}</span>
            </ModalTitle>

            <SplitterGroup direction="horizontal" class="min-h-[16rem]">
                <SplitterPanel :default-size="40" :min-size="25">
                    <div class="flex h-full flex-col gap-2 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('workflows::messages.transition_site_pick_source_label') }}
                        </p>
                        <!-- Read-only unless the workflow allows a per-run source
                             pick (`translation_source_pick`) — see the same block in
                             TranslationInstanceDetail. -->
                        <ul
                            v-if="sitePicker.action.needs_source_pick"
                            class="max-h-72 divide-y divide-gray-100 overflow-auto rounded-md border border-gray-200 dark:divide-gray-800 dark:border-gray-700!"
                        >
                            <li v-for="site in sitePicker.action.source_sites" :key="site.value">
                                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-200">
                                    <input
                                        type="radio"
                                        name="workflow-source-site"
                                        :value="site.value"
                                        v-model="sitePicker.source"
                                        class="size-4 border-gray-300 text-blue-600"
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{ site.label }}</span>
                                    <span class="font-mono text-xs text-gray-400">{{ site.value }}</span>
                                </label>
                            </li>
                        </ul>
                        <div v-else class="flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700! dark:bg-gray-800!">
                            <span class="font-medium text-gray-800 dark:text-gray-100">
                                {{ sitePicker.action.source_site?.label || sitePicker.action.source_site?.value }}
                            </span>
                            <span class="font-mono text-xs text-gray-400">{{ sitePicker.action.source_site?.value }}</span>
                        </div>
                    </div>
                </SplitterPanel>
                <SplitterResizeHandle />
                <SplitterPanel :default-size="60" :min-size="40">
                    <div class="flex h-full flex-col gap-2 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __('workflows::messages.transition_site_pick_target_label') }}
                        </p>
                        <ul
                            v-if="sitePicker.action.pickable_sites?.length"
                            class="max-h-72 divide-y divide-gray-100 overflow-auto rounded-md border border-gray-200 dark:divide-gray-800 dark:border-gray-700!"
                        >
                            <li v-for="site in sitePicker.action.pickable_sites" :key="site.value">
                                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm text-gray-700 dark:text-gray-200">
                                    <input
                                        type="radio"
                                        name="workflow-target-site"
                                        :value="site.value"
                                        v-model="sitePicker.selected"
                                        class="size-4 border-gray-300 text-blue-600"
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{ site.label }}</span>
                                    <span class="font-mono text-xs text-gray-400">{{ site.value }}</span>
                                </label>
                            </li>
                        </ul>
                        <p v-else class="rounded-md border border-dashed border-gray-200 py-6 text-center text-sm text-gray-400 dark:border-gray-700! dark:text-gray-500">
                            {{ __('workflows::messages.transition_site_pick_no_targets') }}
                        </p>
                    </div>
                </SplitterPanel>
            </SplitterGroup>

            <template #footer>
                <div class="flex justify-end gap-2 px-2 pt-3">
                    <!-- The confirm request translates the whole entry before it
                         creates anything, so it takes seconds rather than being
                         instant. Saying so beats a button that just sits there. -->
                    <p v-if="sitePicker.saving" class="mr-auto text-xs text-gray-500 dark:text-gray-400">
                        {{ __('workflows::messages.translation_running') }}
                    </p>
                    <Button variant="ghost" :text="__('workflows::messages.cancel')" @click="cancelSitePicker" />
                    <Button
                        variant="primary"
                        :text="__('workflows::messages.transition_site_pick_confirm')"
                        :loading="sitePicker.saving"
                        :disabled="!sitePicker.selected || sitePicker.saving"
                        @click="confirmSitePicker"
                    />
                </div>
            </template>
        </Modal>

        <!-- PlaceActions::REFRESH_INTERVAL. Only ever shown once the server
             confirmed something actually changed since page-load — see
             checkForUpdates(); a no-op check just reschedules silently. -->
        <Modal
            v-if="showRefreshModal"
            :open="showRefreshModal"
            @update:open="(v) => v || dismissRefresh()"
        >
            <ModalTitle>
                <span class="text-lg font-medium text-gray-900 dark:text-white">{{ __('workflows::messages.refresh_modal_title') }}</span>
            </ModalTitle>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('workflows::messages.refresh_modal_body') }}
            </p>
            <template #footer>
                <div class="flex items-center justify-end gap-2 px-2 pt-3">
                    <Button variant="ghost" :text="__('workflows::messages.refresh_modal_later')" @click="dismissRefresh" />
                    <Button variant="primary" :text="__('workflows::messages.refresh_modal_confirm')" @click="confirmRefresh" />
                </div>
            </template>
        </Modal>

        <!-- The manual state override stays at the end of the page, apart from the
             transitions in the sticky bar. It is an admin correction tool, not an
             everyday step, and next to the transitions it would be easy to mistake
             for one. -->
        <div v-if="canSetState" class="mt-8 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-6 dark:border-gray-800">
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('workflows::messages.set_state') }}</span>
            <Select v-model="manualState" :options="places" size="sm" class="w-56" />
            <Button
                size="sm"
                :text="__('workflows::messages.set')"
                :disabled="settingState || manualState === instance.current_state"
                @click="setState"
            />
        </div>
    </div>
</template>

<script setup>
import { ref, computed, getCurrentInstance, onUnmounted, watch } from 'vue';
import { Alert, Avatar, Badge, Button, Icon, Input, Modal, ModalTitle, Select, SplitterGroup, SplitterPanel, SplitterResizeHandle, Stack, Switch } from '@statamic/cms/ui';
import { Head } from '@statamic/cms/inertia';
import { BOOKMARK_SLASH } from '../support/icons.js';
import WorkflowProgress from '../components/WorkflowProgress.vue';
import VersionListing from '../components/VersionListing.vue';
import WorkflowHistory from '../components/WorkflowHistory.vue';
import ContentBlocks from '../components/ContentBlocks.vue';
import CommentsMargin from '../components/comments/CommentsMargin.vue';
import CommentsPopover from '../components/comments/CommentsPopover.vue';
import { useComments } from '../components/comments/useComments.js';
import { readTechnical, writeTechnical } from '../components/contentBlocks.js';
// Only the registration is still needed here: it seeds the module-level colour
// cache that WorkflowProgress reads through stateColor(). The page itself no
// longer resolves a colour or a label — the bar does.
import { humanize, registerStateColors, registerStateLabels } from '../support/states.js';
import { openPreviewWindow, previewPresentation } from '../support/preview.js';
import { useWatch } from '../support/watch.js';

const props = defineProps({
    instance: { type: Object, required: true },
    content: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    // Super admins + `config/workflows.php → history_groups`; false hides button and drawer.
    canViewHistory: { type: Boolean, default: false },
    history: { type: Array, default: () => [] },
    historyDiffUrlTemplate: { type: String, default: '' },
    comments: { type: Array, default: () => [] },
    commentUrl: { type: String, default: '' },
    // Separate named routes rather than `commentUrl` + a suffix: commentUrl ends in
    // `?workflow=<name>`, so appending would land the path inside the query string.
    commentReadUrl: { type: String, default: '' },
    // Whether the current user watches this version, and where to toggle it.
    watching: { type: Boolean, default: false },
    watchUrl: { type: String, default: '' },
    commentDeleteUrlTemplate: { type: String, default: '' },
    // PATCH {body, mentions}; resolve / reopen are POSTs on the thread's root.
    commentUpdateUrlTemplate: { type: String, default: '' },
    commentResolveUrlTemplate: { type: String, default: '' },
    commentReopenUrlTemplate: { type: String, default: '' },
    // The viewer (composer avatar, "your" mentions) and who can be @mentioned.
    currentUser: { type: Object, default: null },
    mentionableUsers: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    applyUrls: { type: Object, default: () => ({}) },
    assigneeUrl: { type: String, default: '' },
    assignableUsers: { type: Array, default: () => [] },
    canSetState: { type: Boolean, default: false },
    // Strict super admin. Gates the "technical details" switch over the content
    // (field handles, passage uuids) — defaults to false so a caller that
    // forgets the prop hides it rather than leaking it.
    isSuper: { type: Boolean, default: false },
    // Whether the assignee picker is offered — `metadata.assigner_groups`.
    canAssign: { type: Boolean, default: false },
    stateUrl: { type: String, default: '' },
    places: { type: Array, default: () => [] },
    // The same places as a path — ordered steps plus the branch the subject
    // took, if any. Filtered and anchored server-side; see Workflow\ProgressSteps.
    steps: { type: Object, default: () => ({ main: [], branch: null, current: null }) },
    // `horizontal` | `vertical` — the workflow's own choice, and the reason the bar
    // sits where it sits. See ProgressSteps::ORIENTATION.
    progressOrientation: { type: String, default: 'horizontal' },
    queueUrl: { type: String, default: '' },
    // Place handle => Badge color, from each place's `metadata.color`.
    stateColors: { type: Object, default: () => ({}) },
    stateLabels: { type: Object, default: () => ({}) },
    // Minutes to wait before asking the server whether anything changed — see
    // PlaceActions::REFRESH_INTERVAL. Null when the current place carries none,
    // which is the common case.
    refreshAfterMinutes: { type: Number, default: null },
    statusUrl: { type: String, default: '' },
});

// Super-admin "technical details" over the content (field handles, passage
// uuids). Remembered per browser; storage failures just mean "off".
const technical = ref(readTechnical());
watch(technical, (value) => writeTechnical(value));

const preview = computed(() => previewPresentation(props.instance.subject.preview_mode));

registerStateColors(props.stateColors);
registerStateLabels(props.stateLabels);

// Short date + time in the CP's language, for the "in this state since" line.
function formatSince(iso) {
    try {
        return new Date(iso).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) {
        return iso;
    }
}
// --- progress bar placement ---
// Where the bar goes follows its ORIENTATION, and only that: horizontal means
// above the content, vertical means a side column to its left. One value drives
// both, rather than two settings that could disagree.
//
// The value comes from the workflow (`metadata.progress_orientation`, set in the
// settings editor) because it depends on the SHAPE of the definition — a
// seven-step flow reads as a wide band above the content, a four-step one beside
// it. It used to be a constant in this file, which meant every workflow got the
// same answer and only a developer could change it.
//
// `lg:` and not `sm:` for the side column: below that width there is no room for
// two columns, so the vertical bar stacks above the content anyway — which is the
// horizontal case's layout, with the bar's own steps still running downwards.
//
// `lg:self-start` keeps the Card at its own height. A flex row stretches its items
// to the tallest one by default, so the bar grew to the full height of the content
// beside it and stood in a mostly empty panel. No `!` needed here, unlike below:
// nothing else on this element sets `align-self`, and the stretch came from the
// container's default rather than from a class that could lose a cascade fight.
//
// `lg:flex-row!` — the `!` is load-bearing, not defensive. Statamic's own
// stylesheet loads AFTER the addon's and defines `.flex-col` at the same
// specificity, so without the important flag the column direction wins and the bar
// renders ABOVE the content instead of beside it, at the right width, which looks
// like a layout bug and is really a cascade one. (`lg:w-64` needs no flag: nothing
// in the CP stylesheet competes for the width of this element.) Same workaround the
// dark variants in this addon carry, for the same reason.
const progressLayout = computed(() =>
    props.progressOrientation === 'vertical'
        ? { wrapper: 'mb-8 flex flex-col gap-6 lg:flex-row!', bar: 'lg:w-64 lg:shrink-0 lg:self-start', content: 'min-w-0 flex-1' }
        : { wrapper: 'mb-8 space-y-6', bar: '', content: '' },
);

// Shared utility-class strings reused across the template.
const row = 'border-b border-slate-100 py-2.5 text-sm';

const pending = ref(null);
// Assignee-picker state used when a transition declares a manual `user_assignment`.
// `selected` is a list — the assignee picker is multi-select.
const picker = ref({ action: null, selected: [], notify: true, saving: false });
// Target-site picker for transitions declaring `needs_site_pick` (typical:
// start_translation). Single-select, distinct state from the assignee picker
// so the two flows never step on each other.
const sitePicker = ref({ action: null, selected: null, source: null, saving: false });
const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

const { watching: isWatching, busy: watchBusy, toggle: toggleWatch } = useWatch(props.watching, props.watchUrl, axios);

async function fetchJson(url) {
    if (axios) return (await axios.get(url)).data;
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok) throw new Error('request failed');
    return res.json();
}

// --- refresh reminder (PlaceActions::REFRESH_INTERVAL) ---
// The baseline is whatever the page loaded with — comparing against it, not
// against the previous poll, is what lets an unrelated no-op poll leave the
// timer running silently instead of drifting the comparison forward.
const refreshBaseline = { state: props.instance.current_state, updatedAt: props.instance.updated_at };
const showRefreshModal = ref(false);
let refreshTimer = null;

function scheduleRefreshCheck() {
    if (!props.refreshAfterMinutes || !props.statusUrl) return;
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(checkForUpdates, props.refreshAfterMinutes * 60_000);
}

async function checkForUpdates() {
    try {
        const data = await fetchJson(props.statusUrl);
        if (data.current_state !== refreshBaseline.state || data.updated_at !== refreshBaseline.updatedAt) {
            showRefreshModal.value = true;

            return;
        }
    } catch (e) {
        // A failed check is not worth bothering the user over — just try again
        // on the next tick.
    }
    scheduleRefreshCheck();
}

function confirmRefresh() {
    window.location.reload();
}

// "Later" does not mean "never" — the reminder is only useful if it keeps
// coming back, same as the SLA ageing it borrows the idea from.
function dismissRefresh() {
    showRefreshModal.value = false;
    scheduleRefreshCheck();
}

scheduleRefreshCheck();
onUnmounted(() => clearTimeout(refreshTimer));

// Super-only manual state override.
const manualState = ref(props.instance.current_state);
const settingState = ref(false);

async function setState() {
    if (settingState.value || manualState.value === props.instance.current_state) return;
    settingState.value = true;

    try {
        if (axios) {
            await axios.post(props.stateUrl, { state: manualState.value });
        } else {
            const res = await fetch(props.stateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ state: manualState.value }),
            });
            if (!res.ok) throw new Error('request failed');
        }

        window.Statamic?.$toast?.success(__('workflows::messages.state_set'));
        window.location.reload();
    } catch (e) {
        settingState.value = false;
        window.Statamic?.$toast?.error(__('workflows::messages.state_set_error'));
    }
}

// Assignees: local copies so the header updates without a full reload.
const assigneeNames = ref([...(props.instance.assignees ?? [])]);
const assigneeIds = ref([...(props.instance.assignee_ids ?? [])]);
const assigneeDrawer = ref({ open: false, selected: [], saving: false });

// Header shows a bounded, overlapping avatar stack (+N overflow) so the layout
// stays put no matter how many people are assigned; full list is on hover.
const MAX_AVATARS = 4;
const visibleAssignees = computed(() => assigneeNames.value.slice(0, MAX_AVATARS));
const overflowAssignees = computed(() => Math.max(0, assigneeNames.value.length - MAX_AVATARS));

// One entry per site-version — the section shows when there's more than one
// version to compare.
const totalVersions = computed(() => props.versions.length);

// Local, mutable copy of the versions payload so we can clear the "NEW"
// amber badge on the current version as soon as its comments are marked read
// (when the redakteur opens a passage's thread in the comment sidebar).
// JSON round-trip unwraps any Vue reactive proxies safely — plain payload only.
const localVersions = ref(JSON.parse(JSON.stringify(props.versions ?? [])));

function clearCurrentVersionNewFlag() {
    for (const version of localVersions.value) {
        if (version.current) version.has_new_comments = false;
    }
}

// Drawer search + group filter (client-side over the loaded user list).
const MAX_GROUP_BADGES = 2;
const assigneeSearch = ref('');
const assigneeGroup = ref(null);

const groupOptions = computed(() => {
    const groups = new Set();
    props.assignableUsers.forEach((u) => (u.groups ?? []).forEach((g) => groups.add(g)));
    return [...groups].sort((a, b) => a.localeCompare(b)).map((g) => ({ value: g, label: g }));
});

const filteredAssignableUsers = computed(() => {
    const term = assigneeSearch.value.trim().toLowerCase();
    return props.assignableUsers.filter((u) => {
        const matchesName = !term || (u.name ?? '').toLowerCase().includes(term);
        const matchesGroup = !assigneeGroup.value || (u.groups ?? []).includes(assigneeGroup.value);
        return matchesName && matchesGroup;
    });
});

function openAssignees() {
    assigneeSearch.value = '';
    assigneeGroup.value = null;
    assigneeDrawer.value = { open: true, selected: [...assigneeIds.value], saving: false };
}
function closeAssignees() {
    assigneeDrawer.value.open = false;
}

async function saveAssignees() {
    if (assigneeDrawer.value.saving) return;
    assigneeDrawer.value.saving = true;

    const payload = { assignees: [...assigneeDrawer.value.selected] };

    try {
        const response = axios
            ? (await axios.post(props.assigneeUrl, payload)).data
            : await (
                  await fetch(props.assigneeUrl, {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                      body: JSON.stringify(payload),
                  })
              ).json();

        assigneeIds.value = response.data?.assignee_ids ?? payload.assignees;
        assigneeNames.value = response.data?.assignees ?? assigneeNames.value;
        assigneeDrawer.value.open = false;
        window.Statamic?.$toast?.success(__('workflows::messages.assignees_saved'));
    } catch (e) {
        window.Statamic?.$toast?.error(__('workflows::messages.assignees_save_error'));
    } finally {
        assigneeDrawer.value.saving = false;
    }
}

// History drawer state. Opened from the sticky bar; the log lives out of the way
// until someone asks for it.
const historyDrawer = ref({ open: false });

function openHistory() {
    historyDrawer.value.open = true;
}

function closeHistory() {
    historyDrawer.value.open = false;
}

// Comments: the Word-style sidebar (components/comments). Opening a passage's
// thread marks it read; once nothing unread is left, the current version's
// "NEW" badge in the version listing goes too.
const commentSidebar = useComments({
    comments: props.comments,
    blocks: () => props.content,
    urls: {
        create: props.commentUrl,
        read: props.commentReadUrl,
        deleteTemplate: props.commentDeleteUrlTemplate,
        updateTemplate: props.commentUpdateUrlTemplate,
        resolveTemplate: props.commentResolveUrlTemplate,
        reopenTemplate: props.commentReopenUrlTemplate,
    },
    currentUser: props.currentUser,
    mentionableUsers: props.mentionableUsers,
    axios,
    onAllRead: clearCurrentVersionNewFlag,
});

// Timeline handoff: a click on a comment_created event in the History list opens
// the sidebar at that very comment (or its passage, if the comment is gone).
// Hand over rather than stack: two overlays for one intent would be one too many.
function openCommentFromHistory({ passageRef, commentId }) {
    closeHistory();
    commentSidebar.openAtComment(commentId, passageRef ?? null);
}

// Called when a transition button is clicked. Transitions marked with
// `needs_site_pick` (e.g. start_translation) open a target-language modal;
// those with a manual `user_assignment` open the assignee picker; everything else
// applies immediately.
function apply(action) {
    if (!action.can_apply) return;
    if (action.needs_site_pick) {
        // `source` starts on the server's preselection; it only becomes a choice
        // where the workflow allows one.
        sitePicker.value = { action, selected: null, source: action.source_site?.value ?? null, saving: false };
        return;
    }
    if (action.needs_pick) {
        picker.value = { action, selected: [], notify: true, saving: false };
        return;
    }
    performApply(action, {});
}

function cancelPicker() {
    if (picker.value.saving) return;
    picker.value = { action: null, selected: [], notify: true, saving: false };
}

async function confirmPicker() {
    if (!picker.value.selected.length) return;
    picker.value.saving = true;
    await performApply(picker.value.action, { assignees: picker.value.selected, notify: picker.value.notify });
    // performApply reloads on success — no need to reset picker here.
}

function cancelSitePicker() {
    if (sitePicker.value.saving) return;
    sitePicker.value = { action: null, selected: null, source: null, saving: false };
}

// The request behind this does the whole machine translation before it creates
// anything, so a failure means there is no entry to clean up — see
// WorkflowController::applyTransition().
async function confirmSitePicker() {
    if (!sitePicker.value.selected) return;
    sitePicker.value.saving = true;
    // `source_site` only where the workflow allows a per-run pick — the server
    // ignores it otherwise.
    await performApply(sitePicker.value.action, {
        target_site: sitePicker.value.selected,
        ...(sitePicker.value.action.needs_source_pick ? { source_site: sitePicker.value.source } : {}),
    });
}

async function performApply(action, payload) {
    const url = props.applyUrls[action.transition.name];
    pending.value = action.transition.name;

    try {
        const response = axios
            ? (await axios.post(url, payload)).data
            : await (await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload),
            })).json();

        const note = response?.demo ? ` ${__('workflows::messages.demo_note')}` : '';
        // A submitted plain-language rewrite finishes minutes later, so the version
        // still carries the source text when the page reloads. Carried in the same
        // toast — the CP's toast API only has success/error.
        const pendingNote = response?.data?.pending_jobs
            ? ` ${__('workflows::messages.translation_plain_submitted')}`
            : '';
        window.Statamic?.$toast?.success(`${__('workflows::messages.applied', { action: action.transition.label })}${note}${pendingNote}`);

        // Transitions that create a new subject (e.g. start_translation
        // localizing into a target site) return an entry-specific `url` in
        // the response — jump the user straight to the new copy so they can
        // start working on it. Real (non-demo) transitions otherwise reload
        // so the page reflects the new state from the store.
        const nextUrl = response?.data?.url;
        if (nextUrl) {
            window.location.href = nextUrl;
        } else if (!response?.demo) {
            window.location.reload();
        }
    } catch (e) {
        const message = e?.response?.data?.message || __('workflows::messages.transition_error');
        window.Statamic?.$toast?.error(message);
    } finally {
        pending.value = null;
        picker.value.saving = false;
        sitePicker.value.saving = false;
    }
}

// Opening the page deliberately does NOT mark anything read. It used to: every
// passage carrying a new-for-us comment was marked on mount, which meant the whole
// point of the markers was lost — they vanished in the same instant the page
// appeared, so you could never see WHICH set somebody had commented on. Reading is
// opening that passage's thread in the sidebar (useComments → markRead), and only
// that clears it.
</script>
