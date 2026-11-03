# Installation

🇩🇪 [Deutsch](../de/installation.md)

Install the Workflows addon in your Statamic project, set it up with one command, and keep it up to date.

## Requirements

| Requirement | Version | Notes |
| --- | --- | --- |
| PHP | 8.3 or higher | The `pdo_sqlite` extension is needed for the default database. |
| Statamic | 6 (`statamic/cms: ^6.0`) | |
| Laravel | 12 or 13 | Whatever Statamic 6 requires. |
| Composer | 2 | |
| Statamic Pro | optional | Only needed for revisions (the "Unpublished changes" loop). Everything else works without Pro. |

You don't need Node or npm. The addon ships prebuilt CP assets.

## Install the package

The addon is available on [Packagist](https://packagist.org/packages/takepart-media/workflows):

```bash
composer require takepart-media/workflows
```

Composer installs the addon to `vendor/takepart-media/workflows`, and Statamic registers it automatically. `composer require` pins the current major version (for example `^1.0`). Releases follow [Semantic Versioning](https://semver.org/), so you get fixes and features within 1.x but no breaking changes.

## Run the installer

```bash
php artisan workflows:install
```

The installer walks you through these steps:

1. **Run the migrations.** The addon keeps its data in a separate SQLite database at `storage/app/workflows/workflow.sqlite`. The file is created automatically the first time the addon boots. The migrations create seven tables in it (states, history, assignees, comments, comment reads, watches and plain-language jobs).
2. **Publish the CP assets** to `public/vendor/workflows/build` (same as `php artisan vendor:publish --tag=workflows --force`).
3. **Pick the workflow types.** A multi-select lists the three example workflows that ship with the addon: `postmanager` (editorial review and publishing), `translation` (translating into further sites) and `plainlanguage` (plain-language versions). `postmanager` is preselected if it isn't installed yet. You can pick several types in one run.
4. **For each picked type:**
   1. The example is copied to `resources/workflows/<type>.yaml`. If that file already exists, it is kept as it is.
   2. **Pick the collections** the workflow should govern, from the collections in your project. Use the arrow keys to move, space to select and enter to confirm.
   3. **Backfill states** (optional, default "no"). Existing entries of these collections get a workflow state, the same as `php artisan workflows:seed-states`.

> [!IMPORTANT]
> The collection selection **replaces** the `collections` list of the workflow, also for a workflow that already exists. If you run the installer again, select every collection the workflow should keep. The previous file is saved as a backup in `storage/app/workflows/backups/<handle>/` first.

Only workflows in `resources/workflows/` are loaded. The examples inside the addon are templates and are never loaded on their own. To start a workflow from scratch instead, use [`workflows:make`](commands.md#workflowsmake).

## Verify the installation

Log in to the CP and check the following:

1. **Dashboard:** the "All posts" widget (`workflows_overview`) lists the entries of your workflows. The workflow column links to each workflow's manager page, for example `/cp/workflows/post-manager`. The managers have no navigation item of their own; the dashboard is the entry point.
2. **Settings:** as a super admin, you see **Settings > Workflows** with the graph editor for each workflow. Other users don't see this item.
3. **Entry editor:** open an entry of a governed collection. The workflow panel shows the workflow's action buttons.

If the CP stays blank or shows outdated behavior, see [Troubleshooting](troubleshooting.md#the-cp-shows-old-or-no-workflow-assets).

## Prepare your project

The addon doesn't create groups, users or blueprint fields for your project. Check these points before your editors start:

### User groups

Every group handle a workflow references must exist in `resources/users/groups.yaml`, for example in `required_group`, `default_assignee_group` or an assignment action. The shipped examples use these handles:

| Example | Group handles |
| --- | --- |
| `postmanager` | `editor`, `expert`, `approver` |
| `translation` | `translator`, `linguistic_reviewer`, `expert_reviewer`, `publisher` |
| `plainlanguage` | `editor`, `expert_reviewer`, `publisher` |

Create the groups or change the handles in the [workflow editor](editor.md). A handle that doesn't exist causes no error. It simply matches nobody, so the gated buttons stay hidden and assignment pickers stay empty.

The addon's project-wide permission lists (`privileged_groups`, `history_groups`, `comment_moderator_groups`) default to `admins`. Create that group as well, or change the lists in the [configuration](configuration.md#groups-and-permissions).

### Super admins

You need at least one user with `super: true`. Super admins bypass every workflow gate and are the only ones who can open **Settings > Workflows**.

### Blueprints

You don't have to edit any blueprint. The addon adds two fields to the entry blueprints of every governed collection on its own: the workflow panel (`workflow_actions`) and the "last content update" date (`content_updated_at`) that [content ageing](sla.md) reads.

If your content lives in fields other than `title`, `content` and `seo`, map them under [`content_fields`](extending/content-fields.md) or pick the content fields per workflow in the editor.

### Revisions (optional)

The "Unpublished changes" loop (a working copy of a published entry runs through review before it goes live) needs Statamic revisions. Turn them on in three places:

1. Statamic Pro is installed and licensed.
2. `STATAMIC_REVISIONS_ENABLED=true` is set in `.env`.
3. The collection has revisions enabled. You can switch this for all collections of a workflow in the workflow editor.

The editor warns you when a publishing workflow governs a collection without revisions.

### Mail

Workflow mails go out through your Laravel mailer. Configure `MAIL_MAILER` and the sender as usual. Users without an email address get no mail. See [Notifications](notifications.md).

### Scheduler

Content ageing, place reminders, automatic transitions and the plain-language service need Laravel's scheduler. Add the cron entry described in [Commands & scheduler](commands.md#scheduler).

## Set up the dashboard

The dashboard is the entry point to the workflows. How it's filled depends on `config/statamic/cp.php`:

- **`widgets` is empty** (Statamic's default): the addon applies its own layout from [`dashboard_widgets`](extending/dashboard-widgets.md). You don't need to do anything.
- **`widgets` lists anything:** only your list applies, and nothing from the addon is merged in. Add the workflow widgets you want yourself:

```php
// config/statamic/cp.php
'widgets' => [
    ['type' => 'workflows_user_header', 'width' => 100],
    ['type' => 'workflows_queue_stats', 'width' => 100],
    ['type' => 'workflows_my_queue', 'width' => 100, 'limit' => 7],
    ['type' => 'workflows_watched', 'width' => 50, 'limit' => 5],
    ['type' => 'workflows_created_by_me', 'width' => 50, 'limit' => 5],
    ['type' => 'workflows_overview', 'width' => 100],
    ['type' => 'workflows_status', 'width' => 100],
],
```

Include at least `workflows_overview`. Without it, users have no link to the workflow managers. All widgets and their options are listed in [Dashboard widgets](extending/dashboard-widgets.md).

## Update the addon

1. Update the package:

   ```bash
   composer update takepart-media/workflows
   ```

2. Publish the new CP assets. `--force` is required, otherwise the old files stay in place and the CP keeps loading the old bundle:

   ```bash
   php artisan vendor:publish --tag=workflows --force
   ```

3. Run the addon migrations if the release adds any (see the [changelog](../../CHANGELOG.md)). The migrations are safe to run again:

   ```bash
   php artisan migrate --database=workflow --path=vendor/takepart-media/workflows/database/migrations
   ```

   A plain `php artisan migrate` also picks them up.

> [!TIP]
> Add steps 2 and 3 to your deploy script. A standard Statamic project also runs `php artisan statamic:install` after every `composer install` or `update`, which republishes the assets.

Your workflow YAMLs in `resources/workflows/` are your own and are never overwritten by an update.

## Uninstall

1. Remove the package:

   ```bash
   composer remove takepart-media/workflows
   ```

2. Remove the workflow widgets from `widgets` in `config/statamic/cp.php`, if you added them there.
3. Delete the files the addon created in your project:

   ```bash
   rm -rf public/vendor/workflows storage/app/workflows resources/workflows
   rm -f config/workflows.php
   rm -rf lang/vendor/workflows
   ```

   `storage/app/workflows` holds the workflow database and the editor backups. If you moved the workflow data to another database connection, drop the `workflow_*` tables there.

The addon doesn't change any Statamic core files. Entries keep the `content_updated_at` value the addon wrote into them. It's harmless and you can remove it from the entries if you like.

## Develop the addon locally

> [!NOTE]
> Only relevant if you work on the addon itself.

To test changes to an addon checkout without publishing a release, include it as a Composer path repository:

```json
{
    "repositories": [
        { "type": "path", "url": "../statamic-workflows", "options": { "symlink": true } }
    ],
    "require": {
        "takepart-media/workflows": "*"
    }
}
```

Run `composer update takepart-media/workflows`. Composer links `vendor/takepart-media/workflows` to your checkout. After `npm run build` in the addon, publish the assets again or link `public/vendor/workflows/build` to the addon's `resources/dist/build`. A `vendor:publish --tag=workflows --force` replaces such a link with a copy.

## Next steps

- [Configuration](configuration.md): adjust groups, mail delivery and the scheduler.
- [Defining workflows](workflows.md): places, transitions and actions.
- [Workflow editor](editor.md): edit workflows in the CP.
- [Commands & scheduler](commands.md): all artisan commands and the cron setup.

---

[Docs home](README.md) · [Configuration](configuration.md) →
