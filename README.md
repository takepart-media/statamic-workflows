# Workflows — Statamic Addon

Editorial workflow engine and CP dashboard for **Statamic 6**. Ships a
Symfony-Workflow-based state machine, per-workflow CP managers, group-gated
transitions, assignees, comments, content-diff history, and a translation
manager that groups an entry's site-versions by base site.

Every project defines its own workflows in `resources/workflows/*.yaml`. The
addon ships two example definitions (`postmanager`, `translation`) as
templates — pick one during install. The addon never generates content itself:
every subject in a workflow is a real Statamic entry.

For architecture, data model, and the full YAML schema see [`DOCS.md`](./DOCS.md).

---

## Requirements

- PHP 8.3+
- Statamic 6 (`statamic/cms: ^6.0`)
- Laravel 11+ (Prompts and multi-select support)
- Statamic Pro for the revision-loop feature (optional; everything else works
  without Pro)

---

## Installation

The addon lives at `github.com/takepart-media/workflows` and is not on
Packagist. Add it via Composer as a VCS repository.

### 1. Register the repository and require the package

In your consuming project's `composer.json`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:takepart-media/workflows.git" }
  ],
  "require": {
    "takepart-media/workflows": "dev-main"
  }
}
```

Then:

```bash
composer update takepart-media/workflows
```

The addon carries pre-built frontend assets in `resources/dist/build/`, so you
do **not** need Node or `npm run build` on the target machine.

**Version pinning.** `dev-main` tracks the tip of the `main` branch — fine for
development, risky for production because every `composer update` pulls
whatever landed there. If the addon repo has a Git tag (e.g. `v1.0.0`), pin to
it instead with a semver constraint:

```json
"require": { "takepart-media/workflows": "^1.0" }
```

If `composer update` returns *"does not match the constraint"* with only
`dev-*` versions listed, no tag exists yet — either use `dev-main` for now or
create a tag in the addon repo (`git tag v1.0.0 && git push origin v1.0.0`).

### 2. Bootstrap the addon

```bash
php artisan workflows:install
```

This runs the addon's migrations against a dedicated SQLite database
(`database/workflow.sqlite`, auto-provisioned), publishes the CP JS/CSS, and
then walks you through an interactive setup:

1. Pick a workflow type from the shipped examples.
2. **Multi-select** which collections (from your Statamic project) this
   workflow should govern — arrow keys to navigate, space to toggle, enter to
   confirm.
3. Optionally backfill existing entries into the workflow's initial or
   finished state.

The command is safe to re-run: if `resources/workflows/<type>.yaml` already
exists it aborts without overwriting.

### 3. Private repo: authentication

If your Git host requires authentication for the addon repo, either:

- **SSH deploy key** on the target machine (recommended for servers/CI), or
- A `~/.composer/auth.json` entry with a personal access token for
  `github-oauth`.

---

## Alternative: Local path repo (for developing the addon in-place)

If you're actively working on the addon and want its edits to appear in the
consuming project without a `git push` / `composer update` cycle:

```json
{
  "repositories": [
    { "type": "path", "url": "../workflows", "options": { "symlink": true } }
  ],
  "require": {
    "takepart-media/workflows": "*"
  }
}
```

Then `composer update takepart-media/workflows`. Composer symlinks
`vendor/takepart-media/workflows` to the local checkout, so `npm run build` in
the addon repo makes changes visible after a browser reload — no publish step
in between.

For this setup you may also want `public/vendor/workflows/build/` as a symlink
into the addon so newly built assets show up immediately:

```bash
rm -rf public/vendor/workflows/build
ln -s ../../../../workflows/resources/dist/build public/vendor/workflows/build
```

**Caveat:** `php artisan vendor:publish --tag=workflows --force` (which
`statamic:install` runs internally) overwrites this symlink with a real copy.
After such commands, re-establish the symlink.

---

## Upgrading

```bash
composer update takepart-media/workflows
php artisan vendor:publish --tag=workflows --force
```

The addon carries its own migrations; they run on each `workflows:install`.
For an upgrade there's typically nothing extra to migrate, but running
`workflows:install` again is safe — it aborts on existing definitions and
never overwrites.

---

## Commands

| Command                                                       | Purpose                                                                   |
| ------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `workflows:install`                                           | Interactive first-run setup: migrate, publish, pick type, pick collections, offer backfill |
| `workflows:make <name>`                                       | Scaffold a blank workflow YAML                                            |
| `workflows:make <name> --from=postmanager`                    | Scaffold from a shipped example                                           |
| `workflows:seed-states`                                       | Backfill workflow states for existing entries (idempotent)                |
| `workflows:seed-states --workflow=<name>`                     | Restrict backfill to one workflow                                         |
| `workflows:seed-states --redo`                                | Re-derive states even for previously seeded entries (untouched by humans) |
| `workflows:transition-aged [--dry-run] [--workflow=<name>]`   | Auto-age published entries per `metadata.sla` (see [Scheduler](#scheduler)) |
| `workflows:backfill-content-timestamp [--dry-run] [--workflow=<name>]` | Seed `content_updated_at` from Statamic's last-modified date for entries that have none yet — the clock the ageing counts from |
| `workflow:dump <name> --format=mermaid`                       | Print a Mermaid state diagram for a workflow                              |
| `php artisan vendor:publish --tag=workflows-config`           | Publish `config/workflows.php` for tuning                                  |
| `php artisan vendor:publish --tag=workflows-translations`     | Publish `lang/de` + `lang/en` for translation overrides                    |

---

## Configuration (`config/workflows.php`)

Publish with `php artisan vendor:publish --tag=workflows-config`, then tune:

| Key                    | Default              | Purpose                                                                                                                                                            |
| ---------------------- | -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `database`             | dedicated SQLite     | Redirect workflow state to your primary DB if you don't want a separate file                                                                                       |
| `revisions`            | `true`               | Whether the engine writes Statamic revisions on transitions (needs Statamic Pro)                                                                                   |
| `privileged_groups`    | `['admins']`         | Groups that bypass every workflow gate                                                                                                                              |
| `post_creator_groups`  | `['admins']`         | Fallback group for the "New post" button when a workflow does not set `metadata.post_creator_groups`                                                                |
| `nav_section`          | `'Workflows'`        | **Deprecated, unused.** The addon no longer has its own nav section; the managers are reached from the `workflows_overview` dashboard widget                      |
| `header_responsibles`  | both `null`          | The "Responsible" row of the user-header dashboard card: `group` plus the `user_field` read on both sides. A member of the group counts as responsible when their values in that field overlap the current user's. Both `null` → no row |
| `translation.plain_language.max_polls` | `5` | Polls one submission is waited out before it counts as dead. One poll is one minute at the scheduled tick. Env: `PLAIN_LANGUAGE_MAX_POLLS` |
| `translation.plain_language.max_attempts` | `3` | Submissions a rewrite gets in total, the first included. A failed submission is sent to the service again; only after the last one does the version move to the workflow's failure place. `1` disables retries. Env: `PLAIN_LANGUAGE_MAX_ATTEMPTS` |
| `sla_scheduler`        | `true`               | Register the hourly `workflows:transition-aged` tick. Set to `false` (or `WORKFLOWS_SLA_SCHEDULER=false` in `.env`) to disable auto-aging without touching code    |

---

## Localization

Frontend labels in the CP use the addon's `workflows::messages` translation
namespace. To override for your project, publish and edit:

```bash
php artisan vendor:publish --tag=workflows-translations
```

Files land in `lang/vendor/workflows/{de,en}/messages.php`. Add keys like
`state_<place_handle>` and `action_<transition_handle>` to name your custom
places and transitions in each locale. Missing translations fall back to the
YAML `label` field, then to a humanized handle — nothing crashes, just gets
less pretty.

---

## Writing a workflow

Minimal example (edit `resources/workflows/my_flow.yaml`):

```yaml
name: my_flow
type: state_machine
marking_store: { type: method, property: current_state }
collections: [posts]
initial_place: draft
metadata:
  label: 'Post Update'
  nav: { label: 'Posts', slug: post-manager, icon: newspaper, order: 1 }
places:
  draft:
    label: Draft
    metadata: { required_group: editor }
  published:
    label: Published
    metadata: { required_group: editor, allow_publish: true }
transitions:
  publish:
    from: draft
    to: published
    metadata: { label: 'Publish', required_group: editor }
```

Key metadata fields the addon reads:

- `metadata.label`, `metadata.description` — human-readable workflow name
- `metadata.nav.{label,slug,icon,order}` — gives this workflow its manager page at `/cp/workflows/<slug>` (linked from the `workflows_overview` widget) and a child under the super-admin "Settings > Workflows" nav entry. `label` wins over the `nav.<slug>` translation and then applies in every CP language; leave it empty to let the translation supply a language-following title
- `metadata.detail: translation` — activates the site-by-site translation UI
- `metadata.default_assignee_group` — group used for auto-assignments and
  cross-site distribution
- `metadata.assign_creator: true` — assign the entry's creator on top of
  `default_assignee_group` at creation
- `metadata.post_creator_groups` — groups allowed to see "New post" in this
  workflow's manager (overrides the config default)
- `metadata.seed_published_place` — override which place counts as "finished"
  for backfill (only needed when no place has `allow_publish: true`)
- `place.metadata.required_group` — group required to work in this place
- `place.metadata.allow_publish: true` — entering this place publishes the entry
- `place.metadata.assign_group` — group auto-assigned on entering this place
- `transition.metadata.required_group` / `required_assignee` — gate the button
- `transition.metadata.label` — human-readable transition label
- `transition.metadata.variant` — `default`, `primary`, `danger`, or `filled`
  for the transition button color

Any group handle referenced must exist in `resources/users/groups.yaml`. The
install command does not seed groups — that's the project's responsibility.

The full schema, including SLA, history-fields, and the revision loop, is in
[`DOCS.md`](./DOCS.md).

---

## Scheduler

The SLA aging feature (moving long-published entries into the ageing steps the
workflow declares in `metadata.sla`) runs on Laravel's scheduler. The addon
registers the hourly tick itself in `ServiceProvider::schedule()`; Laravel only
fires it when `php artisan schedule:run` is called on cron in the host project.

**Production** — add one crontab entry as the PHP user:

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

Managed hosts (Forge, Ploi, Runcloud, …) usually have a "Scheduled Jobs" UI
that adds exactly this line — pick "every minute".

**Local development** — Herd/Valet don't run cron. Options:

```bash
php artisan schedule:run    # fire anything due right now
php artisan schedule:work   # foreground worker, ticks every minute until Ctrl+C
```

`schedule:work` is what you want while testing SLA thresholds. Without either,
`workflows:transition-aged` runs only when invoked by hand — `--dry-run` shows
what it would do.

---

## Multi-site

The queue table renders one column per site and reads each site's flag from
its own `shortLocale()` (via the shipped `flag-icons` CSS). The translation
manager additionally groups an entry's localizations by base site, following
the `{base}_{lang}` convention (e.g. `main` + `main_en`). Override
`WorkflowController::siteGroup()` if your project needs a different scheme.

---

## Fieldtypes and widgets

**Fieldtypes**:

- `workflow_actions` — the transition-button panel inside the entry editor.
  **Auto-injected** into every blueprint of a governed collection via the
  `EntryBlueprintFound` listener; no manual blueprint edit needed.
- `revision_status` — optional listing column showing whether a Statamic
  revision is pending. Add it to a blueprint if you want it.

**Widgets**:

Enable them in `config/statamic/cp.php → widgets` (details in DOCS.md § 9):

```php
'widgets' => [
    ['type' => 'workflows_user_header', 'width' => 100],
    ['type' => 'workflows_queue_stats', 'width' => 100],
    ['type' => 'workflows_my_queue', 'width' => 100, 'limit' => 7],
    ['type' => 'workflows_watched', 'width' => 50, 'limit' => 5],
    ['type' => 'workflows_created_by_me', 'width' => 50, 'limit' => 5],
    ['type' => 'workflows_overview', 'width' => 100, 'per_page' => 25],
    ['type' => 'workflows_status', 'width' => 100],
],
```

- `workflows_user_header` — the current user's name, groups and responsibles.
- `workflows_queue_stats` — four key figures (my queue, project-wide counts, failed translations with a retry button).
- `workflows_status` — one doughnut per workflow with its state distribution.
- `workflows_my_queue` — posts assigned to the current user, across all workflows (`limit`, `workflows`).
- `workflows_created_by_me` — posts the current user created (`limit`, `workflows`).
- `workflows_watched` — posts the current user watches (`limit`, `workflows`).
- `workflows_overview` — "All posts": one table across every active workflow (new workflows appear automatically), with a workflow column linking to each manager, one state column per site, workflow/state/stale filters and "new post" buttons. Config: `workflows` (restrict), `per_page` (default 15). This is the entry point to the per-workflow managers, which have no nav entry.
- `workflows_assigned_posts` — posts assigned to the current user in ONE workflow, resolved from `collection` (`limit`).

---

## Diagrams

```bash
# Print Mermaid source for a workflow
php artisan workflow:dump postmanager --format=mermaid

# Render all workflows to SVG + index.html (needs npm deps)
cd vendor/takepart-media/workflows
npm install
npm run workflows:build
```

---

## Contributing / developing the addon itself

The addon repo is standalone — clone it separately from any consuming project:

```bash
git clone git@github.com:takepart-media/workflows.git
cd workflows
npm install
```

For live development against a real project, use the local-path-repo setup
described under [Installation → Local path repo](#alternative-local-path-repo-for-developing-the-addon-in-place).

**Frontend edits** — after every change to `resources/js/**` or
`resources/css/**`, run:

```bash
npm run build
```

The bundle under `resources/dist/build/` is **committed** to the repo so
consumers don't need Node. Include the resulting diff in your commit. If you
forget, consuming projects will boot with a stale manifest and your changes
will not reach the browser.

Merge conflicts inside `resources/dist/build/` are noise — never resolve them
by hand. Always take one side, then re-run `npm run build` and commit.

**Backend edits** — pure PHP changes need no build. Composer's symlink (in the
consuming project) picks them up on the next request.

---

## License

Proprietary — © takepart media.
