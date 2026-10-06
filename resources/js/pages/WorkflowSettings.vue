<template>
    <div class="p-6">
        <Head :title="`${__('workflows::messages.settings')} — ${workflow}`" />

        <!-- Sticky so the Save button stays reachable no matter how far the
             operator has scrolled into the places/transitions tables. Negative
             horizontal margin + matching padding keeps the header's inner
             content aligned with the sections below, while the background
             covers the scrolled content and a border adds the usual visual
             separation. -->
        <header class="sticky top-0 z-10 -mx-6 mb-6 flex items-start justify-between gap-3 border-b border-gray-200 bg-white px-6 py-4 dark:border-gray-700! dark:bg-gray-900!">
            <div>
                <h1 class="text-2xl font-semibold dark:text-white">
                    {{ __('workflows::messages.settings') }} — {{ workflow }}
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.settings_intro') }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <!-- Beta: swaps the form below for the read-only graph of the same
                     (unsaved) model. Remembered per browser. -->
                <label class="mr-2 flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <Switch v-model="graphView" size="sm" />
                    {{ __('workflows::messages.editor_graph_view') }}
                </label>
                <Button variant="secondary" :text="__('workflows::messages.back_to_queue')" @click="$inertia.visit(queueUrl)" />
                <!-- Called with no args on purpose — save() takes an options
                     object, and forwarding the click event would shadow it. -->
                <Button variant="primary" icon="save" :text="__('workflows::messages.save')" @click="save()" :loading="saving" :disabled="saving" />
            </div>
        </header>

        <section v-show="!graphView" class="mb-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold dark:text-white">{{ __('workflows::messages.metadata') }}</h2>
            </div>
            <div class="grid grid-cols-2 gap-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <Field
                    :label="__('workflows::messages.nav_label')"
                    :instructions="__('workflows::messages.nav_label_hint')"
                >
                    <Input v-model="form.metadata.nav.label" />
                </Field>
                <Field :label="__('workflows::messages.nav_icon')">
                    <Input v-model="form.metadata.nav.icon" />
                </Field>
                <Field :label="__('workflows::messages.nav_order')">
                    <Input v-model.number="form.metadata.nav.order" type="number" />
                </Field>
                <Field :label="__('workflows::messages.settings_field_nav_slug')">
                    <Input :model-value="slug" disabled />
                </Field>
                <!-- A picker over the real user groups, like "May create entries"
                     below, instead of free text: a mistyped handle assigned nobody
                     and said nothing. One group, not several — see the server. -->
                <Field :label="__('workflows::messages.settings_field_default_assignee_group')">
                    <Select
                        adaptive-width
                        v-model="form.metadata.default_assignee_group"
                        :options="defaultAssigneeGroupOptions"
                        clearable
                        :placeholder="__('workflows::messages.default_assignee_group_none')"
                    />
                </Field>
                <!-- Applies to both kinds of workflow: whoever creates a post
                     (storePost) and whoever hands an entry to another site
                     (transfer) is the creator of that subject. -->
                <Field :label="__('workflows::messages.settings_field_assign_creator')">
                    <Checkbox v-model="form.metadata.assign_creator" solo />
                </Field>
                <Field :label="__('workflows::messages.settings_field_initial_place')">
                    <Select v-model="form.initial_place" :options="placeOptions" adaptive-width />
                </Field>
                <!-- Ausrichtung UND Position der Fortschrittsleiste auf der
                     Detailseite — ein Wert für beides, siehe
                     ProgressSteps::ORIENTATION. Steht hier bei den
                     Workflow-Metadaten und nicht an einem Place, weil sie von der
                     FORM der Definition abhängt: sieben Stufen lesen sich als
                     breites Band über dem Inhalt, vier daneben. Wer die Places
                     ordnet, kann das beurteilen. Nicht clearable: es gibt keine
                     dritte Möglichkeit, und save() lässt den Schlüssel beim
                     Standard ohnehin weg. -->
                <Field
                    :label="__('workflows::messages.settings_field_progress_orientation')"
                    :instructions="__('workflows::messages.settings_field_progress_orientation_hint')"
                >
                    <Select
                        v-model="form.metadata.progress_orientation"
                        :options="progressOrientationOptions"
                        adaptive-width
                    />
                </Field>
                <!-- Which place counts as "already finished" when backfilling
                     states for entries that predate this workflow. Only shown
                     when the workflow has no allow_publish place (the auto-detected
                     target is missing) or when an override is already stored (so
                     the user can clear it). -->
                <Field
                    v-if="showSeedPublishedPlaceField"
                    :label="__('workflows::messages.seed_published_place')"
                    :instructions="__('workflows::messages.seed_published_place_hint')"
                >
                    <Select
                        adaptive-width
                        v-model="form.metadata.seed_published_place"
                        :options="placeOptions"
                        clearable
                        :placeholder="__('workflows::messages.seed_published_place_none')"
                    />
                </Field>
                <!-- Who may use the "New post" button on this workflow's manager
                     page. Super admins always may; leaving this empty means only
                     they do (or whatever config/workflows.php falls back to).
                     Localization workflows don't create entries — they distribute
                     existing ones — so the field is meaningless for them. -->
                <Field
                    v-if="!isLocalizationWorkflow"
                    class="col-span-2"
                    :label="__('workflows::messages.post_creator_groups')"
                    :instructions="postCreatorHint"
                >
                    <Select
                        adaptive-width
                        v-model="form.metadata.post_creator_groups"
                        :options="groupOptions"
                        multiple
                        close-on-select
                        clearable
                        :placeholder="__('workflows::messages.post_creator_groups_none')"
                    />
                </Field>
                <!-- The "workflow CTA" of the New-post dialog: the transition its
                     confirm button runs right after creating. Only transitions
                     leaving the initial place make sense; the writer rejects the
                     rest, the list here just leaves system steps out. -->
                <Field
                    v-if="!isLocalizationWorkflow"
                    class="col-span-2"
                    :label="__('workflows::messages.create_transition')"
                    :instructions="__('workflows::messages.create_transition_hint')"
                >
                    <Select
                        adaptive-width
                        v-model="form.metadata.create_transition"
                        :options="transitionOptions"
                        clearable
                        :placeholder="__('workflows::messages.create_transition_none')"
                    />
                </Field>
                <!-- Who may change the responsible person on a subject. Super Admins
                     always may; empty means only they do — see
                     WorkflowController::assignerGroups(). Not the same thing as
                     `default_assignee_group` above, which says who a subject is handed
                     TO; this says who may do the handing. Applies to localization
                     workflows too — a translation gets handed on like anything else. -->
                <Field
                    class="col-span-2"
                    :label="__('workflows::messages.assigner_groups')"
                    :instructions="assignerHint"
                >
                    <Select
                        adaptive-width
                        v-model="form.metadata.assigner_groups"
                        :options="groupOptions"
                        multiple
                        close-on-select
                        clearable
                        :placeholder="__('workflows::messages.assigner_groups_none')"
                    />
                </Field>
            </div>
        </section>

        <!-- Everything above is common to every workflow. This section is the one
             that differs per KIND of localization manager, and it deliberately
             shows only the settings that matter for THIS one:

             - a plain-language manager gets its fixed target site and the
               plain-language endpoint's status;
             - a translation manager gets neither. `plain_language_site` is what
               makes a workflow a plain-language manager, so offering it here would
               let somebody turn the translation manager into one by picking from a
               dropdown — the two managers would silently swap roles. Which kind a
               workflow is comes from the definition you installed.

             The source site is shown for both, because translating out of some
             version is what both of them do.

             The env lines are a pure read-out: the services take their credentials
             from `.env` via config/workflows.php, never from this editor — it writes
             a committed YAML, so a key stored through it would land in every clone,
             backup and CI log. Shown anyway because a missing `.env` entry would
             otherwise first surface as a failed API call that looks like a broken
             integration. -->
        <section v-if="isLocalizationWorkflow" v-show="!graphView" class="mb-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold dark:text-white">
                    {{ isPlainLanguageWorkflow
                        ? __('workflows::messages.plain_language_settings')
                        : __('workflows::messages.translation_settings') }}
                </h2>
            </div>
            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <div class="grid gap-4 sm:grid-cols-2">
                    <!-- Single-select, so "at most one" holds structurally
                         (`maxSelections` on a multi-select only colours the counter,
                         it enforces nothing). -->
                    <Field
                        :label="__('workflows::messages.translation_source_site')"
                        :instructions="__('workflows::messages.translation_source_site_hint')"
                    >
                        <Select
                            adaptive-width
                            v-model="form.metadata.translation_source_site"
                            :options="siteOptions"
                            clearable
                            :placeholder="__('workflows::messages.translation_source_site_none')"
                        />
                    </Field>
                    <!-- Rendered only where the key already exists, i.e. in the
                         plain-language manager itself. That rule is self-consistent:
                         you always see the setting where it has an effect, and you
                         can never introduce it where it does not belong. Clearable
                         on purpose — it is the escape hatch for a definition that
                         carries the key by mistake (an older translation.yaml did),
                         and the instructions say what clearing means. -->
                    <Field
                        v-if="isPlainLanguageWorkflow"
                        :label="__('workflows::messages.plain_language_site')"
                        :instructions="__('workflows::messages.plain_language_site_hint')"
                    >
                        <Select
                            adaptive-width
                            v-model="form.metadata.plain_language_site"
                            :options="siteOptions"
                            clearable
                            :placeholder="__('workflows::messages.plain_language_site_none')"
                        />
                    </Field>
                    <!-- Optional: without it, a subject stays put after a
                         successful rewrite until a human clicks
                         submit_plain_language — see PollPlainLanguageCommand.
                         Clearable, same reason as plain_language_site above. -->
                    <Field
                        v-if="isPlainLanguageWorkflow"
                        :label="__('workflows::messages.plain_language_completed_place')"
                        :instructions="__('workflows::messages.plain_language_completed_place_hint')"
                    >
                        <Select
                            adaptive-width
                            v-model="form.metadata.plain_language_completed_place"
                            :options="placeOptions"
                            clearable
                            :placeholder="__('workflows::messages.plain_language_completed_place_none')"
                        />
                    </Field>
                    <!-- The failure counterpart. Clearable, and clearing it means
                         something: the untranslated copy is deleted instead of parked,
                         so the failure leaves nothing behind to look at. -->
                    <Field
                        v-if="isPlainLanguageWorkflow"
                        :label="__('workflows::messages.plain_language_failed_place')"
                        :instructions="__('workflows::messages.plain_language_failed_place_hint')"
                    >
                        <Select
                            adaptive-width
                            v-model="form.metadata.plain_language_failed_place"
                            :options="placeOptions"
                            clearable
                            :placeholder="__('workflows::messages.plain_language_failed_place_none')"
                        />
                    </Field>
                    <!-- Opt-out of the strict source. Only in the translation
                         manager: the plain-language manager asks nothing at all, so
                         there is no dialog to put the choice in — the writer rejects
                         the combination rather than accepting a flag that does
                         nothing. -->
                    <Field
                        v-if="!isPlainLanguageWorkflow"
                        class="sm:col-span-2"
                        :label="__('workflows::messages.translation_source_pick')"
                        :instructions="__('workflows::messages.translation_source_pick_hint')"
                    >
                        <Checkbox v-model="form.metadata.translation_source_pick" solo />
                    </Field>
                    <!-- Who sees the source column of the detail page how — a list of
                         audience rules (SourceViewRules). One row per rule: the mode, the
                         audience type, the handles. Same shape as the transition gate's
                         "who may perform" picker above, with users as a third type. A
                         rule without handles names nobody and is dropped on save rather
                         than written as a no-op. -->
                    <Field
                        class="sm:col-span-2"
                        :label="__('workflows::messages.translation_source_view')"
                        :instructions="__('workflows::messages.translation_source_view_hint')"
                    >
                        <div class="space-y-2">
                            <div
                                v-for="(rule, i) in form.metadata.translation_source_view"
                                :key="i"
                                class="flex flex-wrap items-start gap-2"
                            >
                                <Select
                                    adaptive-width
                                    v-model="rule.mode"
                                    :options="sourceViewModeOptions"
                                    class="w-48 shrink-0"
                                />
                                <Select
                                    adaptive-width
                                    v-model="rule.by"
                                    :options="sourceViewByOptions"
                                    class="w-36 shrink-0"
                                    @update:model-value="rule.handles = []"
                                />
                                <Combobox
                                    adaptive-width
                                    v-model="rule.handles"
                                    :options="sourceViewHandleOptions(rule)"
                                    multiple
                                    close-on-select
                                    clearable
                                    class="min-w-64 flex-1"
                                    :placeholder="__('workflows::messages.translation_source_view_handles_none')"
                                />
                                <Button
                                    icon="trash"
                                    variant="ghost"
                                    size="sm"
                                    icon-only
                                    :aria-label="__('workflows::messages.translation_source_view_remove')"
                                    :title="__('workflows::messages.translation_source_view_remove')"
                                    @click="form.metadata.translation_source_view.splice(i, 1)"
                                />
                            </div>
                            <p v-if="!form.metadata.translation_source_view.length" class="text-sm text-gray-500 dark:text-gray-400">
                                {{ __('workflows::messages.translation_source_view_empty') }}
                            </p>
                            <Button
                                variant="secondary"
                                size="sm"
                                icon="plus"
                                :text="__('workflows::messages.translation_source_view_add')"
                                @click="addSourceViewRule"
                            />
                        </div>
                    </Field>
                </div>
                <div class="mt-4 space-y-1 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <p class="mb-2 text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('workflows::messages.translation_apis') }}</p>
                    <template v-if="isPlainLanguageWorkflow">
                        <EnvStatusLine :status="envStatus.plain_language_url" />
                        <EnvStatusLine :status="envStatus.plain_language_token" />
                    </template>
                    <EnvStatusLine v-else :status="envStatus.deepl" />
                </div>
            </div>
        </section>

        <section v-show="!graphView" class="mb-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold dark:text-white">{{ __('workflows::messages.collections') }}</h2>
            </div>
            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('workflows::messages.collections_intro') }}
                </p>
                <Select
                    adaptive-width
                    v-model="form.collections"
                    :options="collectionOptions"
                    multiple
                    close-on-select
                    clearable
                    :placeholder="__('workflows::messages.select_collections')"
                />

                <!-- A collection may appear in several workflows; the alphabetically
                     first one owns it. A workflow that isn't the owner only governs
                     entries with an explicitly stored state, which is easy to
                     mistake for a bug — so name the owner here. -->
                <ul v-if="foreignCollections.length" class="mt-3 space-y-1">
                    <li
                        v-for="item in foreignCollections"
                        :key="item.handle"
                        class="flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400"
                    >
                        <Icon name="info" class="mt-0.5 size-3.5 shrink-0" />
                        <span>{{ __('workflows::messages.collections_owned_by', { collection: item.label, workflow: item.owner }) }}</span>
                    </li>
                </ul>

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.collections_hint') }}
                </p>
            </div>
        </section>

        <!-- Die Alterung gehört dem Workflow, der die Beiträge selbst führt. Ein
             Lokalisierungs-Workflow reagiert auf dessen Frist (Sektion darunter),
             er hat keine eigene — der Writer lehnt den Block dort auch ab. -->
        <section v-if="! isLocalizationWorkflow" v-show="!graphView" class="mb-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold dark:text-white">{{ __('workflows::messages.sla') }}</h2>
            </div>
            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('workflows::messages.sla_intro') }}
                </p>
                <!-- Tage und Schritt paarweise, damit man die Stufe als eine
                     Aussage liest. Die Schritte sind die eigenen Places dieses
                     Workflows: der Autor baut sie samt Transitions, hier wird nur
                     gewählt, welcher wann angesteuert wird. -->
                <div class="grid grid-cols-2 gap-4">
                    <Field :label="__('workflows::messages.sla_needs_update_days')">
                        <Input
                            v-model.number="form.metadata.sla.needs_update_days"
                            type="number"
                            min="0"
                            :placeholder="__('workflows::messages.sla_placeholder_disabled')"
                        />
                    </Field>
                    <Field :label="__('workflows::messages.sla_needs_update_place')">
                        <Select
                            adaptive-width
                            v-model="form.metadata.sla.needs_update_place"
                            :options="slaPlaceOptions"
                        />
                    </Field>
                    <!-- Wer die Stufe übernimmt. Niemand klickt in eine Alterungsstufe,
                         der Scheduler verschiebt — also gibt es keinen Dialog, der fragen
                         könnte. Leer = die bisherigen Zuständigen bleiben. -->
                    <Field class="col-span-2" :label="__('workflows::messages.sla_needs_update_assign')">
                        <div class="flex flex-wrap items-start gap-2">
                            <Select
                                adaptive-width
                                v-model="form.metadata.sla.needs_update_assign.by"
                                :options="slaAssignByOptions"
                                class="w-44 shrink-0"
                                @update:model-value="form.metadata.sla.needs_update_assign.handles = []"
                            />
                            <Combobox
                                v-if="form.metadata.sla.needs_update_assign.by"
                                adaptive-width
                                v-model="form.metadata.sla.needs_update_assign.handles"
                                :options="sourceViewHandleOptions(form.metadata.sla.needs_update_assign)"
                                multiple
                                close-on-select
                                clearable
                                class="min-w-64 flex-1"
                                :placeholder="__('workflows::messages.sla_assign_handles_none')"
                            />
                        </div>
                    </Field>
                    <Field :label="__('workflows::messages.sla_outdated_days')">
                        <Input
                            v-model.number="form.metadata.sla.outdated_days"
                            type="number"
                            min="0"
                            :placeholder="__('workflows::messages.sla_placeholder_disabled')"
                        />
                    </Field>
                    <Field :label="__('workflows::messages.sla_outdated_place')">
                        <Select
                            adaptive-width
                            v-model="form.metadata.sla.outdated_place"
                            :options="slaPlaceOptions"
                        />
                    </Field>
                    <!-- Wer die Stufe übernimmt. Niemand klickt in eine Alterungsstufe,
                         der Scheduler verschiebt — also gibt es keinen Dialog, der fragen
                         könnte. Leer = die bisherigen Zuständigen bleiben. -->
                    <Field class="col-span-2" :label="__('workflows::messages.sla_outdated_assign')">
                        <div class="flex flex-wrap items-start gap-2">
                            <Select
                                adaptive-width
                                v-model="form.metadata.sla.outdated_assign.by"
                                :options="slaAssignByOptions"
                                class="w-44 shrink-0"
                                @update:model-value="form.metadata.sla.outdated_assign.handles = []"
                            />
                            <Combobox
                                v-if="form.metadata.sla.outdated_assign.by"
                                adaptive-width
                                v-model="form.metadata.sla.outdated_assign.handles"
                                :options="sourceViewHandleOptions(form.metadata.sla.outdated_assign)"
                                multiple
                                close-on-select
                                clearable
                                class="min-w-64 flex-1"
                                :placeholder="__('workflows::messages.sla_assign_handles_none')"
                            />
                        </div>
                    </Field>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.sla_hint') }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.sla_place_hint') }}
                </p>
                <p class="mt-1 flex items-start gap-1.5 text-xs text-gray-400 dark:text-gray-500">
                    <Icon name="time-clock" class="mt-0.5 size-3.5 shrink-0" />
                    <span>{{ __('workflows::messages.sla_clock_hint') }}</span>
                </p>
                <p class="mt-1 flex items-start gap-1.5 text-xs text-gray-400 dark:text-gray-500">
                    <Icon name="time-clock" class="mt-0.5 size-3.5 shrink-0" />
                    <span>{{ __('workflows::messages.sla_cron_hint') }}</span>
                </p>
            </div>
        </section>

        <!-- Das Gegenstück: nur eine Fassung hat eine Quelle über sich, die
             wegfallen kann. -->
        <section v-if="isLocalizationWorkflow" v-show="!graphView" class="mb-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-semibold dark:text-white">{{ __('workflows::messages.source_draft') }}</h2>
            </div>
            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('workflows::messages.source_draft_intro') }}
                </p>
                <Field :label="__('workflows::messages.source_draft_place')">
                    <Select
                        adaptive-width
                        v-model="form.metadata.source_draft_place"
                        :options="sourceDraftPlaceOptions"
                    />
                </Field>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.source_draft_hint') }}
                </p>
            </div>
        </section>

        <section v-show="!graphView" class="mb-8">
            <h2 class="mb-3 text-lg font-semibold dark:text-white">{{ __('workflows::messages.places') }}</h2>

            <p class="mb-3 rounded-md border border-amber-300 bg-amber-100 p-3 text-sm leading-relaxed text-amber-950">
                {{ __('workflows::messages.settings_label_hint_intro') }}
                <code class="rounded bg-amber-200 px-1.5 py-0.5 font-mono text-xs text-amber-950">lang/vendor/workflows/&lt;locale&gt;/messages.php</code>
                {{ __('workflows::messages.settings_label_hint_with_key') }}
                <code class="rounded bg-amber-200 px-1.5 py-0.5 font-mono text-xs text-amber-950">state_&lt;handle&gt;</code>
            </p>

            <div class="grid grid-cols-1 gap-3">
                <div
                    v-for="(place, handle) in form.places"
                    :key="handle"
                    :ref="(el) => setCardRef(placeCards, handle, el)"
                    :class="[
                        'overflow-hidden rounded-lg border bg-white dark:bg-gray-800!',
                        isNewPlace(handle)
                            ? 'border-blue-300 ring-1 ring-blue-300 dark:border-blue-500! dark:ring-blue-500!'
                            : 'border-gray-200 dark:border-gray-700!',
                    ]"
                >
                    <!-- Header row: handle (mono or editable for new rows) + inline label + reorder + delete -->
                    <div class="flex items-center gap-2 border-b border-gray-200 bg-gray-50 p-3 dark:border-gray-700! dark:bg-gray-800/60!">
                        <div class="min-w-32 shrink-0">
                            <Input
                                v-if="isNewPlace(handle)"
                                v-model="newPlaceHandles[handle]"
                                @blur="commitPlaceHandle(handle)"
                                size="sm"
                                :placeholder="__('workflows::messages.handle_placeholder')"
                            />
                            <code v-else class="rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 dark:bg-gray-900! dark:text-gray-200!">{{ handle }}</code>
                        </div>
                        <!-- One name per language. Empty = the grey placeholder, which is
                             what the place is called without an own name (language file,
                             else the author's other name) — see placeLabelFallback(). -->
                        <div class="flex flex-1 flex-wrap items-center gap-2">
                            <div v-for="locale in labelLocales" :key="locale" class="flex min-w-40 flex-1 items-center gap-1.5">
                                <span class="font-mono text-2xs uppercase text-gray-500 dark:text-gray-400">{{ locale }}</span>
                                <Input
                                    v-model="place.labels[locale]"
                                    size="sm"
                                    class="flex-1"
                                    :placeholder="placeLabelFallback(handle, locale)"
                                    :aria-label="`${__('workflows::messages.label_placeholder')} (${locale})`"
                                />
                            </div>
                        </div>
                        <Badge
                            :text="placeDisplayName(handle)"
                            :color="place.metadata.color || stateColor(handle)"
                            size="sm"
                        />
                        <div class="ml-auto flex items-center gap-1">
                            <Button
                                variant="ghost"
                                icon="chevron-up"
                                size="sm"
                                icon-only
                                :disabled="isFirst(form.places, handle)"
                                :aria-label="__('workflows::messages.move_up')"
                                @click="movePlaceUp(handle)"
                            />
                            <Button
                                variant="ghost"
                                icon="chevron-down"
                                size="sm"
                                icon-only
                                :disabled="isLast(form.places, handle)"
                                :aria-label="__('workflows::messages.move_down')"
                                @click="movePlaceDown(handle)"
                            />
                            <Button
                                v-if="isNewPlace(handle)"
                                variant="secondary"
                                size="sm"
                                :text="__('workflows::messages.cancel')"
                                @click="cancelNewPlace(handle)"
                            />
                            <Button
                                v-else
                                variant="destructive"
                                icon="trash"
                                size="sm"
                                icon-only
                                :aria-label="__('workflows::messages.delete')"
                                @click="deletePlace(handle)"
                            />
                        </div>
                    </div>

                    <!-- Body grid: fields the row used to carry as cells, now with room to breathe -->
                    <div class="grid grid-cols-1 gap-4 p-3 sm:grid-cols-2">
                        <!-- Kein `required_group` mehr am Place: es hat nie eine
                             Transition gegated (das tut nur der gleichnamige
                             Schlüssel an der Transition) und weist seit der
                             Umstellung auch nichts mehr zu. save() entfernt den
                             Schlüssel aus bestehenden Definitionen. -->
                        <Field :label="__('workflows::messages.settings_field_color')">
                            <Select
                                adaptive-width
                                v-model="place.metadata.color"
                                :options="colorOptions"
                                size="sm"
                                clearable
                                :placeholder="__('workflows::messages.color_default')"
                            />
                        </Field>
                        <Field :label="__('workflows::messages.settings_field_allow_publish')">
                            <Checkbox v-model="place.metadata.allow_publish" solo />
                        </Field>
                        <!-- Reine Darstellung, kein Verhalten: der Place wird in der
                             Fortschrittsleiste der Summary nicht als Stufe auf dem
                             Weg gezeichnet, sondern versetzt an seinem Vorgänger —
                             und nur dann, wenn ein Beitrag tatsächlich darin steht.
                             Mit `instructions`, weil „Abzweigung" allein nicht sagt,
                             was passiert. Siehe Workflow\ProgressSteps. -->
                        <Field
                            class="sm:col-span-2"
                            :label="__('workflows::messages.settings_field_branch')"
                            :instructions="__('workflows::messages.settings_field_branch_hint')"
                        >
                            <Checkbox v-model="place.metadata.branch" solo />
                        </Field>
                        <!-- Behaviours that apply while a subject sits in this place —
                             see Workflow\PlaceActions. A list, not a boolean: a place
                             may want both the loading spinner AND a stale-content
                             reminder at once. -->
                        <Field :label="__('workflows::messages.place_actions')" class="sm:col-span-2">
                            <div class="space-y-2">
                                <div
                                    v-for="(action, i) in place.metadata.actions"
                                    :key="i"
                                    class="rounded-md border border-gray-200 p-2 dark:border-gray-700!"
                                >
                                    <div class="flex items-start gap-2">
                                        <Select
                                            adaptive-width
                                            v-model="action.type"
                                            @update:model-value="(type) => onPlaceActionTypeChange(action, type)"
                                            :options="placeActionTypeOptionsFor(place, action)"
                                            size="sm"
                                            class="w-56 shrink-0"
                                        />
                                        <!-- Minutes for the refresh reminder — the only
                                             built-in place-action type with anything to
                                             configure. -->
                                        <Input
                                            v-if="action.type === 'refresh_interval'"
                                            v-model.number="action.minutes"
                                            type="number"
                                            min="1"
                                            max="1440"
                                            class="flex-1"
                                            :placeholder="__('workflows::messages.action_refresh_interval_minutes_placeholder')"
                                        />
                                        <!-- Dieselbe Auswahl wie auf der
                                             Transition-Karte, weil es dieselbe
                                             Action ist. -->
                                        <Select
                                            adaptive-width
                                            v-else-if="action.type === 'mail'"
                                            v-model="action.mail_type"
                                            :options="mailTypes"
                                            size="sm"
                                            class="flex-1"
                                            :aria-label="__('workflows::messages.action_mail_type_label')"
                                        />
                                        <!-- Erinnerung: Intervall in Tagen plus
                                             derselbe Mail-Typ-Selektor wie bei
                                             `mail`, vorbelegt mit `reminder`. -->
                                        <!-- Eigener Flex-Container: Statamics <Input> ist
                                             intern w-full und ignoriert eine Breite am
                                             Element selbst — ohne Wrapper schöbe es
                                             Mail-Typ und Löschen-Button aus der Karte. -->
                                        <div v-else-if="action.type === 'reminder'" class="flex min-w-0 flex-1 items-start gap-2">
                                            <div class="w-24 shrink-0">
                                                <Input
                                                    v-model.number="action.every_days"
                                                    type="number"
                                                    min="1"
                                                    max="365"
                                                    :aria-label="__('workflows::messages.action_reminder_every_days_label')"
                                                    :placeholder="__('workflows::messages.action_reminder_every_days_placeholder')"
                                                />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <Select
                                                    adaptive-width
                                                    v-model="action.mail_type"
                                                    :options="mailTypes"
                                                    size="sm"
                                                    class="w-full"
                                                    :aria-label="__('workflows::messages.action_mail_type_label')"
                                                />
                                            </div>
                                        </div>
                                        <!-- Frist in Tagen + eine der Transitionen, die
                                             in DIESEM Place starten. -->
                                        <div v-else-if="action.type === 'auto_transition'" class="flex min-w-0 flex-1 items-start gap-2">
                                            <div class="w-24 shrink-0">
                                                <Input
                                                    v-model.number="action.after_days"
                                                    type="number"
                                                    min="1"
                                                    max="365"
                                                    :aria-label="__('workflows::messages.action_auto_transition_days_label')"
                                                    :placeholder="__('workflows::messages.action_reminder_every_days_placeholder')"
                                                />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <Select
                                                    adaptive-width
                                                    v-model="action.transition"
                                                    :options="autoTransitionOptions(handle)"
                                                    size="sm"
                                                    class="w-full"
                                                    :aria-label="__('workflows::messages.action_auto_transition_transition_label')"
                                                />
                                            </div>
                                        </div>
                                        <span v-else class="flex-1" />
                                        <Button
                                            variant="ghost"
                                            icon="trash"
                                            size="sm"
                                            icon-only
                                            :aria-label="__('workflows::messages.delete')"
                                            @click="removePlaceAction(place, i)"
                                        />
                                    </div>

                                    <!-- Wie auf der Transition-Karte, mit dem
                                         Unterschied, auf den es hier ankommt: der
                                         Auslöser ist die ANKUNFT, nicht der Klick —
                                         deshalb ein eigener Hinweistext. Die
                                         Mailer-Zeile steht auch hier, weil
                                         `MAIL_MAILER=log` genau denselben stillen
                                         Ausfall bedeutet. -->
                                    <div
                                        v-if="action.type === 'mail'"
                                        class="mt-2 space-y-1.5 border-t border-gray-200 pt-2 dark:border-gray-700!"
                                    >
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('workflows::messages.action_mail_place_hint') }}
                                        </p>
                                        <MailerStatusLine :status="mailStatus" />
                                    </div>

                                    <div
                                        v-if="action.type === 'auto_transition'"
                                        class="mt-2 border-t border-gray-200 pt-2 dark:border-gray-700!"
                                    >
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('workflows::messages.action_auto_transition_hint', { time: reminderTime ?? '08:00' }) }}
                                        </p>
                                    </div>

                                    <div
                                        v-if="action.type === 'reminder'"
                                        class="mt-2 space-y-1.5 border-t border-gray-200 pt-2 dark:border-gray-700!"
                                    >
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('workflows::messages.action_reminder_hint', { time: reminderTime ?? '08:00' }) }}
                                        </p>
                                        <MailerStatusLine :status="mailStatus" />
                                    </div>

                                    <!-- Felder eines projekteigenen Typs, siehe
                                         Transition-Karte. -->
                                    <div
                                        v-if="customActionFor('place', action.type)?.fields.length"
                                        class="mt-2 grid grid-cols-1 gap-3 border-t border-gray-200 pt-2 sm:grid-cols-2 dark:border-gray-700!"
                                    >
                                        <Field
                                            v-for="field in customActionFor('place', action.type).fields"
                                            :key="field.handle"
                                            :label="field.label"
                                        >
                                            <component
                                                :is="customFieldComponent(field)"
                                                v-bind="customFieldProps(field)"
                                                :model-value="action.values?.[field.handle] ?? customFieldEmpty(field)"
                                                @update:model-value="(v) => setCustomValue(action, field, v)"
                                            />
                                        </Field>
                                    </div>
                                </div>

                                <!-- Gleiche Auswahl-vor-Anlegen wie auf der
                                     Transition-Karte. -->
                                <Dropdown v-if="placeActionTypeOptionsFor(place, null).length">
                                    <template #trigger>
                                        <Button
                                            variant="secondary"
                                            icon="plus"
                                            size="sm"
                                            :text="__('workflows::messages.action_add')"
                                        />
                                    </template>
                                    <DropdownMenu>
                                        <DropdownItem
                                            v-for="option in placeActionTypeOptionsFor(place, null)"
                                            :key="option.value"
                                            :text="option.label"
                                            @click="addPlaceAction(place, option.value)"
                                        />
                                    </DropdownMenu>
                                </Dropdown>
                            </div>
                        </Field>
                    </div>
                </div>
            </div>
            <p v-if="Object.keys(form.places).length === 0" class="mt-2 text-sm text-gray-500">
                {{ __('workflows::messages.no_places') }}
            </p>

            <!-- Der einzige Weg, einen Place anzulegen. Am Listenende statt in der
                 Abschnitts-Überschrift, weil addPlace() die neue Karte genau hier
                 anhängt und dorthin scrollt — der Knopf steht also da, wo das
                 Ergebnis erscheint. Ohne v-if: bei leerer Liste ist er der einzige
                 Ausweg, und ein Knopf, der sich versteckt, wenn man ihn am
                 nötigsten braucht, ist keiner. -->
            <div class="mt-3">
                <Button variant="secondary" icon="plus" :text="__('workflows::messages.add_place')" @click="addPlace" />
            </div>
        </section>

        <section v-show="!graphView" class="mb-8">
            <h2 class="mb-3 text-lg font-semibold dark:text-white">{{ __('workflows::messages.transitions') }}</h2>

            <p class="mb-3 rounded-md border border-amber-300 bg-amber-100 p-3 text-sm leading-relaxed text-amber-950">
                {{ __('workflows::messages.settings_label_hint_intro') }}
                <code class="rounded bg-amber-200 px-1.5 py-0.5 font-mono text-xs text-amber-950">lang/vendor/workflows/&lt;locale&gt;/messages.php</code>
                {{ __('workflows::messages.settings_label_hint_with_key') }}
                <code class="rounded bg-amber-200 px-1.5 py-0.5 font-mono text-xs text-amber-950">action_&lt;handle&gt;</code>
            </p>

            <div class="grid grid-cols-1 gap-3">
                <div
                    v-for="(transition, handle) in form.transitions"
                    :key="handle"
                    :ref="(el) => setCardRef(transitionCards, handle, el)"
                    :class="[
                        'overflow-hidden rounded-lg border bg-white dark:bg-gray-800!',
                        isNewTransition(handle)
                            ? 'border-blue-300 ring-1 ring-blue-300 dark:border-blue-500! dark:ring-blue-500!'
                            : 'border-gray-200 dark:border-gray-700!',
                    ]"
                >
                    <!-- Header row -->
                    <div class="flex items-center gap-2 border-b border-gray-200 bg-gray-50 p-3 dark:border-gray-700! dark:bg-gray-800/60!">
                        <div class="min-w-32 shrink-0">
                            <Input
                                v-if="isNewTransition(handle)"
                                v-model="newTransitionHandles[handle]"
                                @blur="commitTransitionHandle(handle)"
                                size="sm"
                                :placeholder="__('workflows::messages.handle_placeholder')"
                            />
                            <code v-else class="rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 dark:bg-gray-900! dark:text-gray-200!">{{ handle }}</code>
                        </div>
                        <Input v-model="transition.metadata.label" size="sm" class="flex-1" :placeholder="__('workflows::messages.label_placeholder')" />
                        <div class="ml-auto flex items-center gap-1">
                            <Button
                                variant="ghost"
                                icon="chevron-up"
                                size="sm"
                                icon-only
                                :disabled="isFirst(form.transitions, handle)"
                                :aria-label="__('workflows::messages.move_up')"
                                @click="moveTransitionUp(handle)"
                            />
                            <Button
                                variant="ghost"
                                icon="chevron-down"
                                size="sm"
                                icon-only
                                :disabled="isLast(form.transitions, handle)"
                                :aria-label="__('workflows::messages.move_down')"
                                @click="moveTransitionDown(handle)"
                            />
                            <Button
                                v-if="isNewTransition(handle)"
                                variant="secondary"
                                size="sm"
                                :text="__('workflows::messages.cancel')"
                                @click="cancelNewTransition(handle)"
                            />
                            <Button
                                v-else
                                variant="destructive"
                                icon="trash"
                                size="sm"
                                icon-only
                                :aria-label="__('workflows::messages.delete')"
                                @click="deleteTransition(handle)"
                            />
                        </div>
                    </div>

                    <!-- Body grid -->
                    <div class="grid grid-cols-1 gap-4 p-3 sm:grid-cols-2">
                        <!-- The first card leads out of the start place, so its
                             `from` is not a free choice — it follows `initial_place`.
                             Shown disabled rather than editable, because a value
                             that the next reorder overwrites is worse than one that
                             says it is derived.
                             ONLY while nothing has pinned the start elsewhere: once
                             a `start_localization` action exists, THAT transition is
                             the real start and owns this rule instead — the first
                             card goes back to being an ordinary transition, and the
                             pinned one is the admin's own responsibility to wire up
                             (the writer checks it on save, see
                             WorkflowDefinitionWriter). -->
                        <Field
                            :label="__('workflows::messages.settings_field_from')"
                            :instructions="handle === startingTransition && !explicitStartTransition ? __('workflows::messages.from_follows_initial_place') : ''"
                        >
                            <Select
                                adaptive-width
                                v-model="transition.from"
                                :options="placeOptions"
                                size="sm"
                                :disabled="handle === startingTransition && !explicitStartTransition"
                            />
                        </Field>
                        <Field :label="__('workflows::messages.settings_field_to')">
                            <Select v-model="transition.to" :options="placeOptions" size="sm" adaptive-width />
                        </Field>
                        <!-- Das Gate: wer diesen Schritt ausführen darf, zusätzlich
                             dazu, zuständig zu sein. Ein Typ statt zweier Listen —
                             dasselbe Muster wie im SLA-Block. „Alle Zuständigen"
                             heißt: kein Gate, die Zuweisung entscheidet allein. -->
                        <Field
                            :label="__('workflows::messages.required_by')"
                            :instructions="__('workflows::messages.required_by_hint')"
                            class="sm:col-span-2"
                        >
                            <div class="flex items-start gap-2">
                                <Select
                                    adaptive-width
                                    v-model="transition.metadata.required_by"
                                    :options="requiredByOptions"
                                    class="w-52 shrink-0"
                                    @update:model-value="resetRequiredHandles(transition)"
                                />
                                <Combobox
                                    adaptive-width
                                    v-if="requiredBy(transition) !== 'none'"
                                    v-model="transition.metadata.required_handles"
                                    :options="requiredHandleOptions(transition)"
                                    multiple
                                    close-on-select
                                    clearable
                                    class="flex-1"
                                    :placeholder="__('workflows::messages.required_group_none')"
                                />
                                <span v-else class="flex-1" />
                            </div>
                        </Field>
                        <Field :label="__('workflows::messages.settings_field_variant')">
                            <div class="flex items-center gap-2">
                                <Select
                                    adaptive-width
                                    v-model="transition.metadata.variant"
                                    :options="variantOptions"
                                    size="sm"
                                    clearable
                                    :placeholder="defaultVariant(handle)"
                                    :class="['wf-variant-select flex-1', variantTintClasses(transition.metadata.variant || defaultVariant(handle))]"
                                />
                                <!-- Live preview: exactly the button the entry
                                     editor will render for this transition. -->
                                <Button
                                    :variant="transition.metadata.variant || defaultVariant(handle)"
                                    :text="transition.metadata.label || handle"
                                    size="sm"
                                />
                            </div>
                        </Field>

                        <!-- Actions run between the click and the transition.
                             Only one DIALOG fits in that moment, so the type
                             stops being offered once a row already opens one —
                             rather than letting the user build something the
                             writer will reject on save. A user assignment in
                             automatic mode opens nothing and never counts. -->
                        <Field :label="__('workflows::messages.transition_actions')" class="sm:col-span-2">
                            <div class="space-y-2">
                                <!-- Die Sprachauswahl steht hier bewusst NICHT mehr
                                     als abgeleitete Zeile. Sie war read-only, also
                                     nichts, woran man etwas tun konnte, und stand
                                     zwischen den Actions, an denen man etwas tut —
                                     eine Zeile, die aussah wie ein Eintrag und
                                     keiner war. Wo der Dialog sitzt, sagt jetzt die
                                     Action „Erzeugt die Ziel-Fassung", und die ist
                                     eine ganz normale Zeile in dieser Liste. -->
                                <div
                                    v-for="(action, i) in transition.metadata.actions"
                                    :key="i"
                                    class="rounded-md border border-gray-200 p-2 dark:border-gray-700!"
                                >
                                    <div class="flex items-start gap-2">
                                        <!-- Options exclude `user_assignment` on every
                                             OTHER row once one already opens a dialog —
                                             at most one dialog can run before a
                                             transition, and filtering it out of the
                                             choices keeps that invariant true in the UI
                                             itself rather than only failing on save. A
                                             gate like `target_missing` has no such
                                             limit. -->
                                        <Select
                                            adaptive-width
                                            v-model="action.type"
                                            :options="actionTypeOptionsFor(handle, transition, action)"
                                            size="sm"
                                            class="w-48 shrink-0"
                                        />
                                        <!-- Which site this gate watches. Same siteOptions
                                             prop as translation_source_site/plain_language_site
                                             above — one source of truth for "what sites exist". -->
                                        <Select
                                            adaptive-width
                                            v-if="action.type === 'target_missing'"
                                            v-model="action.site"
                                            :options="siteOptions"
                                            clearable
                                            class="flex-1"
                                            :placeholder="__('workflows::messages.action_target_missing_site_none')"
                                        />
                                        <!-- Welche Mail. Steht in der Kopfzeile neben
                                             dem Typ, weil sie das Einzige ist, was
                                             diese Action überhaupt konfiguriert —
                                             und weil ohne sie nicht ablesbar wäre,
                                             WAS hier verschickt wird. -->
                                        <Select
                                            adaptive-width
                                            v-else-if="action.type === 'mail'"
                                            v-model="action.mail_type"
                                            :options="mailTypes"
                                            size="sm"
                                            class="flex-1"
                                            :aria-label="__('workflows::messages.action_mail_type_label')"
                                        />
                                        <!-- The mode sits in the header row, next to the
                                             type, because it changes what the action IS —
                                             a dialog or a silent assignment — while the
                                             criteria below only change who it concerns. -->
                                        <template v-else-if="action.type === 'user_assignment'">
                                            <Select
                                                adaptive-width
                                                v-model="action.mode"
                                                :options="assignmentModeOptions"
                                                size="sm"
                                                class="w-52 shrink-0"
                                            />
                                            <!-- How the criteria below combine. `any` is
                                                 what every migrated `user_modal` carries:
                                                 its group list WAS a union, and switching
                                                 it to `all` would silently mean "in both
                                                 groups at once" — practically nobody. -->
                                            <Select
                                                adaptive-width
                                                v-model="action.match"
                                                :options="assignmentMatchOptions"
                                                size="sm"
                                                class="flex-1"
                                            />
                                        </template>
                                        <span v-else class="flex-1" />
                                        <Button
                                            variant="ghost"
                                            icon="trash"
                                            size="sm"
                                            icon-only
                                            :aria-label="__('workflows::messages.delete')"
                                            @click="removeAction(transition, i)"
                                        />
                                    </div>

                                    <!-- Felder eines projekteigenen Typs, generisch aus
                                         der Deklaration gerendert. Unter der Kopfzeile
                                         statt daneben, weil die Zahl der Felder das
                                         Projekt bestimmt und eine Zeile davon beliebig
                                         breit würde. -->
                                    <div
                                        v-if="customActionFor('transition', action.type)?.fields.length"
                                        class="mt-2 grid grid-cols-1 gap-3 border-t border-gray-200 pt-2 sm:grid-cols-2 dark:border-gray-700!"
                                    >
                                        <Field
                                            v-for="field in customActionFor('transition', action.type).fields"
                                            :key="field.handle"
                                            :label="field.label"
                                        >
                                            <component
                                                :is="customFieldComponent(field)"
                                                v-bind="customFieldProps(field)"
                                                :model-value="action.values?.[field.handle] ?? customFieldEmpty(field)"
                                                @update:model-value="(v) => setCustomValue(action, field, v)"
                                            />
                                        </Field>
                                    </div>

                                    <!-- Was die Benachrichtigung tut, und ob sie
                                         überhaupt ankommt. Die zweite Hälfte steht
                                         hier statt in einer Doku-Seite, weil
                                         `MAIL_MAILER=log` ein Ausfall ist, den man
                                         sonst nirgends bemerkt: die Transition
                                         gelingt, das Log füllt sich, niemand
                                         bekommt etwas. Wer die Action anhängt, ist
                                         genau der, der das wissen muss. -->
                                    <div
                                        v-if="action.type === 'mail'"
                                        class="mt-2 space-y-1.5 border-t border-gray-200 pt-2 dark:border-gray-700!"
                                    >
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('workflows::messages.action_mail_hint') }}
                                        </p>
                                        <MailerStatusLine :status="mailStatus" />
                                    </div>

                                    <!-- Criteria: who is eligible. The same list in both
                                         modes — it decides the picker's contents in one
                                         and the assignees themselves in the other, which
                                         is why switching the mode never changes the
                                         pool. -->
                                    <div v-if="action.type === 'user_assignment'" class="mt-2 space-y-2 border-t border-gray-200 pt-2 dark:border-gray-700!">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('workflows::messages.assignment_criteria_hint') }}
                                        </p>

                                        <template v-for="(node, c) in action.criteria" :key="c">
                                            <!-- Eine GRUPPE: eigenes match, eigene
                                                 Kriterien, eine Ebene tief. Damit ist
                                                 „in der Gruppe UND (Kategorie ODER
                                                 Entität)" sagbar — ein einzelnes match
                                                 über der flachen Liste kann nur alles-UND
                                                 oder alles-ODER. Eingerückt und mit
                                                 Rahmen, weil die Klammer das ist, was man
                                                 beim Lesen braucht. -->
                                            <div
                                                v-if="isCriteriaGroup(node)"
                                                class="space-y-2 rounded-md border border-gray-200 bg-gray-50 p-2 dark:border-gray-700! dark:bg-gray-900!"
                                            >
                                                <div class="flex items-center gap-2">
                                                    <Select
                                                        adaptive-width
                                                        v-model="node.match"
                                                        :options="assignmentMatchOptions"
                                                        size="sm"
                                                        class="flex-1"
                                                    />
                                                    <Button
                                                        variant="ghost"
                                                        icon="trash"
                                                        size="sm"
                                                        icon-only
                                                        :aria-label="__('workflows::messages.delete')"
                                                        @click="removeCriterion(action, c)"
                                                    />
                                                </div>

                                                <div
                                                    v-for="(inner, ic) in node.criteria"
                                                    :key="ic"
                                                    class="flex items-start gap-2"
                                                >
                                                    <Select
                                                        adaptive-width
                                                        v-model="inner.field"
                                                        :options="assignmentCriterionOptions"
                                                        size="sm"
                                                        class="flex-1"
                                                        :placeholder="__('workflows::messages.assignment_criterion_placeholder')"
                                                        @update:model-value="resetCriterionOperator(inner)"
                                                    />
                                                    <Select
                                                        adaptive-width
                                                        v-model="inner.operator"
                                                        :options="operatorOptionsFor(inner)"
                                                        size="sm"
                                                        class="w-56 shrink-0"
                                                    />
                                                    <Combobox
                                                        adaptive-width
                                                        v-if="inner.operator !== 'matches_entry'"
                                                        v-model="inner.value"
                                                        :options="valueOptionsFor(inner)"
                                                        taggable
                                                        searchable
                                                        clearable
                                                        class="flex-1"
                                                        :placeholder="__('workflows::messages.assignment_value_placeholder')"
                                                    />
                                                    <span v-else class="flex-1" />
                                                    <Button
                                                        variant="ghost"
                                                        icon="trash"
                                                        size="sm"
                                                        icon-only
                                                        :aria-label="__('workflows::messages.delete')"
                                                        @click="removeCriterion(node, ic)"
                                                    />
                                                </div>

                                                <Button
                                                    variant="ghost"
                                                    icon="plus"
                                                    size="sm"
                                                    :text="__('workflows::messages.assignment_criterion_add')"
                                                    @click="addCriterion(node)"
                                                />
                                            </div>

                                            <div v-else class="flex items-start gap-2">
                                                <Select
                                                    adaptive-width
                                                    v-model="node.field"
                                                    :options="assignmentCriterionOptions"
                                                    size="sm"
                                                    class="flex-1"
                                                    :placeholder="__('workflows::messages.assignment_criterion_placeholder')"
                                                    @update:model-value="resetCriterionOperator(node)"
                                                />
                                                <Select
                                                    adaptive-width
                                                    v-model="node.operator"
                                                    :options="operatorOptionsFor(node)"
                                                    size="sm"
                                                    class="w-56 shrink-0"
                                                />
                                                <!-- Taggable: the suggestions come from the
                                                     server, but a group, role or term may
                                                     legitimately be created after the
                                                     workflow — the same tolerance the writer
                                                     grants these values. -->
                                                <Combobox
                                                    adaptive-width
                                                    v-if="node.operator !== 'matches_entry'"
                                                    v-model="node.value"
                                                    :options="valueOptionsFor(node)"
                                                    taggable
                                                    searchable
                                                    clearable
                                                    class="flex-1"
                                                    :placeholder="__('workflows::messages.assignment_value_placeholder')"
                                                />
                                                <!-- `matches_entry` takes its value from the
                                                     entry at run time, so there is nothing to
                                                     type here. -->
                                                <span v-else class="flex-1" />
                                                <Button
                                                    variant="ghost"
                                                    icon="trash"
                                                    size="sm"
                                                    icon-only
                                                    :aria-label="__('workflows::messages.delete')"
                                                    @click="removeCriterion(action, c)"
                                                />
                                            </div>
                                        </template>

                                        <!-- What no criteria means differs per mode, and
                                             the automatic one is a save error rather than
                                             a choice — said here instead of letting the
                                             writer be the first to mention it. -->
                                        <p v-if="!action.criteria?.length" class="text-xs" :class="action.mode === 'automatic' ? 'text-red-600 dark:text-red-400!' : 'text-gray-500 dark:text-gray-400!'">
                                            {{ action.mode === 'automatic'
                                                ? __('workflows::messages.assignment_criteria_none_automatic')
                                                : __('workflows::messages.assignment_criteria_none_manual') }}
                                        </p>

                                        <div class="flex items-center gap-2">
                                            <Button
                                                variant="secondary"
                                                icon="plus"
                                                size="sm"
                                                :text="__('workflows::messages.assignment_add_criterion')"
                                                @click="addCriterion(action)"
                                            />
                                            <!-- Eine Klammer für ein ODER innerhalb eines
                                                 UND. Neben dem Kriterien-Knopf, weil es
                                                 dieselbe Entscheidung ist — eine Zeile
                                                 mehr in derselben Liste. -->
                                            <Button
                                                variant="ghost"
                                                icon="plus"
                                                size="sm"
                                                :text="__('workflows::messages.assignment_add_group')"
                                                @click="addCriteriaGroup(action)"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <!-- Erst fragen, dann anlegen. Vorher legte der Knopf
                                     eine Zeile mit dem erstbesten noch freien Typ an,
                                     die man anschließend umstellte — zwei Schritte für
                                     eine Entscheidung, und der vorgeschlagene Typ war
                                     selten der gemeinte. Die Liste ist ohnehin je
                                     Transition verschieden (siehe
                                     actionTypeOptionsFor), also gehört sie an die
                                     Stelle, an der man wählt.
                                     Ganz weg ist der Knopf nur, wenn nichts mehr
                                     hinzuzufügen ist. -->
                                <Dropdown
                                    v-if="actionTypeOptionsFor(handle, transition, null).length"
                                >
                                    <template #trigger>
                                        <Button
                                            variant="secondary"
                                            icon="plus"
                                            size="sm"
                                            :text="__('workflows::messages.action_add')"
                                        />
                                    </template>
                                    <DropdownMenu>
                                        <DropdownItem
                                            v-for="option in actionTypeOptionsFor(handle, transition, null)"
                                            :key="option.value"
                                            :text="option.label"
                                            @click="addAction(handle, transition, option.value)"
                                        />
                                    </DropdownMenu>
                                </Dropdown>
                            </div>
                        </Field>
                    </div>
                </div>
            </div>
            <p v-if="Object.keys(form.transitions).length === 0" class="mt-2 text-sm text-gray-500">
                {{ __('workflows::messages.no_transitions') }}
            </p>

            <!-- Gegenstück zum Places-Knopf: addTransition() hängt ebenfalls hier
                 an, und aus demselben Grund ohne v-if. -->
            <div class="mt-3">
                <Button variant="secondary" icon="plus" :text="__('workflows::messages.add_transition')" @click="addTransition" />
            </div>
        </section>

        <!-- Lazy chunk: the graph editor (Vue Flow, dagre) only loads once the
             toggle is on. Gets the form's live model, so it shows unsaved edits. -->
        <WorkflowEditor
            v-if="graphView"
            :definition="definition"
            :model="form"
            :injected="injected"
            :state-usage="stateUsage"
            :label-locales="labelLocales"
            :label-locale="labelLocale"
            :shipped-place-labels="shippedPlaceLabels"
            :custom-actions="customActions"
            :form-view-label="__('workflows::messages.editor_form_view')"
            @form-view="graphView = false"
        />

        <Modal :open="migrationModal.open" @update:open="(v) => v || closeMigrationModal()">
            <ModalTitle class="wf-modal-head">
                <Icon name="warning-diamond" class="size-4" />
                {{ __('workflows::messages.migrate_place_title') }}
            </ModalTitle>
            <div class="space-y-3 py-3">
                <p class="text-sm">
                    {{ __('workflows::messages.migrate_place_prompt', { place: migrationModal.place, count: migrationModal.affectedCount }) }}
                </p>
                <Field :label="__('workflows::messages.migrate_target')">
                    <Select
                        adaptive-width
                        v-model="migrationModal.target"
                        :options="migrationTargetOptions"
                        :placeholder="__('workflows::messages.select_place')"
                    />
                </Field>
            </div>
            <!-- `#footer` renders outside the Modal's scrolling body, so the
                 buttons stay reachable however long the form gets. -->
            <template #footer>
                <div class="flex justify-end gap-2 px-2 pt-3">
                    <Button variant="ghost" :text="__('workflows::messages.cancel')" @click="closeMigrationModal" />
                    <Button
                        variant="destructive"
                        icon="trash"
                        :text="__('workflows::messages.confirm_delete')"
                        :disabled="!migrationModal.target"
                        @click="confirmMigrationDelete"
                    />
                </div>
            </template>
        </Modal>

        <!-- Removing a collection drops its entries out of the workflow and
             erases their states, assignments, history and comments. The server
             refuses that without `confirm_collection_removal` and answers 422
             with the affected count, which lands here. -->
        <Modal :open="collectionRemovalModal.open" @update:open="(v) => v || closeCollectionRemovalModal()">
            <ModalTitle class="wf-modal-head">
                <Icon name="warning-diamond" class="size-4" />
                {{ __('workflows::messages.collection_removal_title') }}
            </ModalTitle>
            <div class="space-y-3 py-3">
                <p class="text-sm">
                    {{ __('workflows::messages.collection_removal_prompt', {
                        collections: removedCollectionLabels,
                        count: collectionRemovalModal.affectedCount,
                    }) }}
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('workflows::messages.collection_removal_warning') }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-2 px-2 pt-3">
                    <Button variant="ghost" :text="__('workflows::messages.cancel')" @click="closeCollectionRemovalModal" />
                    <Button
                        variant="destructive"
                        icon="trash"
                        :text="__('workflows::messages.confirm_collection_removal')"
                        :loading="saving"
                        :disabled="saving"
                        @click="confirmCollectionRemoval"
                    />
                </div>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { reactive, ref, computed, watch, nextTick, getCurrentInstance, defineAsyncComponent, h } from 'vue';
import { Badge, Button, Combobox, Dropdown, DropdownItem, DropdownMenu, Modal, ModalTitle, Icon, Input, Select, Checkbox, Field, Switch } from '@statamic/cms/ui';
import { Head } from '@statamic/cms/inertia';
import { humanize, stateColor } from '../support/states.js';
import EnvStatusLine from '../components/EnvStatusLine.vue';
import MailerStatusLine from '../components/MailerStatusLine.vue';
import {
    ACTION_TYPES,
    ASSIGNMENT_MATCHES,
    ASSIGNMENT_MODES,
    PLACE_ACTION_TYPES,
    SHARED_ACTION_TYPES,
} from '../editor/model/constants.js';
import { emptyLabels, normalize } from '../editor/model/normalize.js';
import {
    commitPlaceHandle as commitPlaceHandleIn,
    commitTransitionHandle as commitTransitionHandleIn,
    customActionFor as customActionForIn,
    danglingHandles,
    finalizeNewHandles,
    isCriteriaGroup,
    serialize,
} from '../editor/model/serialize.js';
import * as rules from '../editor/model/actionRules.js';
import * as labels from '../editor/model/labels.js';
import { clearTransitionRefs, replaceReferences } from '../editor/model/refs.js';

// Used by the template as is.
const { defaultVariant } = labels;

// The graph editor is its own chunk: Vue Flow and dagre are only fetched when
// somebody switches the graph view on, so the CP pages don't grow.
const WorkflowEditor = defineAsyncComponent({
    loader: () => import('../editor/components/WorkflowEditor.vue'),
    loadingComponent: { render: () => h('p', { class: 'p-6 text-sm text-gray-500' }, __('workflows::messages.editor_loading')) },
    errorComponent: { render: () => h('p', { class: 'p-6 text-sm text-red-600' }, __('workflows::messages.editor_load_failed')) },
    delay: 150,
});

// Form or graph — remembered per browser. Storage may be unavailable (private
// mode, blocked site data); then it just doesn't stick.
const GRAPH_VIEW_KEY = 'workflows.settings.graphView';
const graphView = ref(readGraphView());
watch(graphView, (on) => {
    try {
        window.localStorage.setItem(GRAPH_VIEW_KEY, on ? '1' : '0');
    } catch {
        // not persisted — fine
    }
});

function readGraphView() {
    try {
        return window.localStorage.getItem(GRAPH_VIEW_KEY) === '1';
    } catch {
        return false;
    }
}

// Statamic CP exposes axios via Vue's global properties, not as a bare import.
// Same pattern used in every other addon component (WorkflowQueue, etc).
const axios = getCurrentInstance()?.appContext?.config?.globalProperties?.$axios;

const props = defineProps({
    workflow: { type: String, required: true },
    slug: { type: String, required: true },
    definition: { type: Object, required: true },
    // What DefinitionLoader adds at read time (pending_revision and its system
    // transitions) — not part of `definition`, shown read-only in the graph view.
    injected: { type: Object, default: () => ({ places: {}, transitions: {} }) },
    stateUsage: { type: Object, default: () => ({}) },
    saveUrl: { type: String, required: true },
    deletePlaceUrlTemplate: { type: String, required: true },
    queueUrl: { type: String, required: true },
    reservedPlaces: { type: Array, default: () => [] },
    reservedTransitions: { type: Array, default: () => [] },
    // The languages a place is named in (DefinitionLoader::LABEL_LOCALES), the CP's
    // own one among them, and per place + language the language-file wording an
    // empty name field falls back to (null where the addon ships none).
    labelLocales: { type: Array, default: () => ['de', 'en'] },
    labelLocale: { type: String, default: 'de' },
    shippedPlaceLabels: { type: Object, default: () => ({}) },
    // Badge colours a place may use (Statamic Badge variants minus the
    // SLA-reserved amber/yellow/red). Shipped by the controller from
    // WorkflowDefinitionWriter::PLACE_COLORS.
    colorOptions: { type: Array, default: () => [] },
    // Button variants a transition may use (default/primary/danger/filled),
    // from WorkflowDefinitionWriter::TRANSITION_VARIANTS.
    variantOptions: { type: Array, default: () => [] },
    // [{value, label}] for the progress-bar orientation. Labels come translated
    // from the server — the list is a PHP constant, so the client has no key to
    // build one from. Same split as assignmentCriteria.
    progressOrientationOptions: { type: Array, default: () => [] },
    // Every collection in the project as [{value: handle, label: title}].
    collectionOptions: { type: Array, default: () => [] },
    // handle => name of the workflow that owns the collection (first-wins).
    collectionOwners: { type: Object, default: () => ({}) },
    // Every user group as [{value: handle, label: title}].
    groupOptions: { type: Array, default: () => [] },
    // Every user role as [{value: handle, label: title}]. Only the assignment
    // criteria use it — group and role are the two properties a user has that
    // the addon knows about without the project telling it.
    roleOptions: { type: Array, default: () => [] },
    // Every user as [{value: id, label: name}] — the third audience type of the
    // source-view rules (SourceViewRules::BY_USER).
    userOptions: { type: Array, default: () => [] },
    // What a user assignment may filter on, as
    // [{value, label, operators: [...], options: [{value, label}]}] — the two
    // built-in criteria plus whatever config/workflows.php declares. Resolved
    // server-side, because only the server knows what a project criterion points
    // at. See Workflow\AssignmentCriteria.
    assignmentCriteria: { type: Array, default: () => [] },
    // { transition: [{value, label, fields: [{handle, type, label, min, max, options}]}], place: [...] }
    // Bereits nach dem bearbeiteten Workflow gefiltert; Feld-Optionen sind
    // serverseitig aufgelöst.
    customActions: { type: Object, default: () => ({ transition: [], place: [] }) },
    // Every configured Statamic site as [{value: handle, label: name}] —
    // the pool the plain-language picker chooses from.
    siteOptions: { type: Array, default: () => [] },
    // Group titles from config/workflows.php that apply when this workflow
    // declares no creators of its own.
    postCreatorFallback: { type: Array, default: () => [] },
    assignerFallback: { type: Array, default: () => [] },
    // Per translation service: {name, set, plan} — whether its env variable
    // holds a value, never the value itself. Read-only, and unchanged by saving:
    // the credentials live in config/workflows.php, not in the workflow YAML
    // this page writes. See WorkflowController::envStatus().
    envStatus: { type: Object, default: () => ({}) },
    // Whether a `mail` action would reach anybody: the mailer handle, its
    // transport, a boolean and the from address. No credentials — see
    // WorkflowController::mailStatus().
    mailStatus: { type: Object, default: null },
    // Uhrzeit des täglichen `workflows:send-reminders` — nur für den Hinweis
    // an der `reminder`-Action.
    reminderTime: { type: String, default: null },
    // Die wählbaren Mail-Typen der `mail`-Action, [{value,label}]. Vom Server
    // aufgelöst, weil ein projekteigener Typ nur in `config/workflows.php` steht
    // und sein Label aus einer Projekt-Sprachdatei kommen kann — beides kann der
    // Client nicht kennen. Siehe Mail\MailTypes.
    mailTypes: { type: Array, default: () => [] },
});

// What the model modules (resources/js/editor/model) need from the page. Getters,
// so a computed that reads through them still tracks the props.
const modelCtx = {
    get labelLocales() { return props.labelLocales; },
    get labelLocale() { return props.labelLocale; },
    get shippedPlaceLabels() { return props.shippedPlaceLabels; },
    get customActions() { return props.customActions; },
    get reservedPlaces() { return props.reservedPlaces; },
    get reservedTransitions() { return props.reservedTransitions; },
    get actionTypeOptions() { return actionTypeOptions.value; },
    get placeActionTypeOptions() { return placeActionTypeOptions.value; },
    humanize,
};

const form = reactive(normalize(props.definition, modelCtx));
const saving = ref(false);

// Track auto-generated handles for freshly-added, not-yet-persisted rows. Any
// key present here means "this row is new — show handle input, hide delete,
// show cancel". Persisted rows never appear here.
const newPlaceHandles = reactive({});
const newTransitionHandles = reactive({});
const pendingHandles = { places: newPlaceHandles, transitions: newTransitionHandles };

const migrationModal = reactive({
    open: false,
    place: null,
    affectedCount: 0,
    target: '',
});

const collectionRemovalModal = reactive({
    open: false,
    collections: [],
    affectedCount: 0,
});

// Spell out what an empty selection actually means — that differs depending on
// whether the project config names a fallback group.
const postCreatorHint = computed(() => {
    if (props.postCreatorFallback.length) {
        return __('workflows::messages.post_creator_groups_hint_fallback', {
            groups: props.postCreatorFallback.join(', '),
        });
    }

    return __('workflows::messages.post_creator_groups_hint');
});

const assignerHint = computed(() => {
    if (props.assignerFallback.length) {
        return __('workflows::messages.assigner_groups_hint_fallback', {
            groups: props.assignerFallback.join(', '),
        });
    }

    return __('workflows::messages.assigner_groups_hint');
});

// The server reports removed collections by handle; show their titles instead.
const removedCollectionLabels = computed(() =>
    collectionRemovalModal.collections
        .map((handle) => props.collectionOptions.find((o) => o.value === handle)?.label ?? handle)
        .join(', '),
);

// Selected collections that another workflow already owns. `collectionOwners`
// is a snapshot from page load, so a collection added in this unsaved session
// has no owner yet and correctly stays out of the list.
const foreignCollections = computed(() =>
    (form.collections ?? [])
        .filter((handle) => {
            const owner = props.collectionOwners?.[handle];

            return owner && owner !== props.workflow;
        })
        .map((handle) => ({
            handle,
            label: props.collectionOptions.find((o) => o.value === handle)?.label ?? handle,
            owner: props.collectionOwners[handle],
        })),
);

const placeOptions = computed(() =>
    Object.keys(form.places).map((handle) => ({ value: handle, label: handle })),
);

// Real transitions only — the revision loop's auto-injected system steps are
// driven by saves, not by buttons, so nothing may couple a button to them.
// Label plus handle, because two transitions may well share a label ("Ablehnen").
const transitionOptions = computed(() =>
    Object.entries(form.transitions)
        .filter(([, transition]) => !transition?.metadata?.system)
        .map(([handle, transition]) => ({ value: handle, label: `${transition?.metadata?.label || handle} (${handle})` })),
);

// True when at least one place declares `allow_publish: true`. That place is
// auto-detected as the "finished" target during backfill, so surfacing the
// `seed_published_place` override is only useful when this returns false — a
// workflow like `translation` that ends in a non-publish place.
const hasPublishPlace = computed(() => rules.hasPublishPlace(form));

// Localization workflows (metadata.detail === 'translation') distribute existing
// entries to more sites instead of creating new ones, so the "who may create
// posts" list is meaningless for them and gets hidden. `assign_creator` is NOT
// hidden: transferring an entry into a site is how a subject enters such a
// workflow, and the person who did it is its creator.
const isLocalizationWorkflow = computed(() => rules.isLocalizationWorkflow(form));

// Which KIND of localization manager this is. Mirrors
// WorkflowResolver::isPlainLanguageWorkflow(): claiming a target site via
// `plain_language_site` is what makes a workflow the plain-language manager — no
// separate mode flag that could disagree with it. Drives which settings this
// editor offers, which service status it reads out, and whether a transition may
// carry the "rewrite again" flag.
const isPlainLanguageWorkflow = computed(() => rules.isPlainLanguageWorkflow(form));

// --- transition actions ---
// The type lists (dialog / gate / effect buckets, the scope-shared `mail`) live in
// editor/model/constants.js. builtInActionLabel(): a handle both cards offer
// (SHARED_ACTION_TYPES) is ONE action and must read the same in both.
const builtInActionLabel = (scope, value) => __(`workflows::messages.${
    scope === 'place' && ! SHARED_ACTION_TYPES.includes(value) ? 'place_action_type' : 'action_type'
}_${value}`);

// Eingebaute Typen holen ihr Label aus den Sprachdateien, projekteigene bringen
// es fertig übersetzt vom Server mit — nur der Server weiß, was in der Config
// steht. Deshalb zwei Quellen statt einer.
const customTransitionActions = computed(() => props.customActions?.transition ?? []);
const customPlaceActions = computed(() => props.customActions?.place ?? []);

const actionTypeOptions = computed(() => [
    ...ACTION_TYPES.map((value) => ({ value, label: builtInActionLabel('transition', value) })),
    ...customTransitionActions.value.map(({ value, label }) => ({ value, label })),
]);

// Die Felddeklaration zu einem Typ, oder null für die eingebauten. Der Nachschlag
// nach `value` ist das, was den generischen Renderer und die Speicher-Whitelist
// trägt.
function customActionFor(scope, type) {
    return customActionForIn(props.customActions, scope, type);
}

// Vier Feldtypen, drei Komponenten — `select` und `multiselect` sind dieselbe
// Combobox mit und ohne `multiple`. Bewusst dieselben Bausteine wie überall
// sonst in diesem Editor, damit ein Projektfeld nicht als Fremdkörper dasteht.
function customFieldComponent(field) {
    if (field.type === 'number') return Input;
    if (field.type === 'select' || field.type === 'multiselect') return Combobox;

    return Input;
}

function customFieldProps(field) {
    if (field.type === 'number') {
        return { type: 'number', ...(field.min !== null ? { min: field.min } : {}), ...(field.max !== null ? { max: field.max } : {}) };
    }

    if (field.type === 'select' || field.type === 'multiselect') {
        return {
            options: field.options ?? [],
            multiple: field.type === 'multiselect',
            closeOnSelect: true,
            clearable: true,
            taggable: true,
            adaptiveWidth: true,
        };
    }

    return { type: 'text' };
}

// Womit ein noch nicht gesetztes Feld gebunden wird. Eine Mehrfachauswahl
// braucht ein echtes Array, sonst hat die Combobox nichts, worin sie schieben
// kann; alles andere ist null, damit der Platzhalter sichtbar bleibt.
function customFieldEmpty(field) {
    return field.type === 'multiselect' ? [] : null;
}

// Leere Werte werden gelöscht statt als null gespeichert — dieselbe Regel wie
// bei den anderen Feldern dieses Editors, und der Writer würde ein null ohnehin
// als ungültigen Typ ablehnen.
function setCustomValue(action, field, value) {
    action.values ??= {};

    const empty = value === null || value === undefined || value === ''
        || (Array.isArray(value) && value.length === 0);

    if (empty) {
        delete action.values[field.handle];

        return;
    }

    action.values[field.handle] = field.type === 'number' ? Number(value) : value;
}

// --- user assignment ---
// ASSIGNMENT_MODES / ASSIGNMENT_MATCHES are declared at the top of this script,
// not here — see the note there.
const assignmentModeOptions = computed(() =>
    ASSIGNMENT_MODES.map((value) => ({ value, label: __(`workflows::messages.assignment_mode_${value}`) })),
);

const assignmentMatchOptions = computed(() =>
    ASSIGNMENT_MATCHES.map((value) => ({ value, label: __(`workflows::messages.assignment_match_${value}`) })),
);

// The criterion dropdown. Labels come from the server already translated: a
// project criterion's label lives in config/workflows.php, so the client has no
// key to build one from.
const assignmentCriterionOptions = computed(() =>
    props.assignmentCriteria.map(({ value, label }) => ({ value, label })),
);

function criterionDefinition(criterion) {
    return props.assignmentCriteria.find((c) => c.value === criterion?.field) ?? null;
}

// Only the operators this criterion supports — `matches_entry` exists only where
// the criterion names an entry field to compare against, which the built-in
// group/role never do.
function operatorOptionsFor(criterion) {
    const operators = criterionDefinition(criterion)?.operators ?? ['is', 'is_not'];

    return operators.map((value) => ({ value, label: __(`workflows::messages.assignment_operator_${value}`) }));
}

function valueOptionsFor(criterion) {
    return criterionDefinition(criterion)?.options ?? [];
}

// Switching the criterion can invalidate the operator (away from one that had
// `matches_entry`, say). Reset rather than leave a value the writer would reject
// on save — and drop the value with it, since it belonged to the old criterion.
function resetCriterionOperator(criterion) {
    const allowed = criterionDefinition(criterion)?.operators ?? ['is', 'is_not'];

    if (!allowed.includes(criterion.operator)) {
        criterion.operator = allowed[0] ?? 'is';
    }

    criterion.value = null;
}

// The operator is taken from the criterion rather than hard-coded: a criterion
// that only names an entry field does not support `is` at all, and starting a
// new row on an operator its own criterion rejects would fail on save.
function newCriterion() {
    return {
        field: props.assignmentCriteria[0]?.value ?? 'group',
        operator: props.assignmentCriteria[0]?.operators?.[0] ?? 'is',
        value: null,
    };
}

// `holder` ist die Action ODER eine Gruppe — beide tragen eine `criteria`-Liste,
// und für das Hinzufügen ist das derselbe Vorgang. Genau deshalb ist die Gruppe
// im Format kein eigener Typ, sondern ein Eintrag mit eigener Liste.
function addCriterion(holder) {
    holder.criteria ??= [];
    holder.criteria.push(newCriterion());
}

function removeCriterion(holder, index) {
    holder.criteria.splice(index, 1);
}

// Eine Gruppe startet mit einem Kriterium und auf `any` — der Grund, warum man
// eine Gruppe überhaupt anlegt, ist ein ODER innerhalb eines UND. Mit `all` wäre
// sie beim Anlegen wirkungslos und man müsste zweimal klicken, um etwas zu sehen.
function addCriteriaGroup(action) {
    action.criteria ??= [];
    action.criteria.push({ match: 'any', criteria: [newCriterion()] });
}

// --- place actions ---
// Behaviours that apply while a subject sits in a place (PLACE_ACTION_TYPES in
// editor/model/constants.js). The steps an `auto_transition` may take: see
// actionRules.autoTransitionOptions().
function autoTransitionOptions(placeHandle) {
    return rules.autoTransitionOptions(form, placeHandle);
}

const placeActionTypeOptions = computed(() => [
    ...PLACE_ACTION_TYPES.map((value) => ({ value, label: builtInActionLabel('place', value) })),
    ...customPlaceActions.value.map(({ value, label }) => ({ value, label })),
]);

// The DEFAULT start of the flow: the first card, and the transition an explicit
// `start_localization` action pins instead. Mirrors TransitionActions on the
// server — the editor only displays what the server derives. See
// editor/model/actionRules.js.
const startingTransition = computed(() => rules.startingTransition(form));
const explicitStartTransition = computed(() => rules.explicitStartTransition(form));

// `initial_place` is where the workflow begins, and the source version of a
// localization workflow sits there for as long as it IS the source (see
// derivedState() on the server) — so whichever transition creates the target
// version can only ever fire from there. Rather than let those two drift, the
// first card's `from` follows the start place — so reordering picks which
// transition starts the flow, without also redefining where it starts.
//
// Skipped entirely once a `start_localization` action exists: that transition
// owns being-the-start then, the first card goes back to being an ordinary step,
// and forcing ITS `from` to initial_place would be both pointless and wrong. The
// pinned transition's `from` is the admin's own responsibility — the writer
// checks it on save.
//
// Watching the key ORDER rather than the transitions deeply keeps this from
// firing on every keystroke in a label: only reordering, adding, deleting, or a
// changed start place should trigger it. No cycle either — it reads
// `initial_place` and the key order, and writes a `from`.
watch(
    [() => form.initial_place, () => Object.keys(form.transitions).join('|')],
    () => {
        if (explicitStartTransition.value) return;

        const first = startingTransition.value;

        if (first && form.initial_place) {
            form.transitions[first].from = form.initial_place;
        }
    },
    { immediate: true },
);

// The gating rules for which action types a row may offer live in
// editor/model/actionRules.js; these wrappers bind them to this page's form.
function actionTypeOptionsFor(handle, transition, current) {
    return rules.actionTypeOptionsFor(form, handle, current, modelCtx);
}

// Picks the first still-available type rather than hard-coding one, so a type
// that gating has filtered out (e.g. `plain_language_resubmit` outside the
// plain-language manager) is never proposed. Falls through to `target_missing`
// once the dialog slot is taken, exactly like before this list grew.
// Der Typ kommt aus dem Dropdown, das den Knopf ersetzt hat. Trotzdem gegen die
// Optionsliste geprüft: `wanted` stammt zwar von dort, aber die Liste hängt am
// Zustand der Karte, und ein Klick auf einen Eintrag, der inzwischen ausgeschieden
// ist, soll nichts anlegen, was der Writer beim Speichern ablehnt.
function addAction(handle, transition, wanted = null) {
    transition.metadata.actions ??= [];
    const available = actionTypeOptionsFor(handle, transition, null).map((o) => o.value);
    const type = available.includes(wanted) ? wanted : available[0];
    if (!type) return;
    // A new assignment starts manual and empty — the mode that changes nothing
    // about who is eligible until somebody says so, and the only one an empty
    // criteria list is valid for.
    // `mail_type` nur für die Mail-Action: an einer `user_assignment` wäre es ein
    // Schlüssel ohne Bedeutung, den die Speicher-Whitelist unten ohnehin wegwirft.
    transition.metadata.actions.push({
        type, mode: 'manual', match: 'all', criteria: [], site: null, values: {},
        ...(type === 'mail' ? { mail_type: 'assigned' } : {}),
    });
}

function removeAction(transition, index) {
    transition.metadata.actions.splice(index, 1);
}

function placeActionTypeOptionsFor(place, current) {
    return rules.placeActionTypeOptionsFor(form, place, current, modelCtx);
}

function addPlaceAction(place, wanted = null) {
    place.metadata.actions ??= [];
    const available = placeActionTypeOptionsFor(place, null).map((o) => o.value);
    const type = available.includes(wanted) ? wanted : available[0];
    if (!type) return;
    place.metadata.actions.push({
        type, minutes: type === 'refresh_interval' ? 10 : null, values: {},
        ...(type === 'mail' ? { mail_type: 'assigned' } : {}),
        ...(type === 'reminder' ? { every_days: 7, mail_type: 'reminder' } : {}),
        ...(type === 'auto_transition' ? { after_days: 7, transition: null } : {}),
    });
}

// Switching an existing row to `reminder` must bring the same defaults a new
// row gets from addPlaceAction() — otherwise it saves without `every_days`.
function onPlaceActionTypeChange(action, type) {
    if (type === 'auto_transition' && !(Number(action.after_days) >= 1)) {
        action.after_days = 7;
    }
    if (type !== 'reminder') return;
    if (!Number.isFinite(Number(action.every_days)) || action.every_days === null || action.every_days === '') {
        action.every_days = 7;
    }
    if (!action.mail_type || action.mail_type === 'assigned') {
        action.mail_type = 'reminder';
    }
}

function removePlaceAction(place, index) {
    place.metadata.actions.splice(index, 1);
}

// Show the override when the auto-detection would fail, or when the user has
// already stored an explicit value (so they can still see and clear it after
// later adding an allow_publish place).
const showSeedPublishedPlaceField = computed(
    () => !hasPublishPlace.value || !!form.metadata.seed_published_place,
);

// --- Transition-Gate: wer den Schritt ausführen darf ---
// Formular-Felder, keine YAML-Schlüssel: gespeichert wird weiterhin
// `required_group` bzw. `required_role`, weil canApplyTransition() genau die
// liest. normalize() leitet die beiden hier ab, save() schreibt sie zurück.
const requiredByOptions = computed(() => [
    { value: 'none', label: __('workflows::messages.required_by_none') },
    { value: 'group', label: __('workflows::messages.assignment_criterion_group') },
    { value: 'role', label: __('workflows::messages.assignment_criterion_role') },
]);

// „Alle Zuständigen" ist der Standard, und zwar überall, wo nichts anderes
// dasteht — normalize() und addTransition() setzen den Wert, aber eine Transition
// aus einer Quelle, die beide umgeht, darf nicht mit leerem Dropdown und
// sichtbarer Handle-Liste dastehen.
function requiredBy(transition) {
    return transition.metadata?.required_by ?? 'none';
}

function requiredHandleOptions(transition) {
    return requiredBy(transition) === 'role' ? props.roleOptions : props.groupOptions;
}

// Gruppen- und Rollen-Handles sind nicht austauschbar; beim Umschalten stehen zu
// bleiben hieße, Werte zu behalten, die in der neuen Liste nichts bedeuten.
function resetRequiredHandles(transition) {
    transition.metadata.required_handles = [];
}

// The source-view rules of a localization workflow (metadata.translation_source_view,
// see Workflow\SourceViewRules). Modes and audience types are the server's constants,
// spelled out here because the editor is the only place that shows them as words.
const sourceViewModeOptions = computed(() => [
    { value: 'hidden', label: __('workflows::messages.translation_source_view_mode_hidden') },
    { value: 'collapsed', label: __('workflows::messages.translation_source_view_mode_collapsed') },
]);

const sourceViewByOptions = computed(() => [
    { value: 'group', label: __('workflows::messages.assignment_criterion_group') },
    { value: 'role', label: __('workflows::messages.assignment_criterion_role') },
    { value: 'user', label: __('workflows::messages.translation_source_view_by_user') },
]);

// Same audience types as the source-view rules, plus "keep" as the empty choice —
// an ageing stage without its own responsibility is the default, not an error.
const slaAssignByOptions = computed(() => [
    { value: null, label: __('workflows::messages.sla_assign_keep') },
    ...sourceViewByOptions.value,
]);

function sourceViewHandleOptions(rule) {
    return { group: props.groupOptions, role: props.roleOptions, user: props.userOptions }[rule.by] ?? [];
}

// The strictest mode and the broadest audience type as the starting point: a new
// rule is most often "these people see the translation only".
function addSourceViewRule() {
    form.metadata.translation_source_view.push({ mode: 'hidden', by: 'group', handles: [] });
}

// Label wie in der Places-Tabelle: der Handle ist das, was in der YAML steht und
// wonach der Autor sucht; das Label daneben ist die Beschriftung, die er kennt.
// The user groups, plus a stored handle that matches none of them — a group that
// was renamed or deleted. Listed rather than dropped, so opening and saving the
// settings does not silently clear it; the author sees it and can pick another.
const defaultAssigneeGroupOptions = computed(() => {
    const current = form.metadata.default_assignee_group;
    const options = props.groupOptions ?? [];
    return current && !options.some((o) => o.value === current)
        ? [...options, { value: current, label: current }]
        : options;
});

const placeSelectOptions = computed(() =>
    Object.keys(form.places || {}).map((handle) => ({
        value: handle,
        label: `${placeDisplayName(handle)} (${handle})`,
    })),
);

// --- SLA: welcher eigene Schritt wann angesteuert wird ---
// Die Alterungsstufen sind Places dieses Workflows, keine injizierten Sonderfälle
// mehr. Deshalb kommen die Optionen aus `form.places` — was hier auswählbar ist,
// ist genau das, was in der Places-Tabelle darunter steht, inklusive gerade
// umbenannter Handles. Leere Option, weil eine Stufe abschaltbar sein muss.
const slaPlaceOptions = computed(() => [
    { value: null, label: __('workflows::messages.sla_place_none') },
    ...placeSelectOptions.value,
]);

// Zurückfallen darf eine Fassung nur in einen Schritt, der sie offline nimmt —
// dieselbe Regel prüft der Writer. Ein Publish-Place stünde im Widerspruch zur
// Kaskade, die die Fassung gerade auf Entwurf setzt.
const sourceDraftPlaceOptions = computed(() => [
    { value: null, label: __('workflows::messages.source_draft_place_none') },
    ...placeSelectOptions.value.filter(
        (o) => form.places?.[o.value]?.metadata?.allow_publish !== true,
    ),
]);


const migrationTargetOptions = computed(() =>
    Object.keys(form.places)
        .filter((h) => h !== migrationModal.place)
        .map((h) => ({ value: h, label: h })),
);

// Place names — editor/model/labels.js, bound to this page's form and props.
function placeLabelFallback(handle, locale) {
    return labels.placeLabelFallback(form, handle, locale, modelCtx);
}

// The name the CP shows right now, in its own language.
function placeDisplayName(handle) {
    return labels.placeDisplayName(form, handle, modelCtx);
}

// Tint classes for the transition-variant Select so the dropdown visually
// carries the chosen variant. Statamic's Select forwards `class` to its root
// wrapper; the scoped `.wf-variant-select` styles below turn that into an
// actual coloured trigger. Keep the palette in sync with Statamic's own
// button variants (default = neutral, primary = blue, danger = red, filled
// = solid dark).
function variantTintClasses(variant) {
    switch (variant) {
        case 'primary': return 'wf-variant-primary';
        case 'danger':  return 'wf-variant-danger';
        case 'filled':  return 'wf-variant-filled';
        default:        return 'wf-variant-default';
    }
}


// Reorder a reactive keyed collection (form.places / form.transitions) by
// swapping the given handle with its neighbor. YAML dump respects PHP
// array insertion order, so the persisted definition follows the new order.
function swapKeys(target, handle, direction) {
    const keys = Object.keys(target);
    const i = keys.indexOf(handle);
    const j = direction === 'up' ? i - 1 : i + 1;
    if (i < 0 || j < 0 || j >= keys.length) return;
    [keys[i], keys[j]] = [keys[j], keys[i]];
    const snapshot = {};
    for (const k of keys) snapshot[k] = target[k];
    for (const k of Object.keys(target)) delete target[k];
    Object.assign(target, snapshot);
}

function isFirst(target, handle) {
    return Object.keys(target)[0] === handle;
}

function isLast(target, handle) {
    const keys = Object.keys(target);
    return keys[keys.length - 1] === handle;
}

function movePlaceUp(handle) { swapKeys(form.places, handle, 'up'); }
function movePlaceDown(handle) { swapKeys(form.places, handle, 'down'); }
function moveTransitionUp(handle) { swapKeys(form.transitions, handle, 'up'); }
function moveTransitionDown(handle) { swapKeys(form.transitions, handle, 'down'); }

function isNewPlace(handle) {
    return Object.prototype.hasOwnProperty.call(newPlaceHandles, handle);
}

function isNewTransition(handle) {
    return Object.prototype.hasOwnProperty.call(newTransitionHandles, handle);
}

// Karten-Elemente je Handle, damit addPlace()/addTransition() die neue Karte
// anspringen können. Kein `ref="…"`-Array: bei einem v-for über ein Objekt käme
// nur die Position an, und die verschiebt sich beim Umsortieren.
const placeCards = {};
const transitionCards = {};

function setCardRef(store, handle, el) {
    if (el) {
        store[handle] = el;
    } else {
        delete store[handle];
    }
}

// Nach dem Anlegen steht die neue Karte am Ende einer womöglich langen Liste.
// `nextTick`, weil sie im selben Tick noch nicht gerendert ist.
async function scrollToCard(store, handle) {
    await nextTick();
    store[handle]?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function addPlace() {
    const tmp = `place_${Date.now()}`;
    // Ein neuer Place läuft nicht durch normalize(), also werden die Booleans hier
    // gesetzt — sonst steht die Abzweigungs-Checkbox einer frischen Karte
    // unbestimmt da.
    form.places[tmp] = { labels: emptyLabels(props.labelLocales), legacyLabel: '', metadata: { branch: false } };
    newPlaceHandles[tmp] = '';
    scrollToCard(placeCards, tmp);
}

function addTransition() {
    const tmp = `transition_${Date.now()}`;
    const firstPlace = Object.keys(form.places).find((h) => !isNewPlace(h)) ?? Object.keys(form.places)[0] ?? null;
    form.transitions[tmp] = {
        from: firstPlace,
        to: firstPlace,
        // `required_by` explizit, sonst bindet der Select an undefined und steht
        // leer da — normalize() setzt es nur für Transitions, die vom Server
        // kommen.
        metadata: { label: '', required_by: 'none', required_handles: [] },
    };
    newTransitionHandles[tmp] = '';
    scrollToCard(transitionCards, tmp);
}

function cancelNewPlace(handle) {
    delete form.places[handle];
    delete newPlaceHandles[handle];
    // Any brand-new transition referencing this cancelled place gets rerouted
    // to the first remaining place so the Select doesn't dangle on an id that
    // vanished. Persisted transitions are the user's problem to clean up.
    const firstRemaining = Object.keys(form.places)[0] ?? null;
    replaceReferences(form, 'place', handle, firstRemaining, {
        roles: ['transition_from', 'transition_to', 'initial_place'],
    });
}

function cancelNewTransition(handle) {
    delete form.transitions[handle];
    delete newTransitionHandles[handle];
}

// Renaming a new row takes every reference along — see editor/model/refs.js.
function commitPlaceHandle(oldHandle) {
    commitPlaceHandleIn(form, pendingHandles, oldHandle, modelCtx);
}

function commitTransitionHandle(oldHandle) {
    commitTransitionHandleIn(form, pendingHandles, oldHandle, modelCtx);
}

function deleteTransition(handle) {
    if (!window.confirm(__('workflows::messages.confirm_delete_transition', { handle }))) return;
    delete form.transitions[handle];
    delete newTransitionHandles[handle];
    // A dangling reference would make the definition unsaveable (the writer
    // rejects an unknown transition), so the coupling goes with the step.
    clearTransitionRefs(form, handle);
}

async function deletePlace(handle) {
    const affected = props.stateUsage?.[handle] ?? 0;

    if (affected === 0) {
        if (!window.confirm(__('workflows::messages.confirm_delete_place', { handle }))) return;
        await postDeletePlace(handle, null);
        return;
    }

    migrationModal.place = handle;
    migrationModal.affectedCount = affected;
    migrationModal.target = '';
    migrationModal.open = true;
}

function closeMigrationModal() {
    migrationModal.open = false;
}

async function confirmMigrationDelete() {
    await postDeletePlace(migrationModal.place, migrationModal.target);
    closeMigrationModal();
}

async function postDeletePlace(place, migrateTo) {
    const url = props.deletePlaceUrlTemplate.replace('__PLACE__', encodeURIComponent(place));
    try {
        const { data } = await axios.post(url, migrateTo ? { migrate_to: migrateTo } : {});
        Statamic.$toast.success(__('workflows::messages.place_deleted', { place }));
        applyServerDefinition(data.data.definition);
        delete props.stateUsage[place];
    } catch (e) {
        Statamic.$toast.error(e.response?.data?.message || __('workflows::messages.place_delete_error'));
    }
}

function closeCollectionRemovalModal() {
    collectionRemovalModal.open = false;
}

// The user acknowledged the purge — replay the same save with the confirmation
// flag so the server goes through with it.
async function confirmCollectionRemoval() {
    await save({ confirmCollectionRemoval: true });
    closeCollectionRemovalModal();
}

async function save({ confirmCollectionRemoval = false } = {}) {
    // Resolve any not-yet-committed new-row handles first: `Date.now()`-tmp
    // keys must be replaced by either the user-typed handle or an
    // auto-slug from the label BEFORE we serialize the payload — otherwise
    // the YAML gets a `transition_1754321432` handle no one wanted.
    finalizeNewHandles(form, pendingHandles, modelCtx);

    // Guard: any remaining tmp-handle means the user left both handle and
    // label empty. Abort with a toast rather than persisting a timestamp.
    if (danglingHandles(pendingHandles).length) {
        Statamic.$toast.error(__('workflows::messages.settings_dangling_handles'));
        return;
    }

    saving.value = true;
    try {
        // The cleanup (SLA pairs, cleared Selects, labels, action whitelists) is
        // editor/model/serialize.js — the same function the round-trip tests run.
        const payload = {
            ...serialize(form, modelCtx),
            confirm_collection_removal: confirmCollectionRemoval,
        };
        const { data } = await axios.post(props.saveUrl, payload);
        Statamic.$toast.success(__('workflows::messages.settings_saved'));
        // Adding a collection backfills states for its existing entries — say how
        // many, so a silent bulk write doesn't go unnoticed.
        if (data.data.seeded > 0) {
            Statamic.$toast.success(__('workflows::messages.states_seeded', { count: data.data.seeded }));
        }
        applyServerDefinition(data.data.definition);
        // A purge changes how many entries sit in each place — without this the
        // next place deletion would prompt against stale counts.
        applyStateUsage(data.data.state_usage);
    } catch (e) {
        // Dropping a collection needs an explicit confirmation. The server says
        // so with a 422 carrying `needs_collection_confirmation` — that's a
        // prompt, not a failure, so no error toast and no console noise.
        if (e?.response?.data?.needs_collection_confirmation) {
            collectionRemovalModal.collections = e.response.data.removed_collections ?? [];
            collectionRemovalModal.affectedCount = e.response.data.affected_count ?? 0;
            collectionRemovalModal.open = true;
            saving.value = false;

            return;
        }

        // Loud console output so the user can copy the concrete failure into a
        // bug report. The toast stays terse; the console gives us status +
        // response body, which is the level of detail we actually need to fix
        // save failures (CSRF/419, validation/422, guard/403, PHP/500, etc.).
        console.error('[workflows] settings save failed', {
            status: e?.response?.status,
            statusText: e?.response?.statusText,
            body: e?.response?.data,
            payload: {
                places: Object.keys(form.places),
                transitions: Object.keys(form.transitions),
                initial_place: form.initial_place,
            },
            err: e,
        });
        Statamic.$toast.error(
            e?.response?.data?.message
            || (e?.response?.status ? `${e.response.status} ${e.response.statusText || ''}`.trim() : null)
            || __('workflows::messages.settings_save_error'),
        );
    } finally {
        saving.value = false;
    }
}

// Replace the per-place entry counts in place. `stateUsage` is a reactive Inertia
// prop the editor mutates directly (see postDeletePlace), so it's reassigned key
// by key rather than swapped out.
function applyStateUsage(usage) {
    if (!usage) return;

    for (const key of Object.keys(props.stateUsage)) delete props.stateUsage[key];
    Object.assign(props.stateUsage, usage);
}

function applyServerDefinition(def) {
    const fresh = normalize(def, modelCtx);
    // Server response has canonical handles now — everything is "persisted",
    // so no row should remain flagged as new.
    for (const key of Object.keys(newPlaceHandles)) delete newPlaceHandles[key];
    for (const key of Object.keys(newTransitionHandles)) delete newTransitionHandles[key];
    Object.assign(form, fresh);
}
</script>

<style>
/* Tint the transition-variant Select so its trigger visually carries the
   chosen button variant. Statamic's Select forwards `class` to its root; we
   push the palette into whichever inner element renders the visible trigger
   (button-styled, combobox, or plain input) via a broad descendant selector
   so the styling holds regardless of the CP version's internals. */
.wf-variant-select > button,
.wf-variant-select [role="combobox"],
.wf-variant-select [data-ui-select-trigger],
.wf-variant-select input {
    background-color: var(--wf-variant-bg, transparent) !important;
    border-color: var(--wf-variant-border, currentColor) !important;
    color: var(--wf-variant-fg, inherit) !important;
}

.wf-variant-default { --wf-variant-bg: rgb(243 244 246); --wf-variant-border: rgb(209 213 219); --wf-variant-fg: rgb(31 41 55); }
.wf-variant-primary { --wf-variant-bg: rgb(219 234 254); --wf-variant-border: rgb(96 165 250);  --wf-variant-fg: rgb(30 64 175); }
.wf-variant-danger  { --wf-variant-bg: rgb(254 226 226); --wf-variant-border: rgb(248 113 113); --wf-variant-fg: rgb(153 27 27); }
.wf-variant-filled  { --wf-variant-bg: rgb(31 41 55);    --wf-variant-border: rgb(17 24 39);    --wf-variant-fg: rgb(249 250 251); }

</style>
