# Dashboard-Widgets

🇬🇧 [English](../en/dashboard-widgets.md)

Stelle das CP-Dashboard aus den acht Workflow-Widgets des Addons zusammen.

> **Auf einen Blick**
> - Config-Schlüssel: `dashboard_widgets` in `config/workflows.php`
> - Standard: ein fertiges Layout aus sieben Widgets (= an)
> - Eigener Code: nein

## Überblick

Das Addon bringt acht Dashboard-Widgets mit. Das Dashboard ist der Einstieg in die Workflow-Manager, einen eigenen Navigationseintrag gibt es nicht.

`dashboard_widgets` ist ein Fallback-Layout. Das Addon setzt es nur, solange dein Projekt in `config/statamic/cp.php` unter `widgets` nichts einträgt. Das ist der Statamic-Standard.

Du hast also zwei Wege:

- **Standard-Layout nutzen:** Nichts tun, oder `dashboard_widgets` anpassen.
- **Eigenes Layout:** Die Widgets in `config/statamic/cp.php` unter `widgets` eintragen. Dann gilt nur diese Liste.

## Schnellstart

1. Veröffentliche die Config, falls noch nicht geschehen:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Passe das Layout an. Das ist der mitgelieferte Standard:

   ```php
   // config/workflows.php
   'dashboard_widgets' => [
       ['type' => 'workflows_user_header', 'width' => 100],
       ['type' => 'workflows_queue_stats', 'width' => 100],
       ['type' => 'workflows_my_queue', 'width' => 100, 'limit' => 7],
       ['type' => 'workflows_watched', 'width' => 50, 'limit' => 5],
       ['type' => 'workflows_created_by_me', 'width' => 50, 'limit' => 5],
       ['type' => 'workflows_overview', 'width' => 100],
       ['type' => 'workflows_status', 'width' => 100],
   ],
   ```

3. Öffne das Dashboard im CP. Die Widgets erscheinen in dieser Reihenfolge.

## Konfiguration

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `dashboard_widgets` | `array` \| `false` | nein | Layout oben | Liste von Widget-Einträgen. `false` oder `[]` schaltet den Fallback ab. |

Jeder Eintrag ist ein normaler Statamic-Widget-Eintrag:

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `type` | `string` | ja | – | Handle des Widgets (siehe unten). |
| `width` | `int` | nein | – | Breite in Prozent, ausgewertet von Statamic. Der Standard nutzt `100` und `50`. |
| weitere | – | nein | – | Optionen des jeweiligen Widgets (siehe unten). |

## Die Widgets

| `type` | Inhalt | Optionen |
| --- | --- | --- |
| `workflows_user_header` | Name, Avatar, Gruppen, Zugehörigkeit und Verantwortliche des Users | keine (Config: [Verantwortliche im Dashboard-Header](header-responsibles.md)) |
| `workflows_queue_stats` | Vier Kennzahl-Karten: in meiner Warteschlange, wartet auf Freigabe, fehlgeschlagene Übersetzungen, diese Woche veröffentlicht | `workflows` |
| `workflows_my_queue` | Mir zugewiesene Beiträge über alle aktiven Workflows, ein Tab pro Workflow | `limit`, `workflows` |
| `workflows_watched` | Beiträge, die ich beobachte | `limit`, `workflows` |
| `workflows_created_by_me` | Beiträge, deren `author`-Feld mich nennt | `limit`, `workflows` |
| `workflows_overview` | „Alle Beiträge“: Liste über alle Workflows, Einstieg in die Manager | `per_page`, `workflows`, `default_workflow` |
| `workflows_status` | Ein Donut-Diagramm pro Workflow: Verteilung auf die Zustände | `workflows` |
| `workflows_assigned_posts` | Mir zugewiesene Beiträge **eines** Workflows | `collection`, `limit` |

`workflows_assigned_posts` ist registriert, aber nicht Teil des Standard-Layouts.

### Widget-Optionen

| Option | Typ | Standard | Gilt für | Beschreibung |
| --- | --- | --- | --- | --- |
| `workflows` | `array` | alle aktiven | alle außer `workflows_user_header` und `workflows_assigned_posts` | Workflow-Namen, auf die das Widget beschränkt wird. Unbekannte Namen werden ignoriert. |
| `limit` | `int` | `7` (`workflows_my_queue`), sonst `5` | Listen-Widgets, `workflows_assigned_posts` | Zeilen pro Seite. |
| `per_page` | `int` | `15` | `workflows_overview` | Zeilen pro Seite. |
| `default_workflow` | `string` | der Authoring-Workflow | `workflows_overview` | Workflow, dessen Button beim Laden aktiv ist. |
| `collection` | `string` | `posts` | `workflows_assigned_posts` | Collection, deren Workflow das Widget zeigt. |

## Ablauf

1. Beim Start prüft das Addon: Ist `dashboard_widgets` eine nicht leere Liste **und** `statamic.cp.widgets` leer?
2. Wenn ja, wird `statamic.cp.widgets` auf `dashboard_widgets` gesetzt. Sonst passiert nichts.
3. Widget-Einstellungen pro User oder Rolle haben Vorrang vor beiden Listen.
4. Beim Rendern blenden sich die persönlichen Widgets ohne eingeloggten User aus. `workflows_overview` und `workflows_status` blenden sich aus, wenn kein Workflow im Bereich liegt.

## Beispiele

### Eigenes Layout in Statamic

Dieselben Einträge funktionieren direkt in der Statamic-Config:

```php
// config/statamic/cp.php
'widgets' => [
    ['type' => 'workflows_user_header', 'width' => 100],
    ['type' => 'workflows_my_queue', 'width' => 100, 'limit' => 10],
    ['type' => 'workflows_overview', 'width' => 100, 'per_page' => 25],
],
```

### Auf bestimmte Workflows beschränken

Setze bei `workflows_queue_stats` und `workflows_overview` dieselbe Liste, damit Kennzahlen und Tabelle zusammenpassen:

```php
'dashboard_widgets' => [
    ['type' => 'workflows_queue_stats', 'width' => 100, 'workflows' => ['postmanager']],
    ['type' => 'workflows_overview', 'width' => 100, 'workflows' => ['postmanager']],
],
```

### Fallback abschalten

```php
// config/workflows.php
'dashboard_widgets' => false,
```

## Hinweise & Grenzen

> [!WARNING]
> Es wird nichts gemischt. Steht in `config/statamic/cp.php` unter `widgets` irgendein Eintrag, gilt nur diese Liste. Aus `dashboard_widgets` wird nichts ergänzt.

> [!WARNING]
> `workflows_overview` ist der einzige Weg zu den Manager-Seiten im CP. `/cp/workflows` leitet aufs Dashboard um. Entfernst du das Widget aus deinem Layout, entfernst du auch den Einstieg.

> [!TIP]
> Wenn du `workflows` bei `workflows_queue_stats` setzt, setze dieselbe Liste bei `workflows_overview`. Sonst beziehen sich Kennzahlen und gefilterte Tabelle auf verschiedene Workflows.

> [!NOTE]
> Die Karten von `workflows_queue_stats` springen zu `workflows_my_queue` (Karte 1) bzw. filtern `workflows_overview` (Karten 2 bis 4). Fehlt das Ziel-Widget, verlinkt die Karte auf eine Manager-Seite, sofern sie eindeutig ist. Sonst ist die Karte kein Link.

> [!NOTE]
> Bei den Listen-Widgets ist `limit` die Seitengröße. Alle Zeilen werden ausgeliefert und im Browser seitenweise angezeigt.

> [!NOTE]
> Das Beispiel in DOCS.md setzt `'per_page' => 25`. Der mitgelieferte Standard setzt keinen Wert, es gilt also `15`.

## Fehlerbehebung

| Symptom | Ursache | Lösung |
| --- | --- | --- |
| Die Workflow-Widgets fehlen. | `config/statamic/cp.php` trägt eigene `widgets` ein. | Die Workflow-Widgets dort ergänzen. |
| | Widget-Einstellungen eines Users oder einer Rolle überschreiben das Layout. | Einstellungen im CP prüfen. |
| | `dashboard_widgets` ist `false` oder `[]`. | Layout eintragen. |
| Ein Widget fehlt trotz Eintrag. | Kein Workflow im Bereich, z. B. durch eine falsche `workflows`-Liste. | Workflow-Namen prüfen. |

## Siehe auch

- [Verantwortliche im Dashboard-Header](header-responsibles.md)
- [DOCS.md](../../../DOCS.md), Abschnitt „Dashboard-Widgets“
- [Integration.md](../../../Integration.md), Abschnitt „Dashboard-Widget“
- [config/workflows.php](../../../config/workflows.php), Kommentar zu `dashboard_widgets`
- [src/ServiceProvider.php](../../../src/ServiceProvider.php)
- [src/Widgets/](../../../src/Widgets/)

---

← [Verantwortliche im Dashboard-Header](header-responsibles.md) · [Übersicht](README.md) · [Inhaltsfelder](content-fields.md) →
