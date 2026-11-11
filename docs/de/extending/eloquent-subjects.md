# Eloquent-Models als Subjekte

🇬🇧 [English](../../en/extending/eloquent-subjects.md)

Ein Workflow kann statt Einträgen auch Eloquent-Models steuern, sofern das Model eine [Runway](https://runway.duncanmcclean.com)-Ressource ist. Zustand, Zuständige, Kommentare, Verlauf, Mails und Dashboard-Widgets funktionieren wie gewohnt. Site-Versionen, Übersetzung, Inhaltsvorschau, Revisionen und Content-Alterung gibt es nur für Einträge.

## Workflow an eine Ressource binden

Trage die Runway-Handles unter `runway:` ein, statt oder neben `collections:`:

```yaml
name: review
runway: [ticket]
metadata:
  state_attribute: status   # optional: Place zusätzlich in dieses Model-Attribut schreiben
places: { … }
transitions: { … }
```

Die Subjekt-Id eines Models ist Runways Referenz `runway::<resource>::<key>`. Mit dem Feld `workflow_actions` im Blueprint der Ressource erscheint das Workflow-Panel im Bearbeitungsformular. Statt der Site-Prüfung greifen Runways Rechte `view <resource>` und `edit <resource>`.

## Place im Model-Attribut halten

Mit `metadata.state_attribute` schreibt jede Zustandsänderung die Place auch in dieses Attribut, egal ob durch eine Transition, eine manuelle Korrektur oder einen Befehl. Das passiert in derselben Datenbanktransaktion wie die Zustandszeile. Lehnt das Model das Speichern ab, wird auch der Zustand zurückgerollt.

Dafür müssen Workflow-Tabellen und Model dieselbe Verbindung nutzen. Das stellst du in `config/workflows.php` ein:

```php
'connection' => env('WORKFLOWS_DB_CONNECTION'), // z. B. 'mysql'
```

`workflow` ist dann genau diese Verbindung, `database` wird ignoriert.

## Transitionen verhindern: Guards

Ein Guard wird wie eine [Aktion](actions.md) deklariert, mit einer `guard`-Klasse statt oder neben einem `handler`:

```php
'actions' => [
    'four_eyes_check' => [
        'label' => 'Vier-Augen-Prüfung',
        'scope' => 'transition',
        'guard' => \App\Workflows\FourEyesCheck::class,
    ],
],
```

Die Klasse implementiert `Contracts\TransitionGuard::check(ActionContext): ?string` und gibt einen Grund zurück oder `null`. Hänge die Aktion im Editor oder im YAML an eine Transition. Der Guard läuft, bevor etwas geschrieben wird: Der Button ist dann deaktiviert und zeigt den Grund als Tooltip. `WorkflowTransitioner::apply()` lehnt mit `TransitionBlocked` ab, für jeden Aufrufer, auch für Befehle. `check()` darf nichts schreiben.

## In der Transaktion reagieren: Events

| Event | Wann | Hinweis |
| --- | --- | --- |
| `Events\TransitionApplying` | Guards bestanden, Zustandszeile gesperrt, noch nichts geschrieben | Läuft in der Transaktion. Hier gehört die fachliche Arbeit des Schritts hin (Freigabe eintragen, Lauf abschließen); die danach geschriebene Place passt dann dazu. Wirft der Listener, bricht der Schritt ab. |
| `Events\TransitionApplied` | Nach Zustand, Zuständigen und Verlauf, vor Mails und Action-Handlern | Läuft in der Transaktion. Ein Listener kann eigene Datensätze im selben Commit schreiben; wirft er, wird der Schritt zurückgerollt. Mit `TransitionBlocked` erscheint der Grund im CP. |
| `Events\AssigneesChanged` | Immer, wenn die Zuständigen gesetzt werden | Enthält neue und bisherige User-Ids. |

`ActionContext::$entry` und `MailContext::$entry` enthalten bei Models ein `EloquentSubject` mit `id()`, `value()`, `get()`, `title()`, `model()`, `resourceHandle()` und `editUrl()`.
