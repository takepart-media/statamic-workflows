# Workflows for Statamic

🇩🇪 [Deutsch](README.de.md)

Editorial workflows for **Statamic 6**. Move entries through review and approval steps, assign the right people, discuss changes in context, get notified and keep content fresh, all in the Control Panel.

[![Packagist Version](https://img.shields.io/packagist/v/takepart-media/workflows)](https://packagist.org/packages/takepart-media/workflows)
[![Statamic 6](https://img.shields.io/badge/Statamic-6-ff269e)](https://statamic.com)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4)](https://www.php.net)

## Features

- **Workflows as a state machine.** Define places and transitions per collection in YAML or in a visual graph editor. Every subject is a real Statamic entry.
- **Assignments and approvals.** Assign people manually or by rules, gate transitions by group or role, and enforce the four-eyes principle.
- **Controlled publishing.** Replace Statamic's publish button with workflow steps. Optionally lock editing to the current assignees and control who may publish revisions.
- **Comments in context.** Comment on whole entries or individual passages, mention colleagues, and resolve threads.
- **Notifications.** Step mails, scheduled reminders, mention mails and automatic reminders for users who have not activated their account yet.
- **Dashboard and managers.** Personal queues, statistics and per-workflow listings with filters, history and content diffs.
- **Content ageing (SLA).** Automatically flag published content as outdated after a configurable time.
- **Translation and plain language.** Manage site versions per base entry, translate with DeepL and send content to a plain-language service.
- **Extensible.** Add your own actions, mail types, assignment criteria, widgets and content field mappings.

## Requirements

- PHP 8.3+
- Statamic 6 (Laravel 12.40+ or 13)
- Statamic Pro for revisions (optional; everything else works without it)

## Installation

```bash
composer require takepart-media/workflows
php artisan workflows:install
```

The installer runs the migrations, publishes the CP assets and walks you through creating your first workflow. The package ships pre-built assets, so you don't need Node on your server.

Read the [installation guide](docs/en/installation.md) for prerequisites, dashboard setup and updates.

## Documentation

| | |
| --- | --- |
| [Installation](docs/en/installation.md) | Requirements, installer, updates |
| [Configuration](docs/en/configuration.md) | Every option in `config/workflows.php` |
| [Defining workflows](docs/en/workflows.md) | YAML structure and metadata reference |
| [Workflow editor](docs/en/editor.md) | The graph editor and workflow settings |
| [Working with workflows](docs/en/using.md) | Dashboard, managers, detail page, comments |
| [Notifications](docs/en/notifications.md) | Mails, reminders and delivery |
| [Translation & plain language](docs/en/localization.md) | Localization workflows, DeepL, plain language |
| [Content ageing (SLA)](docs/en/sla.md) | Flagging and moving outdated content |
| [Permissions](docs/en/permissions.md) | Who can do what |
| [Commands & scheduler](docs/en/commands.md) | Artisan commands and scheduled tasks |
| [Troubleshooting](docs/en/troubleshooting.md) | Common problems |
| [Extending](docs/en/extending/README.md) | Custom actions, mail types and more |

The documentation is also available [in German](docs/de/README.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what changed in each release.

## Contributing

Bug reports and feature requests are welcome as [GitHub issues](https://github.com/takepart-media/statamic-workflows/issues).

To work on the addon itself:

```bash
git clone git@github.com:takepart-media/statamic-workflows.git
cd statamic-workflows
composer install && npm install
vendor/bin/pest && npm test
```

After changing anything in `resources/js` or `resources/css`, run `npm run build` and commit the result in `resources/dist/build`. To test against a real project, see [Develop the addon locally](docs/en/installation.md#develop-the-addon-locally).

## Support

Questions and licensing: [it@takepart-media.de](mailto:it@takepart-media.de)

## License

Workflows is commercial software by TAKEPART Media Science + GmbH. The source code is public for inspection; using it requires a license. See [LICENSE.md](LICENSE.md).
