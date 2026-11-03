# Installation

🇬🇧 [English](../en/installation.md)

Installiere das Workflows-Addon in deinem Statamic-Projekt, richte es mit einem Befehl ein und halte es aktuell.

## Voraussetzungen

| Voraussetzung | Version | Hinweise |
| --- | --- | --- |
| PHP | 8.3 oder höher | Für die Standard-Datenbank brauchst du die Erweiterung `pdo_sqlite`. |
| Statamic | 6 (`statamic/cms: ^6.0`) | |
| Laravel | 12 oder 13 | Das, was Statamic 6 verlangt. |
| Composer | 2 | |
| Statamic Pro | optional | Nur für Revisionen nötig (die Schleife „Unveröffentlichte Änderungen“). Alles andere funktioniert ohne Pro. |

Node oder npm brauchst du nicht. Das Addon bringt fertig gebaute CP-Assets mit.

## Paket installieren

Das Addon liegt auf [Packagist](https://packagist.org/packages/takepart-media/statamic-workflows):

```bash
composer require takepart-media/statamic-workflows
```

Composer installiert das Addon nach `vendor/takepart-media/statamic-workflows`, Statamic registriert es automatisch. `composer require` legt die aktuelle Hauptversion fest (zum Beispiel `^1.0`). Releases folgen [Semantic Versioning](https://semver.org/): Innerhalb von 1.x bekommst du Fehlerbehebungen und neue Funktionen, aber keine Breaking Changes.

## Installer ausführen

```bash
php artisan workflows:install
```

Der Installer führt dich durch diese Schritte:

1. **Migrationen ausführen.** Das Addon speichert seine Daten in einer eigenen SQLite-Datenbank unter `storage/app/workflows/workflow.sqlite`. Die Datei wird beim ersten Start des Addons automatisch angelegt. Die Migrationen erzeugen darin sieben Tabellen (Status, Verlauf, Zuständige, Kommentare, Lesestatus der Kommentare, Beobachtungen und Aufträge für Einfache Sprache).
2. **CP-Assets veröffentlichen** nach `public/vendor/statamic-workflows/build` (wie `php artisan vendor:publish --tag=workflows --force`).
3. **Workflow-Typen auswählen.** Eine Mehrfachauswahl listet die drei Beispiel-Workflows, die das Addon mitbringt: `postmanager` (redaktionelle Prüfung und Veröffentlichung), `translation` (Übersetzung in weitere Sites) und `plainlanguage` (Fassungen in Einfacher Sprache). `postmanager` ist vorausgewählt, solange er noch nicht installiert ist. Du kannst in einem Durchlauf mehrere Typen wählen.
4. **Für jeden gewählten Typ:**
   1. Das Beispiel wird nach `resources/workflows/<typ>.yaml` kopiert. Gibt es die Datei schon, bleibt sie unverändert.
   2. **Collections wählen**, die der Workflow steuern soll, aus den Collections deines Projekts. Mit den Pfeiltasten bewegst du dich, mit der Leertaste wählst du aus, mit Enter bestätigst du.
   3. **Status nachtragen** (optional, Voreinstellung „nein“). Bestehende Einträge dieser Collections bekommen einen Workflow-Status, genau wie bei `php artisan workflows:seed-states`.

> [!IMPORTANT]
> Die Collection-Auswahl **ersetzt** die `collections`-Liste des Workflows, auch bei einem bereits vorhandenen Workflow. Führst du den Installer erneut aus, wähle alle Collections, die der Workflow behalten soll. Die vorherige Datei wird vorher als Backup in `storage/app/workflows/backups/<handle>/` gesichert.

Geladen werden nur Workflows in `resources/workflows/`. Die Beispiele im Addon sind Vorlagen und werden nie von selbst geladen. Willst du stattdessen bei null anfangen, nimm [`workflows:make`](commands.md#workflowsmake).

## Installation prüfen

Melde dich im CP an und prüfe Folgendes:

1. **Dashboard:** Das Widget „Alle Beiträge“ (`workflows_overview`) listet die Einträge deiner Workflows. Die Workflow-Spalte verlinkt auf die Manager-Seite des jeweiligen Workflows, zum Beispiel `/cp/workflows/post-manager`. Die Manager haben keinen eigenen Navigationspunkt; der Einstieg ist das Dashboard.
2. **Einstellungen:** Als Super-Admin siehst du **Einstellungen > Workflows** mit dem Graph-Editor für jeden Workflow. Andere Nutzer sehen diesen Punkt nicht.
3. **Eintrags-Editor:** Öffne einen Eintrag aus einer gesteuerten Collection. Das Workflow-Panel zeigt die Aktions-Buttons des Workflows.

Bleibt das CP leer oder verhält es sich wie eine alte Version, sieh unter [Fehlerbehebung](troubleshooting.md#das-cp-zeigt-alte-oder-keine-workflow-assets) nach.

## Projekt vorbereiten

Das Addon legt für dein Projekt keine Gruppen, Nutzer oder Blueprint-Felder an. Prüfe diese Punkte, bevor deine Redaktion loslegt:

### Nutzergruppen

Jeder Gruppen-Handle, den ein Workflow nennt, muss in `resources/users/groups.yaml` existieren, zum Beispiel in `required_group`, `default_assignee_group` oder einer Zuweisungs-Aktion. Die mitgelieferten Beispiele verwenden diese Handles:

| Beispiel | Gruppen-Handles |
| --- | --- |
| `postmanager` | `editor`, `expert`, `approver` |
| `translation` | `translator`, `linguistic_reviewer`, `expert_reviewer`, `publisher` |
| `plainlanguage` | `editor`, `expert_reviewer`, `publisher` |

Lege die Gruppen an oder ändere die Handles im [Workflow-Editor](editor.md). Ein Handle, den es nicht gibt, löst keinen Fehler aus. Er passt einfach auf niemanden: Die abgesicherten Buttons bleiben verborgen, die Zuweisungsauswahl bleibt leer.

Die projektweiten Berechtigungslisten des Addons (`privileged_groups`, `history_groups`, `comment_moderator_groups`) stehen standardmäßig auf `admins`. Lege auch diese Gruppe an oder ändere die Listen in der [Konfiguration](configuration.md#gruppen-und-berechtigungen).

### Super-Admins

Du brauchst mindestens einen Nutzer mit `super: true`. Super-Admins umgehen jede Workflow-Sperre und sind die Einzigen, die **Einstellungen > Workflows** öffnen können.

### Blueprints

Du musst keinen Blueprint anpassen. Das Addon ergänzt die Eintrags-Blueprints jeder gesteuerten Collection selbst um zwei Felder: das Workflow-Panel (`workflow_actions`) und das Datum der letzten inhaltlichen Aktualisierung (`content_updated_at`), das die [Inhaltsalterung](sla.md) liest.

Liegen deine Inhalte in anderen Feldern als `title`, `content` und `seo`, ordne sie unter [`content_fields`](extending/content-fields.md) zu oder wähle die Inhaltsfelder pro Workflow im Editor.

### Revisionen (optional)

Die Schleife „Unveröffentlichte Änderungen“ (eine Arbeitskopie eines veröffentlichten Eintrags durchläuft die Prüfung, bevor sie live geht) braucht Statamic-Revisionen. Schalte sie an drei Stellen ein:

1. Statamic Pro ist installiert und lizenziert.
2. In der `.env` steht `STATAMIC_REVISIONS_ENABLED=true`.
3. Die Collection hat Revisionen aktiviert. Im Workflow-Editor kannst du das für alle Collections eines Workflows umschalten.

Der Editor warnt dich, wenn ein veröffentlichender Workflow eine Collection ohne Revisionen steuert.

### Mail

Workflow-Mails gehen über deinen Laravel-Mailer raus. Richte `MAIL_MAILER` und den Absender wie gewohnt ein. Nutzer ohne E-Mail-Adresse bekommen keine Mail. Siehe [Benachrichtigungen](notifications.md).

### Scheduler

Inhaltsalterung, Erinnerungen, automatische Transitions und der Dienst für Einfache Sprache brauchen Laravels Scheduler. Richte den Cron-Eintrag aus [Befehle & Scheduler](commands.md#scheduler) ein.

## Dashboard einrichten

Das Dashboard ist der Einstieg in die Workflows. Wie es gefüllt wird, hängt von `config/statamic/cp.php` ab:

- **`widgets` ist leer** (Statamics Voreinstellung): Das Addon setzt sein eigenes Layout aus [`dashboard_widgets`](extending/dashboard-widgets.md). Du musst nichts tun.
- **`widgets` enthält irgendetwas:** Dann gilt nur deine Liste, vom Addon wird nichts dazugemischt. Trag die gewünschten Workflow-Widgets selbst ein:

```php
// config/statamic/cp.php
'widgets' => [
    ['type' => 'workflows_user_header', 'width' => 100],
    ['type' => 'workflows_queue_stats', 'width' => 100],
    ['type' => 'workflows_my_queue', 'width' => 100, 'limit' => 7],
    ['type' => 'workflows_watched', 'width' => 50, 'limit' => 5],
    ['type' => 'workflows_created_by_me', 'width' => 50, 'limit' => 5],
    ['type' => 'workflows_overview', 'width' => 100],
    ['type' => 'workflows_status', 'width' => 100],
],
```

Nimm mindestens `workflows_overview` auf. Ohne dieses Widget haben deine Nutzer keinen Weg zu den Workflow-Managern. Alle Widgets und ihre Optionen findest du unter [Dashboard-Widgets](extending/dashboard-widgets.md).

## Addon aktualisieren

1. Paket aktualisieren:

   ```bash
   composer update takepart-media/statamic-workflows
   ```

2. Neue CP-Assets veröffentlichen. `--force` ist nötig, sonst bleiben die alten Dateien liegen und das CP lädt weiter das alte Bundle:

   ```bash
   php artisan vendor:publish --tag=workflows --force
   ```

3. Migrationen des Addons ausführen, wenn das Release welche mitbringt (siehe [Changelog](../../CHANGELOG.md)). Die Migrationen kannst du gefahrlos mehrfach ausführen:

   ```bash
   php artisan migrate --database=workflow --path=vendor/takepart-media/statamic-workflows/database/migrations
   ```

   Ein einfaches `php artisan migrate` findet sie ebenfalls.

> [!TIP]
> Nimm die Schritte 2 und 3 in dein Deploy-Skript auf. Ein Standard-Statamic-Projekt führt außerdem nach jedem `composer install` oder `update` `php artisan statamic:install` aus, das die Assets erneut veröffentlicht.

Deine Workflow-YAMLs in `resources/workflows/` gehören dir und werden bei einem Update nie überschrieben.

## Deinstallieren

1. Paket entfernen:

   ```bash
   composer remove takepart-media/statamic-workflows
   ```

2. Entferne die Workflow-Widgets aus `widgets` in `config/statamic/cp.php`, falls du sie dort eingetragen hast.
3. Lösche die Dateien, die das Addon in deinem Projekt angelegt hat:

   ```bash
   rm -rf public/vendor/statamic-workflows storage/app/workflows resources/workflows
   rm -f config/workflows.php
   rm -rf lang/vendor/workflows
   ```

   `storage/app/workflows` enthält die Workflow-Datenbank und die Editor-Backups. Hast du die Workflow-Daten auf eine andere Datenbankverbindung umgezogen, lösche dort die `workflow_*`-Tabellen.

Das Addon ändert keine Dateien von Statamic selbst. Einträge behalten den `content_updated_at`-Wert, den das Addon in sie geschrieben hat. Er schadet nicht, du kannst ihn aber aus den Einträgen entfernen.

## Addon lokal entwickeln

> [!NOTE]
> Nur relevant, wenn du am Addon selbst arbeitest.

Um Änderungen an einem lokalen Checkout des Addons ohne Release zu testen, bindest du ihn als Composer-Path-Repository ein:

```json
{
    "repositories": [
        { "type": "path", "url": "../statamic-workflows", "options": { "symlink": true } }
    ],
    "require": {
        "takepart-media/statamic-workflows": "*"
    }
}
```

Führe `composer update takepart-media/statamic-workflows` aus. Composer verlinkt `vendor/takepart-media/statamic-workflows` auf deinen Checkout. Veröffentliche nach `npm run build` im Addon die Assets erneut oder verlinke `public/vendor/statamic-workflows/build` auf `resources/dist/build` des Addons. Ein `vendor:publish --tag=workflows --force` ersetzt einen solchen Link durch eine Kopie.

## Nächste Schritte

- [Konfiguration](configuration.md): Gruppen, Mail-Versand und Scheduler anpassen.
- [Workflows definieren](workflows.md): Places, Transitions und Aktionen.
- [Workflow-Editor](editor.md): Workflows im CP bearbeiten.
- [Befehle & Scheduler](commands.md): alle Artisan-Befehle und die Cron-Einrichtung.

---

[Doku-Start](README.md) · [Konfiguration](configuration.md) →
