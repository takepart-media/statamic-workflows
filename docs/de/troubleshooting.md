# Fehlerbehebung

🇬🇧 [English](../en/troubleshooting.md)

Häufige Probleme mit dem Workflows-Addon, ihre Ursachen und wie du sie behebst.

Die meisten Befehle, die Daten ändern, haben eine Option `--dry-run`, und gescheiterte Mails, Aktionen und geplante Schritte landen in deinem Laravel-Log (`storage/logs`). Prüfe zuerst beides.

## Installation und Assets

### Composer findet `takepart-media/statamic-workflows` nicht

**Ursache:** Packagist ist von deinem Projekt aus nicht erreichbar, oder `packagist.org` ist in deiner `composer.json` abgeschaltet.

**Lösung:** Prüfe `composer config repositories` und stell sicher, dass `packagist.org` nicht auf `false` steht. Prüfe, ob die Paketseite auf [Packagist](https://packagist.org/packages/takepart-media/statamic-workflows) existiert.

### Das CP zeigt alte oder keine Workflow-Assets

Die CP-Seite bleibt leer, die Browser-Konsole zeigt JavaScript-Fehler, oder eine Korrektur aus einem Update kommt nicht an.

**Ursache:** Die veröffentlichten Assets in `public/vendor/statamic-workflows/build` fehlen oder stammen aus einer älteren Version. Ohne `--force` ersetzt das Veröffentlichen vorhandene Dateien nicht, das CP lädt also weiter das alte Bundle.

**Lösung:**

```bash
php artisan vendor:publish --tag=workflows --force
```

Lade den Browser danach ohne Cache neu (Cmd+Shift+R bzw. Strg+Shift+R). Nimm den Befehl in dein Deploy-Skript auf.

### Datenbankfehler wie „no such table: workflow_states“

**Ursache:** Die Migrationen des Addons sind auf der Verbindung `workflow` nicht gelaufen, oder der Webserver kann nicht nach `storage/app/workflows/` schreiben.

**Lösung:** Führe die Migrationen aus und prüfe, ob der PHP-Nutzer in den Ordner schreiben darf:

```bash
php artisan migrate --database=workflow --path=vendor/takepart-media/statamic-workflows/database/migrations
```

### Der Installer hat die Collections meines Workflows geändert

**Ursache:** `workflows:install` ersetzt die `collections` eines Workflows durch deine Auswahl, auch wenn der Workflow schon existiert.

**Lösung:** Setz die Collections im Workflow-Editor neu, oder stell die vorherige Datei aus `storage/app/workflows/backups/<handle>/` wieder her.

## Workflows im CP finden

### Ein Workflow taucht im CP nicht auf

**Ursache:** Die Workflow-Manager haben keinen Navigationspunkt. Du erreichst sie über das Widget „Alle Beiträge“ auf dem Dashboard. `/cp/workflows` leitet aufs Dashboard um.

**Lösung:** Prüfe diese Punkte der Reihe nach:

1. Das Dashboard zeigt das Widget `workflows_overview`. Listet dein Projekt in `config/statamic/cp.php` eigene Widgets, trag es dort ein, siehe [Installation](installation.md#dashboard-einrichten).
2. Die YAML liegt unter `resources/workflows/<handle>.yaml`. Die Beispiele im Addon werden nicht geladen.
3. Der Workflow ist aktiv: kein `active: false` in der YAML, und unter **Einstellungen > Workflows** eingeschaltet.
4. Der Workflow hat mindestens eine Collection. Ein Workflow ohne Collections hat keine Einträge, sein Manager bleibt leer.

### Eine Manager-Seite liefert 404

**Ursache:** Der Workflow hat kein `metadata.nav.slug`, oder er ist ausgeschaltet.

**Lösung:** Setz im Workflow-Editor einen Slug und aktiviere den Workflow. Der Manager liegt dann unter `/cp/workflows/<slug>`.

### „Einstellungen > Workflows“ fehlt

**Ursache:** Nur Super-Admins sehen die Workflow-Einstellungen und den Editor.

**Lösung:** Melde dich mit einem Nutzer an, der `super: true` hat.

### Der Eintrags-Editor zeigt kein Workflow-Panel

**Ursache:** Die Collection des Eintrags steht in keinem aktiven Workflow unter `collections`.

**Lösung:** Füge die Collection im Editor zum Workflow hinzu. Erscheint das Panel trotzdem nicht, leere den Cache mit `php artisan cache:clear`.

### Alle bestehenden Einträge liegen im Start-Place

**Ursache:** Die Status wurden für einen Workflow nachgetragen, der weder einen veröffentlichenden Place (`allow_publish`) noch `metadata.seed_published_place` hat. Veröffentlichte Einträge haben dann keinen „fertigen“ Place, in den sie gehören.

**Lösung:** Setz `metadata.seed_published_place` auf den Place, in den fertige Einträge gehören, und leite die Status neu ab. Einträge mit Verlauf bleiben unberührt:

```bash
php artisan workflows:seed-states --workflow=<handle> --redo
```

## Berechtigungen

### Ein Transition-Button fehlt, oder niemand lässt sich zuweisen

**Ursache:** Die Transition ist mit `required_group` oder `required_role` abgesichert, und der Nutzer ist kein Mitglied. Ein Gruppen-Handle, den es in `resources/users/groups.yaml` nicht gibt, löst keinen Fehler aus, er passt einfach auf niemanden. Dasselbe gilt für die Zuweisungsauswahl und `default_assignee_group`.

**Lösung:** Lege die Gruppe an oder korrigiere den Handle im Workflow-Editor, und füge die Nutzer hinzu. Super-Admins und Mitglieder von `privileged_groups` umgehen die Sperren. Siehe [Berechtigungen](permissions.md).

### Statamics Button „Veröffentlichen“ fehlt

**Ursache:** Normale Nutzer können nicht direkt aus dem Eintrags-Editor veröffentlichen. Veröffentlicht wird über eine Transition in einen Place mit `allow_publish: true`. Das gilt für jeden Eintrag, auch in Collections, die kein Workflow steuert. Solange ein veröffentlichter Eintrag eine Arbeitskopie hat, entscheidet stattdessen die Workflow-Einstellung, wer Revisionen veröffentlichen darf.

**Lösung:** Schick den Eintrag durch den Workflow. Soll eine Gruppe auch außerhalb des Workflows veröffentlichen dürfen, trag sie in `privileged_groups` ein. Super-Admins sehen den Button immer.

### Eine Übersetzung lässt sich nicht veröffentlichen

**Ursache:** Eine übersetzte Fassung wird vom Übersetzungs-Workflow geführt, nicht vom Beitragsmanager. Das mitgelieferte Beispiel `translation` endet in `approved`, und das ist kein veröffentlichender Place.

**Lösung:** Ergänze den letzten Place des Übersetzungs-Workflows um `allow_publish: true`, dann veröffentlicht die finale Freigabe die Übersetzung. Oder überlass das Veröffentlichen bewusst Super-Admins und `privileged_groups`.

### Ein Eintrag öffnet sich nur lesend

**Ursache:** Im Workflow ist „Nur Zuständige dürfen bearbeiten“ eingeschaltet, und der Nutzer ist dem Eintrag nicht zugewiesen.

**Lösung:** Weise den Nutzer zu oder nimm ihn in die Ausnahmen der Einstellung auf. Super-Admins dürfen immer bearbeiten.

### „Verlauf anzeigen“ fehlt

**Ursache:** Der Nutzer ist nicht in `history_groups` (Standard `['admins']`). `privileged_groups` deckt den Verlauf nicht ab.

**Lösung:** Trag die Gruppe in `config/workflows.php` unter `history_groups` ein.

### Ein Nutzer kann fremde Kommentare nicht löschen oder erledigen

**Ursache:** Nutzer können nur ihre eigenen Kommentare löschen. Jeden Kommentar löschen darf, wer in der Workflow-Einstellung „Kommentare löschen dürfen“ steht oder, wenn der Workflow sie nicht setzt, in `comment_moderator_groups` (Standard `['admins']`). Diskussionen erledigen dürfen ihr Autor, die aktuell Zuständigen des Eintrags und Moderatoren.

**Lösung:** Nimm den Nutzer oder die Gruppe im Workflow-Editor unter „Kommentare löschen dürfen“ auf, oder trag die Gruppe in `comment_moderator_groups` ein.

### „Neuer Beitrag“ oder die Zuständigen-Auswahl fehlt

**Ursache:** Beides ist standardmäßig leer. Nur Super-Admins dürfen dann Einträge aus einem Manager anlegen oder Verantwortliche ändern.

**Lösung:** Setz „Darf Beiträge anlegen“ und „Darf Verantwortliche zuweisen“ pro Workflow im Editor, oder projektweit `post_creator_groups` und `assigner_groups`.

## Mails

### Workflow-Mails kommen nicht an

**Ursache und Lösung:** Geh diese Punkte durch:

1. **Keine Mail-Aktion.** Die Transition oder der Place, in den sie führt, braucht eine `mail`-Aktion. Siehe [Benachrichtigungen](notifications.md).
2. **Mail für diesen Schritt abgeschaltet.** Die Zuständigen-Auswahl hat einen Schalter „Benachrichtigung per E-Mail senden“.
3. **Empfänger.** Niemand bekommt eine Mail über den eigenen Klick, und Nutzer ohne E-Mail-Adresse bekommen nichts.
4. **Queue ohne Worker.** Steht `mail_delivery` auf `queue`, warten die Mails, bis `php artisan queue:work` läuft. Starte einen Worker oder wechsle zu `after_response`.
5. **Mailer.** Prüfe `MAIL_MAILER` und den Absender in der `.env`. Mit `log` landen Mails nur im Log.
6. **Fehler.** Such im Log nach „Workflow notification failed.“.

### Die Erinnerung zur Kontoaktivierung kommt nicht

**Ursache:** Die Erinnerung geht nur raus, wenn alles davon zutrifft:

- `account_reminder.enabled` ist `true`.
- Eine **Transition** ist gelaufen. Ein von Hand gesetzter Status oder ein direktes Verschieben durch die Inhaltsalterung löst sie nicht aus.
- Der Nutzer ist dem Eintrag zugewiesen, hat eine E-Mail-Adresse und hat die Transition nicht selbst ausgelöst.
- Das Konto ist noch nicht aktiviert (kein Passwort gesetzt).
- Der letzte Aktivierungslink des Nutzers ist abgelaufen. Solange ein Link noch gilt, auch der aus Statamics CP-Einladung, wird nichts verschickt und dieser Link funktioniert weiter.

**Lösung:** Warte, bis der Link abläuft, oder verkürze die Gültigkeit mit `account_reminder.expire_hours`. Der Versand folgt `mail_delivery`, der Punkt zum Queue-Worker oben gilt also auch hier.

### Erinnerungen aus einem Place kommen nicht

**Ursache:** Der Scheduler läuft nicht, `reminder_scheduler` steht auf `false`, der Eintrag hat keine Zuständigen, oder die Erinnerung ist noch nicht fällig. Die erste Erinnerung ist `every_days` nach der Ankunft im Place fällig, jede weitere `every_days` nach der vorigen.

**Lösung:** Richte den [Scheduler](commands.md#scheduler) ein und prüfe, was fällig ist:

```bash
php artisan workflows:send-reminders --dry-run
```

## Scheduler und Alterung

### Veröffentlichte Einträge altern nicht

**Ursache:** Eins davon:

- Der Scheduler läuft nicht, oder `sla_scheduler` steht auf `false`.
- Der Workflow hat kein `metadata.sla`.
- Es ist ein Übersetzungs-Workflow. Die Alterung gehört nur zum redaktionellen Workflow.
- Der Inhalt des Eintrags ist jünger als die Schwelle. Das Alter zählt ab `content_updated_at`.

**Lösung:** Prüfe, was passieren würde:

```bash
php artisan workflows:transition-aged --dry-run
```

Siehe [Inhaltsalterung (SLA)](sla.md).

### Eine automatische Transition hat einen Eintrag falsch verschoben

**Lösung:** Nimm sie zurück:

```bash
php artisan workflows:undo-auto-transition <entry-id> --dry-run
php artisan workflows:undo-auto-transition <entry-id>
```

Zuständige werden nicht wiederhergestellt. Setz sie im Eintrag neu.

### Eine Umschreibung in Einfache Sprache kommt nie an

**Ursache:** Umschreibungen holt `workflows:plain-language-poll` ab. Ohne Scheduler oder mit `poll_scheduler` auf `false` werden sie nie zurückgeschrieben. Der Befehl tut außerdem nichts, solange `PLAIN_LANGUAGE_URL` oder `PLAIN_LANGUAGE_TOKEN` fehlt.

**Lösung:** Richte den Scheduler ein und prüfe die Konfiguration des Dienstes. Führe die Abfrage von Hand aus, um zu sehen, was der Dienst antwortet:

```bash
php artisan workflows:plain-language-poll --dry-run
```

Um erfolgreiche Aufrufe vollständig zu loggen, setz `PLAIN_LANGUAGE_LOG_CALLS=true`. Fehler werden immer geloggt. Siehe [Übersetzung & Einfache Sprache](localization.md).

## Revisionen

### „Unveröffentlichte Änderungen“ greift nie

Änderungen an einem veröffentlichten Eintrag gehen sofort live, statt auf die Prüfung zu warten.

**Ursache:** Statamic-Revisionen sind nicht aktiv. Der Workflow-Editor zeigt eine dieser Warnungen: „Collection [...] hat keine Revisionen“ oder „Revisionen sind eingeschaltet, Statamic ignoriert sie aber“.

**Lösung:** Installiere Statamic Pro, setz `STATAMIC_REVISIONS_ENABLED=true` und aktiviere Revisionen für die Collection. Siehe [Installation](installation.md#revisionen-optional).

## Workflow-Editor

### Ein Workflow lässt sich wegen einer unbekannten Aktion, eines Mail-Typs oder Kriteriums nicht speichern

**Ursache:** Eine eigene Deklaration in `config/workflows.php` ließ sich nicht lesen und wurde verworfen. Typische Gründe: eine Handler- oder Mail-Klasse, die es nicht gibt oder die den Contract nicht implementiert, oder ein Zuweisungskriterium, das auf ein nicht vorhandenes Blueprint-Feld zeigt.

**Lösung:** Korrigiere die Deklaration, leg zum Beispiel zuerst das Blueprint-Feld an. Siehe [Erweitern](extending/README.md).

### Meine Kommentare in der YAML sind weg

**Ursache:** Der Editor schreibt die YAML neu und verwirft dabei Kommentare.

**Lösung:** Die vorherige Fassung liegt in `storage/app/workflows/backups/<handle>/`. Halte Erklärungen lieber in Labels und Beschreibungen fest als in YAML-Kommentaren.

## Gut zu wissen

- **Verlauf ohne Änderungen:** Verlaufseinträge aus der Zeit, bevor es Feld-Snapshots gab, zeigen „Keine Diff-Daten verfügbar (Snapshot fehlt).“ Das ist so gewollt.
- **Zuständige pro Sprachfassung:** Zuständige gehören zu einer Fassung eines Eintrags, nicht zum Beitrag als Ganzes. Die deutsche und die englische Fassung können verschiedene Zuständige haben.
- **Status-Filter auf Manager-Seiten:** Der Filter nach Status zeigt einen Beitrag, sobald **irgendeine** seiner Site-Fassungen in diesem Status ist.
- **Gelöschte Einträge:** Ihre Workflow-Daten werden automatisch entfernt. Für Reste aus von Hand bearbeiteten YAMLs gibt es [`workflows:prune-states`](commands.md#workflowsprune-states).

## Nächste Schritte

- [Befehle & Scheduler](commands.md): alle Befehle und ihre Optionen.
- [Konfiguration](configuration.md): alle Einstellungen an einem Ort.
- [Berechtigungen](permissions.md): wer was darf.

---

← [Befehle & Scheduler](commands.md) · [Doku-Start](README.md) · [Erweitern](extending/README.md) →
