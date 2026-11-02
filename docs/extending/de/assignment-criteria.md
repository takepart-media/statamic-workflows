# Zuweisungskriterien

🇬🇧 [English](../en/assignment-criteria.md)

Mit eigenen Zuweisungskriterien entscheidest du anhand von Feldern an Person und Beitrag, wem ein Beitrag zugewiesen wird.

> **Auf einen Blick**
> - Config-Schlüssel: `assignment_criteria` in `config/workflows.php`
> - Standard: `[]` (nur die eingebauten Kriterien `group` und `role`)
> - Eigener Code: nein

## Überblick

Eine `user_assignment`-Action an einer Transition filtert die möglichen Zugewiesenen über Kriterien. Das Addon bringt zwei Kriterien mit: `group` (Statamic-Benutzergruppe) und `role` (Statamic-Rolle).

Über `assignment_criteria` deklarierst du weitere Kriterien. Jedes liest ein Feld im User-Blueprint, ein Feld in einem Entry-Blueprint oder beide. So lassen sich Fragen beantworten wie „Wer betreut die Kategorie dieses Beitrags?“.

Die Kriterien erscheinen im Graph-Editor im Dropdown der Action. Im manuellen Modus schränken sie die Auswahlliste ein, im automatischen Modus bestimmen sie die Zugewiesenen.

## Schnellstart

1. Veröffentliche die Config, falls noch nicht geschehen:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Lege die Felder an, die das Kriterium lesen soll. Im Beispiel: ein Taxonomie-Feld `entities` im User-Blueprint und ein Taxonomie-Feld `categories` im Entry-Blueprint, beide auf `categories`.

3. Deklariere das Kriterium:

   ```php
   // config/workflows.php
   'assignment_criteria' => [
       'category' => [
           'label' => 'Kategorie',
           'user_field' => 'entities',
           'entry_field' => 'categories',
           'options' => 'taxonomy:categories',
       ],
   ],
   ```

4. Öffne den Workflow im Graph-Editor, wähle an einer Transition die Action „Benutzerzuweisung“ und füge Kriterien hinzu. Der Editor schreibt sie so in die Workflow-YAML:

   ```yaml
   actions:
     - type: user_assignment
       mode: automatic
       match: all
       criteria:
         - { field: group, operator: is, value: redaktion }
         - { field: category, operator: matches_entry }
   ```

   Zugewiesen werden jetzt alle Mitglieder der Gruppe `redaktion`, deren `entities` sich mit den `categories` des Beitrags überschneiden.

## Konfiguration

Ein Eintrag pro Kriterium. Der Array-Schlüssel ist der Handle, der in der YAML als `field` steht.

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `label` | `string` | nein | der Handle | Anzeigename im Editor. Läuft durch `__()`, ein Übersetzungs-Key funktioniert also auch. |
| `user_field` | `string` | mind. eines von beiden | – | Feld-Handle im User-Blueprint. |
| `entry_field` | `string` | mind. eines von beiden | – | Feld-Handle in einem beliebigen Entry-Blueprint des Projekts. |
| `operators` | `array` | nein | alle, die die Felder erlauben | Liste aus `is`, `is_not`, `matches_entry`. Schränkt die Operatoren ein. Die Reihenfolge zählt: Der erste ist der Startwert einer neuen Zeile. |
| `options` | `array` \| `string` | nein | keine | Vorschläge für das Wert-Feld (siehe unten). |

### Wertvorschläge (`options`)

| Form | Beispiel | Ergebnis |
| --- | --- | --- |
| Map `value => label` | `['news' => 'Nachrichten']` | Einträge in dieser Reihenfolge. |
| Liste von Paaren | `[['value' => 'news', 'label' => 'Nachrichten']]` | Wie die Map, erlaubt aber doppelte Labels. |
| `'groups'` | – | Alle Benutzergruppen, nach Titel sortiert. |
| `'roles'` | – | Alle Rollen, nach Titel sortiert. |
| `'sites'` | – | Alle Sites. |
| `'taxonomy:<handle>'` | `'taxonomy:categories'` | Alle Terms der Taxonomie, einmal pro Slug, beschriftet mit dem Titel der Default-Site. |

## Operatoren

Welche Operatoren ein Kriterium anbietet, hängt davon ab, welche Felder du deklarierst:

| Deklarierte Felder | `is` / `is_not` prüfen | Zusätzlich |
| --- | --- | --- |
| nur `user_field` | das Feld der **Person** gegen einen festen Wert | – |
| nur `entry_field` | das Feld des **Beitrags** gegen einen festen Wert | – |
| beide | das Feld der **Person** gegen einen festen Wert | `matches_entry` |

- `is`: Der gelesene Wert enthält `value`. `is_not` ist die Umkehrung.
- `matches_entry`: Die Werte von Person und Beitrag überschneiden sich. Ein `value` ist nicht nötig, der Beitrag liefert ihn.
- Ein Kriterium nur mit `entry_field` gilt für alle Personen gleich. Es wirkt als Vorbedingung für die ganze Zuweisung.

### Wie Werte verglichen werden

- Feldwerte werden immer als Listen behandelt, egal ob das Feld einen oder mehrere Werte speichert.
- Jeder Wert wird auf seinen nackten Slug reduziert. `themen::gesundheit` trifft also `gesundheit`.
- Verglichen werden die gespeicherten Werte, nicht die Labels.

## Im Editor

- Das Dropdown zeigt `group`, `role` und alle gültigen Projekt-Kriterien.
- Pro Kriterium stehen nur die erlaubten Operatoren zur Wahl.
- Das Wert-Feld zeigt die `options` als Vorschläge. Du kannst auch einen Wert tippen, der noch nicht existiert.
- Kriterien lassen sich eine Ebene tief zu Gruppen mit eigenem `match` (`all` oder `any`) zusammenfassen.

## Ablauf

1. **Speichern:** Der Editor lehnt unbekannte Kriterien, nicht erlaubte Operatoren und fehlende Werte bei `is`/`is_not` mit einer Fehlermeldung ab.
2. **Ausführen:** Beim Auslösen der Transition wird jedes Kriterium für jeden User geprüft.
3. **Im Zweifel niemand:** Ein unbekanntes Kriterium, ein unbekannter Operator, ein fehlender Wert oder ein fehlender Beitrag ergibt immer „trifft nicht zu“. Das betrifft vor allem von Hand bearbeitete YAML.

## Beispiele

### Zuständige Person für die Kategorie des Beitrags

Ein Kriterium, das genau eine Frage beantworten soll. `operators` blendet `is` und `is_not` aus:

```php
'assignment_criteria' => [
    'responsibility' => [
        'label' => 'Zuständigkeit',
        'user_field' => 'entity',
        'entry_field' => 'categories',
        'operators' => ['matches_entry'],
    ],
],
```

```yaml
criteria:
  - { field: responsibility, operator: matches_entry }
```

### Vorbedingung auf den Beitrag

Ein Kriterium nur mit `entry_field`, kombiniert mit einem Kriterium, das Personen auswählt:

```php
'assignment_criteria' => [
    'post_category' => [
        'label' => 'Kategorie des Beitrags',
        'entry_field' => 'categories',
        'options' => 'taxonomy:categories',
    ],
],
```

```yaml
actions:
  - type: user_assignment
    mode: automatic
    match: all
    criteria:
      - { field: post_category, operator: is, value: gesundheit }
      - { field: group, operator: is, value: fachredaktion }
```

Gesundheitsbeiträge gehen an die Gruppe `fachredaktion`. Bei anderen Beiträgen wird niemand zugewiesen.

### Gruppe mit eigenem `match`

```yaml
actions:
  - type: user_assignment
    mode: automatic
    match: all
    criteria:
      - { field: category, operator: matches_entry }
      - match: any
        criteria:
          - { field: role, operator: is, value: editor }
          - { field: group, operator: is, value: chefredaktion }
```

## Hinweise & Grenzen

> [!WARNING]
> Feld-Handles werden geprüft. `user_field` muss im User-Blueprint existieren, `entry_field` in mindestens einem Entry-Blueprint. Ein Kriterium mit fehlendem Feld wird verworfen: Es fehlt im Dropdown, und eine YAML, die es nutzt, lässt sich nicht mehr speichern. Lege immer zuerst das Feld an, dann das Kriterium.

> [!WARNING]
> Im automatischen Modus muss mindestens ein Kriterium Personen einschränken (`group`, `role` oder eines mit `user_field`). Bei `match: any` muss das für **alle** Kriterien gelten. Eine leere Kriterienliste ist im automatischen Modus nicht erlaubt. Gruppen werden mit ihrem eigenen `match` geprüft.

> [!NOTE]
> Ein Eintrag ohne `user_field` und ohne `entry_field` wird verworfen. Ein Operator in `operators`, den die Felder nicht hergeben (etwa `matches_entry` ohne `entry_field`), wird still entfernt. Bleibt kein Operator übrig, wird das Kriterium verworfen.

> [!NOTE]
> Die Prüfung von `entry_field` gilt projektweit. Ein Feld, das nur in einer anderen Collection existiert, gilt als vorhanden.

> [!NOTE]
> Zwei Taxonomien mit gleichem Slug gelten beim Vergleich als gleich.

> [!TIP]
> `options` sind nur Vorschläge. Ein Tippfehler im Wert wird nicht erkannt, wähle Werte daher möglichst aus der Liste.

> [!NOTE]
> Ein Projekt-Kriterium mit dem Handle `group` oder `role` ersetzt das eingebaute Kriterium.

> [!NOTE]
> Die Werte aller Kriterien mit `user_field` erscheinen zusätzlich als Zeile „Zugehörigkeit“ im Dashboard-Header, siehe [Verantwortliche im Dashboard-Header](header-responsibles.md).

## Fehlerbehebung

| Symptom | Ursache | Lösung |
| --- | --- | --- |
| Das Kriterium fehlt im Dropdown. | Ein deklariertes Feld existiert nicht, oder keine Operatoren bleiben übrig. | Feld-Handles prüfen, `operators` prüfen. |
| Der Workflow lässt sich nicht mehr speichern. | Die YAML nutzt ein Kriterium, das verworfen wurde. | Fehlendes Feld anlegen oder Kriterium aus der Action entfernen. |
| Speichern scheitert im automatischen Modus. | Kein Kriterium schränkt Personen ein, oder bei `match: any` nicht alle. | Ein Kriterium mit `group`, `role` oder `user_field` ergänzen. |
| Niemand wird zugewiesen. | Kein User erfüllt alle Kriterien, oder der Beitrag hat im `entry_field` keinen Wert. | Werte an Personen und Beitrag prüfen. |

## Siehe auch

- [Verantwortliche im Dashboard-Header](header-responsibles.md)
- [DOCS.md](../../../DOCS.md), Abschnitt „Zuweisungs-Kriterien“
- [config/workflows.php](../../../config/workflows.php), Kommentar zu `assignment_criteria`
- [src/Workflow/AssignmentCriteria.php](../../../src/Workflow/AssignmentCriteria.php)
- [src/Workflow/AssignmentCandidates.php](../../../src/Workflow/AssignmentCandidates.php)
- [src/Workflow/WorkflowDefinitionWriter.php](../../../src/Workflow/WorkflowDefinitionWriter.php)

---

← [Eigene Mail-Typen](mail-types.md) · [Übersicht](README.md) · [Verantwortliche im Dashboard-Header](header-responsibles.md) →
