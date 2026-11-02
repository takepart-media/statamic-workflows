# Eigene Mail-Typen

🇬🇧 [English](../en/mail-types.md)

Verschicke aus deinen Workflows eigene Mails mit eigenen Texten, eigenen Daten und bei Bedarf einem eigenen Template.

> **Auf einen Blick**
> - Config-Schlüssel: `mail_types`, dazu `mail_delivery`
> - Standard: `[]` (= nur die eingebauten Typen)
> - Eigener Code: optional `Contracts\WorkflowMail`

## Überblick

Die `mail`-Action an einer Transition oder einem Place verschickt genau einen Mail-Typ. Welchen, legt `mail_type` in der Workflow-YAML fest. Dasselbe gilt für die Place-Action `reminder`.

Du kannst eigene Typen in drei Stufen ergänzen:

- **Nur Texte:** Der Typ nutzt das gemeinsame Template und liest seine Sätze aus deinen Sprachdateien. Kein PHP nötig.
- **Eigene Daten:** Eine PHP-Klasse liefert Betreff und zusätzliche Template-Variablen, etwa Felder aus dem Eintrag.
- **Eigenes Template:** Ein Blade-Template legt das Layout der Mail fest.

Daten und Template lassen sich frei kombinieren.

## Schnellstart

Dieser Schnellstart legt einen Typ an, der nur eigene Texte hat.

1. Veröffentliche die Config, falls noch nicht geschehen:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Deklariere den Handle in `config/workflows.php`:

   ```php
   'mail_types' => [
       'erinnerung',
   ],
   ```

3. Lege die Texte in `lang/vendor/workflows/de/messages.php` an:

   ```php
   <?php

   return [
       'mail_type_erinnerung' => 'Erinnerung',
       'mail_erinnerung_subject' => 'Noch offen: :title',
       'mail_erinnerung_intro' => 'Der Beitrag :title wartet in „:workflow“ noch auf Bearbeitung.',
   ];
   ```

   Alle anderen Texte wie Überschrift und Button übernimmt der Typ vom eingebauten Typ `assigned`.

4. Öffne den Workflow im CP, füge einer Transition die Action „Mail“ hinzu und wähle den Typ „Erinnerung“.

## Konfiguration

### Eingebaute Typen

| Handle | Empfänger | Beschreibung |
| --- | --- | --- |
| `assigned` | Zugewiesene | Standard, wenn `mail_type` fehlt, und Rückfall für fehlende Texte anderer Typen. |
| `plain_language_ready` | Zugewiesene | Die Fassung in Leichter Sprache ist fertig. |
| `sla` | Zugewiesene | Ein Beitrag ist veraltet. |
| `reminder` | Zugewiesene | Standard der Place-Action `reminder`. |
| `published` | Beobachter | Ein Beitrag wurde veröffentlicht. |

### Deklaration (`mail_types`)

Ein Eintrag hat eine von zwei Formen:

- **Nur ein String** wie `'erinnerung'`: Der Typ nutzt nur Sprachkeys und das gemeinsame Template.
- **Handle als Schlüssel, Array als Wert:** Das Array kann die folgenden Schlüssel enthalten.

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `label` | `string` | nein | siehe Beschreibung | Label im Editor, Klartext oder Sprachkey. Ohne `label` gilt `workflows::messages.mail_type_<handle>`, danach der Handle selbst. |
| `mail` | `class-string` | nein | – | Klasse, die `Contracts\WorkflowMail` implementiert. |
| `view` | `string` | nein | `workflows::mail.notification` | Name eines Blade-Templates. |
| `recipients` | `string` | nein | `assignees` | `assignees` (Zugewiesene) oder `watchers` (Beobachter). Jeder andere Wert gilt als `assignees`. |

### Versand (`mail_delivery`)

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `mail_delivery` | `string` | nein | `after_response` | Wie alle Workflow-Mails verschickt werden. Gesetzt über `WORKFLOWS_MAIL_DELIVERY`. |

| Wert | Verhalten |
| --- | --- |
| `after_response` | Versand im selben Prozess, nachdem die Antwort an den Browser gegangen ist. Kein Worker nötig. |
| `queue` | Versand über `QUEUE_CONNECTION` mit Wiederholungen. Braucht einen laufenden `php artisan queue:work`. |
| `sync` | Versand direkt im Request. |

```env
WORKFLOWS_MAIL_DELIVERY=queue
```

Erinnerungen aus `workflows:send-reminders` werden unabhängig davon immer direkt verschickt.

## Sprachkeys

Alle Texte liegen im Namespace `workflows::messages`. Im Projekt überschreibst du sie in `lang/vendor/workflows/<locale>/messages.php`. Mit `php artisan vendor:publish --tag=workflows-translations` bekommst du die vollständigen Dateien als Vorlage.

Das Schema ist `mail_<handle>_<key>`:

| Key | Verwendung |
| --- | --- |
| `subject` | Betreff. Platzhalter `:title`, ohne Formatierung. |
| `heading` | Überschrift. |
| `intro` | Einleitung. Platzhalter `:title` (fett), `:workflow`, `:days` (nur bei Erinnerungen) und `:updated`. |
| `intro_undated` | Optional. Ersetzt `intro`, wenn es kein Aktualisierungsdatum gibt. |
| `field_state`, `field_actor`, `field_reminder`, `field_entered` | Beschriftungen der Infozeilen. |
| `button` | Button-Text. |
| `outro` | Schlusssatz. |
| `progress` | Fußzeile. Platzhalter `:position` und `:total`. |

Dazu kommt `mail_type_<handle>` als Label im Editor, wenn die Deklaration kein `label` setzt.

Fehlt ein Key, nutzt das Addon denselben Key von `assigned`. Dieser Rückfall gilt **pro Sprache**: Übersetzt du nur Deutsch, bekommen englische Empfänger die Texte von `assigned`.

Eine Mail kann an jede Rolle gehen. Für die mitgelieferten Texte gelten deshalb drei Regeln:

- keine Anrede und kein Name,
- weder „du“ noch „Sie“, stattdessen Passiv oder Infinitiv,
- keine Fachbegriffe wie „Workflow“, „Transition“ oder „Place“.

## Die Mail-Klasse

Eine Mail-Klasse implementiert ein Interface mit einer Methode:

```php
namespace TakepartMedia\Workflows\Contracts;

use TakepartMedia\Workflows\Mail\MailContext;

interface WorkflowMail
{
    /** @return array<string, mixed> */
    public function compose(MailContext $context): array;
}
```

Das Addon löst die Klasse über den Service-Container auf (`app($class)`). Constructor-Injection funktioniert also wie gewohnt.

`compose()` gibt ein Array zurück. Zwei Schlüssel liest das Addon selbst:

| Schlüssel | Typ | Beschreibung |
| --- | --- | --- |
| `subject` | `string` | Betreff. Fehlt er, gilt der Sprachkey `subject`. |
| `view` | `string` | Blade-Template. Hat Vorrang vor `view` aus der Deklaration. |

Alle anderen Schlüssel stehen im Template als Variablen bereit. Sie überschreiben gleichnamige Standard-Variablen.

### Kontext-Objekt

`TakepartMedia\Workflows\Mail\MailContext` beschreibt die Mail an genau einen Empfänger. Alle Eigenschaften sind `readonly`.

| Eigenschaft | Typ | Beschreibung |
| --- | --- | --- |
| `entry` | `Statamic\Contracts\Entries\Entry` | Der Eintrag, um den es geht, frisch geladen. |
| `workflow` | `string` | Name des Workflows. |
| `definition` | `array` | Die rohe Workflow-Definition aus der YAML. |
| `mailType` | `string` | Handle des Mail-Typs. |
| `scope` | `string` | `transition`, `place` oder `reminder`. |
| `handle` | `string` | Handle der Transition bzw. des Places. |
| `state` | `string` | Aktueller Place als **Handle**, nicht als Label. |
| `recipient` | `Statamic\Contracts\Auth\User` | Der Empfänger dieser Mail. |
| `title` | `string` | Titel des Eintrags, sonst der Slug. |
| `url` | `string` | Link zur Übersicht des Eintrags im Workflow. |
| `workflowLabel` | `string` | Navigations-Label des Workflows, sonst sein Name. |
| `actorName` | `?string` | Name des Auslösers. `null`, wenn niemand ausgelöst hat oder der Account gelöscht ist. |
| `actor` | `?Statamic\Contracts\Auth\User` | Derselbe Auslöser als Benutzer-Objekt. `null` unter denselben Bedingungen. |
| `waitingDays` | `?int` | Tage im aktuellen Place. Nur bei Erinnerungen gesetzt. |

| Methode | Beschreibung |
| --- | --- |
| `recipientName(): string` | Name des Empfängers, sonst seine E-Mail-Adresse. |
| `boldTitle(): string` | Titel als Markdown-Fettdruck. Nur für den Mail-Text, nicht für den Betreff. |
| `text(string $key, array $replace = []): string` | Sprachkey dieses Typs, mit Rückfall auf `assigned`. |

## Das Template

Jedes Template bekommt diese Variablen:

| Variable | Beschreibung |
| --- | --- |
| `$context` | Das `MailContext`-Objekt. |
| `$title`, `$url`, `$workflowLabel`, `$recipientName`, `$actorName` | Werte aus dem Kontext. |
| `$stateLabel` | Label des aktuellen Places, in der Sprache des Empfängers. |
| `$autoTransition`, `$reminder`, `$enteredAt`, `$progress` | Infozeilen und Fußzeile des Standard-Templates. |
| `$t` | Fertig übersetzte Sätze: `heading`, `intro`, `field_state`, `field_actor`, `field_reminder`, `field_entered`, `button`, `outro`. |

Das Template wird in dieser Reihenfolge gewählt:

1. `view` aus `compose()`,
2. `view` aus der Deklaration,
3. `workflows::mail.notification`.

Jedes Template wird als Markdown-Mail gerendert. Du kannst also `<x-mail::message>`, `<x-mail::button>`, `<x-mail::panel>` und `<x-mail::subcopy>` verwenden.

## Ablauf

1. Beim Speichern prüft das Addon `mail_type` gegen alle bekannten Typen. Ein unbekannter Handle führt zu `transition_action_mail_unknown`, `place_action_mail_unknown` bzw. `place_action_reminder_unknown`. Fehlt `mail_type`, gilt `assigned`, bei `reminder` gilt `reminder`.
2. Beim Auslösen ermittelt das Addon die Empfänger: die Zugewiesenen oder, bei `recipients: watchers`, die Beobachter. Benutzer ohne E-Mail-Adresse und der Auslöser selbst bekommen keine Mail.
3. Für jeden Empfänger entsteht eine eigene Mail mit eigenem `MailContext`.
4. Die Mail wird in der CP-Sprache des Empfängers gerendert. `compose()` läuft innerhalb dieser Sprache, einmal pro Mail.
5. Bei `after_response` und `queue` lädt der Versand-Job den Eintrag neu. `compose()` läuft dann im Job.
6. Schlägt die Mail an einen Empfänger fehl, wird der Fehler protokolliert. Alle anderen Empfänger bekommen ihre Mail trotzdem.

## Beispiele

### Pressefreigabe mit eigenen Daten und Template

Eine Mail mit Sperrfrist aus dem Eintrag und eigenem Layout:

```php
// config/workflows.php
'mail_types' => [
    'pressefreigabe' => [
        'label' => 'Pressefreigabe',
        'mail' => \App\Workflows\Mail\Pressefreigabe::class,
        'view' => 'mail.pressefreigabe',
    ],
],
```

```php
// lang/vendor/workflows/de/messages.php
return [
    'mail_pressefreigabe_subject' => 'Freigabe nötig: :title',
    'mail_pressefreigabe_intro' => ':title wartet in „:workflow“ auf die Pressefreigabe.',
];
```

```php
<?php

namespace App\Workflows\Mail;

use TakepartMedia\Workflows\Contracts\WorkflowMail;
use TakepartMedia\Workflows\Mail\MailContext;

class Pressefreigabe implements WorkflowMail
{
    public function compose(MailContext $context): array
    {
        return [
            'subject' => $context->text('subject', ['title' => $context->title]),
            'deadline' => $context->entry->value('embargo'),
            'notes' => $context->entry->value('press_notes'),
        ];
    }
}
```

```blade
{{-- resources/views/mail/pressefreigabe.blade.php --}}
<x-mail::message>
# {{ $t['heading'] }}

{{ $t['intro'] }}

@if ($deadline)
- **Sperrfrist:** {{ $deadline }}
@endif

<x-mail::button :url="$url">
{{ $t['button'] }}
</x-mail::button>
</x-mail::message>
```

```yaml
transitions:
  submit_press:
    metadata:
      actions:
        - type: mail
          mail_type: pressefreigabe
```

### Benachrichtigung an Beobachter

Ein Typ ohne PHP, der an die Beobachter statt an die Zugewiesenen geht:

```php
// config/workflows.php
'mail_types' => [
    'korrektur' => [
        'label' => 'Korrektur veröffentlicht',
        'recipients' => 'watchers',
    ],
],
```

```php
// lang/vendor/workflows/de/messages.php
return [
    'mail_korrektur_subject' => 'Korrigiert: :title',
    'mail_korrektur_intro' => 'Zu :title wurde eine Korrektur veröffentlicht.',
];
```

## Hinweise & Grenzen

> [!WARNING]
> Übersetze nur innerhalb von `compose()`. Ein vorher übersetzter String erscheint in der Sprache des Absenders. Deshalb ist `state` ein Handle; im Template nutzt du `$stateLabel`.

> [!WARNING]
> Templates werden mit `html_input => 'escape'` gerendert. Rohes HTML in einem Wert erscheint als sichtbare spitze Klammern. Hebe Text mit Markdown hervor, zum Beispiel mit `boldTitle()`.

> [!WARNING]
> Nennt `mail` eine Klasse, die fehlt oder `WorkflowMail` nicht implementiert, wird der ganze Typ verworfen. Er verschwindet aus dem Editor, und jede YAML mit diesem Handle lässt sich nicht mehr speichern. Ein ungültiges `view` oder `recipients` verwirft den Typ nicht.

> [!NOTE]
> Ein gespeicherter `mail_type`, der nicht mehr deklariert ist, wird zur Laufzeit zu `assigned`. Es geht also die Standard-Mail raus, nicht gar keine.

> [!NOTE]
> Projekt-Einträge mit den Handles `assigned`, `plain_language_ready`, `sla`, `reminder` oder `published` werden ohne Meldung ignoriert. Eingebaute Typen haben Vorrang.

> [!NOTE]
> Jeder Handle muss deklariert sein, auch wenn er nur Sprachkeys hat. Das Addon kann Typen nicht aus den Sprachdateien ableiten.

> [!TIP]
> `compose()` läuft einmal pro Empfänger. Personenbezogene Daten sind dort richtig aufgehoben, teure Abfragen kosten aber entsprechend mehrfach.

> [!TIP]
> `actorName` und `actor` können `null` sein, etwa wenn der Scheduler oder die Abfrage der Leichten Sprache die Mail auslöst. Plane dafür einen Text ohne Namen ein.

> [!TIP]
> Der Betreff ist reiner Text. Nutze `boldTitle()` nur im Mail-Text, sonst erscheinen die Sternchen im Betreff.

## Fehlerbehebung

| Symptom | Ursache und Lösung |
| --- | --- |
| Der Typ erscheint nicht in der Auswahl. | Die `mail`-Klasse fehlt oder implementiert `WorkflowMail` nicht. Prüfe auch den Config-Cache. |
| Die Mail kommt mit den Standard-Texten an. | Der Sprachkey fehlt in der Sprache des Empfängers. Lege ihn für jede Sprache an. |
| Ein Satz erscheint in der falschen Sprache. | Der String wurde außerhalb von `compose()` übersetzt. |
| Im Text stehen spitze Klammern. | Ein Wert enthält HTML. Nutze Markdown. |
| Es kommt keine Mail an. | Bei `queue` läuft kein Worker, oder der Empfänger hat keine E-Mail-Adresse bzw. ist selbst der Auslöser. Prüfe das Log auf `Workflow notification failed.`. |

## Siehe auch

- [Eigene Action-Typen](actions.md)
- [DOCS.md](../../../DOCS.md), Abschnitte „Mail-Typen (`mail_type:`)“ und „Eigene Daten und eigenes Template“
- [config/workflows.php](../../../config/workflows.php) – Kommentarblöcke über `mail_types` und `mail_delivery`
- [src/Contracts/WorkflowMail.php](../../../src/Contracts/WorkflowMail.php)
- [src/Mail/MailContext.php](../../../src/Mail/MailContext.php)
- [src/Mail/MailTypes.php](../../../src/Mail/MailTypes.php)
- [resources/views/mail/notification.blade.php](../../../resources/views/mail/notification.blade.php) – das Standard-Template
- [lang/de/messages.php](../../../lang/de/messages.php) – Block `mail_assigned_*` mit den Textregeln

---

← [Eigene Action-Typen](actions.md) · [Übersicht](README.md) · [Zuweisungskriterien](assignment-criteria.md) →
