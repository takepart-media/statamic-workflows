# Benachrichtigungen

🇬🇧 [English](../en/notifications.md)

Das Addon schreibt den Verantwortlichen eines Eintrags eine Mail, wenn ein Schritt läuft, während ein Eintrag wartet, wenn jemand sie erwähnt und wenn ihr Account noch aktiviert werden muss.

## Überblick

| Mail | Auslöser | Empfänger | Schalter |
| --- | --- | --- | --- |
| Schritt-Mail | Eine Transition oder ein Place mit `mail`-Action | Zugewiesene, bei `published` die Beobachter | je Workflow |
| Place-Erinnerung | Täglicher Task, solange ein Eintrag in einem Place mit `reminder`-Action steht | Zugewiesene | `reminder_scheduler` |
| Kommentar-Erwähnung | Jemand erwähnt in einem Kommentar einen Nutzer mit `@` | Die Erwähnten | `comment_mention_mail` |
| Account-Erinnerung | Nach jeder Transition | Zugewiesene, die ihren Account nie aktiviert haben | `account_reminder.enabled` |

Jede Mail geht einzeln an jeden Empfänger, in dessen CP-Sprache. Niemand sieht, wer die Mail sonst noch bekommen hat. Wer einen Schritt auslöst, bekommt nie eine Mail über den eigenen Klick, und Nutzer ohne E-Mail-Adresse werden übersprungen.

Verschickt wird über deine Laravel-Mail-Konfiguration (`MAIL_MAILER`, `MAIL_FROM_ADDRESS`, …).

## Schritt-Mails

Eine Schritt-Mail ist die `mail`-Action. Du kannst sie an eine Transition oder an einen Place hängen:

- **An einer Transition:** Die Mail geht raus, nachdem die Transition gelaufen ist.
- **An einem Place:** Die Mail geht raus, sobald ein Eintrag in diesem Place ankommt, auch wenn niemand geklickt hat, etwa wenn eine Umschreibung in Einfache Sprache fertig ist oder der [Alterungs-Task](sla.md) einen Eintrag verschiebt.

```yaml
transitions:
  approve_expert:
    from: expert_review
    to: final_approve
    metadata:
      actions:
        - type: mail
          mail_type: assigned

places:
  plain_review:
    metadata:
      actions:
        - type: mail
          mail_type: plain_language_ready
```

Im [Workflow-Editor](editor.md) fügst du die Action „Mail verschicken“ hinzu und wählst den „Mail-Typ“.

Regeln:

- Eine Transition oder ein Place trägt höchstens eine `mail`-Action.
- Hat eine Transition eine eigene `mail`-Action, entfällt die Mail am Ziel-Place. Ein Klick verschickt nie zwei Mails.
- Ohne `mail_type` verschickt die Action `assigned`.
- „Status manuell setzen (Admin)“ verschickt keine Mail.
- In der Personenauswahl kann, wer die Transition ausführt, die Schritt-Mail mit „Benachrichtigung per E-Mail senden“ für diesen einen Lauf abschalten.

### Mail-Typen

| Handle | Bezeichnung im Editor | Empfänger | Inhalt |
| --- | --- | --- | --- |
| `assigned` | Beitrag zugewiesen | Zugewiesene | Ein Beitrag wartet auf Bearbeitung: er wurde im Workflow zur Bearbeitung vorgelegt. Der Standard-Typ. |
| `plain_language_ready` | Einfache Sprache ist fertig | Zugewiesene | Die Fassung in Einfacher Sprache ist fertig und kann geprüft werden. Gedacht für den Place, in dem fertige Umschreibungen landen. |
| `sla` | Aktualisierung fällig | Zugewiesene | Ein Beitrag ist fällig zur Aktualisierung: er wurde seit einem bestimmten Datum nicht aktualisiert, verbunden mit der Bitte um Prüfung. Gedacht für die Alterungs-Places der [Inhaltsalterung](sla.md). |
| `reminder` | Erinnerung: wartet noch | Zugewiesene | Ein Beitrag wartet noch auf Bearbeitung: er steht seit N Tagen im selben Status. Standard der `reminder`-Action. |
| `published` | Veröffentlicht (an Beobachter) | Beobachter | Ein Beitrag wurde veröffentlicht. Geht an alle, die den Eintrag beobachten, statt an die Zugewiesenen. |

Die Zugewiesenen werden nach dem Schritt gelesen. Eine Mail an einer Transition mit Zuweisung erreicht also die neuen Personen. Beobachter sind alle, die beim Eintrag auf „Beobachten“ geklickt haben, dazu alle, die ihm je zugewiesen wurden und das Beobachten nicht beendet haben.

Eigene Typen ergänzt du wie unter [Eigene Mail-Typen](extending/mail-types.md) beschrieben.

### Was eine Mail enthält

Jede Schritt-Mail hat dasselbe Layout:

- eine Überschrift und eine Einleitung mit Eintrag und Workflow,
- „Status“: der Status, in dem der Eintrag jetzt steht,
- „Ausgelöst von“: wer den Schritt ausgeführt hat. Entfällt, wenn niemand geklickt hat, etwa bei geplanten Verschiebungen,
- „Erinnerung“: das Erinnerungsintervall, wenn der aktuelle Place eine `reminder`-Action hat,
- einen Hinweis „Automatisch … am …“ mit der Frist, wenn der aktuelle Place eine automatische Transition hat. Er ersetzt die Zeile „Erinnerung“,
- „Eingegangen am“: wann der Eintrag in seinem aktuellen Status angekommen ist,
- einen Button zur Workflow-Übersicht des Eintrags,
- eine Fußzeile „Schritt N von M“.

## Place-Erinnerungen

Die Place-Action `reminder` schreibt den Zugewiesenen immer wieder, solange ein Eintrag in einem Place wartet.

```yaml
places:
  in_review:
    metadata:
      actions:
        - type: reminder
          every_days: 3
```

| Schlüssel | Typ | Standard | Beschreibung |
| --- | --- | --- | --- |
| `every_days` | `int` | – | Intervall in Tagen, mindestens `1`. |
| `mail_type` | `string` | `reminder` | Welcher Mail-Typ verschickt wird. |

Im Editor ist das die Place-Action „Erinnerung alle X Tage (Mail)“.

So verhält sie sich:

1. Die erste Erinnerung geht `every_days` nach der Ankunft im Place raus.
2. Jede weitere Erinnerung geht `every_days` nach der vorherigen raus.
3. Die Erinnerungen enden, sobald der Eintrag den Place verlässt. Eine Obergrenze gibt es nicht.
4. Jede Erinnerung steht im Verlauf als „Erinnerung verschickt“.
5. Ein Eintrag ohne Zugewiesene bekommt weder eine Erinnerung noch einen Verlaufseintrag.

Verschickt werden die Erinnerungen von `workflows:send-reminders`, das das Addon einmal täglich beim Laravel-Scheduler anmeldet:

| Schlüssel | Env | Standard | Beschreibung |
| --- | --- | --- | --- |
| `reminder_scheduler` | `WORKFLOWS_REMINDER_SCHEDULER` | `true` | Meldet den täglichen Task an. `false` schaltet Erinnerungen global ab. |
| `reminder_time` | `WORKFLOWS_REMINDER_TIME` | `08:00` | Uhrzeit, zu der der Task läuft. |

Derselbe Schalter und dieselbe Uhrzeit steuern auch `workflows:auto-transition`, das direkt vor den Erinnerungen läuft.

Du kannst den Task von Hand ausführen:

```bash
# Fällige Erinnerungen auflisten, ohne zu verschicken
php artisan workflows:send-reminders --dry-run

# Nur ein Workflow
php artisan workflows:send-reminders --workflow=postmanager
```

> [!IMPORTANT]
> Der Scheduler läuft nur, wenn `php artisan schedule:run` in deinem Projekt per Cron läuft. Siehe [Befehle & Scheduler](commands.md).

## Kommentar-Erwähnungen

Erwähnt jemand einen Nutzer in einem Kommentar mit `@`, bekommt dieser die Mail „Neue Erwähnung in einem Kommentar“. Sie nennt, wer erwähnt hat, den Eintrag, den „Abschnitt“ (die kommentierte Passage, falls vorhanden) und den „Bereich“ (den Workflow). Der Button „Kommentar öffnen“ öffnet die Workflow-Übersicht mit dem Kommentar.

- Bei einem neuen Kommentar oder einer Antwort bekommen alle Erwähnten die Mail.
- Beim Bearbeiten bekommen sie nur Nutzer, die durch die Bearbeitung neu erwähnt werden.
- Der Autor des Kommentars bekommt sie nie.

| Schlüssel | Env | Standard | Beschreibung |
| --- | --- | --- | --- |
| `comment_mention_mail` | `WORKFLOWS_COMMENT_MENTION_MAIL` | `true` | `false` schaltet die Erwähnungs-Mails ab. Erwähnungen funktionieren im CP weiter. |

## Account-Erinnerung

Oft werden Personen zugewiesen, bevor sie sich je angemeldet haben, etwa direkt nach einer Statamic-CP-Einladung. Nach jeder Transition prüft das Addon die Zugewiesenen des Eintrags. Wer seinen Account nie aktiviert hat (noch kein Passwort gesetzt), bekommt die Mail „Account aktivieren: … wartet“ mit einem neuen Aktivierungslink. Die Mail nennt den Eintrag, den Workflow und wer ihn übergeben hat, und sagt, wie lange der Link gültig ist.

Die Erinnerung pausiert, solange der letzte Aktivierungslink der Person noch gültig ist. Dazu zählt auch der Link aus Statamics CP-Einladung. Eine neue Mail geht erst raus, wenn der vorherige Link abgelaufen ist, egal wie viele Schritte dazwischen laufen. Aktivierte Nutzer bekommen sie nie.

Sie läuft nach jeder Transition, auch nach Transitions, die der Scheduler ausführt. Der Schalter „Benachrichtigung per E-Mail senden“ betrifft sie nicht: er schaltet nur die Schritt-Mail ab.

| Schlüssel | Env | Standard | Beschreibung |
| --- | --- | --- | --- |
| `account_reminder.enabled` | `WORKFLOWS_ACCOUNT_REMINDER` | `true` | `false` schaltet die Account-Erinnerung ab. |
| `account_reminder.expire_hours` | `WORKFLOWS_ACTIVATION_EXPIRE_HOURS` | `null` | Wie lange ein Aktivierungslink gültig bleibt, in Stunden. `null` übernimmt die Dauer von Statamics Aktivierungs-Password-Broker (`statamic.users.passwords.activations`). |

> [!TIP]
> Der letzte Satz der Mail (`mail_account_reminder_outro`) sagt, was nach Ablauf des Links zu tun ist. Standardmäßig verweist er an die Redaktion. Überschreibe ihn in `lang/vendor/workflows/<locale>/messages.php`, um deinen eigenen Kontakt zu nennen, zum Beispiel eine Support-Adresse. Behalte `:duration` im Text.

> [!WARNING]
> `expire_hours` ändert den Aktivierungslink auch für Statamics CP-Einladungen. Beide nutzen denselben Link; die Dauer lässt sich nicht nur für die Erinnerung ändern.

## Zustellung

Mails, die ein Klick auslöst (Schritt-Mails, Erwähnungs-Mails, Account-Erinnerungen), lassen die Redaktion nicht auf den Mailserver warten. `mail_delivery` legt fest, wie sie verschickt werden:

| Wert | Verhalten |
| --- | --- |
| `after_response` | Standard. Im selben PHP-Prozess verschickt, direkt nachdem die Antwort an den Browser gegangen ist. Kein Queue-Worker nötig. |
| `queue` | Landet in deiner `QUEUE_CONNECTION`, mit Wiederholungen. Braucht ein laufendes `php artisan queue:work`. |
| `sync` | Direkt im Request verschickt. |

```env
WORKFLOWS_MAIL_DELIVERY=queue
```

Place-Erinnerungen aus `workflows:send-reminders` werden immer direkt verschickt.

Scheitert die Mail an einen Empfänger, wird der Fehler protokolliert und alle anderen bekommen ihre Mail trotzdem. Eine gescheiterte Mail macht die Transition nie rückgängig.

## Texte anpassen

Alle Mail-Texte liegen im Übersetzungs-Namespace `workflows::messages` des Addons. Um sie zu ändern, veröffentlichst du die Sprachdateien:

```bash
php artisan vendor:publish --tag=workflows-translations
```

Danach bearbeitest du `lang/vendor/workflows/<locale>/messages.php`. Mail-Keys folgen dem Muster `mail_<typ>_<key>`, zum Beispiel:

```php
// lang/vendor/workflows/de/messages.php
return [
    'mail_assigned_subject' => 'Jetzt dran: :title',
    'mail_reminder_intro' => '„:title“ wartet seit :days Tagen in „:workflow“.',
];
```

| Keys | Mail |
| --- | --- |
| `mail_assigned_*` | `assigned` und der Rückfall für jeden anderen Typ |
| `mail_plain_language_ready_*`, `mail_sla_*`, `mail_reminder_*`, `mail_published_*` | die übrigen eingebauten Typen |
| `mail_reminder_notice` | die Zeile „Erinnerung“ |
| `mail_auto_transition_*` | der Hinweis zur automatischen Transition |
| `mail_comment_mention_*` | Kommentar-Erwähnungen |
| `mail_account_reminder_*`, `duration_days`, `duration_hours` | Account-Erinnerung |

Einen Key, den ein Typ nicht definiert, nimmt er von `assigned`, und zwar je Sprache. Übersetzt du einen Key nur auf Deutsch, bekommen englischsprachige Empfänger dafür den `assigned`-Text.

Die mitgelieferten Texte sprechen niemanden mit Namen an, verzichten auf „du“ und „Sie“ und auf Fachwörter wie „Transition“ oder „Place“, weil eine Mail jede Rolle erreichen kann. Behalte das bei eigenen Texten im Blick.

Alle Keys und ihre Platzhalter stehen unter [Eigene Mail-Typen](extending/mail-types.md#sprachkeys).

## Nächste Schritte

- [Eigene Mail-Typen](extending/mail-types.md): eigene Mails mit eigenen Daten und eigenem Template.
- [Inhaltsalterung (SLA)](sla.md): die `sla`-Mail und die Alterungs-Places.
- [Befehle & Scheduler](commands.md): Cron-Einrichtung und alle geplanten Tasks.
- [Übersetzung & Einfache Sprache](localization.md): der Place, in dem fertige Umschreibungen landen.

---

← [Mit Workflows arbeiten](using.md) · [Doku-Start](README.md) · [Übersetzung & Einfache Sprache](localization.md) →
