# Inhaltsfelder

🇬🇧 [English](../en/content-fields.md)

Mit `content_fields` sagst du dem Addon, wie die Felder deiner Blueprints heißen: Titel, Intro, Body, SEO und Entity-Bezug.

> **Auf einen Blick**
> - Config-Schlüssel: `workflows.content_fields`
> - Standard: `title => 'title'`, `body => 'content'`, `seo => 'seo'`, alles andere `null` / `[]`
> - Eigener Code: nein

## Überblick

Das Addon kennt die Blueprints deines Projekts nicht. `content_fields` ordnet deshalb globalen **Rollen** die passenden Feld-Handles zu. Die Content-Preview, das Umschreiben in Einfache Sprache und der Übersetzungs-Kontext lesen diese Rollen.

*Welche* Felder zum Inhalt eines Workflows gehören, legst du nicht hier fest. Das wählst du pro Workflow in den Workflow-Einstellungen (gespeichert als `metadata.content_fields`). Die Rollen `intro` und `body` dienen nur der Auto-Erkennung, wenn ein Workflow keine Auswahl hat.

Typischer Fall: Deine Artikel haben ein Bard-Feld `intro` und ein Haupt-Bard-Feld `content_sets`. Du trägst beide Rollen ein, und Preview und Einfache Sprache finden den Text ohne weitere Konfiguration.

## Schnellstart

1. Config publizieren:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Rollen in `config/workflows.php` auf deine Feld-Handles setzen:

   ```php
   'content_fields' => [
       'title'  => 'title',
       'intro'  => 'intro',          // Bard-Feld mit dem Einleitungsabsatz
       'body'   => 'content_sets',   // Haupt-Bard-Feld
       'seo'    => 'seo',            // SEO-Pro-Feld
       'entity' => 'entities',       // Terms-Feld: zu welchem Modul der Eintrag gehört
       'scope'  => 'in_entities',    // Set-Feld, das einen Block auf Entities beschränkt
   ],
   ```

3. Optional pro Workflow die Inhaltsfelder wählen: Workflow-Einstellungen → Sektion **Inhalt**. Ohne Auswahl zeigt der Editor „automatisch: <feld>“.

4. Eine Workflow-Detailseite öffnen. Das Inhalts-Panel zeigt jetzt die aufgelösten Felder.

## Konfiguration

Die Schlüssel werden **einzeln** mit den Standardwerten zusammengeführt. Setzt du nur `body`, behältst du alle anderen Standardwerte. Ein fehlender, `null`- oder leerer Schlüssel schaltet die Funktion ab, die ihn braucht. Es gibt keine Env-Variablen.

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `title` | `string` | nein | `'title'` | Titelfeld. Bei Einfacher Sprache landet die zurückgegebene `<h1>` hier. Der Titel geht nie als Inhaltsblock an den Dienst. |
| `intro` | `string\|null` | nein | `null` | Bard-Feld mit dem Einleitungsabsatz. Dient der Auto-Erkennung. Bei Einfacher Sprache steckt dieser Block in `<div class="intro">`, und `generate_intro` ist nur dann `true`. Quelle der abgeleiteten `seo.description`. |
| `body` | `string` | nein | `'content'` | Haupt-Inhaltsfeld. Dient der Auto-Erkennung. Ältere Einfache-Sprache-Jobs (`format = null`) ersetzen beim Zurückschreiben dieses Feld. |
| `seo` | `string` | nein | `'seo'` | SEO-Pro-Feld. Geht nie an den Einfache-Sprache-Dienst. `seo.title` und `seo.description` werden stattdessen aus der Antwort abgeleitet. |
| `entity` | `string\|array\|null` | nein | `null` | Terms-Feld (einzeln oder Liste) mit dem Modul des Eintrags. Die Handles gehen als `modules` im Request mit. Nur der Einfache-Sprache-Dienst nutzt diesen Kontext, DeepL ignoriert ihn. |
| `scope` | `string\|array\|null` | nein | `null` | Feld **innerhalb** von Sets oder Zeilen, das einen Block auf Entities beschränkt. Die Liste reist als `<div data-modules="…">`. Verschachtelte Scopes werden mit dem äußeren geschnitten. |
| `sources_title`, `sources_text` | `string\|null` | nein | `null` | **Veraltet.** Wird geladen, aber nicht mehr gelesen. |
| `podcast_link`, `podcast_code` | `string\|null` | nein | `null` | **Veraltet.** Wird geladen, aber nicht mehr gelesen. |
| `sets` | `array` | nein | `[]` | **Veraltet.** Format `type => [kind, field, extra]`, kind `html`, `heading`, `video`, `asset` oder `code`. Wird geladen, aber nicht mehr gelesen. |

Was die veralteten Schlüssel früher zeigten, wählst du jetzt als Top-Level-Feld in `metadata.content_fields`.

## Welche Felder Inhalt sind

Das Addon bestimmt die Inhaltsfelder eines Eintrags in dieser Reihenfolge:

1. **Auswahl des Workflows:** `metadata.content_fields`, gefiltert auf die Felder, die das Blueprint des Eintrags hat. Die Reihenfolge bleibt, Duplikate fallen weg.
2. **Auto-Erkennung:** `intro` und `body` aus `content_fields`, wenn das Blueprint sie hat. Sonst das erste Feld vom Typ `bard`, `markdown` oder `replicator`.
3. **Nichts:** Der Eintrag hat keinen anzeigbaren Inhalt.

In den Workflow-Einstellungen wählbar sind nur diese Feldtypen: `bard`, `replicator`, `grid`, `group`, `markdown`, `text`, `textarea`, `code`, `video`, `assets`.

## Im Control Panel

- **Inhalts-Panel der Workflow-Detailseite:** Zeigt die aufgelösten Felder in beiden Ansichten, auch in der Quellspalte einer Übersetzung. Replicator, Grid, Group und Bard-Sets werden generisch durchlaufen. Die Preview ist schreibgeschützt und verlinkt in den Entry-Editor.
- **Kommentare:** Jeder Block der Preview ist ein kommentierbarer Abschnitt. Ändert sich sein Text, erscheint der Hinweis „Text geändert“. Ungelesen-Zähler und Erwähnungs-Mails nutzen dieselben Blöcke.
- **Einfache Sprache:** Sendet genau die aufgelösten Inhaltsfelder, abzüglich `translation_exclude_fields`, `seo` und `title`. Zurückgeschrieben werden `title`, die Textblöcke und die abgeleiteten SEO-Texte.
- **Payload-Vorschau:** `…/instances/{id}/translation-payload` liefert `leaves`, `content_fields` und `skipped`.
- **Workflow-Einstellungen und „Workflow erstellen“:** Die Sektion **Inhalt** setzt `metadata.content_fields`. Der Linter warnt, wenn eine Collection nichts erkennt (`content_field_auto_none`) oder gewählte Felder in manchen Blueprints fehlen (`content_field_partial`).
- **History-Diff:** Nutzt `content_fields` **nicht**. Er vergleicht Event-Snapshots über alle Top-Level-Felder.

## Beispiele

### Nur das Haupt-Feld umbenennen

Dein Projekt nennt das Bard-Feld `article_body`. Alle anderen Standardwerte passen:

```php
'content_fields' => [
    'body' => 'article_body',
],
```

`title` und `seo` behalten ihre Standardwerte, weil die Schlüssel einzeln zusammengeführt werden.

### Einen Block auf Module beschränken

Einträge gehören über das Terms-Feld `entities` zu Modulen. Einzelne Sets haben ein Feld `in_entities`:

```php
'content_fields' => [
    'entity' => 'entities',
    'scope'  => 'in_entities',
],
```

Der Einfache-Sprache-Dienst bekommt die Module als `modules` und jeden beschränkten Block in `<div data-modules="…">`.

## Hinweise & Grenzen

> [!NOTE]
> `content_fields` legt nicht mehr fest, *welche* Felder Inhalt sind. `intro` und `body` wirken nur bei Workflows ohne `metadata.content_fields`.

> [!NOTE]
> Die Preview überspringt `toggle`, `select`, `link`, `entries`, Taxonomie-Felder sowie `hidden`- und `computed`-Felder.

> [!TIP]
> `entity` und `scope` akzeptieren einen einzelnen String (Terms-Feld mit `max_items: 1`) oder eine Liste.

> [!WARNING]
> Nennt ein Workflow nur Felder, die das Blueprint eines Eintrags nicht hat, greift für diesen Eintrag die Auto-Erkennung. Das ist gewollt, der Linter warnt davor.

## Fehlerbehebung

| Symptom | Ursache | Lösung |
| --- | --- | --- |
| Das Inhalts-Panel ist leer. | Keine Workflow-Auswahl, und die Auto-Erkennung findet weder `intro`/`body` noch ein Bard-, Markdown- oder Replicator-Feld. | Inhaltsfelder in der Sektion **Inhalt** wählen oder `body` auf das richtige Handle setzen. |
| Ein Feld ist in der Sektion **Inhalt** nicht wählbar. | Der Feldtyp wird nicht unterstützt. | Ein Feld eines unterstützten Typs wählen (Liste oben). |
| Linter-Warnung `content_field_partial` | Gewählte Felder fehlen in manchen Blueprints der Collection. | Felder ergänzen oder akzeptieren, dass diese Einträge auf Auto-Erkennung zurückfallen. |

## Siehe auch

- [Übersetzung & Einfache Sprache](translation.md)
- [DOCS.md](../../../DOCS.md): §7 „Content-Preview: `EntryContentPreview`“, „Config `content_fields`“, §5.10 „Content-Preview & History-Diff“, „Rückschreiben pro Textblatt (Format `sections`)“
- [README.md](../../../README.md): „Content fields (Inhaltsfelder)“
- [config/workflows.php](../../../config/workflows.php)
- [src/Support/ContentFields.php](../../../src/Support/ContentFields.php)
- [src/Content/EntryContentPreview.php](../../../src/Content/EntryContentPreview.php)
- [src/Content/ContentFieldResolver.php](../../../src/Content/ContentFieldResolver.php)
- [src/Content/ContentFieldCatalog.php](../../../src/Content/ContentFieldCatalog.php)
- [src/Translation/PlainLanguageLeafDocument.php](../../../src/Translation/PlainLanguageLeafDocument.php)
- [src/Translation/TranslationRunner.php](../../../src/Translation/TranslationRunner.php)

---

← [Dashboard-Widgets](dashboard-widgets.md) · [Übersicht](README.md) · [Übersetzung & Einfache Sprache](translation.md) →
