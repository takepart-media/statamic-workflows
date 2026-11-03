# Workflows für Statamic

🇬🇧 [English](README.md)

Redaktionelle Workflows für **Statamic 6**. Führe Einträge durch Prüf- und Freigabeschritte, weise die richtigen Leute zu, diskutiere Änderungen direkt am Text, lass dich benachrichtigen und halte Inhalte aktuell, alles im Control Panel.

[![Packagist Version](https://img.shields.io/packagist/v/takepart-media/statamic-workflows)](https://packagist.org/packages/takepart-media/statamic-workflows)
[![Statamic 6](https://img.shields.io/badge/Statamic-6-ff269e)](https://statamic.com)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4)](https://www.php.net)

## Funktionen

- **Workflows als Zustandsautomat.** Places und Transitions pro Collection in YAML oder im visuellen Graph-Editor definieren. Jeder Eintrag im Workflow ist ein echter Statamic-Entry.
- **Zuweisungen und Freigaben.** Zuständige von Hand oder per Regel zuweisen, Transitions auf Gruppen oder Rollen beschränken und das Vier-Augen-Prinzip durchsetzen.
- **Kontrolliertes Veröffentlichen.** Statamics Veröffentlichen-Button durch Workflow-Schritte ersetzen. Optional dürfen nur die aktuell Zuständigen bearbeiten, und du legst fest, wer Revisionen veröffentlichen darf.
- **Kommentare im Kontext.** Ganze Einträge oder einzelne Abschnitte kommentieren, Kollegen erwähnen und Diskussionen als erledigt markieren.
- **Benachrichtigungen.** Schritt-Mails, geplante Erinnerungen, Erwähnungs-Mails und automatische Erinnerungen für Benutzer, die ihren Account noch nicht aktiviert haben.
- **Dashboard und Manager.** Persönliche Listen, Statistiken und Übersichten pro Workflow mit Filtern, Historie und Inhaltsvergleich.
- **Inhaltsalterung (SLA).** Veröffentlichte Inhalte nach einer einstellbaren Zeit automatisch als veraltet markieren.
- **Übersetzung und Einfache Sprache.** Sprachfassungen pro Basiseintrag verwalten, mit DeepL übersetzen und Inhalte an einen Einfache-Sprache-Dienst schicken.
- **Erweiterbar.** Eigene Actions, Mail-Typen, Zuweisungskriterien, Widgets und Feldzuordnungen ergänzen.

## Voraussetzungen

- PHP 8.3+
- Statamic 6 (Laravel 12.40+ oder 13)
- Statamic Pro für Revisionen (optional; alles andere funktioniert ohne)

## Installation

```bash
composer require takepart-media/statamic-workflows
php artisan workflows:install
```

Der Installer führt die Migrationen aus, veröffentlicht die CP-Assets und begleitet dich beim Anlegen deines ersten Workflows. Das Paket bringt fertig gebaute Assets mit, Node brauchst du auf dem Server nicht.

Voraussetzungen, Dashboard-Einrichtung und Updates beschreibt die [Installationsanleitung](docs/de/installation.md).

## Dokumentation

| | |
| --- | --- |
| [Installation](docs/de/installation.md) | Voraussetzungen, Installer, Updates |
| [Konfiguration](docs/de/configuration.md) | Alle Optionen in `config/workflows.php` |
| [Workflows definieren](docs/de/workflows.md) | YAML-Aufbau und Metadaten-Referenz |
| [Workflow-Editor](docs/de/editor.md) | Graph-Editor und Workflow-Einstellungen |
| [Mit Workflows arbeiten](docs/de/using.md) | Dashboard, Manager, Detailseite, Kommentare |
| [Benachrichtigungen](docs/de/notifications.md) | Mails, Erinnerungen und Versand |
| [Übersetzung & Einfache Sprache](docs/de/localization.md) | Lokalisierungs-Workflows, DeepL, Einfache Sprache |
| [Inhaltsalterung (SLA)](docs/de/sla.md) | Veraltete Inhalte markieren und weiterleiten |
| [Berechtigungen](docs/de/permissions.md) | Wer was darf |
| [Befehle & Scheduler](docs/de/commands.md) | Artisan-Befehle und geplante Aufgaben |
| [Fehlerbehebung](docs/de/troubleshooting.md) | Häufige Probleme |
| [Erweitern](docs/de/extending/README.md) | Eigene Actions, Mail-Typen und mehr |

Die Dokumentation gibt es auch [auf Englisch](docs/en/README.md).

## Changelog

Was sich in jedem Release geändert hat, steht im [CHANGELOG.md](CHANGELOG.md) (Englisch).

## Mitentwickeln

Fehlermeldungen und Wünsche gern als [GitHub-Issue](https://github.com/takepart-media/statamic-workflows/issues).

Am Addon selbst arbeiten:

```bash
git clone git@github.com:takepart-media/statamic-workflows.git
cd statamic-workflows
composer install && npm install
vendor/bin/pest && npm test
```

Nach Änderungen in `resources/js` oder `resources/css` führst du `npm run build` aus und committest das Ergebnis in `resources/dist/build`. Zum Testen in einem echten Projekt siehe [Addon lokal entwickeln](docs/de/installation.md#addon-lokal-entwickeln).

## Support

Fragen und Lizenzen: [it@takepart-media.de](mailto:it@takepart-media.de)

## Lizenz

Workflows ist kommerzielle Software der TAKEPART Media Science + GmbH. Der Quellcode ist öffentlich einsehbar, die Nutzung erfordert eine Lizenz. Siehe [LICENSE.md](LICENSE.md).
