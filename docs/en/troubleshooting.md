# Troubleshooting

🇩🇪 [Deutsch](../de/troubleshooting.md)

Common problems with the Workflows addon, what causes them and how to fix them.

Most commands that change data have a `--dry-run` option, and failed mails, actions and scheduled steps are written to your Laravel log (`storage/logs`). Check both first.

## Installation and assets

### Composer can't find `takepart-media/statamic-workflows`

**Cause:** Packagist isn't reachable from your project, or `packagist.org` is disabled in your `composer.json`.

**Fix:** Check `composer config repositories` and make sure `packagist.org` isn't set to `false`. Check that the package page exists on [Packagist](https://packagist.org/packages/takepart-media/statamic-workflows).

### The CP shows old or no workflow assets

The CP page stays blank, the browser console shows JavaScript errors, or a fix from an update doesn't show up.

**Cause:** The published assets in `public/vendor/statamic-workflows/build` are missing or from an older version. Publishing without `--force` doesn't replace existing files, so the CP keeps loading the old bundle.

**Fix:**

```bash
php artisan vendor:publish --tag=workflows --force
```

Then reload the browser without cache (Cmd+Shift+R or Ctrl+Shift+R). Add the command to your deploy script.

### Database errors such as "no such table: workflow_states"

**Cause:** The addon migrations haven't run on the `workflow` connection, or the web server can't write to `storage/app/workflows/`.

**Fix:** Run the migrations and check that the PHP user can write to the folder:

```bash
php artisan migrate --database=workflow --path=vendor/takepart-media/statamic-workflows/database/migrations
```

### The installer changed the collections of my workflow

**Cause:** `workflows:install` replaces a workflow's `collections` with what you select, also when the workflow already exists.

**Fix:** Set the collections again in the workflow editor, or restore the previous file from `storage/app/workflows/backups/<handle>/`.

## Finding workflows in the CP

### A workflow doesn't appear in the CP

**Cause:** The workflow managers have no navigation item. You reach them through the "All posts" widget on the dashboard. `/cp/workflows` redirects to the dashboard.

**Fix:** Check these points in order:

1. The dashboard shows the `workflows_overview` widget. If your project lists its own widgets in `config/statamic/cp.php`, add it there, see [Installation](installation.md#set-up-the-dashboard).
2. The YAML is in `resources/workflows/<handle>.yaml`. The examples inside the addon are not loaded.
3. The workflow is active: no `active: false` in the YAML, and switched on in **Settings > Workflows**.
4. The workflow has at least one collection. A workflow without collections has no entries, so its manager stays empty.

### A manager page returns 404

**Cause:** The workflow has no `metadata.nav.slug`, or it is switched off.

**Fix:** Set a slug in the workflow editor and activate the workflow. The manager is then at `/cp/workflows/<slug>`.

### "Settings > Workflows" is missing

**Cause:** Only super admins see the workflow settings and editor.

**Fix:** Log in with a user that has `super: true`.

### The entry editor shows no workflow panel

**Cause:** The entry's collection isn't in the `collections` of any active workflow.

**Fix:** Add the collection to the workflow in the editor. If the panel still doesn't show, clear the cache with `php artisan cache:clear`.

### All existing entries sit in the initial place

**Cause:** States were backfilled for a workflow that has neither a publishing place (`allow_publish`) nor `metadata.seed_published_place`. Published entries then have no "finished" place to go to.

**Fix:** Set `metadata.seed_published_place` to the place finished entries belong in, then derive the states again. Entries with history are left alone:

```bash
php artisan workflows:seed-states --workflow=<handle> --redo
```

## Permissions

### A transition button is missing, or nobody can be assigned

**Cause:** The transition is gated by `required_group` or `required_role`, and the user isn't a member. A group handle that doesn't exist in `resources/users/groups.yaml` causes no error, it just matches nobody. The same goes for assignment pickers and `default_assignee_group`.

**Fix:** Create the group, or correct the handle in the workflow editor, and add the users to it. Super admins and members of `privileged_groups` bypass the gates. See [Permissions](permissions.md).

### Statamic's "Publish" button is missing

**Cause:** Regular users can't publish directly from the entry editor. Publishing goes through a transition into a place with `allow_publish: true`. This applies to every entry, also in collections no workflow governs. While a published entry has a working copy, the workflow's "may publish revisions" setting decides instead.

**Fix:** Move the entry through the workflow. If a group should be able to publish outside the workflow, add it to `privileged_groups`. Super admins always see the button.

### A translation can't be published

**Cause:** A translated version is handled by the translation workflow, not by the post manager. The shipped `translation` example ends in `approved`, which is not a publishing place.

**Fix:** Add `allow_publish: true` to the final place of the translation workflow, so the final approval publishes the translation. Or leave publishing to super admins and `privileged_groups` on purpose.

### An entry opens read-only

**Cause:** The workflow has "Only assignees may edit" turned on, and the user isn't assigned to the entry.

**Fix:** Assign the user, or add them to the setting's exemptions. Super admins can always edit.

### "Show history" is missing

**Cause:** The user isn't in `history_groups` (default `['admins']`). `privileged_groups` doesn't cover the history.

**Fix:** Add the group to `history_groups` in `config/workflows.php`.

### A user can't delete or resolve someone else's comment

**Cause:** Users can only delete their own comments. Deleting any comment needs the workflow's "May delete comments" setting or, if the workflow doesn't set it, `comment_moderator_groups` (default `['admins']`). Discussions can be resolved by their author, the entry's current assignees and moderators.

**Fix:** Add the user or group to "May delete comments" in the workflow editor, or the group to `comment_moderator_groups`.

### "New post" or the assignee picker is missing

**Cause:** Both are empty by default, so only super admins may create entries from a manager or change responsibles.

**Fix:** Set "May create entries" and "May assign responsibles" per workflow in the editor, or `post_creator_groups` and `assigner_groups` project-wide.

## Mails

### Workflow mails don't arrive

**Cause and fix:** Go through these points:

1. **No mail action.** The transition or the place it leads to needs a `mail` action. See [Notifications](notifications.md).
2. **Mail switched off for this step.** The assignee picker has a "Send email notification" toggle.
3. **Recipient.** Nobody gets a mail about their own click, and users without an email address get nothing.
4. **Queue without worker.** With `mail_delivery` set to `queue`, the mails wait until `php artisan queue:work` runs. Start a worker, or switch to `after_response`.
5. **Mailer.** Check `MAIL_MAILER` and the sender in `.env`. With `log`, mails only end up in the log.
6. **Errors.** Look for "Workflow notification failed." in the log.

### The account activation reminder isn't sent

**Cause:** The reminder goes out only when all of these are true:

- `account_reminder.enabled` is `true`.
- A **transition** ran. Setting a state manually or a direct move by content ageing doesn't trigger it.
- The user is assigned to the entry, has an email address and didn't trigger the transition.
- The account isn't activated yet (no password set).
- The user's last activation link has expired. While a link is still valid, including the one from Statamic's CP invitation, nothing is sent and that link keeps working.

**Fix:** Wait for the link to expire, or shorten the validity with `account_reminder.expire_hours`. Delivery follows `mail_delivery`, so the queue worker point above applies too.

### Place reminders aren't sent

**Cause:** The scheduler isn't running, `reminder_scheduler` is `false`, the entry has no assignees, or the reminder isn't due yet. The first reminder is due `every_days` after the entry arrived in the place, each further one `every_days` after the previous one.

**Fix:** Set up the [scheduler](commands.md#scheduler), then check what is due:

```bash
php artisan workflows:send-reminders --dry-run
```

## Scheduler and ageing

### Published entries don't age

**Cause:** One of these:

- The scheduler isn't running, or `sla_scheduler` is `false`.
- The workflow has no `metadata.sla`.
- It's a translation workflow. Ageing belongs to the authoring workflow only.
- The entry's content is younger than the threshold. The age is counted from `content_updated_at`.

**Fix:** Check what would happen:

```bash
php artisan workflows:transition-aged --dry-run
```

See [Content ageing (SLA)](sla.md).

### An automatic transition moved an entry by mistake

**Fix:** Take it back:

```bash
php artisan workflows:undo-auto-transition <entry-id> --dry-run
php artisan workflows:undo-auto-transition <entry-id>
```

Assignees aren't restored. Set them again in the entry.

### A plain-language rewrite never arrives

**Cause:** Rewrites are collected by `workflows:plain-language-poll`. Without the scheduler, or with `poll_scheduler` set to `false`, they're never written back. The command also does nothing while `PLAIN_LANGUAGE_URL` or `PLAIN_LANGUAGE_TOKEN` is missing.

**Fix:** Set up the scheduler and check the service configuration. Run the poll by hand to see what the service answers:

```bash
php artisan workflows:plain-language-poll --dry-run
```

To log successful calls in full, set `PLAIN_LANGUAGE_LOG_CALLS=true`. Failures are always logged. See [Translation & plain language](localization.md).

## Revisions

### "Unpublished changes" never happens

Changes to a published entry go live at once instead of waiting for review.

**Cause:** Statamic revisions aren't active. The workflow editor shows one of these warnings: "Collection [...] has no revisions" or "Revisions are switched on, but Statamic ignores them".

**Fix:** Install Statamic Pro, set `STATAMIC_REVISIONS_ENABLED=true` and enable revisions for the collection. See [Installation](installation.md#revisions-optional).

## Workflow editor

### A workflow can't be saved because of an unknown action, mail type or criterion

**Cause:** A custom declaration in `config/workflows.php` couldn't be read and was dropped. Typical reasons: a handler or mail class that doesn't exist or doesn't implement the contract, or an assignment criterion pointing at a blueprint field that doesn't exist.

**Fix:** Correct the declaration, for example create the blueprint field first. See [Extending](extending/README.md).

### My comments in the YAML are gone

**Cause:** The editor rewrites the YAML and drops comments.

**Fix:** The previous version is in `storage/app/workflows/backups/<handle>/`. Keep explanations in labels and descriptions instead of YAML comments.

## Good to know

- **History without changes:** history entries recorded before field snapshots existed show "No diff data available (snapshot missing)." That's expected.
- **Assignees per language version:** assignees belong to a version of an entry, not to the post as a whole. The German and English versions can have different assignees.
- **State filter on manager pages:** filtering by state shows a post when **any** of its site versions is in that state.
- **Deleted entries:** their workflow data is removed automatically. For data left behind by hand-edited YAML, use [`workflows:prune-states`](commands.md#workflowsprune-states).

## Next steps

- [Commands & scheduler](commands.md): every command and its options.
- [Configuration](configuration.md): all settings in one place.
- [Permissions](permissions.md): who may do what.

---

← [Commands & scheduler](commands.md) · [Docs home](README.md) · [Extending](extending/README.md) →
