<template>
    <div class="max-w-[1600px] p-6">
        <Head :title="instance.subject.title" />

        <!-- Sticky action bar, identical in build to the workflow summary's — the two
             pages are the same product and their heads are supposed to match. The
             title block below scrolls away; it is orientation, not action. -->
        <div class="wf-page-head">
            <Button :href="queueUrl" icon="arrow-left" variant="ghost" size="sm" :text="__('workflows::messages.back')" />

            <!-- `gap-6`, not `space-x-6` — see the note in WorkflowInstanceDetail. -->
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
                <!-- Transfer acts on this same entry (localize it into further sites),
                     so it belongs with the other actions rather than beside the title. -->
                <Button
                    v-if="canTransfer && transferSites.length"
                    icon="earth"
                    variant="default"
                    size="sm"
                    :text="__('workflows::messages.transfer')"
                    @click="openTransfer"
                />
            </div>
        </div>

        <header class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <!-- Title, site, the path, then the two things you do with the
                     entry itself — same build as the summary page, see the notes
                     there for why the state is a bar and not a badge. -->
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
                         glance; `bookmark`, not `eye`, because the eye is the preview. -->
                    <Button
                        v-if="watchUrl"
                        icon="bookmark"
                        :variant="isWatching ? 'default' : 'ghost'"
                        size="sm"
                        :text="isWatching ? __('workflows::messages.watching') : __('workflows::messages.watch')"
                        :title="isWatching ? __('workflows::messages.unwatch') : null"
                        :disabled="watchBusy"
                        @click="toggleWatch"
                    />
                    <!-- Review info after the content actions: the QA report concerns this
                         version, the history is the audit trail and comes last. The report
                         stands out (dot, filled look) only in the completed place, where
                         the review starts — see qsNotesHighlight() on the server. -->
                    <div v-if="qsNotes" class="relative">
                        <Button
                            icon="clipboard-check"
                            :variant="qsNotesHighlight ? 'default' : 'ghost'"
                            size="sm"
                            :text="__('workflows::messages.qs_notes')"
                            :title="qsNotesHighlight ? __('workflows::messages.qs_notes_highlight_title') : null"
                            @click="openQsNotes"
                        />
                        <span
                            v-if="qsNotesHighlight"
                            class="pointer-events-none absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-blue-500 ring-2 ring-white dark:ring-gray-900"
                            aria-hidden="true"
                        />
                    </div>
                    <!-- All comments of the page (incl. passage-less ones such as QA
                         notes) in the Word-style sidebar; count = open threads. -->
                    <Button
                        icon="mail-chat-bubble-text"
                        variant="ghost"
                        size="sm"
                        :text="commentButtonText"
                        @click="commentSidebar.openSidebar()"
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
            <div class="flex shrink-0 items-center gap-2">
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('workflows::messages.assigned_to') }}</span>
                <div v-if="assigneeNames.length" class="flex items-center -space-x-2" :title="assigneeNames.join(', ')">
                    <Avatar v-for="(name, i) in visibleAssignees" :key="i" :user="{ name }" class="ring-2 ring-white dark:ring-gray-900!" />
                    <div
                        v-if="overflowAssignees"
                        class="flex size-7 items-center justify-center rounded-xl bg-gray-200 text-2xs font-medium text-gray-600 ring-2 ring-white dark:bg-gray-700! dark:text-gray-200! dark:ring-gray-900!"
                    >+{{ overflowAssignees }}</div>
                </div>
                <span v-else class="text-sm italic text-gray-400 dark:text-gray-500">{{ __('workflows::messages.no_assignees') }}</span>
                <!-- Only for groups the workflow lets assign — see the note in
                     WorkflowInstanceDetail. -->
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
        </header>

        <!-- Der Fehlschlag steht über allem anderen: wer hier landet, muss zuerst
             wissen, dass der Inhalt darunter NICHT die Übersetzung ist, sondern die
             unveränderte Quelle. Der Weg zurück ist eine Transition aus diesem Place,
             üblicherweise „Einfache Sprache neu erzeugen". -->
        <Alert
            v-if="plainLanguageFailure"
            variant="error"
            :heading="__('workflows::messages.translation_plain_failed_toast', { title: instance.subject?.title ?? '' })"
            class="mb-6"
        >
            <p class="whitespace-pre-line text-sm">{{ plainLanguageFailure.note }}</p>
        </Alert>

        <!-- Same build as the summary page: the bar above the content when it runs
             horizontally, in a side column to its left when it runs vertically.
             Placed AFTER the alerts on purpose — a failed rewrite has to be the
             first thing read, and a progress bar above it would push it down. -->
        <div :class="progressLayout.wrapper">
            <WorkflowProgress :steps="steps" :orientation="progressOrientation" :class="progressLayout.bar" />

            <!-- Site-by-site: source (origin) | translation (this version). -->
            <section :class="progressLayout.content">
                <!-- Fertig laut Workflow, inhaltlich überholt: die Quelle wurde nach
                     der Freigabe dieser Fassung überarbeitet. Steht bewusst UNTER der
                     Leiste und nicht bei den Alerts oben — der Schritt der Fassung ist
                     korrekt, die Meldung widerspricht der Leiste also nicht, sie
                     ergänzt sie. Dieselbe Bedingung wie das „veraltet"-Zeichen in den
                     Listen (WorkflowResolver::contentStale()), damit beide nie
                     Verschiedenes behaupten. -->
                <Alert
                    v-if="sourceChanged"
                    variant="warning"
                    :heading="__('workflows::messages.source_changed')"
                    class="mb-6"
                >
                    <p class="text-sm">{{ sourceChangedText }}</p>
                </Alert>

                <div class="grid gap-4" :class="showSource ? 'lg:grid-cols-2' : ''">
                    <div v-if="showSource">
                        <h2 class="mb-2.5 text-base font-semibold text-gray-700 dark:text-gray-200">
                            {{ __('workflows::messages.source') }}
                            <span class="ml-1 text-2xs font-medium uppercase text-gray-400">{{ sourceName }} · {{ sourceLang }}</span>
                        </h2>
                        <ContentBlocks :blocks="source" :anchors="false" :technical="isSuper && technical" key-prefix="s-">
                            <template #empty><p class="text-sm text-gray-400">—</p></template>
                        </ContentBlocks>
                    </div>

                    <div>
                        <!-- „Übersetzung" only when there is something it was translated
                             from. The origin of a post is not a translation of anything —
                             calling it one was the other half of the inverted-provenance
                             bug fixed in buildInstancePayload(). -->
                        <h2 class="mb-2.5 flex items-center gap-2 text-base font-semibold text-gray-700 dark:text-gray-200">
                            <span>
                                {{ isSource ? __('workflows::messages.source') : __('workflows::messages.translation_target') }}
                                <span class="ml-1 text-2xs font-medium uppercase text-gray-400">{{ instance.subject.site_name }} · {{ instance.subject.lang }}</span>
                            </span>
                            <!-- Only under a `collapsed` rule (SourceViewRules): the page opened
                                 with one column, and this is how the reader gets the other one
                                 back. Session-only on purpose — the rule says how the page
                                 STARTS, what happens after is the reader's. Under `hidden` there
                                 is no source to reveal, so no button either. -->
                            <!-- Super admins only: field handles + passage uuids in both
                                 columns, remembered per browser. Off by default. -->
                            <label v-if="isSuper" class="ml-auto flex cursor-pointer items-center gap-1.5 text-xs font-normal text-gray-500 dark:text-gray-400">
                                <Switch size="xs" v-model="technical" />
                                {{ __('workflows::messages.content_technical_details') }}
                            </label>
                            <Button
                                v-if="sourceView === 'collapsed' && sourceAvailable"
                                :icon="sourceOpen ? 'eye-slash' : 'eye'"
                                variant="ghost"
                                size="xs"
                                :class="{ 'ml-auto': !isSuper }"
                                :text="sourceOpen ? __('workflows::messages.source_hide') : __('workflows::messages.source_show')"
                                @click="sourceOpen = !sourceOpen"
                            />
                        </h2>

                        <!-- Content is either stale (the just-copied source) or a
                             superseded rewrite while a job is still open — showing it
                             as if it were current would be showing the wrong answer as
                             if it were the right one. Replaces the block list entirely
                             rather than overlaying it, so nothing half-updates while
                             the poll command is mid-write. -->
                        <div
                            v-if="pendingRewrite"
                            class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-gray-200 py-16 text-center dark:border-gray-700!"
                        >
                            <Icon name="loading" class="size-6 animate-spin text-gray-400 dark:text-gray-500!" />
                            <p class="text-sm text-gray-500 dark:text-gray-400!">{{ __('workflows::messages.pending_rewrite_spinner') }}</p>
                            <!-- Auch beim ersten Anlauf. Er ist der längste und häufigste,
                                 und ohne die Zeile steht der Redakteur minutenlang vor einem
                                 Spinner, der nichts über den Fortschritt sagt. -->
                            <p
                                v-if="attempt"
                                class="text-xs text-gray-400 dark:text-gray-500!"
                            >
                                {{ __('workflows::messages.pending_rewrite_attempt', { attempt: attempt.submission, max: attempt.max }) }}
                            </p>
                        </div>

                        <ContentBlocks
                            v-else
                            :blocks="content"
                            commentable
                            :technical="isSuper && technical"
                            :comment-count="commentSidebar.commentCount"
                            :has-new="commentSidebar.hasNew"
                            :active-uuid="commentSidebar.activeUuid.value"
                            @comment="commentSidebar.openForPassage"
                        >
                            <template #empty><p class="text-sm italic text-gray-400">—</p></template>
                        </ContentBlocks>
                    </div>
                </div>
            </section>
        </div>

        <!-- QA report of the plain-language service, opened from the header button:
             what was simplified, added and — the part that decides whether the
             version may go out — left out. The comment thread keeps it as well. -->
        <Stack
            v-if="qsNotes"
            :open="qsDrawer.open"
            @update:open="(v) => v || closeQsNotes()"
            :title="__('workflows::messages.qs_notes')"
            icon="clipboard-check"
            size="half"
        >
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('workflows::messages.qs_notes_intro') }}</p>
            <p class="whitespace-pre-line text-sm text-gray-800 dark:text-gray-200">{{ qsNotes }}</p>
        </Stack>

        <!-- History drawer, opened from the sticky bar — same as on the summary page. -->
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
                @open-comment="openCommentFromHistory"
            />
        </Stack>

        <!-- The transitions moved into the sticky bar. What stays here is the manual
             state override: an admin correction tool, not an everyday step, and next
             to the transitions it would be easy to mistake for one. Last thing on the
             page, exactly as on the workflow summary. -->
        <div v-if="canSetState" class="mt-8 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-800!">
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('workflows::messages.set_state') }}</span>
            <Select v-model="manualState" :options="places" size="sm" class="w-56" />
            <Button size="sm" :text="__('workflows::messages.set')" :disabled="settingState || manualState === instance.current_state" @click="setState" />
        </div>

        <!-- Word-style comment sidebar, shared with WorkflowInstanceDetail. Only the
             translation column is commentable; the source column has no comments. -->
        <CommentsSidebar :comments="commentSidebar" />

        <!-- Assignee editor drawer. -->
        <Stack :open="assigneeDrawer.open" @update:open="(v) => v || closeAssignees()" :title="__('workflows::messages.edit_assignees')" icon="users" size="narrow" inset>
            <div class="sticky top-0 z-10 mb-1 flex flex-col gap-2 border-b border-gray-200 bg-white px-6 py-4 dark:border-gray-700! dark:bg-gray-900!">
                <Input v-model="assigneeSearch" icon="magnifying-glass" clearable :placeholder="__('workflows::messages.search_users')" />
            </div>
            <ul v-if="filteredAssignableUsers.length" class="divide-y divide-gray-100 px-6 pb-4 dark:divide-gray-800">
                <li v-for="user in filteredAssignableUsers" :key="user.id">
                    <label class="flex cursor-pointer items-center gap-3 py-2.5 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" :value="user.id" v-model="assigneeDrawer.selected" class="size-4 rounded border-gray-300 text-blue-600" />
                        <Avatar :user="{ name: user.name }" class="size-6" />
                        <Badge
                            v-if="user.site_allocation"
                            :text="user.site_allocation"
                            color="blue"
                            size="sm"
                        />
                        <span class="min-w-0 flex-1 truncate">{{ user.name }}</span>
                    </label>
                </li>
            </ul>
            <p v-else class="px-6 py-6 text-center text-sm text-gray-400 dark:text-gray-500">{{ __('workflows::messages.no_users_found') }}</p>
            <template #footer-end>
                <button type="button" class="cursor-pointer rounded-md border border-blue-600 bg-blue-600 px-3 py-1 text-sm text-white disabled:cursor-not-allowed disabled:bg-gray-200" :disabled="assigneeDrawer.saving" @click="saveAssignees">
                    {{ __('workflows::messages.save') }}
                </button>
            </template>
        </Stack>

        <!-- Transfer (localize into another site) modal. -->
        <Modal v-if="canTransfer" :open="transferModal.open" @update:open="(v) => v || closeTransfer()">
            <ModalTitle class="wf-modal-head">
                <Icon name="earth" class="size-4" />
                <span class="text-lg font-medium text-gray-900 dark:text-white">{{ __('workflows::messages.transfer_title') }}</span>
            </ModalTitle>
            <div class="space-y-3">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('workflows::messages.site') }}</label>
                <Select v-model="transferModal.sites" :options="transferSites" multiple close-on-select clearable :placeholder="__('workflows::messages.select_site')" />
            </div>
            <template #footer>
                <div class="flex justify-end gap-2 px-2 pt-3">
                    <Button variant="ghost" :text="__('workflows::messages.cancel')" @click="closeTransfer" />
                    <Button variant="primary" :text="__('workflows::messages.transfer')" :disabled="!transferModal.sites.length || transferModal.saving" @click="submitTransfer" />
                </div>
            </template>
        </Modal>

        <!-- Assignee picker for a MANUAL `user_assignment` action. Multi-select. Same markup
             as the picker in WorkflowInstanceDetail and the entry-editor
             fieldtype — a translation step is handed over exactly like any other.

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
            <!-- Plain ModalTitle, no `.wf-modal-head`. Statamic's modal content
                 is `space-y-3 … overflow-auto max-h-[60vh] … p-4`, and that class
                 pins the heading with negative 1rem margins and an opaque
                 background — it works against the container's own spacing and
                 clipped the line below it. The heading scrolling with the content
                 is the lesser problem, and it is what the component ships with. -->
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
                            v-if="user.notified && picker.notify"
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

        <!-- Target-site picker for the derived site dialog (start_translation).
             Splitter layout: left panel shows the source site as context, right
             panel is a single-select radio list of the still-missing locales. On
             confirm the backend creates the localization and applies the
             transition to that new copy. -->
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
                        <!-- Read-only by default: the source is a workflow setting,
                             and stating it here is context, not a question. A project
                             that switched `translation_source_pick` on gets the same
                             spot as a radio list of the versions this post actually
                             has. -->
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
                <div class="flex items-center justify-between gap-2 px-2 pt-3">
                    <!-- The confirm request translates the whole entry before it
                         creates anything, so it takes seconds rather than being
                         instant. Saying so beats a button that just sits there. -->
                    <p v-if="sitePicker.saving" class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('workflows::messages.translation_running') }}
                    </p>
                    <div class="ml-auto flex gap-2">
                        <Button variant="ghost" :text="__('workflows::messages.cancel')" @click="cancelSitePicker" />
                        <Button
                            variant="primary"
                            :text="__('workflows::messages.transition_site_pick_confirm')"
                            :loading="sitePicker.saving"
                            :disabled="!sitePicker.selected || sitePicker.saving"
                            @click="confirmSitePicker"
                        />
                    </div>
                </div>
            </template>
        </Modal>

    </div>
</template>

<script setup>
import { ref, computed, getCurrentInstance, onUnmounted, watch } from 'vue';
import { Alert, Avatar, Badge, Button, Icon, Input, Modal, ModalTitle, Select, SplitterGroup, SplitterPanel, SplitterResizeHandle, Stack, Switch } from '@statamic/cms/ui';
import { Head } from '@statamic/cms/inertia';
import WorkflowProgress from '../components/WorkflowProgress.vue';
import WorkflowHistory from '../components/WorkflowHistory.vue';
import ContentBlocks from '../components/ContentBlocks.vue';
import CommentsSidebar from '../components/comments/CommentsSidebar.vue';
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
    // Null unless this version is behind the post — see
    // WorkflowController::sourceChangedNotice().
    sourceChanged: { type: Object, default: null },
    content: { type: Array, default: () => [] },
    source: { type: Array, default: () => [] },
    sourceName: { type: String, default: '' },
    sourceLang: { type: String, default: '' },
    // Kept in the prop contract for backward compat; no longer read since the
    // page hard-reloads on the refresh tick and the server-rendered 404 handles
    // "version is gone" on its own.
    sourceUrl: { type: String, default: '' },
    // True when this version has no source to compare against: it IS the resolved
    // source, or it is the post's origin (nothing precedes an original).
    isSource: { type: Boolean, default: false },
    // How the source column is shown to THIS reader — `side_by_side`,
    // `collapsed` or `hidden`, from the workflow's audience rules
    // (metadata.translation_source_view, see SourceViewRules). Under `hidden`
    // the server already sends `source` empty; the value is still passed so the
    // page can tell "no source" from "not for you" if it ever wants to.
    sourceView: { type: String, default: 'side_by_side' },
    // True while THIS version has an open plain-language rewrite job — only ever
    // true when the current place opted in via PlaceActions::PENDING_REWRITE_SPINNER.
    // The content panel shows a spinner instead of `content` while this holds,
    // because those blocks are either the just-copied source or a superseded
    // rewrite, never the answer that is still on its way.
    pendingRewrite: { type: Boolean, default: false },
    // Welcher der konfigurierten Versuche gerade läuft: { submission, max }. Der Poll
    // unten hält das aktuell, damit ein zweiter Anlauf sichtbar ist, ohne die Seite neu
    // zu laden — bei fünf Abfragen pro Versuch dauert ein Durchgang sonst minutenlang
    // ohne jedes Lebenszeichen. Null, solange nichts läuft.
    plainLanguageAttempt: { type: Object, default: null },
    // Der QS-Bericht des Dienstes, bereits ohne seine Überschrift — die trägt der
    // Stack selbst. Gefüllt, sobald es einen Bericht gibt, sonst null.
    qsNotes: { type: String, default: null },
    // True im Abschluss-Place: dort hebt sich der QS-Button hervor.
    qsNotesHighlight: { type: Boolean, default: false },
    // Die Begründung des letzten Fehlschlags, aus dem jüngsten
    // plain_language_failed-Event. Der Server liefert sie nur, solange die Fassung
    // im Fehler-Place liegt — siehe plainLanguageFailureFor().
    plainLanguageFailure: { type: Object, default: null },
    // buildInstancePayload() has always sent these; this page just never
    // rendered them, so translation entries had no visible history.
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
    // (field handles, passage uuids) — false hides it.
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
    canTransfer: { type: Boolean, default: false },
    transferUrl: { type: String, default: '' },
    transferSites: { type: Array, default: () => [] },
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

// Zwei Formulierungen, weil „unverändert seit X" für eine Fassung, die noch nie
// freigegeben wurde, eine falsche Aussage wäre — die hat kein eigenes Datum. Der
// Server schickt `version_at: null` genau für diesen Fall.
const sourceChangedText = computed(() => {
    const n = props.sourceChanged;
    if (! n) return '';

    return n.version_at
        ? __('workflows::messages.source_changed_hint', { source_at: n.source_at, version_at: n.version_at })
        : __('workflows::messages.source_changed_hint_never', { source_at: n.source_at });
});
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

const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

const { watching: isWatching, busy: watchBusy, toggle: toggleWatch } = useWatch(props.watching, props.watchUrl, axios);
const pending = ref(null);

// `isSource` is the server's answer to "does this version have something to the
// left of it": false for a real translation, true for the resolved source and for
// the post's origin, which nothing precedes.
const sourceAvailable = computed(() => !props.isSource && props.source.length > 0);

// Whether the reader has the column open right now. Starts closed only under a
// `collapsed` rule; the toggle in the translation column's headline flips it.
const sourceOpen = ref(props.sourceView !== 'collapsed');

// Three reasons for a single column, in the order they are decided: nothing to
// the left of this version; a `hidden` rule for this reader (the server then sends
// no source at all, so the first check already fails — the second is the honest
// name for it); the reader has it collapsed.
const showSource = computed(() => sourceAvailable.value && props.sourceView !== 'hidden' && sourceOpen.value);

// --- refresh interval (PlaceActions::REFRESH_INTERVAL) ---
// Harter Reload nach dem konfigurierten Intervall, ohne Diff-Check und ohne
// Bestätigung: der Zweck der Action ist, dass eine Seite die einen langsamen
// Job beobachtet sich von selbst aktualisiert. Ein Diff-Check verhinderte
// gerade das häufigste sinnvolle Update (Submission-Zähler wandert, State
// bleibt gleich), und das Modal machte aus "automatisch" wieder "auf Klick".
const attempt = ref(props.plainLanguageAttempt);
let refreshTimer = null;

if (props.refreshAfterMinutes) {
    refreshTimer = setTimeout(() => window.location.reload(), props.refreshAfterMinutes * 60_000);
}
onUnmounted(() => clearTimeout(refreshTimer));

// --- manual state (super) ---
const manualState = ref(props.instance.current_state);
const settingState = ref(false);
async function setState() {
    if (settingState.value || manualState.value === props.instance.current_state) return;
    settingState.value = true;
    try {
        await post(props.stateUrl, { state: manualState.value });
        window.Statamic?.$toast?.success(__('workflows::messages.state_set'));
        window.location.reload();
    } catch (e) {
        settingState.value = false;
        window.Statamic?.$toast?.error(__('workflows::messages.state_set_error'));
    }
}

// --- assignees ---
const assigneeNames = ref([...(props.instance.assignees ?? [])]);
const assigneeIds = ref([...(props.instance.assignee_ids ?? [])]);
const assigneeDrawer = ref({ open: false, selected: [], saving: false });
const MAX_AVATARS = 4;
const visibleAssignees = computed(() => assigneeNames.value.slice(0, MAX_AVATARS));
const overflowAssignees = computed(() => Math.max(0, assigneeNames.value.length - MAX_AVATARS));
const assigneeSearch = ref('');
const filteredAssignableUsers = computed(() => {
    const term = assigneeSearch.value.trim().toLowerCase();
    return props.assignableUsers.filter((u) => !term || (u.name ?? '').toLowerCase().includes(term));
});
function openAssignees() {
    assigneeSearch.value = '';
    assigneeDrawer.value = { open: true, selected: [...assigneeIds.value], saving: false };
}
function closeAssignees() {
    assigneeDrawer.value.open = false;
}
async function saveAssignees() {
    if (assigneeDrawer.value.saving) return;
    assigneeDrawer.value.saving = true;
    try {
        const data = (await post(props.assigneeUrl, { assignees: [...assigneeDrawer.value.selected] }))?.data;
        assigneeIds.value = data?.assignee_ids ?? [...assigneeDrawer.value.selected];
        assigneeNames.value = data?.assignees ?? assigneeNames.value;
        assigneeDrawer.value.open = false;
        window.Statamic?.$toast?.success(__('workflows::messages.assignees_saved'));
    } catch (e) {
        window.Statamic?.$toast?.error(__('workflows::messages.assignees_save_error'));
    } finally {
        assigneeDrawer.value.saving = false;
    }
}

// --- transfer ---
const transferModal = ref({ open: false, sites: [], saving: false });
function openTransfer() {
    transferModal.value = { open: true, sites: [], saving: false };
}
function closeTransfer() {
    transferModal.value.open = false;
}
async function submitTransfer() {
    if (!transferModal.value.sites.length || transferModal.value.saving) return;
    transferModal.value.saving = true;
    try {
        const data = (await post(props.transferUrl, { sites: [...transferModal.value.sites] }))?.data;
        window.Statamic?.$toast?.success(__('workflows::messages.transferred'));
        if (data?.url) window.location.href = data.url;
        else window.location.reload();
    } catch (e) {
        transferModal.value.saving = false;
        window.Statamic?.$toast?.error(__('workflows::messages.transfer_error'));
    }
}

// --- QA notes drawer ---
const qsDrawer = ref({ open: false });
function openQsNotes() { qsDrawer.value.open = true; }
function closeQsNotes() { qsDrawer.value.open = false; }

// --- history drawer ---
// Opened from the sticky bar; the log stays out of the way until asked for.
const historyDrawer = ref({ open: false });
function openHistory() { historyDrawer.value.open = true; }
function closeHistory() { historyDrawer.value.open = false; }

// --- comments ---
// The Word-style sidebar (components/comments), same as on the summary page.
// This page has no version listing, so there is no "NEW" badge to clear.
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
});

const commentButtonText = computed(() => {
    const open = commentSidebar.counts.value.open;

    return open ? `${__('workflows::messages.comments')} (${open})` : __('workflows::messages.comments');
});

// Timeline handoff — see WorkflowInstanceDetail::openCommentFromHistory().
function openCommentFromHistory({ passageRef, commentId }) {
    closeHistory();
    commentSidebar.openAtComment(commentId, passageRef ?? null);
}

// --- actions ---
// Transitions with `needs_site_pick` (typical: start_translation) open a
// modal asking for the target language; the picked site handle is posted
// as `target_site`, the backend creates the localization and applies the
// transition to that new copy. Response's `data.url` sends the user to
// the newly created localization.
const sitePicker = ref({ action: null, selected: null, source: null, saving: false });

// Assignee picker for a MANUAL `user_assignment` action. `selected` is a list — the picker
// is multi-select. This page used to handle only the site pick and sent every
// other action straight to the server, so an assignment came back as a 422
// ("please pick a person") with no modal ever opening.
const picker = ref({ action: null, selected: [], notify: true, saving: false });

async function apply(action) {
    if (!action.can_apply) return;
    if (action.needs_site_pick) {
        // `source` starts on the server's preselection — the configured source, or
        // the main site where the post has no version there. Only ever offered as a
        // choice when the workflow allows it.
        sitePicker.value = { action, selected: null, source: action.source_site?.value ?? null, saving: false };
        return;
    }
    if (action.needs_pick) {
        picker.value = { action, selected: [], notify: true, saving: false };
        return;
    }
    await runAction(action, {});
}

function cancelPicker() {
    if (picker.value.saving) return;
    picker.value = { action: null, selected: [], notify: true, saving: false };
}

async function confirmPicker() {
    if (!picker.value.selected.length) return;
    picker.value.saving = true;
    await runAction(picker.value.action, { assignees: picker.value.selected, notify: picker.value.notify });
}

function cancelSitePicker() {
    if (sitePicker.value.saving) return;
    sitePicker.value = { action: null, selected: null, source: null, saving: false };
}

// The request behind this does the whole machine translation before it creates
// anything, so a failure means there is no entry to clean up — the modal simply
// stays open with the error and the click can be repeated. See
// WorkflowController::applyTransition().
async function confirmSitePicker() {
    if (!sitePicker.value.selected) return;
    sitePicker.value.saving = true;
    // `source_site` rides along only where the workflow allows a per-run pick; the
    // server ignores it otherwise, so sending it unconditionally would be a lie
    // about what decided the source.
    await runAction(sitePicker.value.action, {
        target_site: sitePicker.value.selected,
        ...(sitePicker.value.action.needs_source_pick ? { source_site: sitePicker.value.source } : {}),
    });
}

async function runAction(action, payload) {
    pending.value = action.transition.name;
    try {
        const response = await post(props.applyUrls[action.transition.name], payload);

        // One toast, not two: the CP's toast API only has success/error, and a
        // second success would stack over the first. `pending_jobs` means the
        // plain-language service has the text but answers minutes later — the
        // version we are about to open still shows the source, and without saying
        // so it reads as a rewrite that did nothing.
        const pendingNote = response?.data?.pending_jobs
            ? ` ${__('workflows::messages.translation_plain_submitted')}`
            : '';
        window.Statamic?.$toast?.success(
            `${__('workflows::messages.applied', { action: action.transition.label })}${pendingNote}`,
        );

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
        sitePicker.value.saving = false;
        picker.value.saving = false;
    }
}

// --- request helpers (axios with fetch fallback) ---
// `.status` on the thrown error is preserved for callers that need to tell a
// confirmed 404 apart from a network hiccup — neither axios's own AxiosError
// shape nor a bare `throw new Error()` exposed that to the caller before.
async function get(url) {
    if (axios) {
        try {
            return (await axios.get(url)).data;
        } catch (e) {
            throw Object.assign(new Error('request failed'), { status: e?.response?.status });
        }
    }
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok) throw Object.assign(new Error('request failed'), { status: res.status });
    return res.json();
}
async function post(url, body) {
    if (axios) return (await axios.post(url, body)).data;
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) });
    if (!res.ok) throw new Error('request failed');
    return res.json();
}
</script>

