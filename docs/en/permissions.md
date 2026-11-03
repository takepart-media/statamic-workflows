# Permissions

🇩🇪 [Deutsch](../de/permissions.md)

This page explains who may do what in a workflow, and how Statamic's own permissions, the addon's config and each workflow's settings combine.

## The building blocks

| Building block | Where it is set | What it decides |
| --- | --- | --- |
| **Super admin** | Statamic user | May do everything, including the graph editor. |
| **Site access** (`access <site> site`) | Statamic role | A hard precondition for every change to an entry. See [Site access comes first](#site-access-comes-first). |
| **Statamic entry permissions** (`view`, `edit` … entries) | Statamic role | Whether the entry editor opens at all. The addon only ever narrows them. |
| **Privileged groups** | `privileged_groups` in `config/workflows.php` (default `['admins']`) | Bypass the workflow's own gates: run any transition, set a state by hand, transfer between sites, publish from the entry editor. |
| **Assignees** | Per entry and workflow | Only assignees (and privileged users) may run transitions. |
| **Transition gates** | `required_group` / `required_role` on a transition | Narrow which assignees may take a step. |
| **Workflow settings** | `metadata` of the workflow | Who creates, assigns, edits, publishes revisions, resolves and deletes comments. |
| **Config groups** | `config/workflows.php` | Project-wide defaults: `post_creator_groups`, `assigner_groups`, `history_groups`, `comment_moderator_groups`. |

> [!IMPORTANT]
> `privileged_groups` relaxes the workflow's own gates. It does **not** grant history access, comment moderation, assigning, creating entries or the graph editor. Each of these has its own setting.

## Permission matrix

"Site" means the user also needs access to the entry's site. Super admins pass every site check.

| Action | Super admin | Privileged group | Everyone else | Set with |
| --- | --- | --- | --- | --- |
| Open manager pages and detail pages | Yes | Yes | Every CP user | — |
| Create entries ("New post") | Yes | Only if listed | Members of the creator groups | `metadata.post_creator_groups`, else config `post_creator_groups` |
| Run a transition | Yes, site | Yes, site | Assignees who pass the transition's gate, site | `required_group`, `required_role` |
| Set a state by hand | Yes, site | Yes, site | No | `privileged_groups` |
| Change assignees | Yes, site | Only if listed | Members of the assigner groups, site | `metadata.assigner_groups`, else config `assigner_groups` |
| Transfer to other sites (translation manager) | Yes, site | Yes, site | Members of `default_assignee_group`, site | `metadata.default_assignee_group` |
| Publish from the entry editor | Yes | Yes, except while a working copy exists and `revision_publishers` is set | Only `revision_publishers`, only while a working copy exists | `metadata.revision_publishers` |
| Edit the entry | Statamic permissions | Statamic permissions, plus the edit lock | Statamic permissions, plus the edit lock | `metadata.assignees_only_edit`, `metadata.edit_exempt` |
| See the workflow history | Yes | Only if listed | Members of the history groups | config `history_groups` |
| Write a comment | Yes | Site | Site | — |
| Edit a comment | Own | Own | Own | — |
| Delete a comment | Yes | Own, plus moderators | Own, plus moderators | `metadata.comment_deleters`, else config `comment_moderator_groups` |
| Resolve or reopen a thread | Yes | See [Comments](#comments) | See [Comments](#comments) | `metadata.comment_resolvers` |
| See the plain-language QA report | Yes, unless switched off | See [QA notes](#qa-notes) | See [QA notes](#qa-notes) | `metadata.qs_notes_visible`, `metadata.qs_notes_audience` |
| Use **Settings > Workflows** and the graph editor | Yes | No | No | — |

## Site access comes first

Every change to an entry requires `access <site> site` for the entry's site: running a transition, setting a state, changing assignees, transferring, commenting, resolving, deleting comments and watching. This check runs **before** the privileged bypass. `privileged_groups` may relax the addon's own rules, but never grants more than Statamic does.

Related rules:

- The **Edit** button on a detail page appears only when the user may view the entry, has access to its site, and is not kept out by the edit lock.
- The **Live preview** button needs site access only, so reviewers without entry permissions can still see the rendered page.
- Only users with CP access and access to the entry's site can be @-mentioned.
- Marking comments as read is never gated: it only stores the user's own "seen" marker.

## Running transitions

When a user clicks a transition, the addon checks in this order:

1. **Site access.** Without it, the step is refused for everyone, privileged users included.
2. **Mechanical blockers.** Reasons the step cannot run at all, whoever asks: a `target_missing` site that already exists, an automatic `user_assignment` with no candidates, or a localization that cannot start. Super admins are blocked too.
3. **Privileged users** (super admins and `privileged_groups`) may run the step.
4. Everyone else must be an **assignee** of this entry version in this workflow.
5. If the transition declares `required_group` and/or `required_role`, the user must be in **one** of the groups **or** hold **one** of the roles. Without either, every assignee may run it.

The disabled button shows the reason in a tooltip, and the server applies the same check.

> [!NOTE]
> `required_group` on a **place** gates nothing. Only the transition-level key counts.

Steps the addon runs on its own are not gated, because nobody clicks them: the SLA scheduler, `auto_transition`, the plain-language poll moving a version into its completed or failed place, and the revision loop's system transitions.

### Why assignments matter

A transition without a `user_assignment` action leaves the assignees as they are. Since only assignees may run transitions, a workflow whose transitions never hand the entry over stays with whoever was assigned on creation (`default_assignee_group` plus, with `assign_creator`, the creator). Put a `user_assignment` on every transition that hands work to someone else. See [Defining workflows](workflows.md#user_assignment).

### Assignment guards

Two optional workflow settings restrict who a click may hand an entry **to**. They change the candidates of a `user_assignment`, not who may click.

| Setting | YAML | Rule |
| --- | --- | --- |
| **Prevent self-assignment** | `self_assignment` | The person who clicks is left out of a manual picker. In automatic mode they stay in a group they merely belong to, because someone else is in it too. |
| **Four-eyes principle** | `four_eyes` | The person who passed the entry on last (the actor of its latest manual transition) is never a candidate, neither manually nor automatically. |

When a rule leaves nobody, the picker opens with the rule's fallback audience instead (minus the excluded people) and shows its hint. Both rules ignore unattended runs such as the SLA scheduler.

```yaml
metadata:
  four_eyes:
    by: group
    handles: [managing_editors]
    hint: 'Pick a managing editor to sign off.'
```

## Assigning responsibles

The assignee picker on a detail page is a coordination decision, so it is gated by group, not by being an assignee:

- Super admins always may.
- Otherwise the user must be in one of the workflow's `assigner_groups`. Without that key, `assigner_groups` from `config/workflows.php` applies.
- Both default to empty, which means **super admins only**.
- Site access is required as well.

`default_assignee_group` is something else: it says who an entry is handed **to** on creation.

## Creating entries

The "New post" button on a manager page appears for:

- super admins, and
- members of the workflow's `post_creator_groups`, or of `post_creator_groups` in the config when the workflow has no such key.

Both default to empty (super admins only). The button only appears in the workflow that owns the collection, never in a localization workflow, and only for sites the user has access to.

## Publishing

The addon replaces Statamic's `publish` ability for entries. Publishing from the entry editor is allowed for:

- super admins,
- members of `privileged_groups`.

Everyone else publishes **through the workflow**: a transition into a place with `allow_publish: true` publishes the entry, and moving into any other place unpublishes it. This only applies when the workflow has at least one `allow_publish` place.

> [!NOTE]
> The `publish` ability is answered this way for every entry, including entries in collections no workflow governs. A role's "publish entries" permission therefore does not let non-privileged users publish from the entry editor.

### Revisions

With Statamic revisions on, editing a live entry creates a working copy and the workflow moves to "Unpublished changes". The copy goes live through Statamic's own publish button.

- Without `revision_publishers`, the usual rule applies: super admins and privileged groups.
- With `revision_publishers`, **while a working copy exists**, only super admins and that audience may publish it. Privileged groups are no longer included.
- Without a working copy, nothing changes: drafts go live through the workflow only.

Statamic reads the ability when the page loads, so someone who just created the working copy sees the button after a reload.

## Editing

The addon never grants an edit the role does not allow. With `assignees_only_edit: true` it narrows Statamic's answer:

- Super admins may always edit.
- Current assignees of the version in its workflow may edit.
- Anyone matching `edit_exempt` may edit.
- Everyone else sees the entry read-only. Viewing still works, and so does the workflow panel, including transitions.

The lock applies only to entries the workflow governs and only while the version sits in a place. **Privileged groups are not exempt**; add them to `edit_exempt` if they should be.

```yaml
metadata:
  assignees_only_edit: true
  edit_exempt:
    - by: group
      handles: [managing_editors]
```

## History

The **Show history** button, the history drawer and its field diffs are visible to:

- super admins, and
- members of `history_groups` in `config/workflows.php` (default `['admins']`).

An empty list means super admins only. `privileged_groups` does not grant history access.

## Comments

| Action | Rule |
| --- | --- |
| Write | Site access. |
| Edit | Only your own comments. |
| Delete | Your own comments always. Any comment: super admins, plus `comment_deleters` when the workflow sets it, otherwise the config's `comment_moderator_groups` (default `['admins']`; empty means super admins only). |
| Resolve / reopen a thread | Without `comment_resolvers`: the thread's author, comment moderators, and the entry's current assignees. With `comment_resolvers`: **only** super admins and that audience. |

```yaml
metadata:
  comment_resolvers:
    - by: group
      handles: [editors]
  comment_deleters:
    - by: role
      handles: [editor_in_chief]
```

## QA notes

The plain-language service's QA report on the detail page:

| `qs_notes_visible` | `qs_notes_audience` | Who sees it |
| --- | --- | --- |
| absent or `true` | absent | Everyone |
| absent or `true` | set | Super admins and the audience |
| `false` | — | Nobody |

`translation_source_view` (who sees the source column hidden or collapsed) is a display choice, not a permission: it applies to super admins too.

## Translation managers

- **Transfer to other sites**: privileged users, or members of the workflow's `default_assignee_group`. Site access is required. Without `default_assignee_group`, only privileged users may transfer.
- **Sites card in the entry editor**: when a localization workflow governs the collection, Statamic's "Sites" card is hidden for non-privileged users. Localizations are created through the workflow instead.

## The graph editor

**Settings > Workflows**, the graph editor, creating, saving, validating, activating, backfilling and deleting workflows are for **super admins only**. Neither `privileged_groups` nor `admins` membership is enough.

## Audience rows

Several settings take a list of rows. Each row names a kind and its handles:

```yaml
- by: group      # group | role | user
  handles: [editors, reviewers]
- by: user
  handles: ['a1b2c3']   # user IDs
```

A user matching **any** row qualifies. Handles are not checked against existing groups or roles, so you can reference a group you create later. A row without a kind or without handles is refused on save.

## Next steps

- [Defining workflows](workflows.md): where each key lives in the YAML.
- [Workflow editor](editor.md#permissions): the same settings in the Control Panel.
- [Configuration](configuration.md): the project-wide groups in `config/workflows.php`.

---

← [Content ageing (SLA)](sla.md) · [Docs home](README.md) · [Commands & scheduler](commands.md) →
