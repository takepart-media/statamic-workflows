# Workflow editor

🇩🇪 [Deutsch](../de/editor.md)

The graph editor in the Control Panel lets super admins build and change workflows visually, without touching YAML.

## Opening the editor

Go to **Settings > Workflows**. The entry and everything below it are visible to **super admins only**; `privileged_groups` and the `admins` group are not enough.

The overview lists every workflow in `resources/workflows/` with its handle, collections and status (**Active** / **Inactive**). Each row offers:

| Button | What it does |
| --- | --- |
| **Settings** | Opens the graph editor for this workflow. |
| **Queue** | Opens the workflow's manager page (active workflows with a `nav.slug` only). |
| **Backfill states** | Gives every governed entry without a stored state one now. The same as `php artisan workflows:seed-states`. |
| **Activate** / **Deactivate** | Writes `active: true` / `active: false`. An inactive workflow disappears from the nav, the manager pages and the entry editor. Its definition and history are kept. |
| **Delete** | Removes the YAML from `resources/workflows/`. States, assignees, history and comments stay in the database, so reinstalling the same handle brings them back. |

The workflows you set up with `metadata.nav` also appear as children of **Settings > Workflows** and link straight to their editor.

## Creating a workflow

1. Click **Create workflow**.
2. Enter a **Name**. The **Handle** is derived from it until you edit it yourself.
3. Pick a **Start from** option:
   - **Blank**: two places, "Draft" and "Published", to build on.
   - **Template**: one of the shipped examples (`postmanager`, `translation`, `plainlanguage`), under your new handle.
   - **Copy of**: a duplicate of an existing workflow in this project.
4. Choose the **Collections**. For a template or a copy you can keep the source's collections.
5. Click **Create**.

The handle is the file name and the key of every stored state, so it cannot be changed later. It must start with a lowercase letter, contain only `a-z`, `0-9` and `_`, and be at most 48 characters long. `install`, `create`, `settings`, `api` and `instances` are reserved.

> [!NOTE]
> New workflows start **inactive**. Review them, switch them on, then take over existing entries with **Backfill states**.

The command-line counterpart is `php artisan workflows:make`. See [Commands & scheduler](commands.md).

## The editor at a glance

The editor has three areas:

- **Toolbar** at the top.
- **Canvas** in the middle: places are cards, transitions are nodes on the lines between them.
- **Side panel** on the right: the **Overview** when nothing is selected, otherwise the panel for the selected place or transition.

### Toolbar

| Control | What it does |
| --- | --- |
| **Active** | Switches the workflow on or off. Applies immediately, independent of saving. |
| **Add place** / **Add transition** | Adds a new node. |
| Undo / Redo | Steps back and forward through your edits. |
| Zoom out / Zoom in / **Fit to view** | Canvas zoom. |
| **Auto layout** | Arranges every node automatically. |
| **Minimap** | Shows an overview map of the canvas. |
| **Show system steps** | Shows the steps the addon adds by itself (for example the revision loop). They are not editable. |
| Problems | The number of errors and warnings. Opens the problems list. |
| **Workflow settings** | Opens the settings sections. See [Workflow settings](#workflow-settings). |
| **Save** | Validates and writes the YAML. |

### Working on the canvas

- Add the next step with the **+** beside a node, with **Tab**, or by dragging a line from a node's output.
- Double-click the empty canvas to add a new place.
- Right-click a node for **Open**, **Add next step (Tab)**, **Make start place** and **Delete**. Right-click a connection for **Remove connection**. Right-click the empty canvas for **New status here** and **New transition here**.
- Click a place or transition to edit it in the side panel.

A few connections are not possible, and the editor says why:

- A place cannot connect to itself directly. A loop goes through a transition.
- A transition always leads into a place, never into another transition.
- System steps cannot be connected.
- The first transition always starts in the initial place. Change the initial place or the transition order instead.

### Keyboard shortcuts

| Keys | Action |
| --- | --- |
| Ctrl/⌘ + S | Save (also while typing) |
| Ctrl/⌘ + Z | Undo |
| Ctrl/⌘ + Shift + Z, Ctrl + Y | Redo |
| Ctrl/⌘ + , | Open or close the workflow settings |
| Esc | Close settings, close menu, clear selection, collapse panel (in that order) |
| Delete / Backspace | Delete the selection |
| Tab | Open the add menu beside the focused node |
| Enter | Open the focused node's panel |
| Arrow keys | Nudge the focused node by 10 px (with Shift: 50 px) |
| 1 / 0 / + / - | Fit / 100 % / zoom in / zoom out |
| Shift + L | Arrange automatically |

Single keys never fire while you type in a field, a menu or a dialog.

## Editing a place

Select a place to open its panel. It has three tabs.

### General

| Field | YAML |
| --- | --- |
| **Handle** | The key under `places`. Derived from the name for a new place. Fixed once saved, because states, history and translations use it. |
| **Name** | `label` as a per-language map (`en`, `de`). Leave a language empty to use the language-file name (shown in grey). |
| **Colour** | `metadata.color` |
| **Allows publishing** | `metadata.allow_publish` |
| **Side branch** | `metadata.branch`. Not offered on the initial place. |
| **Order** | The place's position in `places`. Drives the progress bar, the final place and the filters, independent of the canvas position. |
| **Initial place** | **Make start place** sets `initial_place`. |

### Actions

The behaviors that apply while an entry sits in this place (`metadata.actions`). Click **Add action** and pick a type:

| Type in the editor | YAML `type` |
| --- | --- |
| **Send mail** | `mail` (with **Mail type**) |
| **Reminder every X days (email)** | `reminder` |
| **Move on automatically after X days** | `auto_transition` |
| **Refresh after X minutes (with modal)** | `refresh_interval` |
| **Loading spinner during rewrite** | `pending_rewrite_spinner` (plain-language manager only) |

Each type can be added once per place. The mail actions show which mailer is configured and whether it actually delivers: with `MAIL_MAILER=log`, transitions succeed but nobody receives a mail. See [Defining workflows](workflows.md#place-actions) for what each action does.

### Connections

Shows the **Incoming transitions**, the **Outgoing transitions**, what the place is **Used as** (for example the SLA target or the backfill place), and how many **Entries** sit in it right now.

#### Deleting a place

Click **Delete** on the Connections tab. The start place cannot be deleted; make another place the start first.

If entries sit in the place, choose a **Move to** target. The editor marks the place as "Deleted on save" and removes its connections right away. Click **Restore** to bring it back before saving. On save, the entries move to the target place and their SLA and auto-transition clocks restart.

## Editing a transition

Select a transition to open its panel. It has three tabs.

### General

| Field | YAML |
| --- | --- |
| **Handle** | The key under `transitions`. Fixed once saved. |
| **Display name** | `metadata.label`. Empty uses the language-file text (`action_<handle>`), otherwise the handle. |
| **Button variant** | `metadata.variant`, with a live preview of the button. |
| **From** | `from`. Several source places are possible. For the first transition, `from` follows the initial place. |
| **To** | `to` |
| **Order** | The transition's position in `transitions`. The first transition is where the workflow starts. |

Badges explain special transitions: **Start** (first step of the workflow, or the one carrying `start_localization`), **CTA** (the `create_transition` run by "New post") and **System** (runs automatically, never clicked).

### Permission

**May perform this step** decides who may click the transition, on top of being responsible for the entry:

| Option | YAML |
| --- | --- |
| **Anyone responsible** | Neither key. |
| **Group** + handles | `metadata.required_group` |
| **Role** + handles | `metadata.required_role` |

The editor stores exactly one of the two keys. Switching the type clears the selection. A hand-written YAML may declare both (a union); the editor then shows the role, and only the role survives the next save. Keep such a transition in YAML if you need the union.

### Actions

The steps around the transition (`metadata.actions`):

| Type in the editor | YAML `type` |
| --- | --- |
| **User assignment** | `user_assignment` |
| **Send mail** | `mail` |
| **Gate: site must still be missing** | `target_missing` |
| **Creates the target version (with language picker)** | `start_localization` (localization workflows only, once per workflow) |
| **Rewrite plain language** | `plain_language_resubmit` (plain-language manager only, not on the start transition) |

For **User assignment**, choose **Pick manually …** or **Assign automatically …**, then **when all of the following conditions are met** or **when any of the following conditions are met**. **Add condition** adds a criterion; **Add group** adds a nested group with its own join. You can type any handle as a value, not only the options in the dropdown.

The type list only offers what the transition can still carry: one user assignment, one mail, and `start_localization` only where no other transition has it. Project actions from `config/workflows.php` appear in the same list. See [Actions](extending/actions.md).

## Workflow settings

Click **Workflow settings** in the toolbar (or press Ctrl/⌘ + ,). The settings are grouped into sections; each shows how many problems it contains. Every change goes through undo/redo and is saved with **Save**.

### General

| Label | YAML |
| --- | --- |
| **Nav label** | `metadata.nav.label` |
| **Nav icon** | `metadata.nav.icon` |
| **Nav order** | `metadata.nav.order` |
| **Nav slug** | `metadata.nav.slug` (read-only) |
| **Initial place** | `initial_place` |
| **Progress bar** | `metadata.progress_orientation` |
| **Finished place (for backfill)** | `metadata.seed_published_place`. Shown only when no place allows publishing, or when a value is stored. |

### Permissions

| Label | YAML |
| --- | --- |
| **Default assignee group** | `metadata.default_assignee_group` |
| **Assign creator automatically** | `metadata.assign_creator` |
| **Prevent self-assignment** | `metadata.self_assignment` (checkbox, audience and optional hint) |
| **Four-eyes principle** | `metadata.four_eyes` (checkbox, audience and optional hint) |
| **Only assignees may edit** | `metadata.assignees_only_edit` |
| **Exempt** | `metadata.edit_exempt`. Shown while "Only assignees may edit" is on. |
| **May mark comments as done** | `metadata.comment_resolvers` |
| **May delete comments** | `metadata.comment_deleters` |
| **May create entries** | `metadata.post_creator_groups`. Entry workflows only. |
| **Workflow action on create** | `metadata.create_transition`. Entry workflows only. |
| **May assign responsibles** | `metadata.assigner_groups` |

The audience fields work in rows: pick a kind per row (**Group**, **Role** or **User**) and its entries. Matching any row qualifies. See [Permissions](permissions.md) for how these settings combine.

### Localization

Only for localization workflows (`detail: translation`). The section adapts to the kind of manager.

| Label | YAML | Shown in |
| --- | --- | --- |
| **Source site** | `metadata.translation_source_site` | both |
| **Target site** | `metadata.plain_language_site` | plain language |
| **Target place after job completion** | `metadata.plain_language_completed_place` | plain language |
| **Target place on failed translation** | `metadata.plain_language_failed_place` | plain language |
| **Never rewrite** | `metadata.plain_language_skip_fields` (comma-separated) | plain language |
| **Show QA notes** | `metadata.qs_notes_visible` | plain language |
| **Show QA notes to:** | `metadata.qs_notes_audience` | plain language |
| **Source selectable when translating** | `metadata.translation_source_pick` | translation |
| **Source view per audience** | `metadata.translation_source_view` | both |
| **Back into step** | `metadata.source_draft_place` | both |

Below the fields, **Translation APIs** shows whether the DeepL key or the plain-language service URL and token are configured. See [Translation & plain language](localization.md).

### Collections

| Label | YAML |
| --- | --- |
| **Collections** | `collections` |
| **Revisions** | `metadata.revisions`. Written into each collection's YAML on save. Requires Statamic Pro. |
| **May publish revisions** | `metadata.revision_publishers`. Shown while revisions are on. |

For each collection another workflow already owns, the section says so. Newly added collections are backfilled with states on save.

> [!WARNING]
> Removing a collection asks you to confirm with **Remove and delete data**: the state, assignments, history and comments of its entries in this workflow are deleted for good.

### Content

**Content fields** (`metadata.content_fields`): which fields are this workflow's content, in reading order. Drag or use the arrows to reorder. Leave it empty and each collection reads its automatically detected field; the section lists what that is. See [Content fields](extending/content-fields.md).

### SLA

Only for entry workflows.

| Label | YAML |
| --- | --- |
| **First stage after X days** | `metadata.sla.needs_update_days` |
| **First stage: step** | `metadata.sla.needs_update_place` |
| **First stage: responsible** | `metadata.sla.needs_update_assign` |
| **Second stage after X days** | `metadata.sla.outdated_days` |
| **Second stage: step** | `metadata.sla.outdated_place` |
| **Second stage: responsible** | `metadata.sla.outdated_assign` |

See [Content ageing (SLA)](sla.md).

### Order

Reorder **Places** and **Transitions** by dragging. The place order drives the progress bar, the final place and the filters. The first transition is where the workflow starts.

### Advanced

- **Reset positions** re-arranges every node; stored with the next save.
- **Saved data (preview)** shows, read-only, what a save would send.

### Settings that are YAML-only

The editor keeps every key it does not know, so you can combine both ways. These keys have no field in the editor: `metadata.label`, `metadata.detail`, `metadata.history_fields`, `metadata.translation_exclude_fields`, `metadata.plain_language_clear_fields`, `metadata.site_fields`, and `system` on transitions. See [Defining workflows](workflows.md).

## Validation

The editor checks your changes against the server while you work.

- **Errors** are what a save would refuse, for example an unknown place, a missing `start_localization` in a localization workflow, or an automatic assignment without criteria.
- **Warnings** are allowed but probably unintended, for example a place no transition reaches, a place with no way out, two transitions with the same from and to, or a governed collection without revisions.

Problems appear in the toolbar counter, at the node, at the field, and as a count on each settings section. While a check is running, the toolbar shows **Checking …**.

If you save with errors, the editor asks **There are N errors — save anyway?**. The server checks again and refuses what cannot work; your changes then stay in the editor and the message names the problem.

## Saving

Click **Save** or press Ctrl/⌘ + S. On save the editor:

1. checks that nobody changed the workflow in the meantime,
2. validates the definition,
3. backs up the current YAML and writes the new one,
4. moves the entries of deleted places and purges removed collections,
5. writes `metadata.revisions` into the collections.

If any step after writing fails, the previous YAML is restored from the backup.

### Conflicts

If someone else saved the workflow, or the file changed on disk, after you opened it, you see **Workflow was changed**. Choose **Reload** to discard your changes and load the current version, or **Overwrite anyway** to replace it.

### Backups

Before a workflow YAML is rewritten (a save, switching it on or off, deleting it, or `workflows:install`), the current file is copied to:

```
storage/app/workflows/backups/<handle>/<timestamp>.yaml
```

No copy is made when the file equals the newest backup. The number of copies kept per workflow is `editor_backups` in `config/workflows.php` (default `20`; `0` switches backups off). There is no restore button: copy a backup back to `resources/workflows/<handle>.yaml` to restore it.

> [!TIP]
> The editor's YAML output has no comments. If you annotate your workflow files by hand, the backups are where those comments survive.

## Next steps

- [Defining workflows](workflows.md): the full YAML reference behind every field.
- [Permissions](permissions.md): what the permission settings do.
- [Working with workflows](using.md): the manager pages and the detail page.

---

← [Defining workflows](workflows.md) · [Docs home](README.md) · [Working with workflows](using.md) →
