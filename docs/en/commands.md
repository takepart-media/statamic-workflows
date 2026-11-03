# Commands & scheduler

🇩🇪 [Deutsch](../de/commands.md)

All artisan commands the addon registers, and the scheduled tasks that run them for you.

## Overview

| Command | Purpose | Scheduled |
| --- | --- | --- |
| [`workflows:install`](#workflowsinstall) | Set up the addon in a project | – |
| [`workflows:make`](#workflowsmake) | Create a new workflow YAML | – |
| [`workflows:seed-states`](#workflowsseed-states) | Give existing entries a workflow state | – |
| [`workflows:prune-states`](#workflowsprune-states) | Remove workflow data that no longer belongs to anything | – |
| [`workflows:transition-aged`](#workflowstransition-aged) | Move published entries on once their content is too old | hourly |
| [`workflows:auto-transition`](#workflowsauto-transition) | Apply due automatic transitions | daily |
| [`workflows:send-reminders`](#workflowssend-reminders) | Send due place reminders | daily |
| [`workflows:undo-auto-transition`](#workflowsundo-auto-transition) | Take back an automatic transition | – |
| [`workflows:backfill-content-timestamp`](#workflowsbackfill-content-timestamp) | Fill in the content date for older entries | – |
| [`workflows:plain-language-poll`](#workflowsplain-language-poll) | Collect finished plain-language rewrites | every minute |
| [`workflows:backfill-plain-language`](#workflowsbackfill-plain-language) | Bring older plain-language versions up to date | – |
| [`workflow:dump`](#workflowdump) | Print a workflow as a Mermaid diagram | – |

Commands with a `--workflow=` option accept a workflow handle, the file name in `resources/workflows/` without `.yaml`. Without the option, they run for all active workflows. Most commands that change data have a `--dry-run` option. Use it first.

## Setup

### `workflows:install`

```bash
php artisan workflows:install
```

Interactive first-time setup. It runs the addon migrations on the `workflow` connection, publishes the CP assets, lets you pick one or more of the shipped example workflows (`postmanager`, `translation`, `plainlanguage`), and for each one asks for the collections and whether to backfill states. An existing `resources/workflows/<type>.yaml` is kept, but its collections are replaced by your new selection. See [Installation](installation.md#run-the-installer).

No options.

### `workflows:make`

```bash
php artisan workflows:make <name> [options]
```

Creates `resources/workflows/<name>.yaml`, either as a blank draft-to-published skeleton or as a copy of a shipped example. It uses the same rules as "Create workflow" in the CP and never overwrites an existing workflow.

| Argument / option | Description |
| --- | --- |
| `name` | Workflow handle in snake_case, for example `press_review`. |
| `--from=` | Start from a shipped example: `postmanager`, `translation` or `plainlanguage`. |
| `--collection=` | Collection the workflow governs. Default `posts` for the blank template. With `--from`, the example keeps its own collections unless you pass this option. |
| `--label=` | Display name. Defaults to the handle. |
| `--inactive` | Write the workflow switched off (`active: false`). By default, the command writes it active. |

```bash
php artisan workflows:make press_review --from=postmanager --collection=press --label="Press review"
```

The command prints the manager's URL slug. Make sure the groups the workflow references exist, see [Installation](installation.md#user-groups).

### `workflows:seed-states`

```bash
php artisan workflows:seed-states [--workflow=] [--redo]
```

Gives entries that existed before a workflow governed them a stored workflow state. Entries without a stored state are shown in a default state, but they don't take part in content ageing, and workflows that don't own the collection show them in nobody's queue. The state is the same one the CP already shows. Seeding writes no history, no assignees and doesn't publish or unpublish anything.

| Option | Description |
| --- | --- |
| `--workflow=` | Only this workflow. A switched-off workflow is skipped with a note. |
| `--redo` | Also re-derive entries that already have a state, as long as they have no workflow history. |

Safe to run again; entries that already have a state are skipped. If a workflow has neither a publishing place nor `metadata.seed_published_place`, every entry lands in the initial place and the command warns you. The CP offers the same action as "Backfill states" in **Settings > Workflows**.

### `workflows:prune-states`

```bash
php artisan workflows:prune-states [--workflow=] [--dry-run] [--force]
```

Removes workflow data (state, history, assignees, comments and more) of entries it no longer describes. The command shows a table per workflow with four reasons:

| Reason | Meaning |
| --- | --- |
| `deleted` | The entry no longer exists. |
| `ungoverned` | The entry's collection is no longer in the workflow's `collections`. |
| `foreign` | A translation workflow holds data for a site it isn't responsible for. |
| `unstarted` | A translation workflow holds a state for an entry that isn't published. |

Except for `deleted`, entries with history, assignees or comments in that workflow are kept and counted as `protected`.

| Option | Description |
| --- | --- |
| `--workflow=` | Only this workflow. Without it, switched-off workflows are included too. |
| `--dry-run` | Show the table, change nothing. |
| `--force` | Skip the confirmation prompt. |

> [!WARNING]
> Pruning can't be undone. Run with `--dry-run` first and back up `storage/app/workflows/workflow.sqlite`.

## Ageing and automatic steps

### `workflows:transition-aged`

```bash
php artisan workflows:transition-aged [--workflow=] [--dry-run]
```

Moves published entries into the "needs update" or "outdated" place of their workflow once their content age reaches the threshold in `metadata.sla`. The age is counted from the entry's `content_updated_at` field. If the workflow has a transition into the target place, it is applied with its assignment and mail; otherwise the entry is moved directly. Workflows without `metadata.sla` and translation workflows are skipped. See [Content ageing](sla.md).

| Option | Description |
| --- | --- |
| `--workflow=` | Only this workflow. |
| `--dry-run` | Print the planned moves without applying them. |

Scheduled hourly. Switch: `sla_scheduler`.

### `workflows:auto-transition`

```bash
php artisan workflows:auto-transition [--workflow=] [--dry-run]
```

Applies the `auto_transition` place action: an entry that has been in such a place until its deadline day has the configured transition applied, as if somebody had clicked it, but without a user. Failures are logged and don't stop the run.

| Option | Description |
| --- | --- |
| `--workflow=` | Only this workflow. |
| `--dry-run` | List the due transitions without applying them. |

Scheduled daily at `reminder_time`, right before the reminders, so an entry that moves on gets no reminder about the place it just left. Switch: `reminder_scheduler`.

### `workflows:send-reminders`

```bash
php artisan workflows:send-reminders [--workflow=] [--dry-run]
```

Applies the `reminder` place action: while an entry stays in such a place, its assignees are mailed every `every_days` days, the first time that long after the entry arrived. Each reminder is logged in the history, so a second run on the same day sends nothing. Entries without assignees get no mail. These mails are always sent inline, independent of `mail_delivery`.

| Option | Description |
| --- | --- |
| `--workflow=` | Only this workflow. |
| `--dry-run` | List the due reminders without sending them. |

Scheduled daily at `reminder_time`. Switch: `reminder_scheduler`. See [Notifications](notifications.md).

### `workflows:undo-auto-transition`

```bash
php artisan workflows:undo-auto-transition <entry> [options]
```

Takes back the newest automatic transition of one entry: the entry returns to the place it came from, its published state follows, and the history gets a row saying so. Assignees are **not** restored; fix them in the entry's assignee picker.

| Argument / option | Description |
| --- | --- |
| `entry` | The entry ID. |
| `--workflow=` | The workflow. Defaults to the one the entry has a state in; required if it has several. |
| `--to=` | Set this place instead of the one before the last automatic transition. |
| `--days-ago=` | Backdate the arrival in the restored place by this many days. Useful for testing reminders and deadlines. |
| `--dry-run` | Show what would change, change nothing. |

### `workflows:backfill-content-timestamp`

```bash
php artisan workflows:backfill-content-timestamp [--workflow=] [--force] [--dry-run]
```

Writes `content_updated_at` for entries that don't have one yet, using the entry's date or, for undated collections, the file's last-modified date. Content ageing already reads the same fallback, so this changes no deadline. It makes the value sortable and comparable per version.

| Option | Description |
| --- | --- |
| `--workflow=` | Only the collections of this workflow. Default: all authoring workflows. |
| `--force` | Overwrite entries that already have a value. This resets every deadline. |
| `--dry-run` | Report what would be written, change nothing. |

## Plain language

### `workflows:plain-language-poll`

```bash
php artisan workflows:plain-language-poll [--dry-run] [--diff]
```

The plain-language service works asynchronously. This command asks it for finished rewrites and writes them into their entries. Failed submissions are retried up to `max_attempts`; after the last one, the version moves to the workflow's failure place (`metadata.plain_language_failed_place`). Without such a place, the empty version is deleted instead. If the service is unreachable, nothing changes and the next run tries again.

| Option | Description |
| --- | --- |
| `--dry-run` | Report what the service says and what would be written, without writing anything. |
| `--diff` | Print the field-by-field changes of every write-back. |

Scheduled every minute. Switch: `translation.plain_language.poll_scheduler`. See [Translation & plain language](localization.md).

### `workflows:backfill-plain-language`

```bash
php artisan workflows:backfill-plain-language [--workflow=] [--force] [--dry-run]
```

Applies today's rules to plain-language versions that were rewritten earlier: it derives `seo.title` and `seo.description` from the version's own content and empties the fields listed in the workflow's `metadata.plain_language_clear_fields`. Versions with a rewrite still running, versions that were never rewritten and values an editor changed after the rewrite are skipped. Writes no history.

| Option | Description |
| --- | --- |
| `--workflow=` | Only this plain-language workflow. |
| `--force` | Also overwrite values that were edited after the rewrite. |
| `--dry-run` | Report what would be written, change nothing. |

## Diagrams

### `workflow:dump`

```bash
php artisan workflow:dump <name> [--format=mermaid]
```

Prints a workflow's places and transitions as [Mermaid](https://mermaid.js.org/) diagram source. Note the singular `workflow:` prefix. `mermaid` is the only format.

```bash
php artisan workflow:dump postmanager > postmanager.mmd
```

## Scheduler

The addon registers its scheduled tasks itself. You only need Laravel's scheduler to run.

| Task | Frequency | Config switch | Env |
| --- | --- | --- | --- |
| `workflows:transition-aged` | hourly | `sla_scheduler` | `WORKFLOWS_SLA_SCHEDULER` |
| `workflows:auto-transition` | daily at `reminder_time` | `reminder_scheduler` | `WORKFLOWS_REMINDER_SCHEDULER` |
| `workflows:send-reminders` | daily at `reminder_time`, after `auto-transition` | `reminder_scheduler` | `WORKFLOWS_REMINDER_SCHEDULER` |
| `workflows:plain-language-poll` | every minute | `translation.plain_language.poll_scheduler` | `PLAIN_LANGUAGE_POLL_SCHEDULER` |

`reminder_time` defaults to `08:00` (`WORKFLOWS_REMINDER_TIME`). All tasks run with `withoutOverlapping()` and `onOneServer()`. On several servers, use a shared cache store such as Redis or the database for the locks.

Check what is registered:

```bash
php artisan schedule:list
```

### Production

Add one cron entry for the user that runs PHP:

```bash
* * * * * cd /path/to/your/project && php artisan schedule:run >> /dev/null 2>&1
```

Managed hosts such as Forge or Ploi offer this as a "scheduler" or "scheduled job" that runs every minute.

### Local development

Herd and Valet don't run cron. Start the scheduler in a terminal while you test:

```bash
php artisan schedule:work
```

Or trigger due tasks once with `php artisan schedule:run`, or call a command directly, for example `php artisan workflows:transition-aged --dry-run`.

> [!NOTE]
> Without a running scheduler, entries don't age, place reminders and automatic transitions don't happen, and plain-language rewrites are never written back.

## Next steps

- [Content ageing (SLA)](sla.md): set up the ageing thresholds.
- [Notifications](notifications.md): mail actions and place reminders.
- [Troubleshooting](troubleshooting.md): when a task doesn't do what you expect.

---

← [Permissions](permissions.md) · [Docs home](README.md) · [Troubleshooting](troubleshooting.md) →
