# Extending Workflows

🇩🇪 [Deutsch](../de/README.md)

Tailor the Workflows addon to your project with your own actions, mails, assignment rules, dashboard widgets and translation services, without changing the addon itself.

## How it works

Every extension is an **opt-in** in `config/workflows.php`. If the key is empty or `null`, the extension is off and the addon behaves as shipped.

Publish the config to your project once:

```bash
php artisan vendor:publish --tag=workflows-config
```

Then add the extensions you need to `config/workflows.php`.

## Extension points

| Extension | Use it to | Config key | Own code |
| --- | --- | --- | --- |
| [Custom action types](actions.md) | Run project code on a transition, e.g. notify Slack | `actions` | `Contracts\WorkflowAction` |
| [Custom mail types](mail-types.md) | Send your own mails with your own copy, data and templates | `mail_types` | optional `Contracts\WorkflowMail` |
| [Assignment criteria](assignment-criteria.md) | Pick assignees by fields on the user or entry blueprint | `assignment_criteria` | – |
| [Dashboard header responsibles](header-responsibles.md) | Show who is responsible for the user's topics on the dashboard | `header_responsibles` | – |
| [Dashboard widgets](dashboard-widgets.md) | Choose which workflow widgets the dashboard shows | `dashboard_widgets` | – |
| [Content fields](content-fields.md) | Map your blueprint fields for preview and translation | `content_fields` | – |
| [Translation & plain language](translation.md) | Connect DeepL and the plain-language service | `translation` | – |

> [!TIP]
> Code-level extensions go through just two contracts, `WorkflowAction` and `WorkflowMail`. Everything else is configuration.

## Not on these pages

- **Project-wide groups and switches** such as `privileged_groups`, `mail_delivery`, `sla_scheduler` or `account_reminder` are described in the comments of [`config/workflows.php`](../../../config/workflows.php). The main ones are also in the [Integration Guide](../../../Integration.md).
- **Per-workflow settings** such as the four-eyes principle or comment permissions are set in the workflow editor and described in [DOCS.md](../../../DOCS.md).

---

[Get started: Custom action types](actions.md) →
