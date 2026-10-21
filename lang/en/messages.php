<?php

return [
    // Chrome
    'back' => 'Back',
    'edit' => 'Edit',
    // "Live preview" button of the workflow views; the two hints are tooltips,
    // depending on whether a working copy is shown.
    'live_preview' => 'Live preview',
    'live_preview_hint' => 'Opens the page in its own window – even while it is still unpublished.',
    'live_preview_pending_hint' => 'Shows the pending unpublished changes (working copy) in its own window.',
    'live_page' => 'Live page',
    'live_page_hint' => 'Opens the published page in its own window.',
    'versions' => 'Translations',
    'content' => 'Content',
    'no_actions' => 'No actions available.',
    'history' => 'History',
    'history_open' => 'Show history',
    'no_history' => 'No history yet.',
    'comments' => 'Comments',
    'new' => 'new',
    'delete' => 'Delete',
    'no_comments' => 'No comments yet.',
    // Tooltip of the `+N` marker in the index (per site column or on the title).
    'new_comments_hint' => 'New comments you have not read yet',
    'write_comment' => 'Write a comment…',
    'save' => 'Save',
    'delete_comment_confirm' => 'Delete this comment?',
    'comment_saved' => 'Comment saved',
    'comment_deleted' => 'Comment deleted',
    'comment_save_error' => 'Could not save comment.',
    'comment_delete_error' => 'Could not delete comment.',
    'transition_error' => 'Could not apply transition.',
    'transition_not_allowed' => 'Transition not allowed from the current state.',
    'actions_load_error' => 'Could not load workflow actions.',
    'save_first_failed' => 'Could not save entry. Transition aborted.',
    'event_note_created' => 'Entry created.',
    'event_note_localized' => 'Localized (:site).',
    'event_note_set_state' => 'State set manually.',
    // Logged per save that actually changed something. `draft_saved` covers drafts
    // and working copies; `entry_saved` only happens on a collection without
    // revisions, where a save writes straight to the live entry.
    'event_note_draft_saved' => 'Draft saved.',
    'event_note_entry_saved' => 'Entry saved.',
    'event_note_revision_created' => 'Working copy created.',
    'event_note_revision_published' => 'Working copy published.',
    'event_note_source_draft' => 'The entry is in draft — this version followed it.',
    'event_note_sla_aged' => 'Not updated for :days days.',
    'event_note_reminder_sent' => 'Reminder sent — in this state for :days days.',
    'history_event_reminder_sent' => 'Reminder sent',
    'state_since' => 'In state ":state" since :date',
    'event_note_auto_transition' => '{1} Applied automatically — the one-day deadline passed.|[2,*] Applied automatically — the :days-day deadline passed.',
    'event_note_auto_transition_undone' => 'Automatic transition [:transition] undone.',
    'event_actor_system' => 'System',
    'event_actor_current_user' => 'Current user',
    'history_show_diff' => 'Show changes',
    'history_hide_diff' => 'Hide changes',
    'history_event_comment_created' => 'commented',
    'history_comment_deleted' => 'Comment removed',
    'comment_content_stale' => 'Refers to an older version of this passage',
    'comment_container_changed' => 'Something in this area changed after the comment was written',
    'history_vs_previous' => 'vs. previous step',
    'history_vs_original' => 'vs. original',
    'history_no_diff_data' => 'No diff data available (snapshot missing).',
    'history_no_comparison' => 'No previous step to compare with.',
    'history_no_changes' => 'No field changes.',
    'loading' => 'Loading …',
    'change_relationship_modified' => 'Relationships changed.',
    'change_relationship_count' => ':from → :to linked items.',
    'change_bool_true' => 'Enabled',
    'change_bool_false' => 'Disabled',
    'change_published_true' => 'Published',
    'change_published_false' => 'Not published',
    'change_value_modified' => 'Value changed.',

    // Labels for fields with no blueprint entry. Statamic keeps slug, date and
    // the published flag outside the blueprint data, so they have no `display`
    // for the diff to read. The `field_<handle>` key works for any field.
    'field_slug' => 'Slug',
    'field_date' => 'Date',
    'field_published' => 'Publication',
    'requires_group' => 'Requires group: :group',
    'requires_assignee' => 'Only assigned users may perform this action.',
    'requires_site_access' => 'No access to the “:site” site — the “access :handle site” permission is missing.',
    'assignee_required' => 'Please pick a person.',
    'assignee_invalid' => 'This user does not meet the assignment criteria.',
    'assignment_no_candidates' => 'Nobody meets this transition’s assignment criteria.',
    'pick_assignee_title' => 'Assign to person',
    'pick_assignee_prompt' => 'Pick who this entry should be handed to next. More than one is allowed.',
    'pick_assignee_count' => ':count selected',
    'confirm' => 'Confirm',
    'open_where_prompt' => 'Where would you like to go?',
    'open_workflow_summary' => 'Workflow summary',
    'open_entry_editor' => 'Edit entry',

    // Translation manager
    'source' => 'Source',
    'translation_target' => 'Translation',
    // The show/hide button of the source column when a rule starts it collapsed
    // (metadata.translation_source_view, mode `collapsed`).
    'source_show' => 'Show source',
    'source_hide' => 'Hide source',
    // Replaces the content block while TransitionActions::PENDING_REWRITE_SPINNER
    // is active and this version has an open job.
    'pending_rewrite_spinner' => 'Rewriting into plain language …',
    'pending_rewrite_attempt' => 'Attempt :attempt of :max',
    'pending_rewrite_retry_started' => 'That attempt failed — attempt :attempt of :max has been started.',
    'transfer' => 'Move to another site',
    'transfer_title' => 'Move to another site',
    'transferred' => 'Transferred to site(s)',
    'transfer_error' => 'Could not transfer.',
    'no_target_sites' => 'No valid target site selected.',
    'target_site_required' => 'Please pick a target language.',
    'target_site_invalid' => 'The picked target language is not available.',
    // The fixed-target path (plain-language manager) has no dialog in front of it
    // to hide taken sites, so the button's reason says what the list would have.
    'target_site_taken' => 'A version in :site already exists.',
    // A different cause than target_site_taken: the site exists (validated) but is
    // not a reachable target for THIS post right now — not part of its collection,
    // claimed by another manager, or its own source. No version exists, so this
    // never claims one does.
    'target_site_unavailable' => ':site is not a reachable target for this post (not part of the collection, claimed elsewhere, or its own source).',
    // Exactly one version per post starts a translation: the one this manager reads
    // from. With the source set to plain language, the German version is no longer
    // this manager's business.
    'transition_start_at_source' => 'Translations start at the source version (:site).',
    // Nothing is translated out of unfinished work: the source has to sit in the last
    // place of its own workflow. Two wordings, because there are two cases — a
    // workflow that owns the version, or nothing but the published flag.
    'translation_source_not_ready' => 'The source in :site is not finished yet — it sits in “:state”, and “:required” is required.',
    'translation_source_unpublished' => 'The source in :site is not published.',
    'translation_source_none_ready' => 'No version of this post is finished — there is nothing to translate from.',
    // No state in this workflow: an unpublished post is not part of a localization
    // manager yet (see WorkflowResolver::derivedState).
    'workflow_not_started' => 'This post is not published yet, so it is not part of this workflow.',
    // "Rewrite again" is offered on the source version and on the plain-language
    // version, and always acts on the latter. Anywhere else it would be meaningless
    // or overwrite the wrong version.
    'transition_resubmit_wrong_version' => 'Rewriting works on the :source or the :target version.',
    'transition_resubmit_no_target' => 'There is no :site version yet that could be rewritten.',
    'transition_resubmit_state' => 'The :site version is in “:state” — this step does not lead out of there.',
    'translation_plain_no_site' => 'This workflow has no plain-language target site.',
    'translation_source_missing' => 'This post has no version in :site — there is nothing to translate from.',
    'translation_plain_job_running' => 'A plain-language rewrite is still running for this post.',
    'transition_site_pick_title' => 'Translate into which language?',
    'transition_site_pick_source_label' => 'Source site',
    'transition_site_pick_target_label' => 'Target language',
    'transition_site_pick_confirm' => 'Start translation',
    'transition_site_pick_no_targets' => 'All available sites already have a localization.',

    // Machine translation. It runs to completion BEFORE the localization is
    // created, so every one of these errors also means no entry was made.
    'translation_running' => 'Translating — this may take a moment.',
    'translation_not_configured' => 'No DeepL API key configured (DEEPL_API_KEY in .env).',
    'translation_unreachable' => 'The translation service could not be reached.',
    'translation_error_timeout' => 'The translation took too long and was stopped — no entry was created.',
    'translation_bad_response' => 'The translation service returned an unexpected response — nothing was written.',
    'translation_error_auth' => 'The DeepL API key was rejected.',
    'translation_error_rate_limit' => 'Too many requests to DeepL. Please wait a moment and try again.',
    'translation_error_quota' => 'The DeepL quota is used up.',
    'translation_error_request' => 'DeepL rejected the request.',
    'translation_error_generic' => 'The translation service answered with status :status.',
    // Plain language does not go through DeepL but through the project's own
    // endpoint — its own messages, so nobody checks the wrong credential.
    'translation_plain_not_configured' => 'No plain-language endpoint configured (PLAIN_LANGUAGE_URL and PLAIN_LANGUAGE_TOKEN in .env).',
    'translation_plain_error_auth' => 'The plain-language token was rejected (PLAIN_LANGUAGE_TOKEN).',
    'translation_plain_error_rate_limit' => 'Too many requests to the plain-language service. Please wait a moment and try again.',
    'translation_plain_error_request' => 'The plain-language service rejected the request.',
    'translation_plain_job_written' => 'Plain language: :count segment(s) applied.',
    'translation_plain_job_written_moved' => 'Plain language: :count segment(s) applied, automatically moved to :place.',
    // Cause fragments, deliberately lower-case and without a closing period: they are
    // dropped into two frames — the retry note ("attempt 1 of 3") and the terminal alert
    // (translation_plain_failed_reason). So they carry neither "given up" nor an attempt
    // count, which would be wrong in the first. The job id is deliberately absent: it
    // means nothing to an editor and is in the log anyway, next to payload and response.
    'translation_plain_job_failed_unknown' => 'the service reported a failure without a reason',
    'translation_plain_job_lost' => 'the service no longer knows the job',
    'translation_plain_job_bad_output' => 'the service returned unusable output',
    'translation_plain_job_timed_out' => 'the job was still unfinished after :polls polls',
    'translation_plain_job_endpoint_error' => 'the service answered with an error (HTTP :status)',
    'translation_plain_job_no_segments' => 'the source has no translatable text',
    'translation_plain_job_source_missing' => 'the source version is missing',
    'translation_plain_job_source_unpublished' => 'the source is not published',
    'translation_plain_job_unexpected' => 'an unexpected error occurred',
    'translation_plain_job_resubmit_refused' => 'the re-submission was refused',

    // The two frames around a cause fragment.
    'translation_plain_job_retry' => 'Attempt :attempt of :max failed: :reason. Sent to the service again.',
    'translation_plain_failed_reason' => 'Translation failed: :reason. Given up after one attempt.|Translation failed: :reason. Given up after :count attempts.',
    'translation_plain_failed_toast' => 'The post “:title” could not be converted into plain language.',
    'translation_plain_qs_notes' => 'Plain-language service QA report:',
    // Button + stack of the QA report on the translation page.
    'qs_notes' => 'QA notes',
    'qs_notes_intro' => 'The plain-language service’s review: what was simplified, added and left out.',
    'qs_notes_highlight_title' => 'New review report — please read before approving.',
    // The job is out, the texts arrive minutes later — say so instead of implying
    // the version is already finished.
    'translation_plain_submitted' => 'Sent to the plain-language service — the texts will appear in a few minutes.',
    'translation_warning_no_blueprint' => 'No blueprint found — no field could be inspected.',
    'translation_warning_not_localizable' => 'Field ":field" is not localizable in the blueprint — the translation would be invisible in the editor.',
    'translation_warning_render_failed' => ':path could not be rendered to HTML and was skipped.',
    'translation_warning_parse_failed' => ':path could not be parsed back to ProseMirror — kept the original.',
    'translation_warning_empty_result' => ':path came back empty — kept the original.',
    'translation_warning_structure_changed' => ':path came back with a different block structure — the new nodes were used.',
    'set_state' => 'Set status manually (admin)',
    'set' => 'Set',
    'state_set' => 'Status updated',
    'state_set_error' => 'Could not set status.',
    'invalid_state' => 'Invalid state.',
    'applied' => 'Applied: :action',
    'demo_note' => '(demo – not persisted)',
    'open_workflow_detail' => 'Open workflow detail',
    'my_queue' => 'My Queue',
    'my_created' => 'Created by me',
    'widget_assigned_title' => 'My posts',
    'widget_open_manager' => 'Open post manager',
    // A separate, shorter text for the foot of the WorkflowStatus stacks only:
    // the link sits next to the pager there and has to fit a narrow line.
    'widget_stack_open_manager' => 'Open manager',
    'widget_no_assigned' => 'No assigned posts',
    'widget_no_created' => 'No posts created by you',
    // Watching: a personal bookmark for the "Watched" dashboard card.
    'watch' => 'Watch',
    'watching' => 'Watching',
    'unwatch' => 'Stop watching',
    'watch_error' => 'Could not change watching.',
    'widget_watched_title' => 'Watched',
    'widget_watched_subline' => 'Last edited',
    'widget_no_watched' => 'You are not watching any posts. Add one with “Watch” on a post’s detail page.',
    'widget_header_groups' => 'Functions',
    'widget_header_no_groups' => 'No functions',
    'widget_header_affiliation' => 'Affiliation',
    'widget_header_responsible' => 'Responsible',
    'widget_tab_all' => 'All',
    'widget_created_subline' => 'Last edited',
    // Carbon supplies the complete phrase ("6 days ago") — see
    // PersonalWorkItems::meta(). The key stays as the seam: a project can wrap
    // the time reference here.
    'widget_meta_since' => ':time',
    'widget_meta_today' => 'today :time',
    // Stat row `workflows_queue_stats` — the four cards above the lists.
    'widget_stats_queue' => 'In my queue',
    'widget_stats_review' => 'Awaiting approval',
    // Named, not just "Failed": the card counts one specific thing — versions
    // parked in a workflow's `plain_language_failed_place`.
    'widget_stats_failed' => 'Failed translations',
    'widget_stats_published_week' => 'Published this week',
    // A trans_choice key even though English needs no dative here: the German
    // side does, and the two files have to carry the same key shape. See
    // QueueStats::oldestSince().
    'widget_stats_oldest_since' => 'oldest since 1 day|oldest since :count days',
    // Both the note under the failed card and, where the viewer may act, the
    // label of the button that replaces it.
    'widget_stats_restart_hint' => 'restart',
    // Never rendered: the accessible name of the figure that opens the popover.
    // The figure itself is a bare number, which tells a screen reader nothing
    // about being a control.
    'widget_stats_show_posts' => 'Show posts',
    // The retry is a bulk action and may skip what it is not allowed to touch,
    // so it reports what it did rather than just succeeding.
    'widget_stats_retry_done' => ':count restarted',
    'widget_stats_retry_error' => 'Could not restart.',
    // :diff arrives with its sign already attached ("+3", "−2", "±0").
    'widget_stats_vs_last_week' => ':diff vs. last week',
    // The stat row's cards are links: appended for screen readers only, so a
    // card does not just read "Awaiting approval 3" but says where it leads.
    // See QueueStatsWidget.vue.
    'widget_stats_jump_to_queue' => 'jump to “My Queue”',
    'widget_stats_filter_overview' => 'show in “All posts”',
    'widget_stats_open_manager' => 'open in the workflow',
    // The removable chip above "All posts" while a stat card filters the table.
    // :label is the card's label.
    'overview_preset_active' => 'Filtered: :label',
    'overview_preset_clear' => 'Remove filter “:label”',
    // Status row `workflows_status` — one card per active workflow. The line
    // under the workflow name; it sits on every card because the cards may wrap
    // onto a second line, where a heading above the row would only cover some
    // of them.
    'widget_status_subline' => 'Content by status',
    // Under the total in the middle of the ring. Not a trans_choice: the number
    // is right above it, the word only names what is being counted.
    'widget_status_entries' => 'Entries',
    // Instead of an empty legend beside the grey ring: the card of a workflow
    // nothing has entered yet is never hidden, so it should say why it is
    // empty.
    'widget_status_empty' => 'No content yet',
    // When lazily loading the posts behind a ring segment fails. The stack
    // closes again — an empty panel with a title is a promise the widget cannot
    // keep.
    'widget_status_load_error' => 'Could not load posts.',
    'all_posts' => 'All posts',
    // Heading of the big listing in a localization manager: it holds the posts that
    // have a version in the source site — only those can be translated.
    'translatable_posts' => 'Translatable posts',
    'signed_in_as' => 'Signed in as :name',

    // Nav / workflow titles (keyed by the workflow's nav slug)
    'nav' => [
        'post-manager' => 'Post Manager',
        'translation-manager' => 'Translation Manager',
        'plain-language-manager' => 'Plain Language',
        'video-manager' => 'Video Manager',
    ],

    // Columns / filters
    'subject' => 'Title',
    'state' => 'State',
    'site' => 'Site',
    'assignees' => 'Assignees',
    'assignee' => 'Assignee',
    'assigned_to' => 'Responsible:',
    'edit_assignees' => 'Edit assignees',
    'no_assignees' => 'No one assigned',
    'assignees_saved' => 'Assignees saved',
    'assignees_save_error' => 'Could not save assignees.',
    'search_users' => 'Search users…',
    'all_roles' => 'All roles',
    'all_groups' => 'All groups',
    'no_users_found' => 'No users found',
    'more_roles' => '+:count more',
    'more_groups' => '+:count more',

    // Creating a post
    'new_post' => 'New post',
    // Entry in the dashboard widget's "New post" menu, one line per workflow.
    'new_post_in' => ':label: new post',
    'assign_to' => 'Assign to',
    'select_site' => 'Choose sites',
    // Label of the collection select in the "New post" modal when the workflow owns several collections.
    // `:collection` comes from Statamic's own translation (`__('Collection')`), so
    // the wording matches the CP sidenav in every locale Statamic ships.
    'select_collection' => 'Choose a :collection',
    'post_collection_invalid' => 'This workflow cannot create entries in that collection.',
    'cancel' => 'Cancel',
    'post_created' => 'Post created',
    'post_create_error' => 'Could not create post.',
    // Placeholder title of a freshly created post — the editor overwrites it.
    'untitled_post' => 'Untitled',
    // The "New post" modal: the title is required, the confirm button says
    // "Create" — or carries the label of the coupled transition
    // (metadata.create_transition), which runs right after creating.
    'post_title' => 'Title',
    'post_title_required' => 'Please enter a title.',
    'create' => 'Create',
    // Toast when the post was created but the coupled transition could not run —
    // the post then sits in the initial place.
    'create_transition_skipped' => 'Post created, but ":label" did not run: :reason',
    'all_sites' => 'All sites',
    'all_states' => 'All states',
    'all_workflows' => 'All workflows',
    'all_assignees' => 'All assignees',
    'embedded_podcast' => 'Embedded podcast',

    // Workflow states
    'state_idea' => 'Idea',
    'state_draft' => 'Draft',
    'state_in_review' => 'In Review',
    'state_expert_review' => 'Expert Review',
    'state_final_approve' => 'Final Approve',
    'state_korrektorat' => 'Proofreading',
    'state_published' => 'Published',
    'state_pending_revision' => 'Unpublished changes',
    'revision_status' => 'Revision status',
    'revision_status_current' => 'No pending changes',
    'state_source_ready' => 'Source Ready',
    'state_in_translation' => 'In Translation',
    'state_linguistic_review' => 'Linguistic Review',
    'state_approved' => 'Approved',
    // Plain language. Distinct handles, because place labels and colours resolve
    // globally per handle (first-wins): shared handles would bleed the two
    // managers' labels into each other.
    // No state: the post is not part of this workflow yet.
    'state_none' => 'Not started',
    'state_plain_ready' => 'Source Ready',
    'state_in_plain_language' => 'Translation Draft',
    'state_plain_review' => 'Subject Review',
    'state_plain_approved' => 'Approved',
    'state_queued' => 'Queued',
    'state_script_generation' => 'Script Generation',
    'state_voiceover' => 'Voiceover',
    'state_rendering' => 'Rendering',
    'state_review' => 'Review',
    'state_done' => 'Done',
    'state_failed' => 'Failed',

    // Workflow actions (transition labels). Handles match transition names
    // across all workflow YAMLs; unknown ones fall back to the YAML label.
    'action_start_draft' => 'Start drafting',
    'action_submit' => 'Submit for review',
    'action_request_expert' => 'Send to expert',
    'action_approve_expert' => 'Expert approve',
    'action_final_approve' => 'Final approve',
    'action_publish' => 'Publish',
    'action_reject' => 'Reject',
    'action_start_translation' => 'Start translation',
    'action_submit_translation' => 'Submit translation',
    'action_pass_linguistic' => 'Pass linguistic review',
    'action_approve' => 'Approve',
    'action_needs_changes' => 'Needs changes',
    'action_start_plain_language' => 'Create plain language',
    'action_regenerate_plain_language' => 'Rewrite plain language',
    'action_submit_plain_language' => 'Submit for subject review',
    'action_approve_plain_language' => 'Approve',
    'action_needs_changes_plain' => 'Needs changes',
    'action_start_generation' => 'Start generation',
    'action_generate_voiceover' => 'Generate voiceover',
    'action_render' => 'Render',
    'action_submit_for_review' => 'Submit for human review',
    'action_fail' => 'Pipeline failed',

    'source_changed' => 'The source has been revised',
    'source_changed_hint' => 'The source is from :source_at, this version from :version_at. The content here is no longer the current state.',
    'source_changed_hint_never' => 'The source is from :source_at. This version has never been approved, so its content is not the current state.',
    'column_content_updated_at' => 'Content updated',
    // Column and filter of the cross-workflow listing in the dashboard widget.
    'column_workflow' => 'Workflow',
    'content_updated_at_instructions' => 'The ageing deadline counts from here. Set automatically whenever the entry is approved — editable by hand for super admins only.',
    'stale_marker' => 'out of date',
    'stale_marker_hint' => 'The entry has been revised since this version.',
    'filter_stale' => 'Up to date',
    'filter_stale_any' => 'All',
    'filter_stale_only' => 'Only with an out-of-date version',

    // SLA / aging.
    // The two stages are the workflow's own steps: the author writes the place,
    // the transitions, the colour and the assignment. All that is configured here
    // is after how many days which of their steps is aimed for.
    'sla' => 'SLA (aging of published entries)',
    'sla_intro' => 'Moves an entry on into a step of your own once the age threshold is reached. Runs hourly via the Laravel scheduler (php artisan schedule:run must be on cron).',
    'sla_needs_update_days' => 'First stage after X days',
    'sla_needs_update_place' => 'First stage: step',
    'sla_outdated_days' => 'Second stage after X days',
    'sla_outdated_place' => 'Second stage: step',
    'sla_needs_update_assign' => 'First stage: responsible',
    'sla_outdated_assign' => 'Second stage: responsible',
    'sla_assign_keep' => 'Unchanged',
    'sla_assign_handles_none' => 'Choose …',
    'sla_placeholder_disabled' => 'empty = disabled',
    'sla_place_none' => 'No step (stage off)',
    'sla_hint' => '365 ≈ 12 months, 540 ≈ 18 months, 0 = next scheduler tick. Days and step belong together: a stage is only active with both.',
    'sla_place_hint' => 'If a transition leads there, it runs — with its assignment, its mail and its permission. Without one, the entry is moved straight in and the step\'s actions run. Without its own responsibility, the current assignees stay. A step without “allows publishing" puts the entry back to draft, and the translations then follow (see below).',
    'sla_clock_hint' => 'The deadline runs from when the entry was last approved — not from the last save.',
    'sla_cron_hint' => 'Requires "php artisan schedule:run" on cron in the host project (see README → Scheduler).',

    // How a version reacts to its source sitting in draft.
    'source_draft' => 'When the source is in draft',
    'source_draft_intro' => 'When the entry goes to draft — through aging or by hand — this version follows: to draft, and back into the step you pick.',
    'source_draft_place' => 'Back into step',
    'source_draft_place_none' => 'Do nothing',
    'source_draft_hint' => 'Only steps without “allows publishing" — the version goes offline. It does not come back on its own: it runs its own way forward again.',

    // Settings editor (super-admin only)
    'settings' => 'Settings',
    'queue' => 'Queue',
    'collections' => 'Collections',
    'settings_index_title' => 'Workflows',
    'handle' => 'Handle',
    'settings_index_intro' => 'Every workflow defined in this project. Click one to edit its places, transitions, and metadata.',
    'settings_index_empty' => 'No workflows yet. Add a YAML under resources/workflows/ or run php artisan workflows:install to start.',
    
    // Long-form description of the shipped examples in the create modal, keyed by
    // type handle. Falls back to the YAML's `metadata.label` for examples a project
    // adds itself, which have no key here.
    'install_description_postmanager' => 'Post update with expert sign-off',
    'install_description_translation' => 'Translation with linguistic and expert review',
    'install_description_plainlanguage' => 'Plain language with subject review',
    'install_collections_label' => 'Collections',
    'install_no_collections' => 'None selected',
    'install_no_project_collections' => 'This project has no Statamic collections yet. Create some and come back here.',
    'install_no_types' => 'No workflow type shipped in the addon.',
    'install_unknown_type' => 'Workflow type [:type] is not available.',

    // Create a workflow (settings index, workflows:make — WorkflowScaffolder)
    'create_workflow' => 'Create workflow',
    'create_success' => 'Workflow created. It is still inactive.',
    'create_error' => 'Could not create workflow.',
    'create_handle_invalid' => 'Invalid handle [:handle] — lowercase letters, digits and underscores, starting with a letter, at most :max characters.',
    'create_handle_reserved' => 'The handle [:handle] is reserved — it is part of the settings URLs.',
    'create_handle_taken' => 'A workflow [:handle] already exists (case is ignored).',
    'create_label_too_long' => 'The name may be at most :max characters long.',
    'create_from_invalid' => 'Unknown source — use blank, example or copy.',
    'create_copy_unknown' => 'Workflow [:workflow] does not exist and cannot be copied.',
    'create_collections_invalid' => 'Collections must be sent as a list.',
    'create_source_blank' => 'blank',
    'create_source_example' => 'from example [:template]',
    'create_source_copy' => 'as a copy of [:workflow]',
    'create_inactive_hint' => 'New workflows start inactive: review first, then switch on. Existing entries can then be taken over with “Backfill states”.',
    'event_note_workflow_created' => 'Workflow [:workflow] created (:source).',

    // Per-workflow on/off and delete (settings index)
    'status' => 'Status',
    'workflow_active' => 'Active',
    'workflow_inactive' => 'Inactive',
    'workflow_activate' => 'Activate',
    'workflow_deactivate' => 'Deactivate',
    'workflow_activated' => 'Workflow activated.',
    'workflow_deactivated' => 'Workflow deactivated.',
    'workflow_active_error' => 'Could not change the status.',
    'workflow_deactivate_hint' => 'An inactive workflow disappears from the nav, the manager pages and the entry editor. Its definition and history are kept.',
    // Per-workflow state backfill (CP counterpart of workflows:seed-states)
    'seed_states' => 'Backfill states',
    'states_seeded_none' => 'Every entry already has a state.',
    'states_seed_error' => 'Could not backfill states.',
    'states_seed_inactive' => 'This workflow is deactivated — activate it before backfilling states.',
    'states_seeded_no_done_place' => 'This workflow has no finished place, so every entry landed in the initial state. Set metadata.seed_published_place in its settings.',

    'workflow_delete' => 'Delete',
    'workflow_delete_title' => 'Delete workflow',
    'workflow_delete_confirm' => 'The definition of “:workflow” will be removed from resources/workflows/. States, assignees, history and comments stay in the database — reinstalling brings them back.',
    'workflow_deleted' => 'Workflow deleted.',
    'workflow_delete_error' => 'Could not delete workflow.',
    'settings_saved' => 'Workflow saved.',
    'settings_dangling_handles' => 'Please give each new place/transition either a handle or a label before saving.',
    'settings_save_error' => 'Could not save workflow.',
    'settings_version_conflict' => 'The workflow has changed in the meantime – by someone else or directly in its file. Reload to see the current version, or overwrite it deliberately.',
    'metadata' => 'Metadata',
    'nav_label' => 'Nav label',
    'nav_label_hint' => 'Used exactly as entered, in every CP language. Leave empty to take the title from the translations (key: nav.<slug>) and follow the CP language.',
    'nav_icon' => 'Nav icon',
    'nav_order' => 'Nav order',
    
    // Field labels in the definition editor. They mirror the YAML structure but
    // carry a readable title instead of the raw key name.
    'settings_field_nav_slug' => 'Nav slug',
    'settings_field_default_assignee_group' => 'Default assignee group',
    'default_assignee_group_none' => 'None',
    'settings_field_assign_creator' => 'Assign creator automatically',
    'settings_field_initial_place' => 'Initial place',
    // Orientation of the progress bar on the detail page. Decides both the bar's
    // layout AND its position — horizontal above the content, vertical in a column
    // to its left. See Workflow\ProgressSteps::ORIENTATION.
    'settings_field_progress_orientation' => 'Progress bar',
    'settings_field_progress_orientation_hint' => 'Sets orientation and position: horizontal puts the bar above the summary content, vertical in a column to its left. On narrow windows it always runs vertically.',
    'progress_orientation_horizontal' => 'Horizontal (above the content)',
    'progress_orientation_vertical' => 'Vertical (left of the content)',
    'settings_field_color' => 'Colour',
    'settings_field_allow_publish' => 'Allows publishing',
    // Presentation only: the place is not drawn as a step on the summary's
    // progress bar but offset from its predecessor — and only while an entry
    // actually sits in it. See ProgressSteps.
    'settings_field_branch' => 'Side branch',
    'settings_field_branch_hint' => 'Not a step on the way but a turn-off from it (a failure state, for instance). Drawn offset from the preceding step, and only while an entry sits in it.',
    'settings_field_from' => 'From',
    'settings_field_to' => 'To',
    'settings_field_variant' => 'Button variant',
    'places' => 'Places',
    'no_places' => 'No places defined.',
    'add_place' => 'Add place',
    'move_up' => 'Move up',
    'move_down' => 'Move down',
    'color_default' => 'Default',
    'required_group_none' => 'No group required',
    'required_by' => 'May perform this step',
    'required_by_none' => 'Anyone responsible',
    'required_by_hint' => 'On top of being responsible.',
    'handle_placeholder' => 'e.g. draft, review',
    'label_placeholder' => 'Display name',
    'transitions' => 'Transitions',

    // A transition's actions: steps between the click and applying it. Two known
    // types open a dialog, and only one of those fits in that moment —
    // `target_missing` opens nothing, it's a silent gate, and does not count.
    'action_add' => 'Add action',
    'from_follows_initial_place' => 'Follows the start place — the first transition is the one leading out of it.',
    'action_type_user_assignment' => 'User assignment',
    'action_type_target_missing' => 'Gate: site must still be missing',
    'action_target_missing_site_none' => 'Pick a site',
    // No dialog, no config field — its mere presence says "this transition creates
    // the target version". At most one per workflow, selectable in any
    // localization workflow. In the translation manager the language dialog
    // follows it: without the action it sits on the first transition, with it on
    // the chosen one.
    'action_type_start_localization' => 'Creates the target version (with language picker)',
    // Also no config, but no count limit either — it can sit on several
    // transitions at once (out of review, out of approval). Only selectable in
    // the plain-language manager, see canResubmitPlainLanguage().
    'action_type_plain_language_resubmit' => 'Rewrite plain language',
    // ONE action, both scopes, one label — which is why the place card reads this
    // same key (see SHARED_ACTION_TYPES in editor/model/constants.js). Unlike the three
    // markers above it DOES something: it sends a mail. At most one per transition
    // and per place; the writer rejects a second. See Workflow\MailAction.
    'action_type_mail' => 'Send mail',
    'action_mail_hint' => 'Once the transition has run, everyone assigned to the entry gets an email with a button to the entry\'s workflow summary — in their own CP language.',
    // The same text would be wrong here: on a place the trigger is not "this step
    // ran" but "a version arrived here" — including when nobody clicked. Which is
    // exactly the plain-language case: the poll command sets the state itself,
    // without a transition.
    'action_mail_place_hint' => 'Whenever a version arrives in this state, everyone assigned to it gets an email — even when nobody clicked (for instance once the plain-language rewrite has landed). If the transition itself already carried a mail, this one is skipped, so one click never sends two.',
    // Which mail is sent — this action's only setting. The wording lives in the
    // language files, not in the action; see Mail\MailTypes.
    'action_mail_type_label' => 'Mail type',
    'action_only_one_modal' => 'Only one dialog can be shown before the transition.',

    // User assignment: who takes the entry over next. The mode says WHO decides —
    // manual opens the picker, automatic assigns every match. Who is eligible in
    // the first place is the same set of criteria either way.
    'assignment_mode' => 'Assignment',
    'assignment_mode_manual' => 'Pick manually …',
    'assignment_mode_automatic' => 'Assign automatically …',
    'assignment_match_all' => 'when all of the following conditions are met',
    'assignment_match_any' => 'when any of the following conditions are met',
    'assignment_criteria_hint' => 'You can enter any handle — you are not limited to the options in the dropdown.',
    'assignment_add_group' => 'Add group',
    'assignment_criterion_add' => 'Add criterion',
    'assignment_group_hint' => 'A group joins its own criteria, which is what makes “in the group AND (category OR entity)” sayable. One level deep.',
    'assignment_add_criterion' => 'Add condition',
    'assignment_criterion_placeholder' => 'Criterion',
    'assignment_value_placeholder' => 'Value',
    'assignment_criteria_none_manual' => 'No condition — every user is selectable.',
    'assignment_criteria_none_automatic' => 'Automatic assignment needs at least one condition.',
    'assignment_criterion_group' => 'Group',
    'assignment_criterion_role' => 'Role',
    // Labels of the assignment criteria shipped in config/workflows.php. A project
    // may put its own key or a plain string there instead.
    'assignment_criterion_category' => 'Category',
    'assignment_criterion_responsibility' => 'Responsibility',
    'assignment_criterion_post_category' => "Entry's category",
    'assignment_operator_is' => 'is',
    'assignment_operator_is_not' => 'is not',
    'assignment_operator_matches_entry' => 'matches the entry',
    'add_transition' => 'Add transition',
    'actions' => 'Actions',
    'confirm_delete_place' => 'Delete place [:handle]?',
    'confirm_delete_transition' => 'Delete transition [:handle]?',
    'migrate_place_prompt' => ':count entries are currently in place :place. Pick a target place to move them to before deleting.',
    'migrate_target' => 'Move to',
    'select_place' => 'Select a place…',
    'confirm_delete' => 'Delete',
    'place_deleted' => 'Place [:place] deleted.',
    'event_note_definition_saved' => 'Workflow definition edited via settings.',
    'event_note_place_deleted' => 'Place [:place] removed via settings.',
    'event_note_place_migrated' => 'Place [:place] removed via settings — :count entries moved to [:target].',
    'removed_place_unknown' => 'Place [:place] does not exist and cannot be removed.',
    'removed_place_still_present' => 'Place [:place] is marked for removal but is still part of the definition.',
    'removed_place_initial' => 'Place [:place] is the initial place and cannot be removed. Choose another initial place first.',
    'removed_place_needs_target' => 'Place [:place] still holds :count entries. Choose a place to move them to.',
    'removed_place_target_invalid' => 'Entries of [:place] cannot be moved to [:target]: it is not one of the remaining places.',
    'settings_save_rolled_back' => 'Saving failed while moving the entries. Nothing was changed — the previous definition is back in place.',

    // Collection picker in the settings editor
    'collections_intro' => 'The collections whose entries this workflow governs. Their entries get the workflow field, publishing is gated on the workflow state, and they show up on the manager page.',
    'select_collections' => 'Choose collections',
    'collections_owned_by' => ':collection is governed by the “:workflow” workflow. This workflow only picks up entries there that were explicitly handed to it.',
    'collections_hint' => 'When a workflow governs more than one collection, “All posts” is split into one tab per collection. Newly added collections are backfilled with states on save; you can redo that any time via “Backfill states” in the workflow index or “php artisan workflows:seed-states”.',
    'seed_published_place' => 'Finished place (for backfill)',
    'seed_published_place_hint' => 'The state backfilling gives entries whose work is already done: for a localization workflow, every post that already exists in the sites THIS manager is responsible for (plain language: its target site; translation: any site other than the source and those another manager runs) — for all others, the published ones. Needed when the workflow has no allow_publish place (the translation flow ends in “Approved”, for instance). Empty = every entry starts in the initial place.',
    'seed_published_place_none' => 'no finished place',
    'post_creator_groups' => 'May create entries',
    'post_creator_groups_none' => 'super admins only',
    'post_creator_groups_hint' => 'User groups that see the “New post” button on this workflow’s manager page. Super admins always may. Empty = super admins only.',
    'post_creator_groups_hint_fallback' => 'User groups that see the “New post” button on this workflow’s manager page. Super admins always may. Empty = the project config applies (:groups).',
    // The transition the "New post" modal's confirm button runs after creating —
    // the "workflow CTA". See WorkflowController::storePost().
    'create_transition' => 'Workflow action on create',
    'create_transition_none' => 'none — just create',
    'create_transition_hint' => 'The confirm button of the "New post" dialog then carries this transition\'s label and runs it right after creating — e.g. "Create draft", which assigns the post to the reviewers and notifies them. Only transitions leaving the initial place. If the transition assigns people by hand, the dialog asks for them right there. If the transition cannot run (no candidates, missing permission), the post is still created and stays in the initial place; the notice says why. Empty = the button says "Create" and does just that.',
    'assigner_groups' => 'May assign responsibles',
    'assigner_groups_none' => 'super admins only',
    'assigner_groups_hint' => 'User groups allowed to decide who is responsible for an entry. Super admins always may. Empty = super admins only. Not the same as “default_assignee_group”, which says who an entry is handed to; this says who may do the handing.',
    'assigner_groups_hint_fallback' => 'User groups allowed to decide who is responsible for an entry. Super admins always may. Empty = the project config applies (:groups).',
    // Only shown in the settings editor of a localization workflow.
    // Translation APIs — only visible in a localization workflow's settings
    // editor, and purely informational: keys and URLs live in .env, not in the
    // settings. The variable names say which service a line belongs to, so the
    // section needs no explanatory copy.
    'translation_apis' => 'Translation APIs',
    'deepl_plan_free' => 'Free endpoint (api-free.deepl.com)',
    'deepl_plan_pro' => 'Pro endpoint (api.deepl.com)',
    'env_set' => 'is set',
    'env_missing' => 'is missing from .env',
    'copy_env_name' => 'Copy :name',
    'copied' => 'Copied',
    'copy_failed' => 'Could not copy — please select it by hand.',

    // The mailer line shown next to a `mail` action. Deliberately
    // carries NO credentials (see WorkflowController::mailStatus()) — only whether
    // anything actually goes out. `log` is Laravel's default and delivers nothing,
    // which is precisely the case this line exists to surface.
    'mailer_current' => 'Mail is sent via',
    'mailer_delivering' => 'delivers',
    'mailer_not_delivering' => 'delivers nothing — the mail is only logged or discarded',
    'mailer_from' => 'From: :address',
    'mailer_from_missing' => 'No from address set (MAIL_FROM_ADDRESS)',
    // Headings of the workflow-specific section. Which one appears is decided by
    // `plain_language_site` — see plain_language_site_hint.
    'plain_language_settings' => 'Plain Language',
    'translation_settings' => 'Translation',
    'plain_language_site' => 'Target site',
    'plain_language_site_none' => 'none — no longer a plain-language manager',
    'plain_language_site_hint' => 'The site this manager writes into. Because it is fixed, "Create plain language" asks for no language, and the texts go to the plain-language service instead of DeepL; for every other manager this site stops being a target. Clearing it turns this workflow into an ordinary translation manager with a language picker — only needed when a definition carries the value by mistake.',
    'plain_language_completed_place' => 'Target place after job completion',
    'plain_language_completed_place_none' => 'none — stays put until manually clicked',
    'plain_language_completed_place_hint' => 'Once a rewrite job completes successfully (text applied, no failures), the scheduler moves the post straight into this place — without going through a transition, so no group check, assignee reset or publish gate. Empty leaves the post as it is today, waiting for someone to click "Submit for subject review". A failed job never triggers this — the failure place below handles that case.',
    'plain_language_failed_place' => 'Target place on failed translation',
    'plain_language_failed_place_none' => 'none — the copy is deleted',
    'plain_language_failed_place_hint' => 'Where the post moves when the plain-language rewrite terminally fails — whether the service refused the submission, reported the job as failed, returned unusable output, or never finished within the poll limit. Like the completion place this skips the transition, so no group check. Give this place a transition carrying the "Rewrite plain language" action and a second attempt is one click. Empty deletes the untranslated copy instead, which leaves nothing visible about the failure beyond a transient message.',
    'translation_source_site' => 'Source site',
    'translation_source_site_none' => 'main site (default)',
    'translation_source_site_hint' => 'The version to translate from. Empty = the main site, i.e. the version editors work in (for posts without a main-site version, their origin). Picking plain language here means every translation is built from the plain-language version rather than the German one — there is exactly one source per manager. If a post has no version in the chosen site, the start stays disabled and says why; the manager never falls back to another source silently.',
    'translation_source_pick' => 'Source selectable when translating',
    'translation_source_pick_hint' => 'Off by default: the configured source site is binding — otherwise it would be a suggestion, not a setting. Switched on, the language dialog also offers the source, preselected from the setting and limited to the versions the post actually has. For projects that want to decide per translation; not available in the plain-language manager, which asks nothing.',
    // Rules for who sees the source column of the detail page how — see
    // Workflow\SourceViewRules. A display rule, not a permission: super admins are
    // not exempt.
    'translation_source_view' => 'Source view per audience',
    'translation_source_view_hint' => 'Who gets the source shown differently from the side-by-side comparison. "Translation only": the source is not delivered at all. "Source collapsed": the page opens with one column and a button reveals the source. When more than one rule matches, the stricter one applies. Anyone no rule matches sees both side by side. Applies to super admins too — it is a display choice, not a permission.',
    'translation_source_view_add' => 'Add rule',
    'translation_source_view_empty' => 'No rules — everyone sees source and translation side by side.',
    'translation_source_view_mode_hidden' => 'Translation only',
    'translation_source_view_mode_collapsed' => 'Source collapsed',
    'translation_source_view_by_user' => 'User',
    'translation_source_view_handles_none' => 'nobody — the rule is not saved',
    'translation_source_view_remove' => 'Remove rule',
    // A place's actions: what applies while a version sits here — plus `mail`,
    // which happens when one ARRIVES. Its label lives with the transition types
    // above, because it is the same action. No
    // click moment like transitions have, hence its own, smaller type set (see
    // Workflow\PlaceActions).
    'place_action_type_pending_rewrite_spinner' => 'Loading spinner during rewrite',
    'place_action_type_refresh_interval' => 'Refresh after X minutes (with modal)',
    'action_refresh_interval_minutes_placeholder' => 'Minutes',
    'place_action_type_reminder' => 'Reminder every X days (email)',
    'place_action_type_auto_transition' => 'Move on automatically after X days',
    'action_auto_transition_days_label' => 'After X days',
    'action_auto_transition_transition_label' => 'Transition',
    'action_auto_transition_hint' => 'If a version is still in this state on the deadline day (arrival day + X days), the chosen transition runs by itself — with its assignment and mail, without an actor. Checked once a day (:time). Mails sent in this state name the deadline.',
    'action_reminder_every_days_label' => 'Every X days',
    'action_reminder_every_days_placeholder' => 'Days',
    'action_reminder_hint' => 'While a version sits in this state, everyone assigned to it gets a reminder every X days — the first one X days after it arrived. Checked once a day (:time); it stops as soon as the version moves on.',
    'refresh_modal_title' => 'New content available',
    'refresh_modal_body' => 'Something changed since this page loaded — reload now to see the latest version?',
    'refresh_modal_later' => 'Later',
    'refresh_modal_confirm' => 'Reload now',
    'states_seeded' => 'Backfilled states for :count existing entries.',
    'collection_unknown' => 'Unknown collection [:collection].',
    'collection_removal_needs_confirmation' => 'Removing a collection discards workflow data. Please confirm.',
    'collection_removal_title' => 'Remove collection — delete workflow data',
    'collection_removal_prompt' => 'You are removing :collections from this workflow. :count entries will drop out of it.',
    'collection_removal_warning' => 'The state, assignments, history and comments of those entries are deleted and cannot be restored. The entries themselves stay untouched.',
    'confirm_collection_removal' => 'Remove and delete data',
    'event_note_collections_removed' => 'Collections [:collections] removed via settings — workflow data of :count entries deleted.',
    // The mail types the `mail` action can send — the labels of the editor's
    // selector. A project type from `config/workflows.php → mail_types` brings its
    // own label as `mail_type_<handle>`.
    'mail_type_assigned' => 'Entry assigned',
    'mail_type_plain_language_ready' => 'Plain language is ready',
    'mail_type_sla' => 'Update due',
    'mail_type_reminder' => 'Reminder: still waiting',

    // Copy for the mails. One type = one `mail_<type>_*` block; rendered in the
    // RECIPIENT's language, not the actor's — see Mail\WorkflowNotification. Any
    // key a type does not set is inherited from `assigned`
    // (Mail\MailTypes::text()), which is why `assigned` is the only complete
    // block here.
    //
    // THREE RULES FOR ANY NEW COPY HERE, all three for the same reason — a mail
    // goes to whoever happens to be assigned, and that can be any role in the
    // building:
    //
    //   1. No salutation and no name. That is why there is no `greeting` key any
    //      more; `recipientName` is still available to a template of your own
    //      (see Mail\WorkflowNotification::defaults()).
    //   2. Never address the reader. Passive or noun forms instead — "has been
    //      listed", "is ready to be proofread", "Open the entry".
    //   3. No vocabulary from the machinery. "Workflow", "transition", "place"
    //      mean nothing to the reader; `:workflow` is the manager's nav label
    //      ("Plain Language") and carries itself.
    'mail_assigned_subject' => 'For editing: :title',
    'mail_assigned_heading' => 'An entry is waiting to be edited',
    'mail_assigned_intro' => 'The entry ":title" has been listed for editing in ":workflow".',
    'mail_assigned_field_state' => 'Status',
    'mail_assigned_field_actor' => 'Triggered by',
    'mail_assigned_field_reminder' => 'Reminder',
    'mail_assigned_field_entered' => 'Received on',
    'mail_assigned_button' => 'Open the entry',
    'mail_assigned_outro' => 'This message was sent automatically.',
    'mail_assigned_progress' => 'Step :position of :total',

    // "The plain-language version is ready." No `field_actor` of its own: nobody
    // triggered this (the scheduler writes the text in), so the line drops out by
    // itself. `:workflow` is deliberately absent from the sentence — whoever reads
    // this mail knows what it is about.
    'mail_plain_language_ready_subject' => 'Plain language is ready: :title',
    'mail_plain_language_ready_heading' => 'The plain-language version is ready',
    'mail_plain_language_ready_intro' => 'The plain-language version of ":title" is now available and ready to be proofread.',
    'mail_plain_language_ready_button' => 'View the plain-language version',

    // "This post has grown old." ONE block for BOTH stages of the `sla` block,
    // `needs_update` and `outdated` — which of the two it is is said by the mail's
    // state line (`field_state`, filled from the place's own label), exactly as
    // `assigned` serves every place of every workflow with a single block.
    //
    // The sentence therefore names NO stage and NO number of days: it has to hold
    // on day 320 as much as on day 900, and a mail claiming "for 320 days" turns
    // false the moment somebody changes the deadline. `:updated` is fine — that is
    // the content's LAST UPDATE (ContentTimestamp), not the deadline, and it holds
    // whenever the mail is read. Without one (a post with no date at all),
    // `intro_undated` is used.
    //
    // "You" on purpose, against the no-address rule of this file: the request for
    // another year's approval goes to authors, and the editors wanted it addressed.
    //
    // No `field_actor` of its own: the deadline passes without anybody clicking
    // anything (TransitionAgedPostsCommand moves the post), so that line drops out
    // by itself.
    'mail_sla_subject' => 'Due for an update: :title',
    'mail_sla_heading' => 'A post is due for an update',
    'mail_sla_intro' => 'The post ":title" has not been updated since :updated. Thank you for reviewing its content and either commenting on it for revision or approving it for publication for another year.',
    'mail_sla_intro_undated' => 'The post ":title" has not been updated for a while. Thank you for reviewing its content and either commenting on it for revision or approving it for publication for another year.',
    'mail_sla_button' => 'Review the post',

    // "This post is still waiting." The mail of a place's `reminder` action, sent
    // again every X days while the post sits there. Unlike `sla` a day count is
    // fine here: the scheduler counts `:days` from the arrival in exactly the
    // state the status line names, so it is right.
    'mail_reminder_subject' => 'Reminder: :title',
    'mail_reminder_heading' => 'A post is still waiting to be worked on',
    'mail_reminder_intro' => 'The post ":title" has been in the same state in ":workflow" for :days days and is waiting to be worked on.',
    // The "Reminder" line in every mail whose state carries a `reminder` action
    // (Mail\WorkflowNotification::reminder()).
    'mail_reminder_notice' => '{1} daily, while the post stays in this state|[2,*] every :days days, while the post stays in this state',
    // The notice panel of every mail whose state carries an `auto_transition`
    // (Mail\WorkflowNotification::autoTransition()).
    'mail_auto_transition_heading' => 'Automatically :transition on :date',
    'mail_auto_transition_body' => 'Until then, :options is possible. Without a response, :transition runs automatically on :date.',
    'mail_auto_transition_or' => 'or',
    'mail_auto_transition_body_single' => 'Without a response, :transition runs automatically on :date.',

    // Warnings from the definition editor (Workflow\DefinitionLinter). They never
    // block a save; handles stay verbatim, like in the writer messages below.
    'lint_place_unreachable' => 'Place [:place] cannot be reached from the initial place by any transition.',
    'lint_place_dead_end' => 'Place [:place] has no outgoing transition – subjects that land here get stuck.',
    'lint_duplicate_edge' => 'Transition [:handle] leads from [:from] to [:to] like transition [:other].',
    'lint_collection_owned_elsewhere' => 'Collection [:collection] is owned by workflow [:owner] – this workflow does not create entries in it.',
    'lint_content_field_partial' => 'Content field [:field] is missing from the blueprints of :collections – entries there read their automatically detected content instead.',
    'lint_content_field_auto_none' => 'No content fields are set and collection [:collection] has no Bard, Markdown or Replicator field to detect – its entries show no content.',

    // Validation failures from the definition editor (Workflow\WorkflowDefinitionWriter).
    // These reach the CP as a toast, so they are translated — but the YAML keys,
    // handles and action types they name stay verbatim: they are what the author
    // has to correct in the editor.
    'writer_scope_transition' => 'Transition [:handle]',
    'writer_scope_place' => 'Place [:handle]',
    'writer_declared_none' => 'none',
    'writer_no_example' => 'No shipped example for workflow [:workflow].',
    'writer_workflow_file_missing' => 'Workflow [:workflow] not found at :path.',
    'writer_workflow_exists' => 'A workflow [:workflow] already exists — it is not overwritten.',
    'writer_workflow_name_invalid' => 'Invalid workflow name [:workflow].',
    'writer_no_places' => 'A workflow must define at least one place.',
    'writer_places_mismatch' => 'The save leaves out existing places (:places). Places are not removed by omitting them.',
    'writer_initial_place_unknown' => 'initial_place [:place] is not one of the defined places.',
    'writer_transition_from_unknown' => 'Transition [:handle] has unknown from-place [:place].',
    'writer_transition_to_unknown' => 'Transition [:handle] has unknown to-place [:place].',
    'writer_place_handle_reserved' => 'Place handle [:handle] is reserved and auto-injected.',
    'writer_sla_not_authoring' => 'Aging belongs to the workflow that authors the entries — a localization workflow reacts to its deadline, it has none of its own.',
    'writer_sla_days_invalid' => 'SLA [:key]: [:value] is not a number of days (empty, or a whole number from 0).',
    'writer_sla_stage_incomplete' => 'SLA stage [:stage]: days and step belong together — with only one of them nothing happens.',
    'writer_sla_place_unknown' => 'SLA stage [:stage] points at the unknown step [:place].',
    'writer_sla_assign_invalid' => 'SLA stage [:stage]: responsibility needs a type (group, role or person) and at least one entry.',
    'writer_sla_days_order' => 'The second stage (:outdated days) must not come before the first (:needs_update days) — both deadlines count from the same approval.',
    'writer_source_draft_not_localization' => 'A fallback step for the source going to draft belongs in a localization workflow — only its versions have a source above them.',
    'writer_source_draft_place_unknown' => 'The fallback step [:place] does not exist in this workflow.',
    'writer_source_draft_place_publishes' => 'The fallback step [:place] allows publishing — but the version is meant to be offline there.',
    'writer_place_color_unsupported' => 'Place [:handle] has unsupported colour [:color].',
    'writer_place_spinner_not_boolean' => 'Place [:handle] has a non-boolean metadata.pending_rewrite_spinner.',
    'writer_place_spinner_needs_site' => 'Place [:handle] declares metadata.pending_rewrite_spinner, which requires metadata.plain_language_site.',
    'writer_place_branch_not_boolean' => 'Place [:handle] has a metadata.branch that is not a boolean.',
    'writer_place_branch_initial' => 'Place [:handle] is the initial place and cannot be a side branch — every new entry would start beside the line rather than on it.',
    'writer_place_assign_group_retired' => 'Place [:handle] declares metadata.assign_group. That key is gone: a transition assigns only through its [:action] action. Use required_group for the permission gate, and put the hand-over on the transition that performs it.',
    'writer_transition_variant_unsupported' => 'Transition [:handle] has unsupported button variant [:variant].',
    'writer_transition_key_not_handle_list' => 'Transition [:handle] metadata.:key must be a list of handles.',
    'writer_transition_resubmit_not_boolean' => 'Transition [:handle] has a non-boolean metadata.plain_language_resubmit.',
    'writer_transition_resubmit_needs_site' => 'Transition [:handle] declares metadata.plain_language_resubmit, which requires metadata.plain_language_site.',
    'writer_transition_start_localization_needs_detail' => 'Transition [:handle] declares a [:action] action, which requires metadata.detail: translation — only a localization workflow creates a target version.',
    'writer_transition_resubmit_action_needs_site' => 'Transition [:handle] declares a [:action] action, which requires metadata.plain_language_site.',
    'writer_start_localization_once' => 'Only one transition may declare a [:action] action.',
    'writer_start_localization_required' => 'A localization workflow (metadata.detail: translation) needs one transition with a [:action] action — the step that creates the target version.',
    'writer_seed_published_place_unknown' => 'metadata.seed_published_place [:place] is not one of the defined places.',
    'writer_completed_place_unknown' => 'metadata.plain_language_completed_place [:place] is not one of the defined places.',
    'writer_completed_place_needs_site' => 'metadata.plain_language_completed_place requires metadata.plain_language_site.',
    'writer_failed_place_unknown' => 'metadata.plain_language_failed_place [:place] is not one of the defined places.',
    'writer_failed_place_needs_site' => 'metadata.plain_language_failed_place requires metadata.plain_language_site.',
    'writer_metadata_not_group_list' => 'metadata.:key must be a list of user-group handles.',
    'writer_site_fields_not_list' => 'metadata.site_fields must be a list of field handles.',
    'writer_clear_fields_not_list' => 'metadata.plain_language_clear_fields must be a list of field handles.',
    'writer_clear_fields_needs_site' => 'metadata.plain_language_clear_fields requires metadata.plain_language_site.',
    'writer_content_fields_not_list' => 'metadata.content_fields must be a list of field handles.',
    'writer_content_fields_duplicate' => 'metadata.content_fields lists the field [:field] more than once.',
    'writer_content_field_unknown' => 'Content field [:field] does not exist in any blueprint of :collections.',
    'writer_content_field_unsupported' => 'Content field [:field] has the type [:type], which cannot be read as content.',
    'writer_metadata_site_unknown' => 'metadata.:key [:site] is not a configured site.',
    'writer_translation_source_same_as_plain' => 'metadata.translation_source_site must differ from metadata.plain_language_site.',
    'writer_source_pick_not_boolean' => 'metadata.translation_source_pick must be a boolean.',
    'writer_progress_orientation_unknown' => 'metadata.progress_orientation uses the unknown value [:value]. Known: :known.',
    'writer_source_pick_needs_detail' => 'metadata.translation_source_pick requires a localization workflow (metadata.detail: translation).',
    'writer_source_pick_conflicts_plain' => 'metadata.translation_source_pick cannot be combined with metadata.plain_language_site — that manager asks nothing.',
    'writer_source_view_not_list' => 'metadata.translation_source_view must be a list of rules (each with mode, by, handles).',
    'writer_source_view_mode_unknown' => 'metadata.translation_source_view: unknown mode ":value" — allowed: :known.',
    'writer_source_view_by_unknown' => 'metadata.translation_source_view: unknown audience type ":value" — allowed: :known.',
    'writer_source_view_handles_not_list' => 'metadata.translation_source_view: handles must be a non-empty list of handles or user ids.',
    'writer_source_view_needs_detail' => 'metadata.translation_source_view requires a localization workflow (metadata.detail: translation) — only its detail page has a source column.',
    'writer_create_transition_not_string' => 'metadata.create_transition must be a transition handle (string).',
    'writer_create_transition_unknown' => 'metadata.create_transition points to the unknown transition ":transition".',
    'writer_create_transition_system' => 'metadata.create_transition must not be a system transition (":transition" is triggered automatically, not by a button).',
    'writer_create_transition_not_from_initial' => 'metadata.create_transition: ":transition" does not leave the initial place ":place" — a new post sits exactly there, so the transition could never run.',
    'writer_create_transition_needs_entry_workflow' => 'metadata.create_transition only exists in entry workflows — a localization workflow creates no posts.',
    'writer_collection_unknown' => 'Unknown collection [:collection].',
    'writer_action_values_not_map' => ':where action [:action] values must be a map.',
    'writer_action_value_undeclared' => ':where action [:action] has an undeclared value [:value]. Declared: :declared.',
    'writer_action_value_invalid' => ':where action [:action] has an invalid value for [:key] — expected :expected.',
    'writer_transition_actions_not_list' => 'Transition [:handle] metadata.actions must be a list.',
    'writer_transition_action_not_map' => 'Transition [:handle] has an action that is not a map.',
    'writer_transition_site_modal_not_configurable' => 'Transition [:handle] declares a [:action] action. That dialog is not configurable: a localization workflow always opens it on the transition out of its initial place.',
    'writer_transition_user_modal_retired' => 'Transition [:handle] declares a [:action] action. That spelling is read for existing definitions but no longer stored — use [:replacement] with criteria instead.',
    'writer_transition_action_type_unknown' => 'Transition [:handle] has unknown action type [:type]. Known types: :known.',
    'writer_transition_target_missing_site_unknown' => 'Transition [:handle] action [target_missing] names [:site], which is not a configured site.',
    'writer_transition_multiple_dialogs' => 'Transition [:handle] declares more than one dialog action.',
    'writer_assignment_group_empty' => 'Transition [:handle]: a criteria group with no criteria matches nobody.',
    'writer_assignment_group_nested' => 'Transition [:handle]: criteria groups cannot be nested further — one level is the maximum.',
    'writer_transition_multiple_assignments' => 'Transition [:handle] declares more than one [:action] action.',
    'writer_transition_multiple_mails' => 'Transition [:handle] declares more than one [:action] action.',
    'writer_transition_action_mail_unknown' => 'Transition [:handle] mail action has unknown mail type [:mail]. Known types: :known.',
    'writer_assignment_mode_unknown' => 'Transition [:handle] action [:action] has an unknown mode [:mode]. Known modes: :known.',
    'writer_assignment_match_unknown' => 'Transition [:handle] action [:action] has an unknown match [:match]. Known values: :known.',
    'writer_assignment_criteria_not_list' => 'Transition [:handle] action [:action] criteria must be a list.',
    'writer_assignment_automatic_without_criteria' => 'Transition [:handle] action [:action] is automatic but names no criteria. That would assign the subject to every user.',
    'writer_assignment_criterion_not_map' => 'Transition [:handle] action [:action] has a criterion that is not a map.',
    'writer_assignment_criterion_unknown' => 'Transition [:handle] names unknown assignment criterion [:criterion]. Known criteria: :known.',
    'writer_assignment_operator_unsupported' => 'Transition [:handle] criterion [:criterion] has operator [:operator], which it does not support. Supported: :supported.',
    'writer_assignment_criterion_no_value' => 'Transition [:handle] criterion [:criterion] has no value.',
    'writer_assignment_subject_only_any' => 'Transition [:handle] action [:action] is automatic, but its criteria are joined with [any] and at least one of them only inspects the subject. A subject condition that holds would admit every user.',
    'writer_assignment_subject_only_all' => 'Transition [:handle] action [:action] is automatic, but its criteria only inspect the subject and never narrow the users. That would assign it to every user.',
    'writer_place_actions_not_list' => 'Place [:handle] metadata.actions must be a list.',
    'writer_place_action_not_map' => 'Place [:handle] has an action that is not a map.',
    'writer_place_action_type_unknown' => 'Place [:handle] has unknown action type [:type]. Known types: :known.',
    'writer_place_spinner_action_needs_site' => 'Place [:handle] declares a [:action] action, which requires metadata.plain_language_site.',
    'writer_place_refresh_interval_invalid' => 'Place [:handle] action [refresh_interval] has an invalid minutes value [:value] — must be a whole number between 1 and 1440.',
    'writer_place_multiple_mails' => 'Place [:handle] declares more than one [:action] action.',
    'writer_place_action_mail_unknown' => 'Place [:handle] mail action has unknown mail type [:mail]. Known types: :known.',
    'writer_place_reminder_invalid' => 'Place [:handle] action [reminder] has an invalid days value [:value] — must be a whole number between 1 and 365.',
    'writer_place_multiple_reminders' => 'Place [:handle] declares more than one [reminder] action.',
    'writer_place_auto_transition_days_invalid' => 'Place [:handle] action [auto_transition] has an invalid days value [:value] — must be a whole number between 1 and 365.',
    'writer_place_auto_transition_invalid' => 'Place [:handle] action [auto_transition]: transition [:transition] does not exist, is a system transition, or does not start in this place.',
    'writer_place_multiple_auto_transitions' => 'Place [:handle] declares more than one [auto_transition] action.',
    'writer_place_action_reminder_unknown' => 'Place [:handle] reminder action has unknown mail type [:mail]. Known types: :known.',
    // Progress bar in the summary header (WorkflowProgress.vue).
    'progress_label' => 'Progress through the workflow',
    'progress_off_track' => 'Side branch',
    'progress_unknown_place' => 'This state is not a place in the workflow.',
    // Graph editor (Settings › Workflows).
    'editor_loading' => 'Loading graph editor …',
    'editor_breadcrumb_label' => 'Breadcrumb',
    'editor_load_failed' => 'The graph editor could not be loaded.',
    'editor_toolbar_label' => 'Graph tools',
    'editor_canvas_label' => 'Workflow graph',
    'editor_zoom_in' => 'Zoom in',
    'editor_zoom_out' => 'Zoom out',
    'editor_fit' => 'Fit to view',
    'editor_auto_layout' => 'Auto layout',
    'editor_minimap' => 'Minimap',
    'editor_show_system' => 'Show system steps',
    'editor_order_hint' => 'Position in the order',
    'editor_usage_hint' => 'Entries in this state',
    'editor_ghost_hint' => 'Added automatically – not editable',
    'editor_badge_start' => 'Start',
    'editor_badge_publish' => 'Publish',
    'editor_badge_gate' => 'Gate',
    'editor_badge_sla_1' => 'SLA 1',
    'editor_badge_sla_2' => 'SLA 2',
    'editor_badge_seed' => 'Seed',
    'editor_badge_source_draft' => 'Source draft',
    'editor_badge_pl_done' => 'PL ✓',
    'editor_badge_pl_failed' => 'PL ✗',
    'editor_badge_cta' => 'CTA',
    'editor_badge_system' => 'System',
    'editor_badge_gate_hint' => 'Decision point: an outgoing step is restricted to a group/role, or several steps leave here.',
    'editor_badge_start_transition_hint' => 'First step of the workflow',
    'editor_chip_gate_group' => 'Group · :count',
    'editor_chip_gate_role' => 'Role · :count',
    'editor_chip_assign_manual' => 'Manual assignment',
    'editor_chip_assign_automatic' => 'Auto assignment',
    'editor_chip_mail' => 'Mail',
    'editor_chip_target_missing' => 'Target missing',
    'editor_chip_start_localization' => 'Starts localization',
    'editor_chip_resubmit' => 'Resubmit',

    // Create workflow (CreateWorkflowModal).
    'create_from_label' => 'Start from',
    'create_from_blank' => 'Blank',
    'create_from_blank_hint' => 'Draft and published — a minimal start to build on.',
    'create_from_example' => 'Template',
    'create_from_example_hint' => 'One of the shipped examples, under a new handle.',
    'create_from_copy' => 'Copy of',
    'create_from_copy_hint' => 'Duplicate an existing workflow of this project.',
    'create_template_label' => 'Template',
    'create_template_installed' => 'already installed',
    'create_copy_of_label' => 'Copy of',
    'create_copy_of_placeholder' => 'Choose a workflow…',
    'create_label_label' => 'Name',
    'create_label_placeholder' => 'e.g. Press release',
    'create_handle_hint' => 'File name and key of every state row — cannot be changed later.',
    'create_handle_required' => 'A handle is required.',
    'create_collections_keep' => 'Keep the source\'s collections',
    'create_collections_keep_hint' => 'Untick to choose different ones — an empty choice means none.',
    'create_collections_keep_copy' => 'Currently: :collections',
    'create_collections_keep_none' => 'The source has no collections.',
    'create_definition_errors' => 'The new workflow would not be valid:',

    // Graph editor: panels.
    'editor_tab_general' => 'General',
    'editor_tab_permission' => 'Permission',
    'editor_tab_permissions' => 'Permissions',
    'editor_tab_connections' => 'Connections & usage',
    'editor_tab_localization' => 'Localization',
    'editor_tab_sla' => 'SLA',
    'editor_tab_order' => 'Order',
    'editor_tab_advanced' => 'Advanced',
    'editor_kind_place' => 'Place',
    'editor_kind_transition' => 'Transition',
    'editor_kind_workflow' => 'Workflow settings',
    'editor_close_panel' => 'Clear selection',
    'editor_collapse_panel' => 'Collapse side panel',
    'editor_expand_panel' => 'Expand side panel',
    'editor_field_handle' => 'Handle',
    'editor_handle_new_hint' => 'Derived from the name until you type one. Can\'t be changed after saving.',
    'editor_handle_saved_hint' => 'Saved handles are fixed (states, history and translations use them).',
    'editor_handle_missing' => 'Please enter a name or a handle.',
    'editor_handle_invalid' => 'Lowercase letters, digits and _ only, starting with a letter.',
    'editor_handle_reserved' => 'This handle is reserved.',
    'editor_handle_taken' => 'This handle is already taken.',
    'editor_field_labels' => 'Name',
    'editor_labels_hint' => 'A name per language takes precedence; empty shows the language-file name in grey (key :key).',
    'editor_transition_label_hint' => 'Empty: the language-file text (key :key), else the handle.',
    'editor_field_order' => 'Order',
    'editor_order_position' => ':position of :total',
    'editor_order_places_hint' => 'Drives the progress bar, the final place and the filters – independent of the position on the canvas.',
    'editor_order_transitions_hint' => 'The first transition is where the workflow starts.',
    'editor_drag_to_reorder' => 'Drag to reorder',
    'editor_set_initial' => 'Make start place',
    'editor_is_initial' => 'Start place',
    'editor_incoming' => 'Incoming transitions',
    'editor_outgoing' => 'Outgoing transitions',
    'editor_referenced_by' => 'Used as',
    'editor_none' => 'None',
    'editor_usage' => 'Entries',
    'editor_usage_count' => ':count entries in this place',
    'editor_usage_new' => 'New – no entries yet.',
    'editor_delete_initial_blocked' => 'The start place can\'t be deleted. Make another place the start first.',
    'editor_delete_on_save' => 'Deleted on save; connections to this place are removed right away.',
    'editor_place_pending_deletion' => 'Place [:place] will be deleted on save.',
    'editor_pending_deletions' => 'Deleted on save',
    'editor_restore_place' => 'Restore',
    'editor_open' => 'Open',
    'editor_no_actions' => 'No actions.',
    'editor_from_hint' => 'Several source places possible.',
    'editor_system_hint' => 'System step – runs automatically, never clicked.',
    'editor_start_hint' => 'First transition: leads out of the start place.',
    'editor_start_explicit_hint' => 'Creates the target version (action "creates the target version").',
    'editor_active_hint' => 'Applies immediately, independent of saving.',
    'editor_reset_positions' => 'Reset positions',
    'editor_reset_positions_hint' => 'Re-arranges every node automatically; stored with the next save.',
    'editor_payload_preview' => 'Saved data (preview)',
    'editor_payload_preview_hint' => 'What a save would send – read-only.',
    'editor_unsaved' => 'Unsaved',
    'editor_errors' => ':count errors',
    'editor_entries_migrated' => ':count entries moved to other places.',
    'editor_conflict_title' => 'Workflow was changed',
    'editor_conflict_body' => 'Reloading discards your changes; overwriting replaces the other version.',
    'editor_conflict_reload' => 'Reload',
    'editor_conflict_overwrite' => 'Overwrite anyway',
    'editor_leave_unsaved' => 'Discard unsaved changes and leave this page?',

    // Graph editor: canvas.
    'editor_add_next' => 'Add next step (Tab)',
    'editor_add_search' => 'Search …',
    'editor_add_transition_new_place' => 'New transition → new status',
    'editor_add_place' => 'New status (unconnected)',
    'editor_add_transition' => 'New transition (unconnected)',
    'editor_add_target_new_place' => 'New target status',
    'editor_add_transition_to_place' => 'Transition to existing status',
    'editor_add_target_place' => 'Target: existing status',
    'editor_add_place_here' => 'New status here',
    'editor_add_transition_here' => 'New transition here',
    'editor_disconnect' => 'Remove connection',
    'editor_confirm_delete_nodes' => 'Delete :count items?',
    'editor_connect_unknown' => 'System steps can\'t be connected.',
    'editor_connect_self' => 'A status can\'t connect to itself directly – a loop goes through a transition.',
    'editor_connect_transition_to_transition' => 'A transition always leads into a status.',
    'editor_connect_exists' => 'This connection already exists.',
    'editor_connect_start_locked' => 'The first transition always starts in the initial status – this connection is locked. Change the initial status or the transition order.',

    // Graph editor: history and delete.
    'editor_undo' => 'Undo',
    'editor_redo' => 'Redo',
    'editor_undo_action' => 'Undo: :action',
    'editor_redo_action' => 'Redo: :action',
    'editor_key_ctrl' => 'Ctrl',
    'editor_key_shift' => 'Shift',
    'editor_history_edit' => 'Edit',
    'editor_history_add' => 'Add',
    'editor_history_delete' => 'Delete',
    'editor_history_connect' => 'Connect',
    'editor_history_disconnect' => 'Disconnect',
    'editor_history_reorder' => 'Reorder',
    'editor_history_initial' => 'Set initial place',
    'editor_history_move' => 'Move',
    'editor_history_layout' => 'Arrange',
    'editor_history_restore' => 'Restore',
    'editor_history_migration' => 'Change migration target',
    'editor_delete_refs' => 'These connections and references will be removed:',
    'editor_delete_timer_note' => 'Moved entries start over in the target place: SLA and auto-transition clocks restart.',
    'editor_deleted' => 'Deleted (:count).',

    // Graph editor: overview and settings.
    'editor_kind_overview' => 'Overview',
    'editor_overview_hint' => 'Click a state or transition to edit it.',
    'editor_overview_add_hint' => 'Add the next step with the “+” beside a node, Tab, or by dragging a line from its output; double-click the empty canvas for a new state.',
    'editor_overview_places' => 'States',
    'editor_overview_transitions' => 'Transitions',
    'editor_overview_entries' => 'Entries in total',
    'editor_problems' => 'Problems',
    'editor_problems_none' => 'No known problems.',
    'editor_settings_sections' => 'Settings sections',
    'editor_tab_connections_short' => 'Connections',

    // Graph editor: validation.
    'editor_rule_transition_no_from' => 'Transition [:handle] has no source place.',
    'editor_rule_required_handles_missing' => 'Transition [:handle]: a permission type is chosen, but no group or role.',
    'editor_rule_start_localization_from_initial' => 'Transition [:handle] creates the target version and must therefore start in the initial place [:place].',
    'editor_rule_resubmit_on_start' => 'Transition [:handle] creates the target version in the first place — a [:action] action makes no sense there.',
    'editor_rule_reminder_mail_type' => 'Place [:handle], action [reminder]: no mail type is chosen.',
    'editor_rule_place_isolated' => 'Place [:place] is not connected to any transition.',
    'editor_rule_no_publish_place' => 'No place allows publishing and no place for already published entries is set — existing entries cannot be placed.',
    'editor_warnings' => ':count warnings',
    'editor_validating' => 'Checking …',
    'editor_validating_hint' => 'The server is checking the latest changes — the list may still be out of date.',
    'editor_problems_count' => ':errors errors, :warnings warnings',
    'editor_save_with_errors_title' => 'There are :count errors — save anyway?',
    'editor_save_with_errors_body' => 'The server checks again when saving and refuses what cannot work; your changes stay in the editor then.',
    'editor_save_anyway' => 'Save anyway',

    // Content fields (editor + create).
    'editor_tab_content' => 'Content',
    'content_fields_label' => 'Content fields',
    'content_fields_intro' => 'Which fields of the entries are this workflow\'s content – in reading order. Summary, comments and plain language read only these fields; anything nested inside (Replicator, Grid, Bard sets) is walked automatically.',
    'content_fields_hint' => 'Leave empty to detect automatically.',
    'content_fields_placeholder' => 'Automatic',
    'content_fields_only_in' => 'only in :collections',
    'content_fields_stale' => 'not available',
    'content_fields_remove' => 'Remove [:field]',
    'content_fields_auto_intro' => 'No fields chosen – each collection reads its automatically detected field:',
    'content_fields_auto' => 'automatic: :field',
    'content_fields_auto_none' => 'none found',
    'content_fields_no_collections' => 'Choose collections first – the fields come from their blueprints.',
    'content_fields_order_hint' => 'The order is the reading order – drag or use the arrows.',
    'create_content_fields_label' => 'Content fields',
    'create_content_fields_hint' => 'Optional. In reading order; empty = detected automatically per collection.',
    'create_content_fields_keep' => 'Taken over from the source.',
];
