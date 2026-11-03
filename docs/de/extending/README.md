# Workflows erweitern

🇬🇧 [English](../../en/extending/README.md)

Passe das Workflows-Addon an dein Projekt an: mit eigenen Aktionen, Mails, Zuweisungsregeln, Dashboard-Widgets und Übersetzungsdiensten. Am Addon selbst änderst du dafür nichts.

## So funktioniert's

Jede Erweiterung ist ein **Opt-in** in `config/workflows.php`. Ist der Schlüssel leer oder `null`, ist die Erweiterung aus und das Addon verhält sich wie ausgeliefert.

Veröffentliche die Config einmalig in dein Projekt:

```bash
php artisan vendor:publish --tag=workflows-config
```

Danach trägst du die gewünschten Erweiterungen in `config/workflows.php` ein.

## Erweiterungspunkte

| Erweiterung | Wofür | Config-Schlüssel | Eigener Code |
| --- | --- | --- | --- |
| [Eigene Action-Typen](actions.md) | Projektcode bei einer Transition ausführen, z. B. Slack benachrichtigen | `actions` | `Contracts\WorkflowAction` |
| [Eigene Mail-Typen](mail-types.md) | Eigene Mails mit eigenen Texten, Daten und Templates verschicken | `mail_types` | optional `Contracts\WorkflowMail` |
| [Zuweisungskriterien](assignment-criteria.md) | Zuständige nach Feldern im Benutzer- oder Beitrags-Blueprint auswählen | `assignment_criteria` | – |
| [Verantwortliche im Dashboard-Header](header-responsibles.md) | Im Dashboard die Ansprechpartner der eigenen Themen anzeigen | `header_responsibles` | – |
| [Dashboard-Widgets](dashboard-widgets.md) | Festlegen, welche Workflow-Widgets im Dashboard erscheinen | `dashboard_widgets` | – |
| [Inhaltsfelder](content-fields.md) | Die Felder deiner Blueprints für Vorschau und Übersetzung zuordnen | `content_fields` | – |
| [Übersetzung & Einfache Sprache](translation.md) | DeepL und den Einfache-Sprache-Dienst anbinden | `translation` | – |

> [!TIP]
> Mit Code erweiterst du das Addon nur über die beiden Contracts `WorkflowAction` und `WorkflowMail`. Alles andere ist reine Konfiguration.

## Nicht auf diesen Seiten

- **Projektweite Gruppen und Schalter** wie `privileged_groups`, `mail_delivery`, `sla_scheduler` oder `account_reminder`: Sie sind in den Kommentaren von [`config/workflows.php`](../../../config/workflows.php) beschrieben, die wichtigsten auch unter [Konfiguration](../configuration.md).
- **Einstellungen pro Workflow** wie Vier-Augen-Prinzip oder Kommentar-Rechte: Du stellst sie im Workflow-Editor ein, beschrieben sind sie unter [Workflow-Editor](../editor.md).

---

← [Doku-Start](../README.md) · [Los geht's: Eigene Action-Typen](actions.md) →
