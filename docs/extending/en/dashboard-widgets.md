# Dashboard widgets

🇩🇪 [Deutsch](../de/dashboard-widgets.md)

Build the CP dashboard from the addon's eight workflow widgets.

> **At a glance**
> - Config key: `dashboard_widgets` in `config/workflows.php`
> - Default: a ready-made layout of seven widgets (= on)
> - Own code: no

## Overview

The addon ships eight dashboard widgets. The dashboard is the entry point to the workflow managers; there is no navigation item of their own.

`dashboard_widgets` is a fallback layout. The addon only applies it as long as your project lists nothing under `widgets` in `config/statamic/cp.php`. That is Statamic's default.

So you have two options:

- **Use the default layout:** do nothing, or adjust `dashboard_widgets`.
- **Your own layout:** list the widgets under `widgets` in `config/statamic/cp.php`. Then only that list applies.

## Quick start

1. Publish the config if you have not done so yet:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Adjust the layout. This is the shipped default:

   ```php
   // config/workflows.php
   'dashboard_widgets' => [
       ['type' => 'workflows_user_header', 'width' => 100],
       ['type' => 'workflows_queue_stats', 'width' => 100],
       ['type' => 'workflows_my_queue', 'width' => 100, 'limit' => 7],
       ['type' => 'workflows_watched', 'width' => 50, 'limit' => 5],
       ['type' => 'workflows_created_by_me', 'width' => 50, 'limit' => 5],
       ['type' => 'workflows_overview', 'width' => 100],
       ['type' => 'workflows_status', 'width' => 100],
   ],
   ```

3. Open the dashboard in the CP. The widgets appear in this order.

## Configuration

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `dashboard_widgets` | `array` \| `false` | no | layout above | List of widget entries. `false` or `[]` turns the fallback off. |

Every entry is a regular Statamic widget entry:

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `type` | `string` | yes | – | The widget's handle (see below). |
| `width` | `int` | no | – | Width in percent, handled by Statamic. The default uses `100` and `50`. |
| others | – | no | – | Options of the specific widget (see below). |

## The widgets

| `type` | Shows | Options |
| --- | --- | --- |
| `workflows_user_header` | The user's name, avatar, groups, affiliation and responsibles | none (config: [Dashboard header responsibles](header-responsibles.md)) |
| `workflows_queue_stats` | Four key-figure cards: in my queue, awaiting approval, failed translations, published this week | `workflows` |
| `workflows_my_queue` | Entries assigned to me across all active workflows, one tab per workflow | `limit`, `workflows` |
| `workflows_watched` | Entries I watch | `limit`, `workflows` |
| `workflows_created_by_me` | Entries whose `author` field names me | `limit`, `workflows` |
| `workflows_overview` | "All posts": a listing across all workflows, the entry point to the managers | `per_page`, `workflows`, `default_workflow` |
| `workflows_status` | One doughnut chart per workflow: distribution across states | `workflows` |
| `workflows_assigned_posts` | Entries assigned to me in **one** workflow | `collection`, `limit` |

`workflows_assigned_posts` is registered but not part of the default layout.

### Widget options

| Option | Type | Default | Applies to | Description |
| --- | --- | --- | --- | --- |
| `workflows` | `array` | all active | all except `workflows_user_header` and `workflows_assigned_posts` | Workflow names the widget is restricted to. Unknown names are ignored. |
| `limit` | `int` | `7` (`workflows_my_queue`), otherwise `5` | list widgets, `workflows_assigned_posts` | Rows per page. |
| `per_page` | `int` | `15` | `workflows_overview` | Rows per page. |
| `default_workflow` | `string` | the authoring workflow | `workflows_overview` | The workflow whose button is active on load. |
| `collection` | `string` | `posts` | `workflows_assigned_posts` | The collection whose workflow the widget shows. |

## Lifecycle

1. On boot the addon checks: is `dashboard_widgets` a non-empty list **and** `statamic.cp.widgets` empty?
2. If so, `statamic.cp.widgets` is set to `dashboard_widgets`. Otherwise nothing happens.
3. Widget preferences per user or role take precedence over both lists.
4. On render, the personal widgets hide themselves without a logged-in user. `workflows_overview` and `workflows_status` hide themselves when no workflow is in scope.

## Examples

### Your own layout in Statamic

The same entries work directly in the Statamic config:

```php
// config/statamic/cp.php
'widgets' => [
    ['type' => 'workflows_user_header', 'width' => 100],
    ['type' => 'workflows_my_queue', 'width' => 100, 'limit' => 10],
    ['type' => 'workflows_overview', 'width' => 100, 'per_page' => 25],
],
```

### Restrict to specific workflows

Set the same list on `workflows_queue_stats` and `workflows_overview` so the figures and the table match:

```php
'dashboard_widgets' => [
    ['type' => 'workflows_queue_stats', 'width' => 100, 'workflows' => ['postmanager']],
    ['type' => 'workflows_overview', 'width' => 100, 'workflows' => ['postmanager']],
],
```

### Turn off the fallback

```php
// config/workflows.php
'dashboard_widgets' => false,
```

## Notes & limitations

> [!WARNING]
> Nothing is merged. As soon as `config/statamic/cp.php` has any entry under `widgets`, only that list applies. Nothing from `dashboard_widgets` is added.

> [!WARNING]
> `workflows_overview` is the only way to the manager pages in the CP. `/cp/workflows` redirects to the dashboard. If you remove the widget from your layout, you also remove the entry point.

> [!TIP]
> If you set `workflows` on `workflows_queue_stats`, set the same list on `workflows_overview`. Otherwise the figures and the filtered table cover different workflows.

> [!NOTE]
> The `workflows_queue_stats` cards jump to `workflows_my_queue` (card 1) or filter `workflows_overview` (cards 2 to 4). If the target widget is missing, the card links to a manager page when that page is unambiguous. Otherwise the card is not a link.

> [!NOTE]
> For the list widgets, `limit` is the page size. All rows are delivered and paged in the browser.

> [!NOTE]
> The example in DOCS.md sets `'per_page' => 25`. The shipped default sets no value, so `15` applies.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| The workflow widgets are missing. | `config/statamic/cp.php` lists its own `widgets`. | Add the workflow widgets there. |
| | Widget preferences of a user or role override the layout. | Check the preferences in the CP. |
| | `dashboard_widgets` is `false` or `[]`. | Add a layout. |
| A widget is missing despite its entry. | No workflow is in scope, for example because of a wrong `workflows` list. | Check the workflow names. |

## See also

- [Dashboard header responsibles](header-responsibles.md)
- [DOCS.md](../../../DOCS.md), section "Dashboard-Widgets"
- [Integration.md](../../../Integration.md), section "Dashboard-Widget"
- [config/workflows.php](../../../config/workflows.php), comment on `dashboard_widgets`
- [src/ServiceProvider.php](../../../src/ServiceProvider.php)
- [src/Widgets/](../../../src/Widgets/)

---

← [Dashboard header responsibles](header-responsibles.md) · [Overview](README.md) · [Content fields](content-fields.md) →
