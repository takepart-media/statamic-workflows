<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The workflow engine ships its own dedicated SQLite database so its schema
    | never collides with the app's default connection. The addon registers the
    | connection at boot time using the values below. Change the driver if you
    | want workflow state to share your primary database instead.
    |
    | Lives under storage/, not database/: this file is runtime-generated state,
    | not schema — the same reason migrations and seeders live in database/ while
    | logs, caches and uploads live in storage/. The nested `workflows/` folder
    | keeps it out of the way of everything else a project already keeps in
    | storage/app.
    |
    */

    'database' => [
        'driver' => 'sqlite',
        'database' => storage_path('app/workflows/workflow.sqlite'),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Statamic revisions
    |--------------------------------------------------------------------------
    |
    | The engine writes a Statamic revision on every transition — an audit trail
    | you get for free. Revisions require Statamic Pro; without Pro the addon
    | swallows the error and continues, so this stays safe on OSS installs.
    | Set to false to skip revision creation entirely.
    |
    */

    'revisions' => env('WORKFLOWS_REVISIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Privileged groups
    |--------------------------------------------------------------------------
    |
    | Members of these Statamic user groups may bypass workflow group gates
    | (set state manually, transfer between sites, publish out of process).
    | Super Admins are always privileged; extend this list to grant the same
    | escape hatch to other groups.
    |
    */

    'privileged_groups' => ['admins'],

    /*
    |--------------------------------------------------------------------------
    | Post-creator groups
    |--------------------------------------------------------------------------
    |
    | Project-wide fallback for the groups allowed to create entries from a
    | workflow index (the "New post" button). Super Admins are always allowed.
    |
    | Empty by default on purpose: creating content is a permission, and an addon
    | should not hand it to a group it invented. Grant it per workflow in the CP
    | (Settings > Workflows > <workflow> > "May create entries"), which writes
    | `metadata.post_creator_groups` into the workflow YAML and overrides this
    | list. Set it here only if every workflow in the project shares the same
    | creators.
    |
    */

    'post_creator_groups' => [],

    /*
    |--------------------------------------------------------------------------
    | Assigning responsibles
    |--------------------------------------------------------------------------
    |
    | Project-wide fallback for the groups allowed to change who is responsible for a
    | subject (the assignee picker on an instance page). Super Admins are always
    | allowed.
    |
    | Empty by default, for the same reason as post_creator_groups above: deciding who
    | owns a task is a permission, not a default. Grant it per workflow in the CP
    | (Settings > Workflows > <workflow> > "May assign responsibles"), which writes
    | `metadata.assigner_groups` into the workflow YAML and overrides this list.
    |
    | Not to be confused with `default_assignee_group` in a workflow's metadata, which
    | says who a subject is handed TO. This says who may do the handing.
    |
    */

    'assigner_groups' => [],

    /*
    |--------------------------------------------------------------------------
    | History groups
    |--------------------------------------------------------------------------
    |
    | Groups allowed to see an entry's workflow history on its instance page (the
    | "Show history" button, the history drawer and its field diffs). Super Admins
    | always may.
    |
    | Only admins by default: the history exposes every save, transition and
    | removed comment, which is more than an editor needs to do their step. Not
    | covered by `privileged_groups` — list a group here explicitly to grant it.
    | An empty list means Super Admins only.
    |
    */

    'history_groups' => ['admins'],

    /*
    |--------------------------------------------------------------------------
    | Comment moderator groups
    |--------------------------------------------------------------------------
    |
    | Groups allowed to delete ANY comment, not just their own. Everyone else can
    | only delete what they wrote. Super Admins always may; an empty list means
    | Super Admins only. Not covered by `privileged_groups`.
    |
    */

    'comment_moderator_groups' => ['admins'],

    /*
    |--------------------------------------------------------------------------
    | Assignment criteria
    |--------------------------------------------------------------------------
    |
    | What a `user_assignment` action on a transition may filter candidates by,
    | beyond the two the addon ships with (`group` and `role`). Everything
    | declared here shows up in the graph editor as another option in the
    | criterion dropdown, usable in both modes — to narrow the picker in the
    | manual mode, and to decide the assignees outright in the automatic one.
    |
    | Empty by default, and necessarily so: the interesting questions are
    | project-shaped ("does this editor's specialism cover the post's category?")
    | and depend on blueprint fields the addon cannot know about.
    |
    | Per entry, keyed by the handle stored in the workflow YAML:
    |
    |   label       Shown in the dropdown. Passed through the translator, so a
    |               translation key works here as well as a plain string.
    |   user_field  The field on the User blueprint this criterion reads.
    |   entry_field The field on the ENTRY this criterion reads.
    |   operators   Optional. Narrows the operator dropdown to these, in THIS
    |               order — which also picks what a fresh condition row starts
    |               on. Omit for everything the fields allow. An operator the
    |               fields cannot support is dropped rather than reported.
    |   options     Optional suggestions for the value input. Either a
    |               `value => label` map, or one of the strings `groups`,
    |               `roles`, `sites`, `taxonomy:<handle>`. The input stays
    |               free-text either way — a handle that does not exist yet is
    |               allowed, exactly as it is for group handles elsewhere.
    |
    | THE FIELD HANDLES ARE CHECKED against the real blueprints, and a criterion
    | naming one that does not exist is DROPPED — it never reaches the dropdown,
    | and a workflow YAML already using it can no longer be saved until the field
    | exists. Deliberately harsh, because the alternative failure is silent and
    | worse: `is` against a missing field matches nobody, while `is_not` — its
    | plain negation — matches EVERYBODY, which in the automatic mode assigns the
    | subject to the entire user base without any error. Create the blueprint
    | field first, then the criterion.
    |
    | At least ONE of `user_field` / `entry_field` is required — with neither
    | there is nothing to read, and the entry is dropped rather than offered as a
    | criterion that could never match.
    |
    | WHICH SIDE a criterion reads is the criterion's business, not the
    | operator's. `is` / `is_not` always compare the side this criterion is about,
    | so the same operator reads a person in one row and a post in the next, and
    | the row still says what it means because the criterion is named:
    |
    |   user_field only   The PERSON's field against a fixed value.
    |                     "Zugehörigkeit · ist gleich · nord"
    |   entry_field only  The POST's field against a fixed value. Says nothing
    |                     about the person: it holds or fails for everybody alike,
    |                     which makes it a precondition on the whole assignment.
    |                     "Kategorie des Beitrags · ist gleich · gesundheit"
    |   both              `is`/`is_not` read the PERSON (this feature filters
    |                     people), plus `matches_entry` — the person's field
    |                     overlapping the post's, with no value to write down.
    |                     "Zuständigkeit · stimmt mit dem Beitrag überein"
    |                     Want a fixed-value test on the post as well? Declare a
    |                     second, entry-only criterion; it is one line.
    |
    | One caveat for the automatic mode: an assignment needs at least one
    | criterion that narrows PEOPLE, and with `match: any` all of them must, since
    | a satisfied subject condition would admit every user. The writer refuses
    | both cases rather than silently assigning the whole user base.
    |
    | Values are compared as lists and reduced to their bare slug first, so a
    | `terms` field storing `themen::gesundheit` matches a user field storing
    | `gesundheit`. See Workflow\AssignmentCriteria.
    |
    */

    'assignment_criteria' => [

        // Both sides — offers every operator, including `matches_entry`.
        // 'category' => [
        //     // A translation key rather than a literal, so the default is not
        //     // frozen in German. AssignmentCriteria runs the value through __(),
        //     // so a project may put a plain label here instead.
        //     'label' => 'workflows::messages.assignment_criterion_category',
        //     'user_field' => 'entities',
        //     'entry_field' => 'categories',
        //     'options' => 'taxonomy:categories',
        // ],

        // Both sides, but meant for exactly one question — "whoever handles THIS
        // post's category". Without the whitelist the same criterion would also
        // offer the fixed-value pair, and a row reading "Zuständigkeit · ist
        // gleich · gesundheit" answers a question nobody asked here.
        // 'responsibility' => [
        //     'label' => 'workflows::messages.assignment_criterion_responsibility',
        //     'user_field' => 'entity',
        //     'entry_field' => 'categories',
        //     'operators' => ['matches_entry'],
        // ],

        // Subject only — a condition on the post, to combine with one that
        // actually names people.
        // 'post_category' => [
        //     'label' => 'workflows::messages.assignment_criterion_post_category',
        //     'entry_field' => 'categories',
        //     'options' => 'taxonomy:categories',
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard header: who is responsible for me
    |--------------------------------------------------------------------------
    |
    | The "Verantwortlicher" row of the `workflows_user_header` dashboard card —
    | the people the current user turns to for their own affiliation (the row
    | above it, derived from the `assignment_criteria` user fields).
    |
    | A user of `group` counts as responsible for the current user when their
    | `user_field` values OVERLAP the current user's values in the SAME field —
    | bare-slug compared, exactly as the assignment criteria compare (see
    | Workflow\AssignmentCriteria::valueList()). The current user never shows up
    | in their own row.
    |
    |   group       The user group the responsibles are members of.
    |   user_field  The field on the User blueprint that carries the topic, read
    |               on BOTH sides. One field, because the question is symmetric:
    |               two people share a topic or they do not.
    |
    | Both null by default, and the row is then omitted entirely — like every
    | other project-shaped question here, the addon cannot invent a group handle
    | or a blueprint field. It is also omitted when the current user carries no
    | values in that field, or when nobody in the group matches.
    |
    */

    'header_responsibles' => [
        'group' => null,
        'user_field' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Own action types
    |--------------------------------------------------------------------------
    |
    | Action types this project adds to the ones the addon ships. They appear in
    | the graph editor like any built-in, are stored in the workflow YAML, and
    | — if they name a handler — run when the transition does.
    |
    | This is the addon's ONLY outward-facing hook. Nothing else here fires an
    | event or calls project code, so "when this step runs, do that" has exactly
    | one place to live.
    |
    | Per entry, keyed by the handle stored in the YAML:
    |
    |   label      Shown in the editor. Passed through the translator, so a
    |              translation key works as well as a plain string. REQUIRED.
    |   scope      `transition` or `place`. REQUIRED — the two are separate type
    |              sets with separate rules, so an action has to pick one.
    |   workflows  Which workflows may use it. Omit for all of them. Keeps an
    |              action out of a manager where it means nothing, instead of
    |              relying on nobody picking it.
    |   handler    A class implementing Contracts\WorkflowAction. Optional: without
    |              one the action is a pure marker that your own code reads back
    |              through TransitionActions::custom() / PlaceActions::custom().
    |   fields     Optional inputs, rendered in the editor and stored under
    |              `values`. Types: `text`, `number` (with `min`/`max`), `select`,
    |              `multiselect`. `options` takes the same shapes as the
    |              assignment criteria above — a `value => label` map, or one of
    |              `groups`, `roles`, `sites`, `taxonomy:<handle>`.
    |
    | WHEN a handler runs:
    |
    |   scope: transition   after the transition applied — state, published flag,
    |                       assignees, history and revision are all written.
    |   scope: place        right after that, when the subject has ARRIVED in the
    |                       place carrying it. For "however it got here, do this".
    |
    | Several actions on one transition run in the order the YAML lists them, which
    | is the order the graph editor lists them in.
    |
    | Two things a handler CANNOT do, both worth knowing before you write one:
    |
    | - It cannot stop the transition. It runs afterwards; throwing does not undo
    |   anything. The exception is caught and logged, the next handler still runs,
    |   and the click still succeeded. Whatever must be able to say "no" belongs in
    |   the definition (`required_group`, `target_missing`).
    | - It cannot assume somebody is logged in. `$context->actorId` is null when
    |   the scheduled aging command drives the transition.
    |
    | A declaration that cannot be read is DROPPED — no label, no/invalid scope, a
    | handle that shadows a built-in type, or a handler class that is missing or
    | does not implement the contract. Dropped means: gone from the editor, and the
    | writer refuses to save a workflow that names it. Loud, rather than an action
    | that silently never runs.
    |
    */

    'actions' => [

        // 'press_release' => [
        //     'label' => 'Pressemitteilung vorbereiten',
        //     'scope' => 'transition',
        //     'workflows' => ['postmanager'],
        //     'handler' => \App\Workflows\PressRelease::class,
        //     'fields' => [
        //         'channel'   => ['type' => 'select', 'label' => 'Kanal',
        //                         'options' => ['mail' => 'E-Mail', 'wire' => 'Ticker']],
        //         'lead_time' => ['type' => 'number', 'label' => 'Vorlauf (Tage)', 'min' => 1, 'max' => 90],
        //         'verteiler' => ['type' => 'multiselect', 'label' => 'Verteiler', 'options' => 'groups'],
        //     ],
        // ],

        // Place-side, and a pure marker: no handler, so nothing runs — your own
        // code reads it via PlaceActions::custom() wherever it needs to.
        // 'legally_reviewed' => [
        //     'label' => 'Juristisch geprüft',
        //     'scope' => 'place',
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Eigene Mail-Typen
    |--------------------------------------------------------------------------
    |
    | Die Action `mail` verschickt eine Mail an alle Zugewiesenen. WELCHE Mail,
    | sagt ihr `mail_type`-Schlüssel in der Workflow-YAML — ausgewählt im
    | Einstellungs-Editor. Mitgeliefert sind `assigned` ("dir wurde ein Beitrag
    | zugewiesen", die Voreinstellung) und `plain_language_ready` ("die Einfache
    | Sprache ist fertig").
    |
    | Zwei Schreibweisen, weil die zwei Fälle unterschiedlich groß sind:
    |
    |   'erinnerung',                    nur andere Wörter
    |   'pressefreigabe' => [ ... ],     eigene Daten und/oder eigenes Template
    |
    | Je Eintrag, mit dem Handle als Schlüssel:
    |
    |   label   optional. Ein Klartext-Label ODER ein Sprachkey. Fehlt es, gilt
    |           `mail_type_<handle>` aus den Sprachdateien, sonst der Handle.
    |   mail    optional. Eine Klasse, die Contracts\WorkflowMail implementiert.
    |           Sie bereitet die Daten auf: Betreff, zusätzliche View-Variablen,
    |           optional ein eigenes Template. DAS ist der Erweiterungspunkt für
    |           "gleiches Layout, andere Daten".
    |   view    optional. Ein Blade-Name, wenn ein Typ nur ein anderes Template
    |           will und keine zusätzlichen Daten.
    |
    | DIE WÖRTER kommen in jedem Fall aus den Sprachdateien des Projekts:
    |
    |     lang/vendor/workflows/de/messages.php
    |     'mail_type_pressefreigabe'      => 'Pressefreigabe',
    |     'mail_pressefreigabe_subject'   => 'Freigabe nötig: :title',
    |     'mail_pressefreigabe_intro'     => '„:title" wartet im Workflow „:workflow".',
    |
    | Es gibt acht Keys — `subject`, `heading`, `intro`, `field_state`,
    | `field_actor`, `button`, `outro`, `progress`. Jeder, den ein Typ nicht selbst
    | schreibt, wird von `assigned` geerbt; ein eigener Betreff plus eigenes Intro
    | genügt also. Siehe Mail\MailTypes.
    |
    | Für die Texte gelten drei Regeln, weil eine Mail an wen auch immer gerade
    | zugewiesen ist geht: keine Anrede und kein Name, kein Du und kein Sie, und
    | keine Fachbegriffe der Mechanik. Begründet bei den Strings selbst, in
    | lang/{de,en}/messages.php.
    |
    | Der Handle muss hier stehen, weil sich eine Sprachdatei nicht danach
    | befragen lässt, welche Typen es gibt, ohne Key-Namen zu erraten.
    |
    | Eine Deklaration, die nicht gelesen werden kann, wird VERWORFEN — eine
    | `mail`-Klasse, die es nicht gibt oder die den Contract nicht implementiert.
    | Verworfen heißt: weg aus dem Editor, und der Writer lehnt jede YAML ab, die
    | den Handle nennt. Laut, statt einer Mail, die still als die falsche rausgeht.
    | Eingebaute Handles gewinnen eine Kollision.
    |
    */

    'mail_types' => [

        // Nur andere Wörter, kein PHP:
        // 'erinnerung',

        // Mit eigener Datenaufbereitung und eigenem Template:
        // 'pressefreigabe' => [
        //     'label' => 'Pressefreigabe',
        //     'mail'  => \App\Workflows\Mail\Pressefreigabe::class,
        //     'view'  => 'mail.pressefreigabe',
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | CP navigation
    |--------------------------------------------------------------------------
    |
    | DEPRECATED -- no longer read. The addon used to register a CP nav section
    | of this title, listing every workflow manager in it. The managers are now
    | reached from the dashboard's workflow widget (and stay reachable by URL
    | under /cp/workflows/{slug}); the only nav entry left is the super-admin
    | "Settings > Workflows", which lives in Statamic's own Settings section.
    |
    | The key is kept so published configs that still set it keep loading
    | unchanged; it may be removed in a future major version.
    |
    */

    'nav_section' => 'Workflows',

    /*
    |--------------------------------------------------------------------------
    | Graph editor backups
    |--------------------------------------------------------------------------
    |
    | Before the graph editor saves (or `workflows:install` rewrites the
    | collections, the on/off switch flips `active`, a workflow is deleted or
    | reset to its example) a workflow YAML is rewritten, the current file is
    | copied to storage/app/workflows/backups/<handle>/<timestamp>.yaml — no
    | new copy when it equals the newest one. This is how many copies are kept
    | per workflow; older ones are pruned. The editor's YAML dump drops
    | comments, so a hand-annotated file survives here, and a save whose entry
    | migration fails is rolled back from it. 0 switches backups off.
    |
    */

    'editor_backups' => 20,

    /*
    |--------------------------------------------------------------------------
    | Dashboard widgets
    |--------------------------------------------------------------------------
    |
    | The dashboard layout the addon sets when the project configures no
    | widgets of its own (`widgets` in config/statamic/cp.php is empty). As
    | soon as the project lists any widget there, only that list applies and
    | nothing from here is merged in. Set to false to never set a default.
    |
    | Order runs from personal to overall: who am I -> how much is waiting ->
    | what do I have to do -> my other posts -> all posts -> distribution.
    |
    */

    'dashboard_widgets' => [
        ['type' => 'workflows_user_header', 'width' => 100],
        ['type' => 'workflows_queue_stats', 'width' => 100],
        ['type' => 'workflows_my_queue', 'width' => 100, 'limit' => 7],
        ['type' => 'workflows_watched', 'width' => 50, 'limit' => 5],
        ['type' => 'workflows_created_by_me', 'width' => 50, 'limit' => 5],
        ['type' => 'workflows_overview', 'width' => 100],
        ['type' => 'workflows_status', 'width' => 100],
    ],

    /*
    |--------------------------------------------------------------------------
    | SLA scheduler
    |--------------------------------------------------------------------------
    |
    | Whether the hourly `workflows:transition-aged` task registers itself with
    | Laravel's scheduler. Set to false (or `WORKFLOWS_SLA_SCHEDULER=false` in
    | .env) to disable the auto-aging tick without touching code — the command
    | itself stays available for manual invocation. Per-workflow SLA metadata
    | (metadata.sla in the YAML) is orthogonal: workflows without that block
    | are already opt-out on their own; this switch kills the tick globally.
    |
    */

    'sla_scheduler' => env('WORKFLOWS_SLA_SCHEDULER', true),

    /*
    |--------------------------------------------------------------------------
    | Reminder scheduler
    |--------------------------------------------------------------------------
    |
    | Whether the daily `workflows:send-reminders` task registers itself with
    | Laravel's scheduler, and at what time of day it runs. It serves the
    | `reminder` place action: while an entry sits in such a place, its
    | assignees are mailed every `every_days`. Places without the action are
    | untouched; this switch kills the tick globally.
    |
    */

    'reminder_scheduler' => env('WORKFLOWS_REMINDER_SCHEDULER', true),

    'reminder_time' => env('WORKFLOWS_REMINDER_TIME', '08:00'),

    /*
    |--------------------------------------------------------------------------
    | Mail delivery
    |--------------------------------------------------------------------------
    |
    | How the mails a click triggers (transition + place-arrival `mail`
    | actions) are sent, so a transition does not wait for the mail API:
    |
    |   after_response  right after the response is sent — no worker needed
    |   queue           onto QUEUE_CONNECTION — needs `queue:work`, gets retries
    |   sync            inline, inside the request (old behaviour)
    |
    | Reminders (`workflows:send-reminders`) always send inline.
    |
    */

    'mail_delivery' => env('WORKFLOWS_MAIL_DELIVERY', 'after_response'),

    /*
    |--------------------------------------------------------------------------
    | Translation services
    |--------------------------------------------------------------------------
    |
    | Credentials for the machine-translation services a localization workflow
    | can use. They live here and nowhere else — deliberately NOT in the
    | workflow YAML the CP graph editor writes: `resources/workflows/*.yaml`
    | is committed, so a key stored there would land in every clone, backup and
    | CI log, every environment would share it, and rotating it would mean a
    | commit. Reading them through config() rather than env() also keeps them
    | working under `php artisan config:cache`.
    |
    | Which DeepL endpoint gets used is NOT configured here: free keys end in
    | `:fx`, which is how the official DeepL SDKs decide, and a second setting
    | could only ever contradict the key. See Translation\DeeplConfig.
    |
    | To read the values from differently named variables, publish this file
    | (`--tag=workflows-config`) and change the env() calls.
    |
    */

    'translation' => [

        'deepl' => [
            'key' => env('DEEPL_API_KEY'),
        ],

        // The plain-language service ("Einfache Sprache"). Which site carries
        // that version stays a per-workflow decision (metadata.plain_language_site
        // in the YAML) — only the connection is global.
        //
        // `url` is the service BASE — scheme and host, no path. The API path is the
        // adapter's business and lives in Translation\PlainLanguageConfig, the same
        // way DeeplConfig owns `/v2/translate`. A full endpoint URL is tolerated so
        // an older .env keeps working.
        'plain_language' => [
            'url' => env('PLAIN_LANGUAGE_URL'),
            'token' => env('PLAIN_LANGUAGE_TOKEN'),

            // Fixed fields added to every request body, on top of the protocol's own
            // (`input_format`, `generate_intro`). `guideline` names the rewriting
            // profile on the service side and is specific to the project; `type` is the
            // kind of rewrite. Null values are not sent.
            'body_extras' => [
                'guideline' => env('PLAIN_LANGUAGE_GUIDELINE'),
                'type' => env('PLAIN_LANGUAGE_TYPE'),
            ],

            // The service works asynchronously: submitting returns a job id and the
            // rewritten texts are collected later by `workflows:plain-language-poll`.
            // This registers that command with the scheduler (every minute). Set to
            // false to run it yourself — e.g. from a supervised worker — but without
            // either, a submitted rewrite is never written back.
            'poll_scheduler' => env('PLAIN_LANGUAGE_POLL_SCHEDULER', true),

            // Log every call to the service in full — the request payload on the way
            // out and the response body on the way back, for calls that SUCCEED. Off
            // by default because the payload is the entry's entire content: one
            // article is easily a dozen kilobytes, and a poll answering "still
            // working" every minute would bury the log in answers nobody reads.
            //
            // Turn it on to diagnose what the service actually receives and returns —
            // whether a flag travels as the shape the endpoint expects, for instance.
            //
            // FAILURES are logged either way, payload and body included, and are not
            // affected by this switch: a rejection or a dead host has to be visible
            // without somebody having flipped a setting beforehand first.
            //
            // The bearer token is never logged, under either setting: it is a header
            // on the pending request and never enters the payload.
            'log_calls' => env('PLAIN_LANGUAGE_LOG_CALLS', false),

            // Two numbers, because a rewrite can fail in two different ways.
            //
            // `max_polls` is how long ONE submission is waited out: the polls a job gets
            // before it counts as dead. It has to exist because the common failure is not
            // an error at all — a job Cortex never finishes answers the status endpoint
            // with a cheerful 200 and "still working", so without a waiting limit nothing
            // would ever declare it lost. One poll is one minute at the scheduled tick.
            //
            // `max_attempts` is how many SUBMISSIONS the rewrite gets in total, the first
            // one included. When a submission fails, PollPlainLanguageCommand hands the
            // whole call to the service again rather than giving up; only once the last
            // attempt fails does the version move to the workflow's
            // `plain_language_failed_place`. Set it to 1 for no retries at all.
            //
            // Worst case until a post is parked is roughly max_polls x max_attempts
            // minutes. Pick `max_polls` ABOVE the service's usual turnaround, not at it:
            // a value that matches the normal completion time re-submits healthy jobs.
            //
            // Values under 1 are treated as 1, and anything non-numeric falls back to the
            // default — `config()` returns its own default only for a MISSING key, so an
            // empty `PLAIN_LANGUAGE_MAX_POLLS=` would otherwise arrive as 0 and fail every
            // job on its first poll.
            'max_polls' => env('PLAIN_LANGUAGE_MAX_POLLS', 5),
            'max_attempts' => env('PLAIN_LANGUAGE_MAX_ATTEMPTS', 3),
        ],

    ],


    /*
    |--------------------------------------------------------------------------
    | Content fields
    |--------------------------------------------------------------------------
    |
    | The blueprint field handles of your entries that the translation, the
    | plain-language rewrite and the content preview have to know about. Leave a
    | key out (or null) and the feature that needs it skips that field.
    |
    | WHICH fields are an entry's content is no longer decided here but per
    | workflow, in its `metadata.content_fields` (a sorted list of top-level
    | fields, picked in the workflow settings). Below those fields the structure
    | is walked generically — Replicator, Grid, Group, Bard sets — so no set
    | mapping is needed. A workflow that names none detects its content: first
    | `intro` + `body` from here if the blueprint has them, else the first
    | Bard, Markdown or Replicator field.
    |
    |   title, body, seo    the entry's title, main Bard field and SEO field
    |   intro               the Bard field with the opening paragraph
    |   entity              field naming the entity/module an entry belongs to;
    |                       sent to the rewriting service as request context
    |   scope               set field limiting a block to certain entities
    |
    | Deprecated — kept so existing configs still load, but the content preview
    | stops reading them once it walks `metadata.content_fields`:
    |
    |   sources_*, podcast_*  extra fields shown in the content preview
    |   sets                how the preview reads each Bard set type:
    |                       type => [kind, field, extra]; kind is html, heading
    |                       (extra = subheadline field), video, asset or code
    |                       (extra = label). Unlisted types are left out.
    |
    */

    'content_fields' => [
        'title' => 'title',
        'intro' => null,
        'body' => 'content',
        'seo' => 'seo',
        'entity' => null,
        'scope' => null,
        'sources_title' => null,
        'sources_text' => null,
        'podcast_link' => null,
        'podcast_code' => null,
        'sets' => [],
    ],

];
