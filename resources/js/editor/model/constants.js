// Shared constants of the workflow-definition model. Framework-free: imported by
// the graph editor and by the vitest suite.
// Every list here mirrors a constant on the server — keep the two in step.

// Mirrors Workflow\TransitionActions::MODES / MATCHES.
export const ASSIGNMENT_MODES = ['manual', 'automatic'];
export const ASSIGNMENT_MATCHES = ['all', 'any'];

// Workflow metadata keys holding {by, handles} rows (AudienceRows.php).
export const AUDIENCE_ROW_KEYS = ['revision_publishers', 'comment_resolvers', 'comment_deleters', 'edit_exempt', 'qs_notes_audience'];

// --- transition actions ---
// Steps (dialogs) or preconditions (gates/markers) that run between the click
// and the transition. Keep in step with Workflow\TransitionActions::TYPES — the
// site dialog is deliberately absent: it is derived from `start_localization`
// plus plain_language_site, never one of the types an editor can pick.
// `user_assignment` is the only type that CAN open a dialog, and only in its
// manual mode — mirroring TransitionActions::opensDialog() on the server.
export const DIALOG_ACTION_TYPES = ['user_assignment'];
export const GATE_ACTION_TYPES = ['target_missing', 'start_localization', 'plain_language_resubmit'];
// Ein dritter Eimer, weil `mail` weder das eine noch das andere ist: kein Dialog
// vor der Transition und keine Sperre, sondern eine WIRKUNG danach. In einen der
// beiden Namen mit hineingeschrieben wäre es an der einen Stelle falsch, an der
// die Aufteilung überhaupt etwas entscheidet — dem Dialog-Limit in
// actionTypeOptionsFor().
export const EFFECT_ACTION_TYPES = ['mail'];
export const ACTION_TYPES = [...DIALOG_ACTION_TYPES, ...GATE_ACTION_TYPES, ...EFFECT_ACTION_TYPES];

// Typen, die BEIDE Scopes tragen — Spiegel von Workflow\MailAction::TYPE, das in
// TransitionActions::TYPES und PlaceActions::TYPES steht. Ein Handle, das beide
// Karten anbieten, ist EINE Action und muss deshalb in beiden gleich heißen.
export const SHARED_ACTION_TYPES = ['mail'];

// --- place actions ---
// Behaviours that apply while a subject sits in a place, not between a click
// and a transition — so a distinct, smaller type set. Keep in step with
// Workflow\PlaceActions::TYPES.
export const PLACE_ACTION_TYPES = ['pending_rewrite_spinner', 'refresh_interval', 'mail', 'reminder', 'auto_transition'];

// The mail type a `mail` action means when none is stored (MailTypes::FALLBACK),
// and the one a `reminder` defaults to.
export const DEFAULT_MAIL_TYPE = 'assigned';
export const DEFAULT_REMINDER_MAIL_TYPE = 'reminder';

// The two SLA ageing stages, in order. Each is a `<stage>_days` / `<stage>_place`
// pair plus an optional `<stage>_assign`.
export const SLA_STAGES = ['needs_update', 'outdated'];

// ProgressSteps::orientation() reads an absent key as this — so it is never written.
export const DEFAULT_PROGRESS_ORIENTATION = 'horizontal';

// Credentials never belong in the workflow YAML (they come from
// config/workflows.php → .env). An older build of the editor offered fields for
// them; serialize() drops whatever it may have written.
export const CREDENTIAL_METADATA_KEYS = ['cortex_url', 'cortex_token', 'plain_language_url', 'plain_language_token', 'deepl_key'];

// What a place / transition handle should look like — same rule as
// `php artisan make:workflow` (MakeWorkflowCommand) applies to a workflow name.
export const HANDLE_PATTERN = /^[a-z][a-z0-9_]*$/;

// The placeholder keys a freshly added, not-yet-named row lives under
// (`place_<Date.now()>` / `transition_<Date.now()>`). One still left at save time
// means the author gave neither a handle nor a label.
export const TMP_PLACE_HANDLE = /^place_\d+$/;
export const TMP_TRANSITION_HANDLE = /^transition_\d+$/;
