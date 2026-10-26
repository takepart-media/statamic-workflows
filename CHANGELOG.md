# Changelog

Alle nennenswerten Änderungen am Addon. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [Unreleased]

### Hinzugefügt

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
- **PHP-Testsuite** (Pest + Orchestra Testbench, in-memory DB, temporärer
  Stache): `vendor/bin/pest` bzw. `composer test`. (DOCS.md § 11 → Tests)

### Geändert

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
