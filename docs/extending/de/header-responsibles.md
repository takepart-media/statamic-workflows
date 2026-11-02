# Verantwortliche im Dashboard-Header

🇬🇧 [English](../en/header-responsibles.md)

Zeige im Dashboard-Header, an wen sich eine Person bei Fragen zu ihren Themen wenden kann.

> **Auf einen Blick**
> - Config-Schlüssel: `header_responsibles` in `config/workflows.php`
> - Standard: `['group' => null, 'user_field' => null]` (= aus)
> - Eigener Code: nein

## Überblick

Das Dashboard-Widget `workflows_user_header` stellt den eingeloggten User vor: Name, Avatar, Gruppen und „Zugehörigkeit“. Mit `header_responsibles` bekommt es eine weitere Zeile „Verantwortlicher“.

Diese Zeile nennt die Mitglieder einer Benutzergruppe, die für dieselben Themen zuständig sind wie der User. Ein typischer Fall: Redakteurinnen sehen auf einen Blick, wer ihre Themenverantwortlichen sind.

## Schnellstart

1. Veröffentliche die Config, falls noch nicht geschehen:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Lege eine Benutzergruppe für die Verantwortlichen an, z. B. `themenverantwortliche`. Lege im User-Blueprint ein Feld für die Themen an, z. B. `entity`.

3. Trage beides in die Config ein:

   ```php
   // config/workflows.php
   'header_responsibles' => [
       'group' => 'themenverantwortliche',
       'user_field' => 'entity',
   ],
   ```

4. Pflege das Feld `entity` bei den Verantwortlichen und bei den übrigen Usern. Im Dashboard erscheint jetzt die Zeile „Verantwortlicher“.

## Konfiguration

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `group` | `string` \| `null` | ja | `null` | Handle der Statamic-Benutzergruppe, deren Mitglieder als Verantwortliche infrage kommen. `null` oder `''` schaltet die Zeile ab. |
| `user_field` | `string` \| `null` | ja | `null` | Handle eines Felds im User-Blueprint. Es wird auf **beiden** Seiten gelesen: beim aktuellen User und bei den Gruppenmitgliedern. `null` oder `''` schaltet die Zeile ab. |

Beide Werte müssen gesetzt sein, sonst fehlt die Zeile. Das Widget selbst hat keine eigenen Widget-Optionen.

## Im Dashboard

Das Widget `workflows_user_header` zeigt unter dem Namen bis zu drei Zeilen:

1. **Funktionen:** die Statamic-Gruppen des Users, oder „Keine Funktionen“.
2. **Zugehörigkeit:** alle Werte, die die [Zuweisungskriterien](assignment-criteria.md) mit `user_field` an der Person lesen. Beschriftet werden sie über die `options` der Kriterien, z. B. mit dem Term-Titel statt dem Slug. Ohne passende Option erscheint der Slug. Hat der User keine Werte, fehlt die Zeile.
3. **Verantwortlicher:** alle Mitglieder von `group`, deren Werte in `user_field` sich mit den Werten des Users im selben Feld überschneiden.

Für die Zeile „Verantwortlicher“ gilt:

- Verglichen wird über den nackten Slug, wie bei den Zuweisungskriterien. `themen::gesundheit` trifft also `gesundheit`.
- Angezeigt wird der Name, ersatzweise die E-Mail-Adresse, natürlich sortiert.
- Der User selbst erscheint nie in seiner eigenen Zeile.

Ohne eingeloggten User blendet sich das Widget aus.

## Beispiele

### Dasselbe Feld für Zuweisung und Header

Nutzt ein Zuweisungskriterium dasselbe User-Feld, passen „Zugehörigkeit“ und „Verantwortlicher“ zusammen. Die Zugehörigkeit erhält dann auch die Term-Titel als Beschriftung:

```php
// config/workflows.php
'assignment_criteria' => [
    'responsibility' => [
        'label' => 'Zuständigkeit',
        'user_field' => 'entity',
        'entry_field' => 'categories',
        'options' => 'taxonomy:categories',
    ],
],

'header_responsibles' => [
    'group' => 'themenverantwortliche',
    'user_field' => 'entity',
],
```

Eine Redakteurin mit `entity: [gesundheit]` sieht unter „Zugehörigkeit“ den Titel des Terms „gesundheit“. Unter „Verantwortlicher“ stehen alle Mitglieder von `themenverantwortliche`, deren `entity` ebenfalls `gesundheit` enthält.

## Hinweise & Grenzen

> [!WARNING]
> Gruppe und Feld werden **nicht** gegen Blueprints geprüft, anders als bei den Zuweisungskriterien. Ein Tippfehler führt ohne Fehlermeldung dazu, dass die Zeile fehlt.

> [!NOTE]
> Es gibt nur **ein** Feld für beide Seiten. Unterschiedliche Felder für User und Verantwortliche sind nicht möglich.

> [!NOTE]
> `header_responsibles` ist unabhängig von `assignment_criteria`. Die Kriterien legen fest, wem ein Beitrag zugewiesen werden darf. Diese Zeile zeigt, wen eine Person fragt.

> [!NOTE]
> Weitere Zeilen lassen sich nicht per Config ergänzen, nur im Code des Addons.

## Fehlerbehebung

| Symptom | Ursache | Lösung |
| --- | --- | --- |
| Die Zeile „Verantwortlicher“ fehlt. | `group` oder `user_field` ist nicht gesetzt. | Beide Werte eintragen. |
| | Die Gruppe existiert nicht, oder das Feld ist falsch geschrieben. | Handles mit dem CP abgleichen. |
| | Der User hat im Feld keinen Wert. | Feld beim User pflegen. |
| | Kein Gruppenmitglied teilt einen Wert mit dem User. | Feld bei den Verantwortlichen pflegen. |
| Das ganze Widget fehlt. | Es steht nicht im Dashboard-Layout. | Siehe [Dashboard-Widgets](dashboard-widgets.md). |

## Siehe auch

- [Zuweisungskriterien](assignment-criteria.md)
- [Dashboard-Widgets](dashboard-widgets.md)
- [DOCS.md](../../../DOCS.md), Abschnitt „`UserHeader` (`workflows_user_header`)“
- [config/workflows.php](../../../config/workflows.php), Kommentar zu `header_responsibles`
- [src/Widgets/UserHeader.php](../../../src/Widgets/UserHeader.php)

---

← [Zuweisungskriterien](assignment-criteria.md) · [Übersicht](README.md) · [Dashboard-Widgets](dashboard-widgets.md) →
