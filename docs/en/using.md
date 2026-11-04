# Working with workflows

🇩🇪 [Deutsch](../de/using.md)

This page walks editors and admins through the Control Panel: the dashboard, the manager pages, an entry's workflow summary and the entry editor.

## The dashboard

The dashboard is where everyday work starts. The workflow managers have no navigation item of their own; you reach them from the dashboard widgets.

The addon ships eight widgets. Seven of them make up the default layout, in this order:

| Widget | What it shows |
| --- | --- |
| User header (`workflows_user_header`) | Your name and avatar, your "Functions" (user groups), your "Affiliation" and the people "Responsible" for you. |
| Key figures (`workflows_queue_stats`) | Four cards: "Assigned to me" (personal), "Awaiting approval", "Failed translations" and "Published this week" (all project-wide). "Awaiting approval" also shows how long the oldest entry has been waiting, "Published this week" compares with last week. Where you may, "Failed translations" offers a "restart" button. Each card links to the entries behind its number. |
| Assigned to me (`workflows_my_queue`) | Every version currently assigned to you, across all active workflows, with one tab per workflow plus "All". |
| Watched (`workflows_watched`) | The entries you watch (see [Watching](#watching)). |
| My posts (`workflows_created_by_me`) | Entries whose `author` field names you, no matter who created them or who is assigned. |
| All posts (`workflows_overview`) | The listing of every workflow in one table. A button row on top narrows it to one workflow or shows "All workflows"; the workflow column links to the matching manager page. This widget is the entry point to the managers. |
| Content by status (`workflows_status`) | One doughnut chart per workflow showing how its entries are spread across states. Click a segment or legend row to list the entries in that state. |

The eighth widget, `workflows_assigned_posts`, lists your assigned entries in a single workflow. It is registered but not part of the default layout.

To change the layout, restrict widgets to certain workflows or turn the default off, see [Dashboard widgets](extending/dashboard-widgets.md).

## Manager pages

Each workflow that declares a nav entry (`metadata.nav`) has a manager page at `/cp/workflows/<slug>`, for example `/cp/workflows/post-manager`. You open it from the "All posts" widget.

The page header shows the workflow's name and, if you may create content in it, a "New post" button (see [Creating posts](#creating-posts)). Below that are three lists.

| List | Contents |
| --- | --- |
| Assigned to me | Every version of an entry in this workflow that is assigned to you. One row per version. |
| My posts | Every version whose `author` field names you. One row per version. |
| All posts | One row per entry, with one state column per site. A localization manager calls this list "Translatable posts" and only lists entries whose source is ready. |

If the workflow governs more than one collection, "All posts" shows one tab per collection. Each tab keeps its own search, filters, sorting and column setup.

### Columns

"All posts" has these columns:

| Column | Description |
| --- | --- |
| Title | Links to the entry's workflow summary. Always visible. |
| One column per site | The version's state in that site, as a colored badge. The header shows the site name with a short site code; language variants of a site sit next to their base site. A dash means there is no version in that site yet. |
| Content updated | When the content was last approved (see [Content ageing](sla.md)). Visible by default in an authoring workflow, hidden in localization managers. Sortable. |

The personal lists ("Assigned to me", "My posts") show Title, State and Site, plus Collection when the workflow governs more than one collection.

Each state cell can carry extra markers:

- a live-preview button that opens that version in its own window,
- an "out of date" marker when the entry was revised after this version was approved (see [Content ageing](sla.md#the-derived-out-of-date-marker)),
- a comment flag with the number of open comments, plus `+N` in amber for comments you have not read yet,
- the date since which the version has been in its current state ("since …").

Use the column picker on the right of the toolbar to show or hide columns. The title column always stays visible.

### Filters

"All posts" offers search and these filters. A filter without any options is left out.

| Filter | Narrows to |
| --- | --- |
| State | Entries with a version in the chosen state. |
| Assignee | Entries with a version assigned to the chosen person. |
| Author | Entries whose `author` field names the chosen person. |
| Waiting longer than | Entries with a version that has been waiting in an open state for more than 3, 7, 14 or 30 days. Final states such as "Published" don't count as waiting. |
| Up to date | "Only with an out-of-date version". Offered only when such a version exists. |

The personal lists have no toolbar.

## The workflow summary

Click an entry's title, or a state badge in a site column, to open the workflow summary for that version. This is the page where you move an entry through its workflow.

### Header and actions

A sticky bar at the top holds "Back" and one button per transition you can run from the current state. A disabled button shows the reason in its tooltip, for example that only assigned users may perform the action, or that a certain group is required.

Below the bar you find:

- the entry's title and a site code,
- "In state "…" since …", the date the version entered its current state,
- "Edit", which opens the entry editor. It only appears if you may edit the entry (see [Permissions](permissions.md)),
- "Live preview" or "Live page" (see [Preview](#preview)),
- "Watch" (see [Watching](#watching)),
- "Show history", if you may see the history (see [History](#history-and-content-diff)),
- "Responsible:" with the avatars of the current assignees.

### Progress

The progress bar shows the workflow's steps from start to finish: completed steps with a check mark, the current step with a dot, upcoming steps in grey. Steps that branch off the main line, such as the ageing steps of [Content ageing](sla.md), are drawn hanging off the step they were reached from. The workflow decides whether the bar runs horizontally above the content or vertically beside it.

### Running a transition

Click a transition button. What happens next depends on the transition:

- **No assignment step:** the transition runs at once. The current assignees stay responsible.
- **Automatic assignment:** the transition runs at once and hands the entry to everyone who matches the transition's criteria.
- **Manual assignment:** the "Assign to person" dialog opens first (see below).
- **Site pick:** transitions that create a new language version, such as "Start translation", open a dialog where you pick the target language. Depending on the workflow, you can also pick the source site.

After the transition, the page reloads and shows the new state.

### The assignee picker

The "Assign to person" dialog lists everyone who meets the transition's assignment criteria. Pick one or more people and click "Confirm".

- A blue badge next to a name shows the person's site allocation, if the workflow uses one.
- If the step sends a mail, the "Send email notification" switch appears. Turn it off to hand the entry over silently. People who will get a mail are marked "Gets an email".
- If an automatic assignment would have handed the entry back to you alone, the dialog opens instead and explains why.

To change who is responsible without running a transition, click the pencil next to "Responsible:". The drawer lists all users with search and a group filter. This pencil only appears for users the workflow allows to assign (see [Permissions](permissions.md)).

### Content and comments

The "Content" section shows the entry's content fields as readable passages. Comments sit in a margin next to the content, similar to a word processor. Use "Show comments" / "Hide comments" to toggle the margin; your browser remembers the choice.

To comment:

- **On a whole passage:** click the `+` button in the gutter next to it.
- **On a text selection:** select text inside a passage and click the comment icon that appears next to it ("Comment on selection"). The selection stays highlighted in the text.
- **In general:** comments without a passage appear under "General".

Write your comment and press "Comment", or <kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>Enter</kbd>. Type `@` to mention someone; the list only offers people with CP access to the entry's site. Mentioned people get a mail (see [Notifications](notifications.md#comment-mentions)).

Comments form threads: one comment on a passage, with replies below it. In a thread you can:

| Action | Who |
| --- | --- |
| Reply | Everyone who can open the summary. Replying to a resolved thread reopens it. |
| Edit | Only the comment's author. |
| Delete | The author, super admins, and the workflow's comment moderators. Deleting a thread's first comment deletes its replies. |
| Resolve / Reopen | By default the thread's author, the entry's current assignees, comment moderators and super admins. A workflow can restrict this to a chosen audience. |

The margin filters threads by "Open", "Resolved" and "All". Resolved threads no longer count towards the comment flags in the listings.

A comment shows a hint when the text it refers to has changed since: "Refers to an older version of this passage", or "Something in this area changed after the comment was written". Comments you have not read yet are marked "new". A passage counts as read once you open its comments.

Super admins can switch on "Technical details" to see field handles and passage IDs.

> [!NOTE]
> Who may delete and resolve comments is configured per workflow and globally. See [Permissions](permissions.md).

### Watching

"Watch" adds the entry to your "Watched" dashboard widget. It is a personal bookmark: it doesn't make you responsible. While you watch an entry, the button reads "Watching"; click it again to stop watching.

Being assigned to an entry starts watching it automatically. Watchers receive the "Published (to watchers)" mail when a workflow sends it (see [Notifications](notifications.md)).

### History and content diff

"Show history" opens a drawer with the version's log: every transition, manual state change, draft save, working copy, reminder and comment event, with time and person. Events that nobody triggered, such as scheduled moves, show "System".

Click "Show changes" on an event to see what changed in the content:

- A **step** (a transition, a manual state change, creation) is compared with the previous step. Draft saves in between are skipped, so you see the net change of the whole step.
- A **draft save** is compared with the save before it, so you see exactly what that save changed.

Text changes are highlighted word by word. Relationships show the linked items' names.

> [!NOTE]
> The history is only visible to super admins and the groups in `history_groups` (default: `admins`). See [Configuration](configuration.md).

### Preview

The preview button opens the page in its own window:

| Button | When | Shows |
| --- | --- | --- |
| Live page | The version is published and has no pending changes. | The published page. |
| Live preview | The version is unpublished, or has a working copy. | A preview of the draft or the working copy, even before publication. |

The same buttons appear next to each state badge in the listings.

### Revisions and working copies

If a governed collection has Statamic revisions enabled (Statamic Pro), editing a published entry creates a working copy instead of changing the live entry. The workflow then shows the state "Unpublished changes":

- The published version stays online.
- "Live preview" shows the working copy.
- Once the working copy is published, the entry returns to its publish state by itself. Publishing a working copy also counts as an approval for [Content ageing](sla.md).

Who may publish a working copy can be restricted per workflow (see [Permissions](permissions.md)).

### Admin tools

Users with privileged access (super admins and `privileged_groups`) see "Set status manually (admin)" at the bottom of the page. It sets the state directly, without running a transition. It sends no mails and runs no transition actions, and it is logged in the history as "State set manually."

If a place has a refresh interval, the page checks for changes in the background. When something changed, it asks "New content available" and offers "Reload now" or "Later".

### Translation and plain-language summaries

Localization workflows use a two-column summary: the source on the left, the translation on the right.

- "Show source" / "Hide source" toggles the source column. A workflow can show the source collapsed or hide it for certain people.
- "The source has been revised" appears when the source was approved after this version.
- "Move to another site" creates further language versions. It is available to privileged users and to the workflow's default assignee group.
- While a plain-language rewrite is running, the content shows "Rewriting into plain language …".

In plain-language workflows, the button "Notes on the AI rewrite" opens the plain-language service's review report: what was simplified, added and left out. While the version sits in the step where finished rewrites land, the button is highlighted with a dot ("New review report — please read before approving."). Who sees the button is configured per workflow.

For setup, see [Translation & plain language](localization.md).

## Creating posts

The "All posts" widget shows **one** "New post" button for every workflow you may create in. A workflow's manager page shows the same button for that workflow alone.

1. Click "New post".
2. Enter a title.
3. Choose the workflow. The select lists only workflows you may create in and allows exactly one. If the widget is switched to a workflow you may create in, that one is preselected; otherwise (for example under "All") the first one is. If only one workflow is available, for example on a manager page, the field is read-only and just shows it.
4. If the workflow governs several collections, pick one.
5. If the workflow couples a first transition to creation and that transition asks for a person, pick the assignees in the same dialog. "Send email notification" works as in the assignee picker.
6. Confirm. The button carries the coupled transition's label, for example "Start drafting", or "Create" without one.

The post is created as a draft in the first site of the collection you have access to. It gets the workflow's start state and is assigned to the workflow's default assignee group, plus you if the workflow says so. If a coupled transition is set, it runs right away. The page then reloads so you can see where the post went.

> [!NOTE]
> Creating posts is off by default. Only super admins and the groups in `post_creator_groups` (or the workflow's own setting) may create. See [Permissions](permissions.md).

## In the entry editor

Every blueprint of a governed collection gets the workflow panel automatically. You don't need to edit the blueprint.

### Header

The entry editor's header shows the version's current state as a badge, followed by one button per available transition, next to Statamic's own "Save" button. Without any available transition, it reads "No actions available."

When you click a transition button in the editor:

1. The entry is saved first. If the save fails, for example because of a validation error, the transition is aborted ("Could not save entry. Transition aborted.").
2. If the collection has revisions enabled, a Statamic revision is recorded with the transition's label.
3. The transition runs, including the assignee picker or site picker if needed.
4. You are taken to the workflow summary.

### Publishing

Statamic's "Publish" button is hidden when all of these hold:

- the workflow controls publishing (it has at least one place with `allow_publish`),
- the current place does not allow publishing,
- you are not a super admin and not in `privileged_groups`.

In that case, the entry goes live through the workflow's transitions instead. While a working copy exists and the workflow restricts who may publish it, only those people see the button.

In a collection with a localization workflow, the "Sites" card is hidden for non-privileged users. New language versions are created through the workflow instead.

### Read-only entries

If a workflow has "Only assignees may edit" switched on, the entry editor opens read-only for everyone who is not a current assignee, not in the workflow's exempt list and not a super admin. The workflow panel stays usable, so transitions still work. Statamic's own permissions apply on top.

## Fieldtypes

| Fieldtype | Handle | Purpose |
| --- | --- | --- |
| Workflow actions | `workflow_actions` | The workflow panel in the entry editor described above. Added to the first tab of every blueprint of a governed collection automatically. It stores nothing. |
| Revision status | `revision_status` | Shows whether an entry has an unpublished working copy. Optional, add it yourself. |

### Revision status

`revision_status` is a display-only field for collections with revisions. In the entries listing it shows an orange "Unpublished changes" badge; in the entry editor it shows the same badge, or "No pending changes". It stores nothing.

The fieldtype can't be picked in the blueprint builder. Add it to the blueprint YAML:

```yaml
-
  handle: revision_status
  field:
    type: revision_status
    display: 'Revision status'
```

On listings of collections with revisions, the addon also adds a "Revision status" filter with the options "Unpublished changes" and "No pending changes".

## Next steps

- [Notifications](notifications.md): which mails the workflow sends and to whom.
- [Permissions](permissions.md): who may run, assign, edit, publish and comment.
- [Content ageing (SLA)](sla.md): how published content ages and comes back.
- [Translation & plain language](localization.md): localization workflows and their summary page.

---

← [Workflow editor](editor.md) · [Docs home](README.md) · [Notifications](notifications.md) →
