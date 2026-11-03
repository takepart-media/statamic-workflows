# Configuration

🇩🇪 [Deutsch](../de/configuration.md)

Reference for every key in `config/workflows.php`, grouped by topic.

## Publish the config

The addon works without a config file in your project. To change a setting, publish the config once:

```bash
php artisan vendor:publish --tag=workflows-config
```

This copies the file to `config/workflows.php`. Keys you leave out of your copy fall back to the addon's defaults.

Many settings also read an environment variable, so you can change them per environment without publishing the config. If you cache your config with `php artisan config:cache`, run it again after changing `.env`.

> [!NOTE]
> Per-workflow settings, such as places, transitions, who may create entries in one workflow, or the four-eyes principle, live in the workflow's YAML and are edited in the [workflow editor](editor.md). This page covers the project-wide settings only.

## Database

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `database` | `array` | SQLite, see below | – | Connection settings for the workflow data. The addon registers them as the database connection `workflow`. |
| `database.driver` | `string` | `'sqlite'` | – | Any Laravel database driver. |
| `database.database` | `string` | `storage_path('app/workflows/workflow.sqlite')` | – | For SQLite: the file path. The file and its folder are created automatically if missing. |
| `database.prefix` | `string` | `''` | – | Table prefix. |
| `database.foreign_key_constraints` | `bool` | `true` | – | SQLite foreign key enforcement. |

To keep the workflow data in your main database instead, replace the array with a full Laravel connection, for example:

```php
'database' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
],
```

Then run the addon migrations against it, see [Installation](installation.md#update-the-addon).

## Revisions

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `revisions` | `bool` | `true` | `WORKFLOWS_REVISIONS` | Whether your project uses Statamic revisions together with the workflows. When `true`, the addon switches Statamic revisions on, unless `statamic.revisions.enabled` is explicitly `false`, and the editor warns about publishing workflows whose collections have no revisions. `false` leaves Statamic's setting alone and silences that warning. |

Statamic ships with `STATAMIC_REVISIONS_ENABLED` set to `false`, which counts as explicitly off. To use revisions, set `STATAMIC_REVISIONS_ENABLED=true` and install Statamic Pro. Every transition also tries to write a Statamic revision named after the transition, independent of this setting. If Statamic can't write it, for example without Pro, the transition continues without one. See [Installation](installation.md#revisions-optional).

## Groups and permissions

Lists of Statamic user group handles. Super admins are always allowed, whatever these lists say. Details and how the lists interact with per-workflow settings: [Permissions](permissions.md).

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `privileged_groups` | `array` | `['admins']` | – | Members bypass the workflow gates: they may set a state manually, move entries between sites, publish outside the workflow and use Statamic's own publish button. |
| `post_creator_groups` | `array` | `[]` | – | Project-wide fallback for who sees the "New post" button on a manager page. A workflow's own "May create entries" setting overrides it. Empty means super admins only. |
| `assigner_groups` | `array` | `[]` | – | Project-wide fallback for who may change the responsibles of an entry. A workflow's own "May assign responsibles" setting overrides it. Empty means super admins only. |
| `history_groups` | `array` | `['admins']` | – | Who may see an entry's history ("Show history", the history drawer and its field changes). Not covered by `privileged_groups`. Empty means super admins only. |
| `comment_moderator_groups` | `array` | `['admins']` | – | Who may delete any comment, not just their own, and resolve or reopen any discussion. A workflow's own "May delete comments" setting overrides it. Not covered by `privileged_groups`. Empty means super admins only. |

> [!NOTE]
> `privileged_groups` does **not** include `history_groups` or `comment_moderator_groups`. List a group explicitly in each list it should be in.

## Comments

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `comment_mention_mail` | `bool` | `true` | `WORKFLOWS_COMMENT_MENTION_MAIL` | Mail users who are @-mentioned in a comment. Never sent to the comment's author; after an edit, only to newly mentioned users. Delivered according to `mail_delivery`. |

## Assignment and dashboard

These keys are extension points. Each has its own page with all options and examples.

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `assignment_criteria` | `array` | `[]` | – | Additional criteria for assignment actions, based on fields of the user or entry blueprint. See [Assignment criteria](extending/assignment-criteria.md). |
| `header_responsibles` | `array` | `['group' => null, 'user_field' => null]` | – | The "responsible" row of the dashboard's user header. Both `null` hides the row. See [Dashboard header responsibles](extending/header-responsibles.md). |
| `dashboard_widgets` | `array\|false` | seven workflow widgets | – | The dashboard layout the addon applies while `widgets` in `config/statamic/cp.php` is empty. `false` turns it off. See [Dashboard widgets](extending/dashboard-widgets.md). |

## Actions and mail types

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `actions` | `array` | `[]` | – | Your own action types for transitions and places, optionally with a PHP handler. See [Custom action types](extending/actions.md). |
| `mail_types` | `array` | `[]` | – | Your own mail types for the `mail` action, with your own copy, data or template. See [Custom mail types](extending/mail-types.md). |

## Workflow editor

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `editor_backups` | `int` | `20` | – | Backups kept per workflow. Before a workflow YAML is rewritten (editor save, installer, switching a workflow on or off, deleting it or resetting it to its example), the current file is copied to `storage/app/workflows/backups/<handle>/<timestamp>.yaml`. Older copies are pruned. `0` turns backups off. |
| `nav_section` | `string` | `'Workflows'` | – | **Deprecated, not read.** Kept so older published configs still load. The managers are reached from the dashboard. |

## Scheduler

Switches for the tasks the addon registers with Laravel's scheduler. Turning a switch off only removes the scheduled task; you can still run the command by hand. For the cron setup, see [Commands & scheduler](commands.md#scheduler).

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `sla_scheduler` | `bool` | `true` | `WORKFLOWS_SLA_SCHEDULER` | Registers `workflows:transition-aged` hourly ([content ageing](sla.md)). |
| `reminder_scheduler` | `bool` | `true` | `WORKFLOWS_REMINDER_SCHEDULER` | Registers `workflows:auto-transition` and `workflows:send-reminders` once a day (the `auto_transition` and `reminder` place actions). |
| `reminder_time` | `string` | `'08:00'` | `WORKFLOWS_REMINDER_TIME` | Time of day (`HH:MM`, app time zone) for both daily tasks. |
| `translation.plain_language.poll_scheduler` | `bool` | `true` | `PLAIN_LANGUAGE_POLL_SCHEDULER` | Registers `workflows:plain-language-poll` every minute. See [Translation](#translation). |

## Mail delivery

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `mail_delivery` | `string` | `'after_response'` | `WORKFLOWS_MAIL_DELIVERY` | How mails triggered by a click are sent, so a transition doesn't wait for the mail server. |

| Value | Behavior |
| --- | --- |
| `after_response` | Sent in the same PHP process right after the response went to the browser. No queue worker needed. |
| `queue` | Pushed onto your default queue connection (`QUEUE_CONNECTION`). Needs a running `php artisan queue:work`, and gets the queue's retries. |
| `sync` | Sent inline, before the response. The click waits for every mail. |

This applies to the `mail` actions of transitions and places, to @-mention mails and to account reminders. Place reminders from `workflows:send-reminders` are always sent inline. See [Notifications](notifications.md).

## Account reminder

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `account_reminder.enabled` | `bool` | `true` | `WORKFLOWS_ACCOUNT_REMINDER` | After every transition, assignees who never activated their account (no password set) get a mail with a fresh activation link. Someone is reminded again only after their last activation link has expired. |
| `account_reminder.expire_hours` | `int\|null` | `null` | `WORKFLOWS_ACTIVATION_EXPIRE_HOURS` | How long an activation link stays valid, in hours. `null` keeps Statamic's setting. A number also applies to the invitations Statamic sends from the CP. |

## Translation

Credentials and behavior of the machine-translation services. They live only here and in `.env`, never in the workflow YAML. All keys, the lifecycle of a translation and the service protocol are described in [Translation & plain language](extending/translation.md).

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `translation.deepl.key` | `string\|null` | `null` | `DEEPL_API_KEY` | DeepL API key. Free keys (ending in `:fx`) use the free endpoint automatically. |
| `translation.plain_language.url` | `string\|null` | `null` | `PLAIN_LANGUAGE_URL` | Base URL of the plain-language service (scheme and host). |
| `translation.plain_language.token` | `string\|null` | `null` | `PLAIN_LANGUAGE_TOKEN` | Bearer token for the service. Never logged. |
| `translation.plain_language.body_extras.guideline` | `string\|null` | `null` | `PLAIN_LANGUAGE_GUIDELINE` | Rewriting profile on the service side. |
| `translation.plain_language.body_extras.type` | `string\|null` | `null` | `PLAIN_LANGUAGE_TYPE` | Kind of rewrite. |
| `translation.plain_language.poll_scheduler` | `bool` | `true` | `PLAIN_LANGUAGE_POLL_SCHEDULER` | Collect finished rewrites every minute. Without it (or your own runner), rewrites are never written back. |
| `translation.plain_language.log_calls` | `bool` | `false` | `PLAIN_LANGUAGE_LOG_CALLS` | Log request and response of successful calls. Failures are always logged. |
| `translation.plain_language.max_polls` | `int` | `5` | `PLAIN_LANGUAGE_MAX_POLLS` | Polls (about one per minute) a submission is waited out before it counts as failed. |
| `translation.plain_language.max_attempts` | `int` | `3` | `PLAIN_LANGUAGE_MAX_ATTEMPTS` | Submissions in total, the first included. `1` disables retries. |
| `translation.plain_language.heading_set` | `string\|null` | `'heading'` | – | Bard set that returned `<h2>`/`<h3>` headings become in a top-level Bard field. `null` turns it off. |
| `translation.plain_language.heading_index_field` | `string\|null` | `'heading_index'` | – | Field in which heading sets are numbered. `null` turns it off. |
| `translation.plain_language.include_disabled` | `bool` | `true` | – | Also rewrite disabled Replicator rows and Bard sets. |

The DeepL service is on as soon as the key is set; the plain-language service needs both `url` and `token`.

## Content fields

The blueprint field handles the translation, the plain-language rewrite and the content preview need to know. Leave a key `null` and the feature that needs it skips that field. Which fields make up an entry's content is set per workflow in the editor. Details: [Content fields](extending/content-fields.md).

| Key | Type | Default | Env | Description |
| --- | --- | --- | --- | --- |
| `content_fields.title` | `string\|null` | `'title'` | – | The entry's title field. |
| `content_fields.intro` | `string\|null` | `null` | – | Bard field with the opening paragraph. |
| `content_fields.body` | `string\|null` | `'content'` | – | The main Bard field. |
| `content_fields.seo` | `string\|null` | `'seo'` | – | The SEO field. |
| `content_fields.entity` | `string\|null` | `null` | – | Field naming the entity or module an entry belongs to. Sent to the plain-language service as context. |
| `content_fields.scope` | `string\|null` | `null` | – | Set field that limits a block to certain entities. |
| `content_fields.sources_title`, `sources_text`, `podcast_link`, `podcast_code`, `sets` | – | `null` / `[]` | – | **Deprecated.** Kept so older configs still load. |

## Environment variables at a glance

```env
WORKFLOWS_REVISIONS=true
WORKFLOWS_COMMENT_MENTION_MAIL=true
WORKFLOWS_SLA_SCHEDULER=true
WORKFLOWS_REMINDER_SCHEDULER=true
WORKFLOWS_REMINDER_TIME=08:00
WORKFLOWS_MAIL_DELIVERY=after_response
WORKFLOWS_ACCOUNT_REMINDER=true
WORKFLOWS_ACTIVATION_EXPIRE_HOURS=

DEEPL_API_KEY=
PLAIN_LANGUAGE_URL=
PLAIN_LANGUAGE_TOKEN=
PLAIN_LANGUAGE_GUIDELINE=
PLAIN_LANGUAGE_TYPE=
PLAIN_LANGUAGE_POLL_SCHEDULER=true
PLAIN_LANGUAGE_LOG_CALLS=false
PLAIN_LANGUAGE_MAX_POLLS=5
PLAIN_LANGUAGE_MAX_ATTEMPTS=3
```

## Override translations

All CP labels and mail texts come from the addon's `workflows::messages` translations in English and German. To change a text, create an override file for the language in your project:

```text
lang/vendor/workflows/en/messages.php
lang/vendor/workflows/de/messages.php
```

The file only needs the keys you change. Laravel merges it over the shipped file:

```php
<?php

// lang/vendor/workflows/en/messages.php
return [
    'state_review' => 'In review',
    'action_publish' => 'Go live',
];
```

To start from a full copy of the shipped files instead:

```bash
php artisan vendor:publish --tag=workflows-translations
```

Place and transition names follow a fixed key scheme:

| Key | Names |
| --- | --- |
| `state_<place handle>` | A place. A label you set for the current language in the workflow editor takes precedence. |
| `action_<transition handle>` | A transition. Takes precedence over the label in the YAML. |

If no key and no label exist, the humanized handle is shown. The wording of your own mail types also goes into these files, see [Custom mail types](extending/mail-types.md).

## Next steps

- [Defining workflows](workflows.md): the workflow YAML and its metadata.
- [Permissions](permissions.md): how the group lists combine with per-workflow settings.
- [Notifications](notifications.md): mail actions, reminders and delivery.
- [Extending](extending/README.md): all extension points in detail.

---

← [Installation](installation.md) · [Docs home](README.md) · [Defining workflows](workflows.md) →
