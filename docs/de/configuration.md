# Konfiguration

🇬🇧 [English](../en/configuration.md)

Referenz aller Schlüssel in `config/workflows.php`, nach Themen gruppiert.

## Config veröffentlichen

Das Addon funktioniert ohne Config-Datei in deinem Projekt. Um eine Einstellung zu ändern, veröffentliche die Config einmalig:

```bash
php artisan vendor:publish --tag=workflows-config
```

Damit landet die Datei unter `config/workflows.php`. Schlüssel, die in deiner Kopie fehlen, fallen auf die Voreinstellungen des Addons zurück.

Viele Einstellungen lesen außerdem eine Umgebungsvariable. So kannst du sie pro Umgebung ändern, ohne die Config zu veröffentlichen. Cachst du deine Config mit `php artisan config:cache`, führe den Befehl nach jeder Änderung an der `.env` erneut aus.

> [!NOTE]
> Einstellungen pro Workflow, etwa Places, Transitions, wer in einem Workflow Beiträge anlegen darf oder das Vier-Augen-Prinzip, stehen in der YAML des Workflows und werden im [Workflow-Editor](editor.md) bearbeitet. Diese Seite beschreibt nur die projektweiten Einstellungen.

## Datenbank

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `database` | `array` | SQLite, siehe unten | – | Verbindungsdaten für die Workflow-Daten. Das Addon registriert sie als Datenbankverbindung `workflow`. |
| `database.driver` | `string` | `'sqlite'` | – | Jeder Laravel-Datenbanktreiber. |
| `database.database` | `string` | `storage_path('app/workflows/workflow.sqlite')` | – | Bei SQLite: der Dateipfad. Datei und Ordner werden bei Bedarf automatisch angelegt. |
| `database.prefix` | `string` | `''` | – | Tabellen-Präfix. |
| `database.foreign_key_constraints` | `bool` | `true` | – | Fremdschlüssel-Prüfung bei SQLite. |

Willst du die Workflow-Daten stattdessen in deiner Hauptdatenbank halten, ersetze das Array durch eine vollständige Laravel-Verbindung, zum Beispiel:

```php
'database' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
],
```

Führe danach die Migrationen des Addons darauf aus, siehe [Installation](installation.md#addon-aktualisieren).

## Revisionen

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `revisions` | `bool` | `true` | `WORKFLOWS_REVISIONS` | Ob dein Projekt Statamic-Revisionen zusammen mit den Workflows nutzt. Bei `true` schaltet das Addon Statamics Revisionen ein, sofern `statamic.revisions.enabled` nicht ausdrücklich `false` ist, und der Editor warnt bei veröffentlichenden Workflows, deren Collections keine Revisionen haben. `false` lässt Statamics Einstellung unangetastet und schaltet diese Warnung ab. |

Statamic liefert `STATAMIC_REVISIONS_ENABLED` mit `false` aus, und das zählt als ausdrücklich aus. Für Revisionen setzt du `STATAMIC_REVISIONS_ENABLED=true` und installierst Statamic Pro. Jede Transition versucht außerdem, unabhängig von dieser Einstellung, eine Statamic-Revision mit dem Namen der Transition zu schreiben. Kann Statamic sie nicht schreiben, etwa ohne Pro, läuft die Transition ohne Revision weiter. Siehe [Installation](installation.md#revisionen-optional).

## Gruppen und Berechtigungen

Listen von Handles der Statamic-Nutzergruppen. Super-Admins dürfen immer, egal was in diesen Listen steht. Einzelheiten und wie die Listen mit den Einstellungen pro Workflow zusammenspielen: [Berechtigungen](permissions.md).

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `privileged_groups` | `array` | `['admins']` | – | Mitglieder umgehen die Workflow-Sperren: Sie dürfen einen Status von Hand setzen, Einträge zwischen Sites verschieben, außerhalb des Workflows veröffentlichen und Statamics eigenen Veröffentlichen-Button benutzen. |
| `post_creator_groups` | `array` | `[]` | – | Projektweiter Rückfallwert dafür, wer auf einer Manager-Seite den Button „Neuer Beitrag“ sieht. Die Workflow-Einstellung „Darf Beiträge anlegen“ geht vor. Leer heißt: nur Super-Admins. |
| `assigner_groups` | `array` | `[]` | – | Projektweiter Rückfallwert dafür, wer die Verantwortlichen eines Eintrags ändern darf. Die Workflow-Einstellung „Darf Verantwortliche zuweisen“ geht vor. Leer heißt: nur Super-Admins. |
| `history_groups` | `array` | `['admins']` | – | Wer den Verlauf eines Eintrags sehen darf („Verlauf anzeigen“, die Verlaufs-Schublade und ihre Feldänderungen). Nicht von `privileged_groups` abgedeckt. Leer heißt: nur Super-Admins. |
| `comment_moderator_groups` | `array` | `['admins']` | – | Wer jeden Kommentar löschen darf, nicht nur die eigenen, und jede Diskussion erledigen oder wieder öffnen darf. Die Workflow-Einstellung „Kommentare löschen dürfen“ geht vor. Nicht von `privileged_groups` abgedeckt. Leer heißt: nur Super-Admins. |

> [!NOTE]
> `privileged_groups` schließt `history_groups` und `comment_moderator_groups` **nicht** ein. Trag eine Gruppe ausdrücklich in jede Liste ein, in der sie stehen soll.

## Kommentare

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `comment_mention_mail` | `bool` | `true` | `WORKFLOWS_COMMENT_MENTION_MAIL` | Per @ erwähnte Nutzer bekommen eine Mail. Nie an die Person, die den Kommentar schreibt; nach einer Bearbeitung nur an neu Erwähnte. Versand gemäß `mail_delivery`. |

## Zuweisung und Dashboard

Diese Schlüssel sind Erweiterungspunkte. Jeder hat eine eigene Seite mit allen Optionen und Beispielen.

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `assignment_criteria` | `array` | `[]` | – | Zusätzliche Kriterien für Zuweisungs-Aktionen, auf Basis von Feldern im Nutzer- oder Eintrags-Blueprint. Siehe [Zuweisungskriterien](extending/assignment-criteria.md). |
| `header_responsibles` | `array` | `['group' => null, 'user_field' => null]` | – | Die Zeile „Verantwortlich“ im Nutzer-Header des Dashboards. Sind beide `null`, fehlt die Zeile. Siehe [Verantwortliche im Dashboard-Header](extending/header-responsibles.md). |
| `dashboard_widgets` | `array\|false` | sieben Workflow-Widgets | – | Das Dashboard-Layout, das das Addon setzt, solange `widgets` in `config/statamic/cp.php` leer ist. `false` schaltet es ab. Siehe [Dashboard-Widgets](extending/dashboard-widgets.md). |

## Aktionen und Mail-Typen

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `actions` | `array` | `[]` | – | Eigene Aktionstypen für Transitions und Places, optional mit PHP-Handler. Siehe [Eigene Action-Typen](extending/actions.md). |
| `mail_types` | `array` | `[]` | – | Eigene Mail-Typen für die Aktion `mail`, mit eigenen Texten, Daten oder eigenem Template. Siehe [Eigene Mail-Typen](extending/mail-types.md). |

## Workflow-Editor

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `editor_backups` | `int` | `20` | – | Aufbewahrte Backups pro Workflow. Bevor eine Workflow-YAML neu geschrieben wird (Speichern im Editor, Installer, Workflow ein- oder ausschalten, löschen oder auf das Beispiel zurücksetzen), wird die aktuelle Datei nach `storage/app/workflows/backups/<handle>/<zeitstempel>.yaml` kopiert. Ältere Kopien werden gelöscht. `0` schaltet Backups ab. |
| `nav_section` | `string` | `'Workflows'` | – | **Veraltet, wird nicht gelesen.** Bleibt erhalten, damit ältere veröffentlichte Configs weiter laden. Die Manager erreichst du über das Dashboard. |

## Scheduler

Schalter für die Aufgaben, die das Addon bei Laravels Scheduler anmeldet. Ein ausgeschalteter Schalter entfernt nur die geplante Aufgabe; den Befehl kannst du weiter von Hand ausführen. Die Cron-Einrichtung steht unter [Befehle & Scheduler](commands.md#scheduler).

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `sla_scheduler` | `bool` | `true` | `WORKFLOWS_SLA_SCHEDULER` | Meldet `workflows:transition-aged` stündlich an ([Inhaltsalterung](sla.md)). |
| `reminder_scheduler` | `bool` | `true` | `WORKFLOWS_REMINDER_SCHEDULER` | Meldet `workflows:auto-transition` und `workflows:send-reminders` einmal täglich an (die Place-Aktionen `auto_transition` und `reminder`). |
| `reminder_time` | `string` | `'08:00'` | `WORKFLOWS_REMINDER_TIME` | Uhrzeit (`HH:MM`, Zeitzone der App) für beide täglichen Aufgaben. |
| `translation.plain_language.poll_scheduler` | `bool` | `true` | `PLAIN_LANGUAGE_POLL_SCHEDULER` | Meldet `workflows:plain-language-poll` jede Minute an. Siehe [Übersetzung](#übersetzung). |

## Mail-Versand

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `mail_delivery` | `string` | `'after_response'` | `WORKFLOWS_MAIL_DELIVERY` | Wie Mails verschickt werden, die ein Klick auslöst, damit eine Transition nicht auf den Mailserver wartet. |

| Wert | Verhalten |
| --- | --- |
| `after_response` | Im selben PHP-Prozess direkt nachdem die Antwort an den Browser ging. Kein Queue-Worker nötig. |
| `queue` | Landet auf deiner Standard-Queue-Verbindung (`QUEUE_CONNECTION`). Braucht ein laufendes `php artisan queue:work` und bekommt die Wiederholungsversuche der Queue. |
| `sync` | Sofort, vor der Antwort. Der Klick wartet auf jede Mail. |

Das gilt für die `mail`-Aktionen von Transitions und Places, für Mails bei @-Erwähnungen und für Konto-Erinnerungen. Erinnerungen aus `workflows:send-reminders` gehen immer sofort raus. Siehe [Benachrichtigungen](notifications.md).

## Konto-Erinnerung

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `account_reminder.enabled` | `bool` | `true` | `WORKFLOWS_ACCOUNT_REMINDER` | Nach jeder Transition bekommen Zuständige, die ihr Konto nie aktiviert haben (kein Passwort gesetzt), eine Mail mit einem neuen Aktivierungslink. Erneut erinnert wird erst, wenn der letzte Aktivierungslink abgelaufen ist. |
| `account_reminder.expire_hours` | `int\|null` | `null` | `WORKFLOWS_ACTIVATION_EXPIRE_HOURS` | Wie lange ein Aktivierungslink gültig ist, in Stunden. `null` behält Statamics Einstellung. Eine Zahl gilt auch für die Einladungen, die Statamic aus dem CP verschickt. |

## Übersetzung

Zugangsdaten und Verhalten der maschinellen Übersetzungsdienste. Sie stehen nur hier und in der `.env`, nie in der Workflow-YAML. Alle Schlüssel, den Ablauf einer Übersetzung und das Protokoll des Dienstes beschreibt [Übersetzung & Einfache Sprache](extending/translation.md).

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `translation.deepl.key` | `string\|null` | `null` | `DEEPL_API_KEY` | DeepL-API-Schlüssel. Kostenlose Schlüssel (Endung `:fx`) nutzen automatisch den Free-Endpunkt. |
| `translation.plain_language.url` | `string\|null` | `null` | `PLAIN_LANGUAGE_URL` | Basis-URL des Dienstes für Einfache Sprache (Schema und Host). |
| `translation.plain_language.token` | `string\|null` | `null` | `PLAIN_LANGUAGE_TOKEN` | Bearer-Token für den Dienst. Wird nie geloggt. |
| `translation.plain_language.body_extras.guideline` | `string\|null` | `null` | `PLAIN_LANGUAGE_GUIDELINE` | Umschreib-Profil auf Seiten des Dienstes. |
| `translation.plain_language.body_extras.type` | `string\|null` | `null` | `PLAIN_LANGUAGE_TYPE` | Art der Umschreibung. |
| `translation.plain_language.poll_scheduler` | `bool` | `true` | `PLAIN_LANGUAGE_POLL_SCHEDULER` | Fertige Umschreibungen jede Minute abholen. Ohne das (oder einen eigenen Runner) werden Umschreibungen nie zurückgeschrieben. |
| `translation.plain_language.log_calls` | `bool` | `false` | `PLAIN_LANGUAGE_LOG_CALLS` | Anfrage und Antwort erfolgreicher Aufrufe loggen. Fehler werden immer geloggt. |
| `translation.plain_language.max_polls` | `int` | `5` | `PLAIN_LANGUAGE_MAX_POLLS` | Abfragen (etwa eine pro Minute), die auf eine Einreichung gewartet wird, bevor sie als gescheitert gilt. |
| `translation.plain_language.max_attempts` | `int` | `3` | `PLAIN_LANGUAGE_MAX_ATTEMPTS` | Einreichungen insgesamt, die erste mitgezählt. `1` schaltet Wiederholungen ab. |
| `translation.plain_language.heading_set` | `string\|null` | `'heading'` | – | Bard-Set, zu dem zurückgelieferte `<h2>`/`<h3>`-Überschriften in einem Bard-Feld der obersten Ebene werden. `null` schaltet das ab. |
| `translation.plain_language.heading_index_field` | `string\|null` | `'heading_index'` | – | Feld, in dem Überschriften-Sets durchnummeriert werden. `null` schaltet das ab. |
| `translation.plain_language.include_disabled` | `bool` | `true` | – | Auch deaktivierte Replicator-Zeilen und Bard-Sets umschreiben. |

DeepL ist aktiv, sobald der Schlüssel gesetzt ist; der Dienst für Einfache Sprache braucht `url` und `token`.

## Inhaltsfelder

Die Handles der Blueprint-Felder, die Übersetzung, Umschreibung in Einfache Sprache und Inhaltsvorschau kennen müssen. Steht ein Schlüssel auf `null`, überspringt die Funktion, die ihn braucht, dieses Feld. Welche Felder den Inhalt eines Eintrags ausmachen, legst du pro Workflow im Editor fest. Einzelheiten: [Inhaltsfelder](extending/content-fields.md).

| Schlüssel | Typ | Standard | Env | Beschreibung |
| --- | --- | --- | --- | --- |
| `content_fields.title` | `string\|null` | `'title'` | – | Das Titelfeld des Eintrags. |
| `content_fields.intro` | `string\|null` | `null` | – | Bard-Feld mit dem einleitenden Absatz. |
| `content_fields.body` | `string\|null` | `'content'` | – | Das Haupt-Bard-Feld. |
| `content_fields.seo` | `string\|null` | `'seo'` | – | Das SEO-Feld. |
| `content_fields.entity` | `string\|null` | `null` | – | Feld mit der Einheit oder dem Modul, zu dem ein Eintrag gehört. Geht als Kontext an den Dienst für Einfache Sprache. |
| `content_fields.scope` | `string\|null` | `null` | – | Set-Feld, das einen Block auf bestimmte Einheiten beschränkt. |
| `content_fields.sources_title`, `sources_text`, `podcast_link`, `podcast_code`, `sets` | – | `null` / `[]` | – | **Veraltet.** Bleiben erhalten, damit ältere Configs weiter laden. |

## Umgebungsvariablen im Überblick

```env
WORKFLOWS_REVISIONS=true
WORKFLOWS_COMMENT_MENTION_MAIL=true
WORKFLOWS_SLA_SCHEDULER=true
WORKFLOWS_REMINDER_SCHEDULER=true
WORKFLOWS_REMINDER_TIME=08:00
WORKFLOWS_MAIL_DELIVERY=after_response
WORKFLOWS_ACCOUNT_REMINDER=true
WORKFLOWS_ACTIVATION_EXPIRE_HOURS=

DEEPL_API_KEY=
PLAIN_LANGUAGE_URL=
PLAIN_LANGUAGE_TOKEN=
PLAIN_LANGUAGE_GUIDELINE=
PLAIN_LANGUAGE_TYPE=
PLAIN_LANGUAGE_POLL_SCHEDULER=true
PLAIN_LANGUAGE_LOG_CALLS=false
PLAIN_LANGUAGE_MAX_POLLS=5
PLAIN_LANGUAGE_MAX_ATTEMPTS=3
```

## Übersetzungen überschreiben

Alle CP-Beschriftungen und Mail-Texte kommen aus den Übersetzungen `workflows::messages` des Addons, auf Englisch und Deutsch. Um einen Text zu ändern, lege in deinem Projekt eine Override-Datei für die Sprache an:

```text
lang/vendor/workflows/en/messages.php
lang/vendor/workflows/de/messages.php
```

Die Datei braucht nur die Schlüssel, die du änderst. Laravel legt sie über die mitgelieferte Datei:

```php
<?php

// lang/vendor/workflows/de/messages.php
return [
    'state_review' => 'In Prüfung',
    'action_publish' => 'Live schalten',
];
```

Willst du stattdessen mit einer vollständigen Kopie der mitgelieferten Dateien beginnen:

```bash
php artisan vendor:publish --tag=workflows-translations
```

Namen von Places und Transitions folgen einem festen Schlüsselschema:

| Schlüssel | Benennt |
| --- | --- |
| `state_<place-handle>` | Einen Place. Ein Label, das du im Workflow-Editor für die aktuelle Sprache gesetzt hast, geht vor. |
| `action_<transition-handle>` | Eine Transition. Geht vor dem Label in der YAML. |

Gibt es weder Schlüssel noch Label, wird der lesbar gemachte Handle angezeigt. Auch die Texte deiner eigenen Mail-Typen gehören in diese Dateien, siehe [Eigene Mail-Typen](extending/mail-types.md).

## Nächste Schritte

- [Workflows definieren](workflows.md): die Workflow-YAML und ihre Metadaten.
- [Berechtigungen](permissions.md): wie die Gruppenlisten mit den Einstellungen pro Workflow zusammenspielen.
- [Benachrichtigungen](notifications.md): Mail-Aktionen, Erinnerungen und Versand.
- [Erweitern](extending/README.md): alle Erweiterungspunkte im Detail.

---

← [Installation](installation.md) · [Doku-Start](README.md) · [Workflows definieren](workflows.md) →
