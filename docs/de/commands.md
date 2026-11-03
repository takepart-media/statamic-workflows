# Befehle & Scheduler

🇬🇧 [English](../en/commands.md)

Alle Artisan-Befehle, die das Addon registriert, und die geplanten Aufgaben, die sie für dich ausführen.

## Überblick

| Befehl | Zweck | Geplant |
| --- | --- | --- |
| [`workflows:install`](#workflowsinstall) | Das Addon in einem Projekt einrichten | – |
| [`workflows:make`](#workflowsmake) | Eine neue Workflow-YAML anlegen | – |
| [`workflows:seed-states`](#workflowsseed-states) | Bestehenden Einträgen einen Workflow-Status geben | – |
| [`workflows:prune-states`](#workflowsprune-states) | Workflow-Daten entfernen, die zu nichts mehr gehören | – |
| [`workflows:transition-aged`](#workflowstransition-aged) | Veröffentlichte Einträge weiterschieben, wenn ihr Inhalt zu alt ist | stündlich |
| [`workflows:auto-transition`](#workflowsauto-transition) | Fällige automatische Transitions ausführen | täglich |
| [`workflows:send-reminders`](#workflowssend-reminders) | Fällige Erinnerungen verschicken | täglich |
| [`workflows:undo-auto-transition`](#workflowsundo-auto-transition) | Eine automatische Transition zurücknehmen | – |
| [`workflows:backfill-content-timestamp`](#workflowsbackfill-content-timestamp) | Das Inhaltsdatum älterer Einträge nachtragen | – |
| [`workflows:plain-language-poll`](#workflowsplain-language-poll) | Fertige Umschreibungen in Einfache Sprache abholen | jede Minute |
| [`workflows:backfill-plain-language`](#workflowsbackfill-plain-language) | Ältere Fassungen in Einfacher Sprache auf den aktuellen Stand bringen | – |
| [`workflow:dump`](#workflowdump) | Einen Workflow als Mermaid-Diagramm ausgeben | – |

Befehle mit der Option `--workflow=` erwarten einen Workflow-Handle, also den Dateinamen in `resources/workflows/` ohne `.yaml`. Ohne die Option laufen sie für alle aktiven Workflows. Die meisten Befehle, die Daten ändern, haben eine Option `--dry-run`. Nutze sie zuerst.

## Einrichtung

### `workflows:install`

```bash
php artisan workflows:install
```

Interaktive Ersteinrichtung. Der Befehl führt die Migrationen des Addons auf der Verbindung `workflow` aus, veröffentlicht die CP-Assets, lässt dich einen oder mehrere der mitgelieferten Beispiel-Workflows wählen (`postmanager`, `translation`, `plainlanguage`) und fragt für jeden nach den Collections und ob Status nachgetragen werden sollen. Eine vorhandene `resources/workflows/<typ>.yaml` bleibt erhalten, ihre Collections werden aber durch deine neue Auswahl ersetzt. Siehe [Installation](installation.md#installer-ausführen).

Keine Optionen.

### `workflows:make`

```bash
php artisan workflows:make <name> [optionen]
```

Legt `resources/workflows/<name>.yaml` an, entweder als leeres Gerüst von Entwurf bis veröffentlicht oder als Kopie eines mitgelieferten Beispiels. Es gelten dieselben Regeln wie bei „Workflow anlegen“ im CP, und ein vorhandener Workflow wird nie überschrieben.

| Argument / Option | Beschreibung |
| --- | --- |
| `name` | Workflow-Handle in snake_case, zum Beispiel `press_review`. |
| `--from=` | Mit einem mitgelieferten Beispiel starten: `postmanager`, `translation` oder `plainlanguage`. |
| `--collection=` | Collection, die der Workflow steuert. Standard `posts` für das leere Gerüst. Mit `--from` behält das Beispiel seine eigenen Collections, es sei denn, du gibst diese Option an. |
| `--label=` | Anzeigename. Standard ist der Handle. |
| `--inactive` | Den Workflow ausgeschaltet schreiben (`active: false`). Ohne die Option schreibt der Befehl ihn aktiv. |

```bash
php artisan workflows:make press_review --from=postmanager --collection=press --label="Presseprüfung"
```

Der Befehl gibt den URL-Slug des Managers aus. Achte darauf, dass die Gruppen existieren, die der Workflow nennt, siehe [Installation](installation.md#nutzergruppen).

### `workflows:seed-states`

```bash
php artisan workflows:seed-states [--workflow=] [--redo]
```

Gibt Einträgen, die es schon gab, bevor ein Workflow sie gesteuert hat, einen gespeicherten Workflow-Status. Einträge ohne gespeicherten Status werden in einem Standard-Status angezeigt, nehmen aber nicht an der Inhaltsalterung teil, und Workflows, denen die Collection nicht gehört, zeigen sie in niemandes Warteschlange. Der geschriebene Status ist derselbe, den das CP schon anzeigt. Das Nachtragen schreibt keinen Verlauf, keine Zuständigen und veröffentlicht oder depubliziert nichts.

| Option | Beschreibung |
| --- | --- |
| `--workflow=` | Nur dieser Workflow. Ein ausgeschalteter Workflow wird mit einem Hinweis übersprungen. |
| `--redo` | Auch Einträge neu ableiten, die schon einen Status haben, solange sie keinen Workflow-Verlauf haben. |

Du kannst den Befehl gefahrlos erneut ausführen; Einträge mit Status werden übersprungen. Hat ein Workflow weder einen veröffentlichenden Place noch `metadata.seed_published_place`, landet jeder Eintrag im Start-Place und der Befehl warnt dich. Im CP gibt es dieselbe Aktion als „Status nachtragen“ unter **Einstellungen > Workflows**.

### `workflows:prune-states`

```bash
php artisan workflows:prune-states [--workflow=] [--dry-run] [--force]
```

Entfernt Workflow-Daten (Status, Verlauf, Zuständige, Kommentare und mehr) von Einträgen, die sie nicht mehr beschreiben. Der Befehl zeigt pro Workflow eine Tabelle mit vier Gründen:

| Grund | Bedeutung |
| --- | --- |
| `deleted` | Den Eintrag gibt es nicht mehr. |
| `ungoverned` | Die Collection des Eintrags steht nicht mehr in den `collections` des Workflows. |
| `foreign` | Ein Übersetzungs-Workflow hält Daten für eine Site, für die er nicht zuständig ist. |
| `unstarted` | Ein Übersetzungs-Workflow hält einen Status für einen Eintrag, der nicht veröffentlicht ist. |

Außer bei `deleted` bleiben Einträge mit Verlauf, Zuständigen oder Kommentaren in diesem Workflow erhalten und werden als `protected` gezählt.

| Option | Beschreibung |
| --- | --- |
| `--workflow=` | Nur dieser Workflow. Ohne die Option sind auch ausgeschaltete Workflows dabei. |
| `--dry-run` | Tabelle anzeigen, nichts ändern. |
| `--force` | Die Rückfrage überspringen. |

> [!WARNING]
> Das Aufräumen lässt sich nicht rückgängig machen. Führe zuerst `--dry-run` aus und sichere `storage/app/workflows/workflow.sqlite`.

## Alterung und automatische Schritte

### `workflows:transition-aged`

```bash
php artisan workflows:transition-aged [--workflow=] [--dry-run]
```

Schiebt veröffentlichte Einträge in den Place „Aktualisierung nötig“ oder „Veraltet“ ihres Workflows, sobald ihr Inhalt das Alter aus `metadata.sla` erreicht. Das Alter zählt ab dem Feld `content_updated_at` des Eintrags. Hat der Workflow eine Transition in den Ziel-Place, wird sie mit ihrer Zuweisung und Mail ausgeführt; sonst wird der Eintrag direkt verschoben. Workflows ohne `metadata.sla` und Übersetzungs-Workflows werden übersprungen. Siehe [Inhaltsalterung](sla.md).

| Option | Beschreibung |
| --- | --- |
| `--workflow=` | Nur dieser Workflow. |
| `--dry-run` | Die geplanten Verschiebungen ausgeben, ohne sie auszuführen. |

Stündlich geplant. Schalter: `sla_scheduler`.

### `workflows:auto-transition`

```bash
php artisan workflows:auto-transition [--workflow=] [--dry-run]
```

Führt die Place-Aktion `auto_transition` aus: Bei einem Eintrag, der bis zu seinem Stichtag in einem solchen Place lag, wird die eingestellte Transition ausgeführt, als hätte jemand geklickt, nur ohne Nutzer. Fehler werden geloggt und halten den Lauf nicht an.

| Option | Beschreibung |
| --- | --- |
| `--workflow=` | Nur dieser Workflow. |
| `--dry-run` | Die fälligen Transitions auflisten, ohne sie auszuführen. |

Täglich um `reminder_time` geplant, direkt vor den Erinnerungen. So bekommt ein Eintrag, der weiterzieht, keine Erinnerung an den Place, den er gerade verlassen hat. Schalter: `reminder_scheduler`.

### `workflows:send-reminders`

```bash
php artisan workflows:send-reminders [--workflow=] [--dry-run]
```

Führt die Place-Aktion `reminder` aus: Solange ein Eintrag in einem solchen Place liegt, bekommen seine Zuständigen alle `every_days` Tage eine Mail, das erste Mal so lange nach seiner Ankunft. Jede Erinnerung wird im Verlauf vermerkt, ein zweiter Lauf am selben Tag verschickt also nichts. Einträge ohne Zuständige bekommen keine Mail. Diese Mails gehen unabhängig von `mail_delivery` immer sofort raus.

| Option | Beschreibung |
| --- | --- |
| `--workflow=` | Nur dieser Workflow. |
| `--dry-run` | Die fälligen Erinnerungen auflisten, ohne sie zu verschicken. |

Täglich um `reminder_time` geplant. Schalter: `reminder_scheduler`. Siehe [Benachrichtigungen](notifications.md).

### `workflows:undo-auto-transition`

```bash
php artisan workflows:undo-auto-transition <entry> [optionen]
```

Nimmt die neueste automatische Transition eines Eintrags zurück: Der Eintrag kehrt in den Place zurück, aus dem er kam, sein Veröffentlichungsstatus zieht mit, und der Verlauf bekommt eine Zeile dazu. Zuständige werden **nicht** wiederhergestellt; korrigiere sie in der Zuständigen-Auswahl des Eintrags.

| Argument / Option | Beschreibung |
| --- | --- |
| `entry` | Die ID des Eintrags. |
| `--workflow=` | Der Workflow. Standard ist der, in dem der Eintrag einen Status hat; Pflicht, wenn es mehrere sind. |
| `--to=` | Diesen Place setzen statt des Places vor der letzten automatischen Transition. |
| `--days-ago=` | Die Ankunft im wiederhergestellten Place um so viele Tage zurückdatieren. Praktisch, um Erinnerungen und Fristen zu testen. |
| `--dry-run` | Zeigen, was sich ändern würde, nichts ändern. |

### `workflows:backfill-content-timestamp`

```bash
php artisan workflows:backfill-content-timestamp [--workflow=] [--force] [--dry-run]
```

Schreibt `content_updated_at` für Einträge, die noch keinen Wert haben, aus dem Datum des Eintrags oder, bei Collections ohne Datum, aus dem Änderungsdatum der Datei. Die Inhaltsalterung liest schon denselben Rückfallwert, keine Frist ändert sich also. Der Befehl macht den Wert sortierbar und pro Fassung vergleichbar.

| Option | Beschreibung |
| --- | --- |
| `--workflow=` | Nur die Collections dieses Workflows. Standard: alle redaktionellen Workflows. |
| `--force` | Auch Einträge überschreiben, die schon einen Wert haben. Das setzt jede Frist neu. |
| `--dry-run` | Melden, was geschrieben würde, nichts ändern. |

## Einfache Sprache

### `workflows:plain-language-poll`

```bash
php artisan workflows:plain-language-poll [--dry-run] [--diff]
```

Der Dienst für Einfache Sprache arbeitet asynchron. Dieser Befehl fragt ihn nach fertigen Umschreibungen und schreibt sie in ihre Einträge. Gescheiterte Einreichungen werden bis zu `max_attempts`-mal wiederholt; nach der letzten wandert die Fassung in den Fehler-Place des Workflows (`metadata.plain_language_failed_place`). Ohne einen solchen Place wird die leere Fassung stattdessen gelöscht. Ist der Dienst nicht erreichbar, ändert sich nichts und der nächste Lauf versucht es erneut.

| Option | Beschreibung |
| --- | --- |
| `--dry-run` | Melden, was der Dienst sagt und was geschrieben würde, ohne etwas zu schreiben. |
| `--diff` | Die Änderungen jedes Zurückschreibens Feld für Feld ausgeben. |

Jede Minute geplant. Schalter: `translation.plain_language.poll_scheduler`. Siehe [Übersetzung & Einfache Sprache](localization.md).

### `workflows:backfill-plain-language`

```bash
php artisan workflows:backfill-plain-language [--workflow=] [--force] [--dry-run]
```

Wendet die heutigen Regeln auf Fassungen in Einfacher Sprache an, die früher umgeschrieben wurden: Der Befehl leitet `seo.title` und `seo.description` aus dem eigenen Inhalt der Fassung ab und leert die Felder aus `metadata.plain_language_clear_fields` des Workflows. Übersprungen werden Fassungen mit laufender Umschreibung, Fassungen, die nie umgeschrieben wurden, und Werte, die jemand nach der Umschreibung bearbeitet hat. Schreibt keinen Verlauf.

| Option | Beschreibung |
| --- | --- |
| `--workflow=` | Nur dieser Workflow für Einfache Sprache. |
| `--force` | Auch Werte überschreiben, die nach der Umschreibung bearbeitet wurden. |
| `--dry-run` | Melden, was geschrieben würde, nichts ändern. |

## Diagramme

### `workflow:dump`

```bash
php artisan workflow:dump <name> [--format=mermaid]
```

Gibt die Places und Transitions eines Workflows als [Mermaid](https://mermaid.js.org/)-Diagrammquelltext aus. Beachte das Präfix `workflow:` in der Einzahl. `mermaid` ist das einzige Format.

```bash
php artisan workflow:dump postmanager > postmanager.mmd
```

## Scheduler

Das Addon meldet seine geplanten Aufgaben selbst an. Du musst nur dafür sorgen, dass Laravels Scheduler läuft.

| Aufgabe | Häufigkeit | Config-Schalter | Env |
| --- | --- | --- | --- |
| `workflows:transition-aged` | stündlich | `sla_scheduler` | `WORKFLOWS_SLA_SCHEDULER` |
| `workflows:auto-transition` | täglich um `reminder_time` | `reminder_scheduler` | `WORKFLOWS_REMINDER_SCHEDULER` |
| `workflows:send-reminders` | täglich um `reminder_time`, nach `auto-transition` | `reminder_scheduler` | `WORKFLOWS_REMINDER_SCHEDULER` |
| `workflows:plain-language-poll` | jede Minute | `translation.plain_language.poll_scheduler` | `PLAIN_LANGUAGE_POLL_SCHEDULER` |

`reminder_time` steht standardmäßig auf `08:00` (`WORKFLOWS_REMINDER_TIME`). Alle Aufgaben laufen mit `withoutOverlapping()` und `onOneServer()`. Bei mehreren Servern brauchst du für die Sperren einen gemeinsamen Cache-Store wie Redis oder die Datenbank.

Prüfe, was angemeldet ist:

```bash
php artisan schedule:list
```

### Produktion

Lege einen Cron-Eintrag für den Nutzer an, unter dem PHP läuft:

```bash
* * * * * cd /pfad/zu/deinem/projekt && php artisan schedule:run >> /dev/null 2>&1
```

Verwaltete Hoster wie Forge oder Ploi bieten das als „Scheduler“ oder „Scheduled Job“ an, der jede Minute läuft.

### Lokale Entwicklung

Herd und Valet führen keinen Cron aus. Starte den Scheduler beim Testen in einem Terminal:

```bash
php artisan schedule:work
```

Oder stoße fällige Aufgaben einmalig mit `php artisan schedule:run` an, oder ruf einen Befehl direkt auf, zum Beispiel `php artisan workflows:transition-aged --dry-run`.

> [!NOTE]
> Ohne laufenden Scheduler altern keine Einträge, es gibt keine Erinnerungen und keine automatischen Transitions, und Umschreibungen in Einfache Sprache werden nie zurückgeschrieben.

## Nächste Schritte

- [Inhaltsalterung (SLA)](sla.md): die Schwellen für die Alterung einrichten.
- [Benachrichtigungen](notifications.md): Mail-Aktionen und Erinnerungen.
- [Fehlerbehebung](troubleshooting.md): wenn eine Aufgabe nicht tut, was du erwartest.

---

← [Berechtigungen](permissions.md) · [Doku-Start](README.md) · [Fehlerbehebung](troubleshooting.md) →
