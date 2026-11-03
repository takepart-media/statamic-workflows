# Workflows documentation

🇩🇪 [Deutsch](../de/README.md)

Workflows adds editorial workflows to Statamic 6: a state machine for your entries, assignees, approvals, comments, notifications, content ageing and translation management, all inside the Control Panel.

## Get started

1. [Installation](installation.md): require the package and run the installer.
2. [Configuration](configuration.md): tune `config/workflows.php` for your project.
3. [Defining workflows](workflows.md): describe places and transitions in YAML, or
4. [Workflow editor](editor.md): build them visually in the CP.

## Guides

| Page | What you'll find |
| --- | --- |
| [Working with workflows](using.md) | Dashboard widgets, managers, the detail page, comments, history |
| [Notifications](notifications.md) | Step mails, reminders, mentions, account activation reminders |
| [Translation & plain language](localization.md) | Localization workflows, DeepL, plain-language rewrites |
| [Content ageing (SLA)](sla.md) | Automatically flag and move outdated content |
| [Permissions](permissions.md) | Who can view, edit, publish, transition, assign and comment |
| [Commands & scheduler](commands.md) | Artisan commands and scheduled tasks |
| [Troubleshooting](troubleshooting.md) | Common problems and how to fix them |

## Extending

Hook your own code and configuration into the addon: custom actions, mail types, assignment criteria, dashboard widgets, content fields and translation services. See [Extending Workflows](extending/README.md).

## More

- [Changelog](../../CHANGELOG.md)
- [License](../../LICENSE.md)
- Support: [it@takepart-media.de](mailto:it@takepart-media.de) · [Issues](https://github.com/takepart-media/statamic-workflows/issues)

---

[Start with installation](installation.md) →
