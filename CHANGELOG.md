# Changelog

All notable changes to this addon. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- **Account reminder sent while the activation link was still valid.** With
  Statamic's file-based users the activation token lives in a YAML file, not
  in a database table, so the check never found it and every transition sent
  a new reminder (invalidating the invitation link). The check now asks the
  password broker's own token repository.

## [1.0.2] - 2026-11-04

### Changed

- **One "New post" button.** The "All posts" widget shows a single "New post"
  button instead of one per workflow. Its dialog asks for the title and then
  the workflow, as a single select limited to the workflows the user may
  create in. It preselects the workflow the widget is switched to, or the
  first one when that is not possible (e.g. under "All"). With only one workflow on offer (and
  always on a manager page) the select is read-only.

## [1.0.1] - 2026-11-04

Superseded by 1.0.2. Use 1.0.2 instead.

## [1.0.0] - 2026-11-03

First release on Packagist.

### Added

- **Documentation** in English ([`docs/en`](docs/en/README.md)) and German
  ([`docs/de`](docs/de/README.md)): installation, configuration, defining
  workflows, the editor, daily use, notifications, translation, content ageing,
  permissions, commands, troubleshooting and one page per extension point.
- **Automatic "Activate account" reminder**: after every transition, assignees
  who have not yet activated their account get an email with a fresh
  activation link. They are reminded again only once the last link has
  expired. The email states the exact validity period. Settings:
  `workflows.account_reminder.enabled` and
  `workflows.account_reminder.expire_hours` (hours; defaults to Statamic's
  duration).
- **"Revisions" setting** in the workflow editor (Collections): turns
  revisions on or off for all collections of the workflow and writes this to
  the collections on save. Includes a note that Statamic Pro is required, and
  a warning if Pro is missing or revisions are disabled globally.
- **"May publish revisions" setting** (rows of kind and entries, e.g. group X
  and users Y, Z): while a working copy exists, only super admins and this
  selection may publish it.
- **"Show QA notes" setting** in the plain-language workflow
  (`metadata.qs_notes_visible`, `metadata.qs_notes_audience`): hides the QA
  report for everyone or shows it only to super admins and a selection of
  groups, roles or users.
- **"Only assignees may edit" setting** (`metadata.assignees_only_edit`,
  Settings › Permissions): entries in the workflow's collections can only be
  edited by their current assignees; everyone else sees them read-only. Super
  admins and a configurable exemption (`edit_exempt`, groups, roles or users)
  can always edit.
- **"May mark comments as done" and "May delete comments" settings**
  (`metadata.comment_resolvers`, `metadata.comment_deleters`, Settings ›
  Permissions; rows of kind and entries). Super admins always may, and anyone
  may delete their own comments. Without a setting, the previous rule applies.
- **Four-eyes principle** (`metadata.four_eyes`, Settings › Permissions): on
  the next transition an entry never goes back to the person who last passed
  it on, neither manually nor automatically. If no one else is left, the
  picker opens with its own target audience (groups, roles or people) and a
  note.
- **"Published" mail** (`mail_type: published`) for the `mail` action: sent to
  everyone watching the entry, not to the assignees. Mail types can declare
  `recipients: watchers` for this.
- **Comment counts in the dashboard widgets** "My queue", "My posts" and
  "Watched": open comments in grey, unread ones as `+N` in amber — as in the
  listings.
- **"Assignee" and "Author" filters** in the workflow overview and on the
  manager page: show only entries where the person is assignee or author in
  any localization.
- **"Waiting longer than" filter** (3 / 7 / 14 / 30 days) in the workflow
  overview and on the manager page: entries with a localization that has been
  in its current state for longer than that. States without an outgoing
  transition (e.g. "Published") do not count as waiting.
- **Graph editor** for workflows under Settings › Workflows › `<Workflow>`:
  places and transitions as nodes, editing in side panels, undo/redo, keyboard
  shortcuts, live validation, versioned saving with backups, deferred deletion
  of places with migration. Replaces the former form editor. "Create workflow"
  (empty, from template, as copy) in the CP and via `workflows:make`. (See
  [docs/en/README.md](docs/en/README.md).)
- **Content fields per workflow** (`metadata.content_fields`): ordered list of
  the top-level fields read by the summary, comments and plain language. If
  unset, auto-detection (`content_fields.intro`/`body` from the config,
  otherwise the first Bard/Markdown/Replicator field). Validation in the
  writer (`writer_content_field*`), linter warnings
  (`lint_content_field_partial`, `lint_content_field_auto_none`). Selection in
  the editor (Settings › "Content") and in the create modal. (See
  [docs/en/README.md](docs/en/README.md).)
- **Generic content summary** (`ContentWalker`): reads any structure of the
  content fields (Replicator, Grid, Group, Bard sets, nested arbitrarily)
  without project-specific set mapping. Rendered as reading flow; for super
  admins, handle/UUID chips behind "Technical details". (See
  [docs/en/README.md](docs/en/README.md).)
- **Plain language per text leaf**: each piece of prose in the content fields
  is sent to the service individually marked (`<section data-wf-leaf>`) and
  written back strictly — missing or unknown sections discard the response,
  and the retry resubmits. New option `metadata.plain_language_skip_fields`.
  Diagnostics via `workflows:plain-language-poll --dry-run` / `--diff`. (See
  [docs/en/README.md](docs/en/README.md).)
- **Mail hint in the assignee picker**: on manual assignment, each person
  shows "Gets an email" if the step sends them a notification. Decided by the
  same rules as delivery (`WorkflowNotifier::stepMail()` / `reaches()`). (See
  [docs/en/README.md](docs/en/README.md).)
- **Mail toggle in the assignee picker**: "Send email notification", on by
  default. Off = the step hands over without mail (`notify: false` →
  `WorkflowTransitioner::apply(notify: false)`). (See
  [docs/en/README.md](docs/en/README.md).)
- **Prevent self-assignment** (`metadata.self_assignment`): the clicking
  person is omitted from the manual picker. If an assignment would only return
  the entry to them, the picker opens instead with a configurable target
  audience (group, role or person, excluding the clicking user), topped by a
  customizable note. Configurable in the graph editor under Settings ›
  Permissions. (See [docs/en/README.md](docs/en/README.md).)
- **Assigning means watching**: anyone newly assigned to an entry
  automatically watches it (`WorkflowAssigneeStore::set()`). Deliberately
  unwatching is respected. Not watching is now shown as a crossed-out
  bookmark. (See [docs/en/README.md](docs/en/README.md).)
- **PHP test suite** (Pest + Orchestra Testbench, in-memory DB, temporary
  Stache): `vendor/bin/pest` or `composer test`. (See
  [docs/en/README.md](docs/en/README.md).)

### Changed

- **The plain-language service's QA report no longer appears in the comment
  thread**, only behind the "QA notes" button. It is still stored as a
  comment.
- **Comments like in Word:** select text → a comment icon appears, the
  comment is anchored to exactly that passage, which is highlighted in the
  text. Comments sit in a margin column next to the content, level with their
  passage, instead of in a drawer. Replies, resolving, mentions and mails work
  as before.
- When the comment column is hidden, a passage's comment button (or a click on
  highlighted text) opens its comments in a **popover**; "Show comments" still
  shows all of them in the column.
- Translation / plain language: when the source is shown alongside, there is
  no comment column — comments (including on selected text) always open in a
  popover there. If the source is hidden via setting, the page behaves like
  regular entries.
- Sets are highlighted on hover and the comment button only appears then; a
  set with a comment on the whole set has a light yellow background.
- Overview: the date after the status badge now reads "since …".
- Plain language now sends only the **content fields and the title**; other
  fields are no longer included.
- Comment hashes are computed over the new block format.
- Plain-language jobs still running from before the update (`format = null`)
  are written back using the old method (`LegacyPlainLanguageDocument`).
- "Created by me" is now **"My posts"** and shows entries in which the current
  user is set as **author** (`author` field) instead of entries they created
  (dashboard tile and manager page).
- "My queue" is now **"Assigned to me"** (including the stats card).

### Deprecated

- `config/workflows.php → content_fields`: `sets`, `sources_title`,
  `sources_text`, `podcast_link`, `podcast_code`. Still loaded but no longer
  read — these fields belong in `metadata.content_fields` instead. Still
  valid: `title`, `intro`, `body`, `seo`, `entity`, `scope`.

### Removed

- Installation UI in the CP. New workflows are created via the create modal;
  `php artisan workflows:install` remains for the initial installation.
- The old form editor (`pages/WorkflowSettings.vue`) and its endpoints.

### Upgrade notes

> **Package renamed.** The package is now `takepart-media/statamic-workflows`
> on Packagist. Projects that installed `takepart-media/workflows` from the Git
> repository run `composer remove takepart-media/workflows`, remove the VCS
> entry from `repositories`, then `composer require
> takepart-media/statamic-workflows`. The CP assets now publish to
> `public/vendor/statamic-workflows/build` (delete the old
> `public/vendor/workflows`). Config, translations, views and publish tags keep
> the name `workflows`.

1. In the host project, run `composer update takepart-media/statamic-workflows` — new
   dependency `league/html-to-markdown`.
2. Run the addon's migrations (`php artisan migrate`): new columns `format`
   and `plan` in `workflow_plain_language_jobs`.
3. Existing comments show the text-changed notice **once**, because their
   stored hashes still use the old block format. Not an error, nothing to do.
4. Plain language now sends only content fields + title. Projects that
   previously had additional fields rewritten (e.g. forus) should check
   `metadata.content_fields` of their plain-language workflow — if unset,
   auto-detection applies.
5. If you set the deprecated config keys, move the affected fields to
   `metadata.content_fields`.

### Fixed

- Whether someone may moderate comments was cached in the controller without
  the user and could therefore apply to the next user of the same controller
  instance.
- **Revisions on managed collections:** the addon never actually enabled
  them. Setting them at runtime was lost, and the editor kept offering "Save &
  Publish". The ineffective code has been removed. The workflow editor now
  warns when a collection of a publishing workflow has no revisions; enable
  them with the new "Revisions" setting.

[Unreleased]: https://github.com/takepart-media/statamic-workflows/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/takepart-media/statamic-workflows/compare/v1.0.0...v1.0.2
[1.0.1]: https://github.com/takepart-media/statamic-workflows/releases/tag/v1.0.1
[1.0.0]: https://github.com/takepart-media/statamic-workflows/releases/tag/v1.0.0
