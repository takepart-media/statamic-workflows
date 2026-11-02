# Eigene Action-Typen

🇬🇧 [English](../en/actions.md)

Hänge eigene Actions an Transitions und Places und führe beim Ausführen einer Transition deinen eigenen Projektcode aus.

> **Auf einen Blick**
> - Config-Schlüssel: `actions`
> - Standard: `[]` (= aus)
> - Eigener Code: optional `Contracts\WorkflowAction`

## Überblick

Du deklarierst eigene Action-Typen in `config/workflows.php`. Sie erscheinen im Graph-Editor neben den eingebauten Actions und werden wie diese in der Workflow-YAML gespeichert.

Jede Action gehört zu genau einem Scope: Sie hängt entweder an einer Transition oder an einem Place. Optional bekommt sie Eingabefelder, deren Werte im Editor gepflegt werden.

Nennt die Deklaration einen Handler, ruft das Addon ihn nach jeder erfolgreichen Transition auf. Das ist der einzige Punkt, an dem das Addon Projektcode aufruft. Andere Events feuert es nicht.

Ohne Handler ist die Action eine reine Markierung. Dein Code liest sie bei Bedarf selbst aus, etwa „Juristisch geprüft“ an einem Place.

## Schnellstart

1. Veröffentliche die Config, falls noch nicht geschehen:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Deklariere die Action in `config/workflows.php`:

   ```php
   'actions' => [
       'press_release' => [
           'label' => 'Pressemitteilung vorbereiten',
           'scope' => 'transition',
           'handler' => \App\Workflows\PressRelease::class,
       ],
   ],
   ```

3. Schreibe den Handler:

   ```php
   <?php

   namespace App\Workflows;

   use TakepartMedia\Workflows\Contracts\WorkflowAction;
   use TakepartMedia\Workflows\Workflow\ActionContext;

   class PressRelease implements WorkflowAction
   {
       public function handle(ActionContext $context): void
       {
           logger()->info('Pressemitteilung für '.$context->entry->id());
       }
   }
   ```

4. Öffne den Workflow im CP, wähle eine Transition und füge die Action „Pressemitteilung vorbereiten“ hinzu. Nach dem Speichern läuft der Handler bei jeder Ausführung dieser Transition.

## Konfiguration

### Deklaration (`actions.<handle>`)

Der Array-Schlüssel ist der Handle der Action. Er landet als `type` in der YAML.

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `label` | `string` | ja | – | Label im Editor. Läuft durch `__()`, ein Sprachkey funktioniert also genauso wie Klartext. |
| `scope` | `string` | ja | – | `transition` oder `place`. Beide Scopes haben getrennte Typ-Listen und eine getrennte Validierung. |
| `workflows` | `array` \| `string` | nein | alle | Workflow-Namen, in denen die Action angeboten wird. Fehlt der Schlüssel oder ist die Liste leer, gilt sie für alle Workflows. |
| `handler` | `class-string` | nein | – | Klasse, die `Contracts\WorkflowAction` implementiert. Ohne Handler ist die Action eine reine Markierung. |
| `fields` | `array` | nein | `[]` | Eingabefelder. Der Schlüssel ist der Feld-Handle, die Werte werden unter `values` gespeichert. |

### Felder (`fields.<key>`)

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `type` | `string` | nein | `text` | `text`, `number`, `select` oder `multiselect`. Ein unbekannter Typ wird zu `text`. |
| `label` | `string` | nein | Feld-Handle | Label im Editor, läuft durch `__()`. |
| `min`, `max` | `int` | nein | – | Nur für `number`. Grenzen für den Wert, beide inklusive. |
| `options` | `array` \| `string` | nein | – | Nur für `select` und `multiselect`. Siehe [Optionen](#optionen). |

### Optionen

`options` akzeptiert dieselben Formen wie die [Zuweisungskriterien](assignment-criteria.md):

- eine Map `value => label`, zum Beispiel `['mail' => 'E-Mail', 'wire' => 'Ticker']`,
- eine Liste `[['value' => …, 'label' => …], …]`,
- einen der Strings `groups`, `roles`, `sites` oder `taxonomy:<handle>`. Die Optionen werden dann aus Statamic geladen.

## Der Handler

Ein Handler implementiert ein Interface mit einer Methode:

```php
namespace TakepartMedia\Workflows\Contracts;

use TakepartMedia\Workflows\Workflow\ActionContext;

interface WorkflowAction
{
    public function handle(ActionContext $context): void;
}
```

Das Addon löst die Klasse über den Service-Container auf (`app($class)`). Constructor-Injection funktioniert also wie gewohnt.

### Kontext-Objekt

`TakepartMedia\Workflows\Workflow\ActionContext` beschreibt den Moment, in dem der Handler läuft. Alle Eigenschaften sind `readonly`.

| Eigenschaft | Typ | Beschreibung |
| --- | --- | --- |
| `entry` | `Statamic\Contracts\Entries\Entry` | Der Eintrag nach der Transition, frisch geladen. |
| `workflow` | `string` | Name des Workflows. |
| `scope` | `string` | `transition` oder `place`. |
| `handle` | `string` | Handle der Transition (Scope `transition`) bzw. des Ziel-Places (Scope `place`). |
| `from` | `string` | Place, aus dem der Eintrag kam. |
| `to` | `string` | Place, in dem der Eintrag jetzt steht. |
| `values` | `array<string, mixed>` | Die Feldwerte der Action, bereits in den deklarierten Typ umgewandelt. |
| `actorId` | `?string` | ID des Benutzers, der die Transition ausgelöst hat. `null`, wenn der Scheduler sie ausführt. |

Dazu kommt `value(string $key, mixed $default = null): mixed` als Kurzform für `$context->values[$key] ?? $default`.

### Feldwerte

Gespeicherte Werte werden beim Lesen in den deklarierten Typ umgewandelt:

| Feldtyp | Wert im Handler |
| --- | --- |
| `number` | `int` |
| `text`, `select` | `string` (Zahlen werden zu Strings) |
| `multiselect` | Liste eindeutiger Strings |

Nicht deklarierte Schlüssel und unbrauchbare Werte fallen weg. Fehlt ein Wert, liefert `value()` deinen Default.

## Im Editor

Der Graph-Editor zeigt pro Transition bzw. Place nur die Actions, deren `scope` passt und die für den bearbeiteten Workflow erlaubt sind. Die Felder erscheinen unter der Action.

Beim Speichern prüft das Addon die YAML. Bei einem Fehler wird der Workflow nicht gespeichert:

| Fehler-Code | Ursache |
| --- | --- |
| `transition_action_type_unknown`, `place_action_type_unknown` | Der `type` ist weder eingebaut noch für diesen Workflow und Scope deklariert. |
| `action_values_not_map` | `values` ist keine Map. `values` darf auch fehlen. |
| `action_value_undeclared` | Ein Schlüssel in `values` ist kein deklariertes Feld. Die Meldung listet die deklarierten Felder. |
| `action_value_invalid` | Der Wert passt nicht zum Feldtyp: `number` muss numerisch sein und innerhalb von `min`/`max` liegen, `multiselect` ein Array, `text`/`select` ein String oder eine Zahl. |

So sieht eine gespeicherte Action in der YAML aus:

```yaml
transitions:
  publish:
    metadata:
      actions:
        - type: press_release
          values:
            channel: mail
            lead_time: 14
```

## Ablauf

Die Handler laufen ganz am Ende einer Transition. Zu diesem Zeitpunkt sind Status, Published-Flag, Zuweisungen, History-Eintrag und Revision bereits gespeichert. Auch die Schritt-Mail und die Account-Erinnerung sind schon eingereiht.

1. Zuerst laufen die `transition`-Actions der ausgeführten Transition.
2. Danach laufen die `place`-Actions des Ziel-Places.

Innerhalb eines Scopes gilt die Reihenfolge aus der YAML, also die Reihenfolge im Editor. Actions ohne Handler werden übersprungen.

Jeder Handler läuft isoliert. Wirft er eine Exception, protokolliert das Addon sie mit der Meldung `Workflow action handler failed.` und den Angaben `workflow`, `scope`, `handle`, `action` und `exception`. Der nächste Handler läuft trotzdem, und die Transition gilt als erfolgreich.

Speichert ein Handler den Eintrag, entsteht kein zusätzlicher `draft_save`-Eintrag in der History.

## Beispiele

### Pressemitteilung mit Feldern

Eine Transition-Action mit Kanal, Vorlauf und Verteiler, nur im Workflow `postmanager`:

```php
// config/workflows.php
'actions' => [
    'press_release' => [
        'label' => 'Pressemitteilung vorbereiten',
        'scope' => 'transition',
        'workflows' => ['postmanager'],
        'handler' => \App\Workflows\PressRelease::class,
        'fields' => [
            'channel' => [
                'type' => 'select',
                'label' => 'Kanal',
                'options' => ['mail' => 'E-Mail', 'wire' => 'Ticker'],
            ],
            'lead_time' => [
                'type' => 'number',
                'label' => 'Vorlauf (Tage)',
                'min' => 1,
                'max' => 90,
            ],
            'verteiler' => [
                'type' => 'multiselect',
                'label' => 'Verteiler',
                'options' => 'groups',
            ],
        ],
    ],
],
```

```php
<?php

namespace App\Workflows;

use App\Press\PressDesk;
use TakepartMedia\Workflows\Contracts\WorkflowAction;
use TakepartMedia\Workflows\Workflow\ActionContext;

class PressRelease implements WorkflowAction
{
    public function __construct(private PressDesk $desk) {}

    public function handle(ActionContext $context): void
    {
        $this->desk->prepare(
            entry: $context->entry,
            channel: $context->value('channel', 'mail'),
            leadDays: $context->value('lead_time', 7),
            groups: $context->value('verteiler', []),
            actorId: $context->actorId, // null, wenn der Scheduler die Transition ausführt
        );
    }
}
```

### Markierung an einem Place

Eine Action ohne Handler markiert einen Place. Dein Code liest die Markierung selbst aus:

```php
// config/workflows.php
'actions' => [
    'legally_reviewed' => [
        'label' => 'Juristisch geprüft',
        'scope' => 'place',
    ],
],
```

```yaml
places:
  review:
    metadata:
      actions:
        - type: legally_reviewed
```

Zum Auslesen gibt es zwei Methoden. Beide liefern eine Liste von `['type' => …, 'values' => […]]` in YAML-Reihenfolge und enthalten nur deklarierte Handles:

```php
use TakepartMedia\Workflows\Workflow\PlaceActions;
use TakepartMedia\Workflows\Workflow\TransitionActions;

$placeActions = PlaceActions::custom($definition['places']['review']);
$transitionActions = TransitionActions::custom($definition['transitions']['publish']);

$reviewed = collect($placeActions)->contains('type', 'legally_reviewed');
```

## Hinweise & Grenzen

> [!WARNING]
> Ein Handler kann eine Transition nicht verhindern. Er läuft erst danach, und eine Exception macht nichts rückgängig. Was „nein“ sagen muss, gehört in die Workflow-Definition, etwa `required_group` oder `target_missing`.

> [!WARNING]
> `actorId` ist `null`, wenn der Scheduler die Transition ausführt (`TransitionAgedPostsCommand`). Prüfe das, bevor du einen Benutzer lädst.

> [!WARNING]
> Ungültige Deklarationen werden verworfen, nicht repariert. Das betrifft ein fehlendes `label`, ein fehlendes oder ungültiges `scope`, einen reservierten Handle und eine `handler`-Klasse, die fehlt oder den Contract nicht implementiert. Die Action verschwindet aus dem Editor, und jede YAML mit diesem Handle lässt sich nicht mehr speichern. Bereits gespeicherte Workflows laufen weiter, ignorieren die Action aber.

> [!NOTE]
> Reservierte Handles sind `user_assignment`, `target_missing`, `start_localization`, `plain_language_resubmit`, `mail`, `account_reminder`, `pending_rewrite_spinner`, `refresh_interval`, `reminder`, `auto_transition`, `site_modal`, `user_modal` und `notify_assignees`. Eingebaute Typen gewinnen jede Kollision.

> [!TIP]
> `select`-Werte werden beim Speichern nicht gegen `options` geprüft, nur gegen den Typ. Wenn dein Code gültige Werte braucht, prüfe sie im Handler.

> [!NOTE]
> `workflows` wirkt nur im Editor und beim Speichern. Zur Laufzeit prüft das Addon nur, ob der Handle überhaupt deklariert ist.

> [!NOTE]
> Config-Änderungen wirken sofort. Mit `php artisan config:cache` wirken sie erst nach erneutem Cachen.

## Fehlerbehebung

| Symptom | Ursache und Lösung |
| --- | --- |
| Die Action erscheint nicht im Editor. | `label` oder `scope` fehlt, der Handle ist reserviert, oder die `handler`-Klasse fehlt bzw. implementiert `WorkflowAction` nicht. Prüfe auch `workflows` und den Config-Cache. |
| Der Workflow lässt sich nicht speichern (`…_action_type_unknown`). | Die YAML nennt einen Handle, der verworfen wurde oder für diesen Workflow bzw. Scope nicht deklariert ist. |
| Der Handler läuft nicht. | Die Action hängt am falschen Scope, oder der Handler wirft. Suche im Log nach `Workflow action handler failed.`. |

## Siehe auch

- [Eigene Mail-Typen](mail-types.md)
- [Zuweisungskriterien](assignment-criteria.md) – dieselben `options`-Formen
- [DOCS.md](../../../DOCS.md), Abschnitt „Eigene Action-Typen aus dem Projekt“
- [config/workflows.php](../../../config/workflows.php) – Kommentarblock über `actions`
- [src/Contracts/WorkflowAction.php](../../../src/Contracts/WorkflowAction.php)
- [src/Workflow/ActionContext.php](../../../src/Workflow/ActionContext.php)
- [src/Workflow/CustomActions.php](../../../src/Workflow/CustomActions.php)

---

[Übersicht](README.md) · [Eigene Mail-Typen](mail-types.md) →
