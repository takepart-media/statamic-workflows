# Changelog

Alle nennenswerten Änderungen am Addon. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [Unreleased]

### Hinzugefügt

- **Einstellung „Revisionen“** im Workflow-Editor (Collections): schaltet
  Revisionen für alle Collections des Workflows ein oder aus und schreibt das
  beim Speichern in die Collections. Mit dem Hinweis, dass Statamic Pro nötig
  ist, und einer Warnung, wenn Pro fehlt oder Revisionen global aus sind.
- **Einstellung „Revisionen veröffentlichen dürfen“** (Zeilen aus Art
  und Einträgen, z. B. Gruppe X und Benutzer Y, Z): Solange eine Arbeitskopie existiert, dürfen nur Super-Admins und
  diese Auswahl sie live stellen.
- **Einstellung „QS-Hinweise anzeigen“** im Einfache-Sprache-Workflow
  (`metadata.qs_notes_visible`, `metadata.qs_notes_audience`): blendet den
  Prüfbericht für alle aus oder zeigt ihn nur Super-Admins und einer Auswahl aus
  Gruppen, Rollen oder Benutzern.
- **Einstellung „Nur Zuständige dürfen bearbeiten“** (`metadata.assignees_only_edit`,
  Einstellungen › Berechtigungen): Beiträge der Collections des Workflows
  bearbeiten nur die aktuell Zuständigen, alle anderen sehen sie
  schreibgeschützt. Super-Admins und eine einstellbare Ausnahme (`edit_exempt`,
  Gruppen, Rollen oder Benutzer) dürfen immer.
- **Einstellungen „Kommentare als erledigt markieren dürfen“ und „Kommentare
  löschen dürfen“** (`metadata.comment_resolvers`, `metadata.comment_deleters`,
  Einstellungen › Berechtigungen; Zeilen aus Art und Einträgen). Super-Admins
  dürfen immer, eigene Kommentare darf jeder löschen. Ohne Einstellung gilt die
  bisherige Regel.
- **4-Augen-Prinzip** (`metadata.four_eyes`, Einstellungen › Berechtigungen):
  Ein Beitrag geht bei der nächsten Transition nie an die Person zurück, die ihn
  zuletzt weitergegeben hat, weder manuell noch automatisch. Bleibt sonst
  niemand, öffnet sich die Auswahl mit einer eigenen Zielgruppe (Gruppen, Rollen
  oder Personen) und einem Hinweis.
- **Mail „Veröffentlicht“** (`mail_type: published`) für die `mail`-Action: geht
  an alle, die den Beitrag beobachten, nicht an die Zugewiesenen. Mail-Typen
  können dafür `recipients: watchers` deklarieren.
- **Kommentaranzahl in den Dashboard-Widgets** „Meine Warteschlange“, „Meine
  Beiträge“ und „Beobachtet“: offene Kommentare grau, ungelesene als `+N` in
  Amber — wie in den Listen.
- **Filter „Zugewiesen an“ und „Autor“** in der Workflow-Übersicht und auf der
  Manager-Seite: zeigt nur Beiträge, bei denen die Person in irgendeiner
  Sprachfassung zugewiesen bzw. Autor ist.
- **Filter „Wartet länger als“** (3 / 7 / 14 / 30 Tage) in der Workflow-Übersicht
  und auf der Manager-Seite: Beiträge, von denen eine Sprachfassung länger als
  das im aktuellen Status steht. Status ohne ausgehende Transition (z. B.
  „Veröffentlicht“) zählen nicht als Warten.
- **Graph-Editor** für Workflows unter Settings › Workflows › `<Workflow>`:
  Places und Transitions als Knoten, Bearbeiten in Seitenpanels, Undo/Redo,
  Tastaturkürzel, Live-Validierung, versioniertes Speichern mit Backups,
  verzögertes Löschen von Places mit Migration. Ersetzt den früheren
  Formular-Editor. „Workflow anlegen" (leer, aus Vorlage, als Kopie) im CP und
  per `workflows:make`. (DOCS.md § 4, § 5.9, § 11)
- **Inhaltsfelder pro Workflow** (`metadata.content_fields`): geordnete Liste der
  Top-Level-Felder, die Zusammenfassung, Kommentare und Einfache Sprache lesen.
  Ohne Angabe Auto-Erkennung (`content_fields.intro`/`body` aus der Config, sonst
  erstes Bard-/Markdown-/Replicator-Feld). Validierung im Writer
  (`writer_content_field*`), Linter-Warnungen (`lint_content_field_partial`,
  `lint_content_field_auto_none`). Auswahl im Editor (Einstellungen › „Inhalt")
  und im Anlegen-Modal. (DOCS.md § 2)
- **Generische Inhalts-Zusammenfassung** (`ContentWalker`): liest jede Struktur
  der Inhaltsfelder (Replicator, Grid, Group, Bard-Sets, beliebig verschachtelt)
  ohne projektspezifische Set-Zuordnung. Darstellung als Lesefluss; für
  Super-Admins Handle-/UUID-Chips hinter „Technische Details". (DOCS.md § 7)
- **Einfache Sprache pro Textblatt**: jedes Stück Prosa der Inhaltsfelder geht
  einzeln markiert an den Dienst (`<section data-wf-leaf>`) und wird strikt
  zurückgeschrieben — fehlende oder fremde Abschnitte verwerfen die Antwort,
  der Retry reicht neu ein. Neue Option `metadata.plain_language_skip_fields`.
  Diagnose per `workflows:plain-language-poll --dry-run` / `--diff`.
  (DOCS.md § 2 → „Rückschreiben pro Textblatt")
- **Mail-Hinweis im Assignee-Picker**: Bei manueller Zuweisung steht neben jeder
  Person „Erhält E-Mail", wenn der Schritt ihr eine Benachrichtigung schickt.
  Entschieden von denselben Regeln wie der Versand
  (`WorkflowNotifier::stepMail()` / `reaches()`). (DOCS.md § „Hinweis im
  Assignee-Picker")
- **Mail-Schalter im Assignee-Picker**: „Benachrichtigung per E-Mail senden",
  standardmäßig an. Aus = der Schritt übergibt ohne Mail (`notify: false` →
  `WorkflowTransitioner::apply(notify: false)`). (DOCS.md § „Mail-Schalter im
  Assignee-Picker")
- **Selbstzuweisung verhindern** (`metadata.self_assignment`): Im manuellen
  Picker fehlt die klickende Person. Würde eine Zuweisung den Beitrag nur an
  sie zurückgeben, öffnet sich stattdessen der Picker mit einer einstellbaren Zielgruppe
  (Gruppe, Rolle oder Person, ohne den Klickenden). Darüber steht ein
  anpassbarer Hinweistext. Einstellbar im Graph-Editor unter Einstellungen ›
  Berechtigungen. (DOCS.md § „Selbstzuweisung verhindern")
- **Zuweisen heißt beobachten**: Wer einem Beitrag neu zugewiesen wird,
  beobachtet ihn automatisch (`WorkflowAssigneeStore::set()`). Bewusstes
  Entbeobachten bleibt bestehen. Nicht-mehr-Beobachten zeigt jetzt ein
  durchgestrichenes Lesezeichen. (DOCS.md § `Watched`)
- **PHP-Testsuite** (Pest + Orchestra Testbench, in-memory DB, temporärer
  Stache): `vendor/bin/pest` bzw. `composer test`. (DOCS.md § 11 → Tests)

### Geändert

- **Der QS-Bericht des Einfache-Sprache-Dienstes steht nicht mehr im
  Kommentar-Thread**, nur noch hinter dem Button „QS-Hinweise“. Gespeichert wird
  er weiterhin als Kommentar.
- **Kommentare wie in Word:** Text markieren → Kommentar-Icon erscheint, der
  Kommentar hängt an genau dieser Stelle und sie wird im Text hervorgehoben.
  Kommentare stehen in einer Randspalte neben dem Inhalt, auf Höhe ihrer Stelle,
  statt in einem Drawer. Antworten,
  Abschließen, Erwähnungen und Mails bleiben wie bisher.
- Ist die Kommentarspalte ausgeblendet, öffnet der Kommentar-Button einer
  Passage (oder ein Klick auf markierten Text) deren Kommentare als **Popover**;
  „Kommentare einblenden“ zeigt weiterhin alle in der Spalte.
- Übersetzung / Einfache Sprache: Steht die Quelle daneben, gibt es keine
  Kommentarspalte mehr – Kommentare (auch zu markiertem Text) öffnen dort immer
  im Popover. Ist die Quelle per Einstellung verborgen, verhält sich die Seite
  wie bei Beiträgen.
- Sets werden beim Überfahren hervorgehoben, der Kommentar-Button erscheint nur
  dann; ein Set mit Kommentar zum ganzen Set ist leicht gelb hinterlegt.
- Übersicht: Das Datum hinter dem Status-Badge heißt jetzt „seit …“
  (englisch „since …“).
- Einfache Sprache schickt nur noch die **Inhaltsfelder und den Titel**; andere
  Felder gehen nicht mehr mit.
- Comment-Hashes werden über das neue Block-Format gebildet.
- Laufende Einfache-Sprache-Jobs von vor dem Update (`format = null`) schreibt
  weiter das alte Verfahren zurück (`LegacyPlainLanguageDocument`).
- „Von mir erstellt“ heißt jetzt **„Meine Beiträge“** und zeigt die Beiträge,
  bei denen der aktuelle Benutzer als **Autor** (`author`-Feld) eingetragen ist,
  statt der Beiträge, die er angelegt hat (Dashboard-Kachel und Manager-Seite).
- „Meine Warteschlange“ heißt jetzt **„Mir zugewiesen“** (auch die Kennzahl-Karte).

### Deprecated

- `config/workflows.php → content_fields`: `sets`, `sources_title`,
  `sources_text`, `podcast_link`, `podcast_code`. Werden noch geladen, aber nicht
  mehr gelesen — die Felder gehören stattdessen in `metadata.content_fields`.
  Weiterhin gültig: `title`, `intro`, `body`, `seo`, `entity`, `scope`.

### Entfernt

- Installations-UI im CP. Neue Workflows entstehen über das Anlegen-Modal;
  `php artisan workflows:install` bleibt für die Erstinstallation.
- Der alte Formular-Editor (`pages/WorkflowSettings.vue`) und seine Endpoints.

### Upgrade-Hinweise

1. Im Host-Projekt `composer update takepart-media/workflows` — neue
   Abhängigkeit `league/html-to-markdown`.
2. Migrationen des Addons ausführen (`php artisan migrate`): neue Spalten
   `format` und `plan` in `workflow_plain_language_jobs`.
3. Bestehende Kommentare zeigen **einmalig** den Text-geändert-Hinweis, weil ihre
   gespeicherten Hashes noch das alte Block-Format haben. Kein Fehler, nichts zu
   tun.
4. Einfache Sprache sendet nur noch Inhaltsfelder + Titel. Projekte, die bisher
   weitere Felder umschreiben ließen (z. B. forus), prüfen
   `metadata.content_fields` ihres Einfache-Sprache-Workflows — ohne Angabe gilt
   die Auto-Erkennung.
5. Wer die deprecated Config-Keys gesetzt hat, überträgt die betroffenen Felder
   nach `metadata.content_fields`.

### Behoben

- Ob jemand Kommentare moderieren darf, wurde im Controller ohne Benutzer
  zwischengespeichert und konnte so für den nächsten Benutzer derselben
  Controller-Instanz gelten.
- **Revisionen bei verwalteten Collections:** Das Addon hat sie nie wirklich
  eingeschaltet. Das Setzen zur Laufzeit ging verloren, und der Editor bot
  weiter „Speichern & Veröffentlichen“. Der wirkungslose Code ist entfernt. Der
  Workflow-Editor warnt jetzt, wenn eine Collection eines veröffentlichenden
  Workflows keine Revisionen hat; einzuschalten mit der neuen Einstellung
  „Revisionen“.

