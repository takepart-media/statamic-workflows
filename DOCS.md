# Workflow Addon — Vollständige Dokumentation

`addons/takepart-media/workflows`

Dieses Addon ist die **redaktionelle Workflow-Engine + CP-Dashboard** für das
Multi-Site-Statamic-Projekt. Es baut auf `symfony/workflow` auf, wird über
committete YAML-Dateien konfiguriert und ersetzt für nicht-privilegierte Nutzer
den nativen „Veröffentlichen"-Flow.

---

## 1. Big Picture

Das Addon macht im Kern vier Dinge:

1. **Zustands-Engine für Statamic-Entries.** Jede Localization (Site-Version) eines
   Entries in einer *governed* Collection hat einen persistierten Workflow-Zustand
   (Place). Übergänge (Transitions) verändern diesen Zustand.
2. **Publish-Gate.** Regelmäßige Nutzer dürfen Entries nicht mehr direkt
   veröffentlichen — der Zustand muss dafür in einem als `allow_publish`
   markierten Place stehen. Erreicht wird das durch eine Overriding-Policy und
   das Verstecken des nativen Publish-Buttons im Entry-Editor. Versteckt wird nur,
   wenn **drei** Dinge zutreffen: der Workflow steuert das Veröffentlichen
   überhaupt (`gates_publishing`, also mindestens ein `allow_publish`-Place), der
   aktuelle Place erlaubt es nicht, und der Nutzer ist nicht privilegiert. Ein
   Lokalisierungs-Workflow hat keinen Publish-Place und mischt sich deshalb nicht
   ein — dort bleibt Statamics Knopf stehen, sonst käme eine fertige Übersetzung
   überhaupt nicht live (`syncPublishedFlag()` setzt das Flag dort ebenfalls nie).
3. **CP-Manager (Inertia-Vue).** Pro Workflow gibt es einen eigenen Manager
   unter `/cp/workflows/<slug>` (z. B. „Beitragsmanager", „Übersetzungsmanager")
   mit Queue, Filtern, „Meine Warteschlange", Detailseite (Content-Preview,
   Verlauf, Diff, Kommentare, Aktionen). Einen eigenen Nav-Eintrag haben die
   Manager nicht mehr: Einstieg ist das Dashboard-Widget `workflows_overview`
   (§ 9), dessen Workflow-Spalte auf die Manager verlinkt.
4. **Revision-Loop.** Für Collections mit Statamic-Revisions bewegt sich der
   Entry automatisch zwischen dem Publish-Place und einem injizierten
   `pending_revision`-Place — genau passend zu Statamics Working-Copy-Lebenszyklus.

### Datenhaltung

Alle Workflow-Daten liegen in einer **eigenen SQLite-Datei**
(`database/workflow.sqlite`), unabhängig von der App-DB. Die Connection heißt
`workflow` und wird im ServiceProvider dynamisch registriert.

Vier Tabellen (alle in dieser Connection):

| Tabelle | Zweck |
| --- | --- |
| `workflow_states` | Aktueller Place pro (subject_id, workflow). Unique. |
| `workflow_events` | Append-only Log: Transitionen, Set-State, Anlegen, Localize. Mit `data_snapshot` (JSON der Entry-Felder) für den Diff-View. |
| `workflow_assignees` | Zuweisungen pro (subject_id, workflow, user_id). |
| `workflow_comments` + `workflow_comment_reads` | Kommentare pro Passage im Content sowie „gelesen bis"-Marker pro Nutzer. |

`subject_id` = **Statamic-Entry-ID einer Localization** — nicht die logische Post-ID.
Jede Sprach-/Site-Version hat ihren eigenen State und ihre eigenen Assignees.

---

## 2. Konfiguration: die Workflow-YAML

Datei: `resources/workflows/<name>.yaml` (im Projekt) oder als Fallback im Addon
selbst (`addons/.../workflows/resources/workflows/<name>.yaml`).

Die YAML ist eine Obermenge der Symfony-Workflow-Konfiguration. Zusätzliche
addon-eigene Keys werden entweder als Metadaten mitgeführt oder bewusst ignoriert.

```yaml
name: post_update
type: state_machine
marking_store: { type: method, property: current_state }
collections: [posts]              # governed collections
initial_place: idea
active: true                      # optional; false schaltet den Workflow ab
                                  # (fehlt der Key, gilt er als aktiv)

metadata:
  label: 'Post Update with Medical Sign-off'
  sla:                            # Alterung: nach X Tagen in welchen eigenen Schritt
    needs_update_days: 365
    needs_update_place: needs_update
  nav:                            # Manager-Seite + Eintrag unter Settings › Workflows
    label: 'Beitragsmanager'      # optionaler Override; leer lassen, damit
                                  # workflows::messages.nav.<slug> greift und der
                                  # Titel der CP-Sprache folgt (so machen es die
                                  # mitgelieferten Beispiele)
    slug: post-manager            # URL: /cp/workflows/post-manager
    icon: media-news-paper
    order: 1
  default_assignee_group: redaktion   # Auto-Assignment bei „Neuer Beitrag"
  assign_creator: true                # Ersteller wird zusätzlich assigned
  history_fields: [title, image, intro, content_sets]  # Whitelist für den Diff-View
  detail: translation             # optional: Lokalisierungs-Workflow + Detailansicht
  translation_source_site: simple # optional: Quelle der Übersetzung; leer = Ursprung
  plain_language_site: simple     # macht daraus den Einfache-Sprache-Manager

places:
  idea:
    label: 'Idea'
    metadata:
      required_group: redaktion
  published:
    label: 'Published'
    metadata:
      required_group: redaktion
      allow_publish: true         # <-- entsperrt die Veröffentlichung

transitions:
  publish:
    from: korrektorat
    to: published
    metadata:
      label: 'Publish'
      required_group: redaktion   # zusätzlich zu Assignee erforderlich
      actions:                    # Schritte um die Transition herum
        - type: user_assignment
          mode: manual            # manual | automatic
          match: all              # all | any
          criteria:
            - { field: group, operator: is, value: themenverantwortliche }
        - type: mail              # mailt die Zugewiesenen, NACH der Transition
          mail_type: assigned     # welche Mail (weglassen = assigned)
```

### Metadaten-Referenz

**Auf Place-Ebene** (`places.<name>.metadata`)

| Key | Wirkung |
| --- | --- |
| `allow_publish: true` | Entry wird beim Eintritt veröffentlicht (`published(true)`), Publish-Gate geöffnet. |
| ~~`required_group`~~ | **Entfallen.** Hat nie eine Transition gegated — das tut nur der gleichnamige Schlüssel an der **Transition**. Der Settings-Editor bietet ihn nicht mehr an und entfernt ihn beim nächsten Speichern; bestehende YAMLs bleiben gültig, der Schlüssel ist wirkungslos. Ersatz: Gate → an die Transition, Zuweisung → `user_assignment`-Action. Das gilt auch für die Alterungsstufen: sie sind gewöhnliche Places mit gewöhnlichen Transitions und haben deshalb keine eigene Sprache mehr (§ 15). |
| ~~`assign_group: <handle>`~~ | **Entfallen.** Der Writer lehnt den Schlüssel ab. Eine Transition weist ausschließlich über ihre `user_assignment`-Action zu — siehe „Zuweisung passiert nur noch, wo es dransteht" unten. |
| `revision_pending: true` | Interner Marker (wird automatisch für den `pending_revision`-Place gesetzt — bitte selbst nicht setzen). |
| `branch: true` | **Nur Darstellung, kein Verhalten.** Der Place ist keine Stufe auf dem Weg, sondern ein Abstecher davon (Fehlerfall, Parkplatz). Die Fortschrittsleiste im Summary-Header zeichnet ihn versetzt am vorherigen Schritt und **nur dann, wenn eine Fassung tatsächlich darin steht**; die Hauptlinie bleibt unverändert. Im Settings-Editor als Checkbox „Abzweigung". Auf dem Start-Place abgelehnt — jede neue Fassung stünde sofort neben dem Weg. Siehe `Workflow\ProgressSteps`. |
| `actions: [...]` | Verhalten, das gilt, solange eine Fassung hier steht — kein Klick-Moment wie bei Transitions, deshalb eine eigene, kleinere Typ-Menge. Im Settings-Editor pflegbar (Place-Karte → „Actions"). Siehe die Place-Typen-Tabelle unten. |

**Place-Action-Typen** (`places.<name>.metadata.actions[]`)

| `type` | Wirkung |
| --- | --- |
| `pending_rewrite_spinner` | Solange eine Fassung in **diesem** Place steht UND für sie ein **offener** Einfache-Sprache-Auftrag existiert (`PlainLanguageJobStore::hasOpen()`), zeigt ihre Detailseite einen Spinner statt der Content-Blöcke — die wären sonst entweder die gerade kopierte Quelle oder ein überholter Rewrite, nie die Antwort, die noch unterwegs ist. Geprüft wird pro Beitrag **und** Place (`WorkflowResolver::showsPendingRewriteSpinner()`), nicht pro Transition — ein Beitrag in einem anderen Place zeigt den Spinner nie, egal wie lange sein Auftrag noch offen ist. Nur zulässig in einem Workflow mit `plain_language_site` — ohne den kann `workflow_plain_language_jobs` für ihn ohnehin keine Zeile haben. Keine Konfiguration. |
| `refresh_interval` | Allgemein, nicht an den Einfache-Sprache-Dienst gekoppelt: Der Client wartet nach dem Laden der Detailseite `minutes` Minuten, fragt dann `WorkflowController::instanceStatus()` (Status + `Entry::lastModified()`-Fingerabdruck) ab und zeigt **nur** bei einer Abweichung vom beim Laden erfassten Stand ein Bestätigungs-Modal („Neu laden?"). Ohne Abweichung läuft der Timer stillschweigend erneut los — kein Modal, kein Reload. `minutes` ist Pflicht, ganzzahlig, 1–1440. |
| `mail` | **Dieselbe Action wie an einer Transition** — gleiches Handle, gleiche Mail-Typen, ein Label im Editor (`Workflow\MailAction`). Was der Scope ändert, ist allein der Auslöser: hier ist es die **ANKUNFT**, nicht der Klick. Sobald eine Fassung in diesem Place ankommt, bekommt jeder, der ihr dann zugewiesen ist, eine Mail — auch wenn niemand geklickt hat. Genau dafür gibt es die Place-Variante: `PollPlainLanguageCommand` setzt eine fertige Einfache-Sprache-Fassung per `WorkflowStateStore::set()` direkt in `metadata.plain_language_completed_place`, ohne Transition — an einer Transition hinge die Mail dort ins Leere. Konfiguration: `mail_type: <typ>` (weglassen = `assigned`), siehe Mail-Typen unten. Höchstens **eine** je Place. Feuert **nicht** beim manuellen Setzen des Status über `WorkflowController::setState()` — das ist ein Eingriff, kein Schritt. Brachte die Transition, die hierher führte, selbst schon eine Mail mit, entfällt diese hier (siehe Doppelmail-Regel unten). |
| `reminder` | Erinnerung **alle X Tage**, solange eine Fassung in diesem Place steht: Jeder, der ihr zugewiesen ist, bekommt `every_days` Tage nach der Ankunft eine Mail und danach alle `every_days` Tage wieder, unbegrenzt, bis sie weiterzieht. Konfiguration: `every_days` (Pflicht, ganzzahlig, 1–365), `mail_type` (weglassen = `reminder`, Built-in-Typ mit `:days` im Intro). Höchstens **eine** je Place. Verschickt von `workflows:send-reminders` (`Console\SendPlaceRemindersCommand`), täglich um `config('workflows.reminder_time')` (Default 08:00), abschaltbar über `workflows.reminder_scheduler`. Die Uhr: `workflow_states.entered_at` (setzt `WorkflowStateStore::set()` nur bei echtem Wechsel, ein Self-Loop startet sie nicht neu) und das neueste `reminder_sent`-Event mit `from_state = <place>`. Der spätere Zeitpunkt gewinnt. Ohne Assignees geht weder eine Mail raus noch wird ein Event geschrieben. `reminder_sent` schreibt bewusst **kein** `to_state` und zählt nicht als „letzte Aktivität". `--dry-run` und `--workflow=` wie beim SLA-Command. |
| `auto_transition` | Führt eine der **eigenen ausgehenden Transitionen** dieses Place selbst aus, wenn eine Fassung am Stichtag noch hier steht. Der Stichtag ist der Ankunftstag (`workflow_states.entered_at`) plus `after_days`, als **Datum**. Konfiguration: `after_days` (1–365), `transition` (muss in diesem Place starten und darf keine System-Transition sein). Höchstens **eine** je Place. Ausgeführt von `workflows:auto-transition` (`Console\AutoTransitionCommand`) über `WorkflowTransitioner::apply()`, ohne Actor und mit History-Notiz: Zuweisung und Mail der Transition laufen wie bei einem Klick. Geplant zur selben Zeit wie `workflows:send-reminders`, aber **davor** registriert, damit ein weitergezogener Beitrag keine Erinnerung mehr für den alten Status bekommt. Mails in einem solchen Status zeigen statt der Erinnerungszeile ein Panel mit Stichtag und den möglichen Wegen hinaus (`WorkflowNotification::autoTransition()`). |

Aufgelöst wird das von `Workflow\PlaceActions` — dem Place-seitigen Gegenstück zu
`TransitionActions`, mit bewusst eigener, kleinerer Typ-Menge: Places haben
keinen Klick-Moment, also gibt es kein Dialog-Konzept und kein Limit wie bei
`start_localization`. Ein Place darf alle Typen gleichzeitig tragen (Spinner
**und** Reminder **und** Benachrichtigung), aber jeden Typ nur einmal.

**Auto-Transition zurücknehmen.** `php artisan workflows:undo-auto-transition <entry-id>` (`Console\UndoAutoTransitionCommand`) stellt den Place vor der letzten automatischen Transition wieder her. Gefunden wird sie über die History-Notiz. Der Command gleicht den Published-Flag an und schreibt eine `set_state`-Zeile. `entered_at` startet neu, mit `--days-ago=N` wird es zurückdatiert (zum Testen). Mit `--to=<place>` wird der Place explizit gesetzt, etwa für Läufe ohne History-Zeile. `--workflow=` braucht es nur, wenn der Eintrag in mehreren Workflows steht. `--dry-run` zeigt nur an. Zuweisungen werden **nicht** zurückgestellt, weil die alten nirgends gespeichert sind.

Zwei Arten von Aussage stecken in dieser Tabelle, und der Unterschied ist der
Grund, warum die Benachrichtigung überhaupt hierher gehört:
`pending_rewrite_spinner` und `refresh_interval` sagen **solange** eine Fassung
hier steht, `mail` sagt **wenn** eine hier ankommt. Der
Ankunftsmoment wird an genau zwei Stellen ausgelöst —
`WorkflowTransitioner::apply()` und
`PollPlainLanguageCommand::moveToCompletedPlace()` —, beide über
`WorkflowNotifier::notifyPlaceArrival()`, damit sie nicht auseinanderlaufen
können.

#### Zuweisung passiert nur noch, wo es dransteht

**Ein Place weist niemanden mehr zu.** Früher übergab jede Transition den Beitrag
beim Eintritt an die Gruppe des Ziel-Places (`assign_group`, ersatzweise die erste
`required_group`). Das ist entfallen: eine Transition ändert die Verantwortlichen
**ausschließlich** über ihre `user_assignment`-Action. Trägt sie keine, bleiben
sie unverändert.

Der Grund ist, dass beides gleichzeitig nicht widerspruchsfrei war. Eine Action,
die den Beitrag an zwei namentlich passende Personen übergibt, wurde von der
Place-Regel des nächsten Schritts wieder auf eine ganze Gruppe verbreitert — das
letzte Wort hatte der Place, nicht die Regel, die man dafür geschrieben hatte.
Jetzt ist „wer ist zuständig" allein aus den Transitions ablesbar.

**Was das kostet, und zwar sofort:** `canApplyTransition()` verlangt von jedem
nicht-privilegierten Nutzer, Assignee zu sein. Ein Workflow, dessen Transitions
keine einzige `user_assignment` deklarieren, bleibt darum für immer bei dem, was
`initialAssignees()` beim Anlegen gesetzt hat — nachgelagerte Gruppen kommen an
den Beitrag nicht mehr heran. **Die Übergabe muss in der Definition stehen.** Wo
früher ein Place `required_group: medical_writer` trug und das die Zuweisung
miterledigte, gehört jetzt eine `user_assignment` an die Transition, die dorthin
führt.

**`required_group` gibt es nur noch an der Transition.** Dort ist es das
Berechtigungs-Gate: `canApplyTransition()` liest es, und wer nicht drin ist, kann
den Schritt nicht ausführen.

Am **Place** war es nie ein Gate — kein Codepfad hat es je gegen den handelnden
Nutzer geprüft. Nachweisbar an einem Nutzer, der Assignee und Mitglied der
Place-Gruppe ist, aber nicht der Transition-Gruppe: `canApplyTransition()` sagt
`false`. Gelesen wurde es nur an drei Stellen, und alle drei sind weg:

| Was es tat | Ersatz |
| --- | --- |
| Zuweisung nach jeder Transition | `user_assignment`-Action an der Transition |
| Zuweisung bei „Status setzen" | nichts — der Handeingriff weist nicht mehr zu, der Assignee-Drawer steht daneben |
| Quelle der SLA-Injektion | **entfallen** — die Alterungsstufen sind selbst gepflegte Places und Transitions (§ 15) |

Der Settings-Editor zeigt den Place-Schlüssel nicht mehr und entfernt ihn beim
nächsten Speichern. Bestehende YAMLs bleiben gültig — der Schlüssel wird schlicht
ignoriert, statt einen Fehler zu werfen, der jede noch nicht neu gespeicherte
Definition unspeicherbar machte.

Zwei automatische Zuweisungen gibt es weiterhin, beide außerhalb von Transitions:
`initialAssignees()` beim Anlegen (`default_assignee_group` + `assign_creator`)
und `syncPlaceAssignees()` beim privilegierten Handeingriff „Status setzen"
(`setState()`), der keine Transition ist und deshalb keine Action tragen kann.

**Auf Transition-Ebene** (`transitions.<name>.metadata`)

| Key | Wirkung |
| --- | --- |
| `label: <string>` | Menschlicher Button-Text (Fallback, wenn keine Übersetzung existiert). |
| `required_group: <handle>` oder Liste | Ausführender Nutzer muss zusätzlich zur Assignee-Bedingung in einer dieser Gruppen sein. |
| `required_role: <handle>` oder Liste | Dasselbe über Statamic-Rollen (`hasRole`). Mit `required_group` eine **Vereinigung**: eine Gruppe ODER eine Rolle genügt — ein Schritt, der „der Fachgruppe oder jedem mit Prüfer-Rolle" offensteht, ist ein Publikum, zweifach beschrieben. Beide weglassen = offen für jeden Assignee. |

**Im Settings-Editor ist das ein Feld, nicht zwei.** Die Transition-Karte zeigt
„Ausführen darf" mit den Optionen **Alle Zuständigen / Gruppe / Rolle** und
daneben — außer bei „Alle Zuständigen" — die passende Handle-Liste. Gespeichert
wird daraus je Transition genau **einer** der beiden Schlüssel; ein Wechsel des
Typs leert die Auswahl, weil ein Gruppen-Handle in der Rollenliste nichts
bedeutet.

Die Vereinigung bleibt für handgeschriebene YAMLs lesbar. Trägt eine Definition
beide Schlüssel, zeigt der Editor die **Rolle** (die speziellere Aussage) — und
beim nächsten Speichern bleibt nur sie übrig. Wer die Vereinigung braucht, pflegt
die Transition in der YAML.

„Alle Zuständigen" ist kein Loch, sondern die Aussage, dass die **Zuweisung
allein** entscheidet: wer den Beitrag hat, darf den Schritt gehen. Sinnvoll
überall dort, wo eine `user_assignment` den Beitrag ohnehin schon an genau die
richtigen Leute übergeben hat — siehe „Zuweisung passiert nur noch, wo es
dransteht" oben. Sobald aus einem Place aber **mehrere** Wege herausführen, ist
das Gate das Einzige, was sie auseinanderhält: es gibt einen Zuständigen-Satz,
aber zwei Publika.
| `actions: [...]` | Schritte um die Transition herum — die meisten zwischen Klick und Vollzug, `mail` danach. Im Settings-Editor pflegbar (Transition-Karte → „Actions"). Siehe die Typen-Tabelle unten. |
| `system: true` | Transition erscheint **nicht** als Button; ist rein für die Diagramm-Vollständigkeit bzw. wird automatisch getrieben (z. B. `mark_pending`, `publish_revision`). |

**Action-Typen** (`transitions.<name>.metadata.actions[]`)

| `type` | Wirkung |
| --- | --- |
| `user_assignment` | Wer den Beitrag als Nächstes übernimmt. `mode: manual` öffnet einen Auswahl-Dialog (**mehrfach wählbar**), `mode: automatic` weist **alle** Treffer ohne Dialog zu. Wer überhaupt infrage kommt, sagen in **beiden** Fällen dieselben `criteria`, verknüpft über `match: all` (alle Bedingungen) oder `match: any` (eine genügt); ein Eintrag darf eine **Gruppe** mit eigenem `match` sein, womit UND und ODER gemischt sagbar sind — siehe „Zuweisungs-Kriterien" unten. Die Gewählten bzw. Getroffenen werden zu den Assignees des Ziel-Places. Höchstens **eine** je Transition. |
| `target_missing` | **Kein Dialog, sondern eine stille Sperre.** Blockiert die Transition (`can_apply: false`, mit Begründung), solange der Beitrag bereits eine Version in `site` hat — root-basiert geprüft (`WorkflowResolver::groupLocales()`), gilt also unabhängig davon, welche Fassung angeklickt wurde. Fehlt die Version noch, tut die Action nichts: sie sperrt nur, sie erlaubt nie etwas, was sonst gesperrt wäre. `site` ist Pflicht und wird wie `plain_language_site` gegen die echte Site-Liste geprüft. |
| `start_localization` | **Keine Konfiguration, nur eine Markierung.** UI-Label „Erzeugt die Ziel-Fassung (mit Sprachauswahl)". Benennt die Transition, die die Ziel-Version erzeugt. In einem Lokalisierungs-Workflow (`metadata.detail: translation`) **Pflicht**, genau einmal — beides prüft der Writer; außerhalb abgelehnt. **Im Übersetzungsmanager sitzt die Sprachauswahl an genau dieser Transition** und an keiner anderen. Siehe „Die Sprachauswahl ist nicht konfigurierbar — aber WELCHE Transition sie trägt, ist es" unten. |
| `plain_language_resubmit` | **Keine Konfiguration, aber kein Zähl-Limit.** Legt nichts an: schickt den Text der bestehenden Version erneut an den Dienst und überschreibt sie mit dem Ergebnis — der Reparatur-Pfad für einen misslungenen Rewrite. Kann auf mehreren Transitions gleichzeitig stehen (aus der Prüfung, aus der Freigabe). Nur in einem Workflow mit `plain_language_site` (dieselbe Kopplung wie bei `pending_rewrite_spinner`). Im Settings-Editor nur wählbar in dessen Manager, außer auf der Start-Transition (die erzeugt die Version erst). |
| `mail` | **Die einzige eingebaute Action mit einer Wirkung nach außen — und die einzige, die auch an einem Place stehen darf.** Mailt jedem, der dem Beitrag nach der Transition zugewiesen ist, eine Nachricht mit einem Button auf die Workflow-Übersicht des Beitrags (`InstanceUrl`) und — als Fußzeile — den Stufen des Workflows. Läuft **nach** dem Vollzug, kann die Transition also nicht verhindern; ein nicht erreichbarer Mailserver wird protokolliert, der Klick gilt trotzdem. Eine Mail **je Empfänger**, jeweils in dessen CP-Sprache. Höchstens **eine** je Transition. Einzige Konfiguration: `mail_type: <typ>` — WELCHE Mail (siehe Mail-Typen unten); weggelassen heißt `assigned`. Wer stattdessen eine Mail beim Ankommen in einem Status braucht, hängt dieselbe Action an den **Place** — dieselbe Action, anderer Auslöser. Ältere Definitionen schreiben `type: notify_assignees` mit `mail:`; beides wird weiter gelesen und beim nächsten Speichern umgeschrieben (`Workflow\MailAction`). |

Aufgelöst wird das von `Workflow\TransitionActions` — der einzigen Stelle, die die
Schreibweise kennt. **Höchstens eine DIALOG-Action je Transition** (`user_assignment`
im manuellen Modus ist heute der einzige Dialog; `DIALOG_TYPES` nennt die Typen,
`opensDialog()` entscheidet den Einzelfall), sonst lehnt der Writer ab: zwischen
Klick und Transition lässt sich nur ein Dialog zeigen, ein zweiter hätte keinen
Moment, in dem er laufen könnte. `target_missing` öffnet nichts und zählt nicht
gegen dieses Limit — und eine **automatische** Zuweisung ebenso wenig, die läuft
ohne Klick-Moment. Ein unbekannter `type` wird abgelehnt — anders als bei
Kriterien-Werten, denn ein Tippfehler täte sonst einfach nichts und fiele erst im
Betrieb auf.

**`mail` fällt in dieser Liste aus dem Rahmen, und zwar absichtlich.** Jeder
andere eingebaute Typ öffnet etwas VOR der Transition oder entscheidet, ob sie
überhaupt laufen darf; die restlichen zwei sind reine Markierungen, die etwas
anderes ausliest. Dieser eine ist eine **Wirkung** — er passiert danach, und er ist
das Einzige, was dieses Addon von sich aus nach draußen tut. Er ist deshalb auch
der einzige Typ, den beide Scopes tragen: eine Wirkung lässt sich sinnvoll an einen
Schritt ODER an ein Ziel hängen, ein Dialog oder eine Sperre nicht. Drei Folgen
davon:

- Er läuft **nicht** über den Handler-Pfad der projekteigenen Actions.
  `WorkflowTransitioner::runHandlers()` erreicht per Definition nur Deklarationen
  aus `config/workflows.php` (`TransitionActions::custom()` filtert darauf). Ein
  eingebauter Effekt wird von dem Code aufgerufen, dem er gehört — so wie jeder
  andere eingebaute Typ an genau der Stelle GELESEN wird, die sich für ihn
  interessiert. Konkret: `WorkflowTransitioner::apply()`, nach Status, Zuweisung,
  History und Revision, vor den Projekt-Handlern.
- Er kann nichts verhindern. Ein abgelehnter SMTP-Verbindungsaufbau landet im Log;
  der Klick war erfolgreich, weil zu diesem Zeitpunkt alles geschrieben ist.
  Fehler werden **je Empfänger** gefangen — eine falsche Adresse darf die übrigen
  nicht mitnehmen.
- Empfänger sind die Zugewiesenen **nach** der Transition, aus
  `WorkflowAssigneeStore` gelesen. Steht auf derselben Transition auch eine
  `user_assignment`, mailt er also an die NEUEN Zuständigen — die
  Reihenfolge innerhalb von `metadata.actions` spielt dafür keine Rolle, die
  Zuweisung ist längst angewandt, wenn eine der beiden angesehen wird. Ist danach
  niemand zugewiesen, passiert stillschweigend nichts.

**Betriebshinweis, der öfter zuschlägt als jeder Code-Fehler hier:** mit
`MAIL_MAILER=log` (Laravels Standard) gelingt jede Transition, das Log füllt sich
und **niemand bekommt eine Mail**. Genau deshalb steht im Settings-Editor direkt an
der Action, welcher Mailer eingerichtet ist und ob er zustellt — grün heißt „gibt
die Nachricht aus diesem Prozess heraus", nicht „ist korrekt konfiguriert" (ein
falsches SMTP-Passwort sieht von dort identisch aus und fällt erst im Log auf).
Zugangsdaten erreichen den Client nicht, siehe `WorkflowController::mailStatus()`.
Kopf und Fuß der Mail kommen aus Laravels Default-Theme; wer sie ändern will,
published im Hostprojekt `php artisan vendor:publish --tag=laravel-mail`.

**Zeilen aus dem Status.** Jede Mail zeigt unter „Status" das Eintrittsdatum (`$enteredAt`, „Eingegangen am"). Trägt der Status eine `auto_transition`, kommt das Frist-Panel dazu (`$autoTransition`: `heading`, `body`, `date`). Trägt er nur einen `reminder`, erscheint stattdessen die Erinnerungszeile (`$reminder`). Eigene Templates bekommen alle drei Werte ebenfalls, als `null`, wenn es nichts anzuzeigen gibt.

**Versand nach der Antwort.** Mails aus einem Klick (Transition- und Place-`mail`) gehen über `WorkflowNotifier::queue()` als Job `Jobs\SendWorkflowNotification` raus, damit die Transition nicht auf die Mail-API wartet. `config('workflows.mail_delivery')` (`WORKFLOWS_MAIL_DELIVERY`) steuert das:
- `after_response` (Default): direkt nach dem Senden der HTTP-Antwort, im selben PHP-Prozess, ohne Worker.
- `queue`: über `QUEUE_CONNECTION`, braucht `queue:work`, dafür mit Retries.
- `sync`: inline, das alte Verhalten.

Der Job bekommt nur IDs und liest Entry und Definition beim Versand neu. Reminder (`workflows:send-reminders`) senden immer inline über `notify()`, weil der Command die Zahl der verschickten Mails für das `reminder_sent`-Event braucht.

**Doppelmail-Regel.** Trägt eine Transition `mail` UND landet sie in einem Place,
der es ebenfalls trägt, geht **eine** Mail raus, nicht zwei: die der
Transition. Sie ist die genauere Aussage — sie benennt diesen Schritt, während der
Place jeden Weg hinein benennt. Entschieden wird das in
`WorkflowTransitioner::apply()`, der einzigen Stelle, an der sich beide treffen
können; der Poll-Befehl kennt nur den Place-Fall.

#### Mail-Typen (`mail_type:`)

Die `mail`-Action verschickt genau eine von mehreren Mails. Welche, sagt ihr
`mail_type`-Schlüssel — die einzige Einstellung, die sie hat. Mitgeliefert:

| Handle | Mail |
| --- | --- |
| `assigned` | Dir wurde ein Beitrag zugewiesen. Die Voreinstellung — und was eine Mail-Action ohne `mail_type` bedeutet. |
| `plain_language_ready` | Die Einfache Sprache ist fertig. Gedacht für den Place, den ein Workflow als `plain_language_completed_place` nennt. |

**Der billige Fall ist der häufige und bleibt billig.** Ein Typ ist ein Handle plus
ein Block Sprachkeys; eine Mailable (`Mail\WorkflowNotification`) und ein Blade
(`resources/views/mail/notification.blade.php`) bedienen alle. Die Keys heißen
`mail_<handle>_<key>` mit `subject`, `heading`, `intro`, `field_state`,
`field_actor`, `button`, `outro`, `progress`. **Jeder Key, den ein Typ nicht selbst
setzt, wird von `assigned` geerbt** (`Mail\MailTypes::text()`), und zwar pro Locale
einzeln — ein Typ mit eigenem Betreff und eigenem Intro erbt den Rest.

**Drei Regeln für die Texte**, alle drei aus demselben Grund: eine Mail geht an wen
auch immer gerade zugewiesen ist, und das kann jede Rolle im Haus sein.

1. **Keine Anrede, kein Name.** Es gibt deshalb keinen `greeting`-Key. Ein eigenes
   Template bekommt `recipientName` trotzdem, wenn es grüßen will.
2. **Kein Du und kein Sie.** Passiv oder Infinitiv statt Anrede — „ist eingetragen",
   „kann gegengelesen werden", „Beitrag öffnen".
3. **Keine Fachbegriffe der Mechanik.** „Workflow", „Transition", „Place" sagen dem
   Leser nichts. `:workflow` ist ohnehin das Nav-Label des Managers („Einfache
   Sprache") und trägt sich allein.

Deklariert wird ein Typ an **zwei Orten, in einem Format**:

- `Mail\MailTypes::BUILT_IN` für einen Typ, der zu diesem Addon gehört;
- `config/workflows.php → mail_types` für einen, der zu einem Projekt gehört.

Beide Schreibweisen sind erlaubt, weil die zwei Fälle unterschiedlich groß sind:

```php
'mail_types' => [
    'erinnerung',                    // nur andere Wörter, kein PHP
    'pressefreigabe' => [
        'label' => 'Pressefreigabe',                          // optional; sonst mail_type_<handle>
        'mail'  => \App\Workflows\Mail\Pressefreigabe::class,  // optional
        'view'  => 'mail.pressefreigabe',                      // optional
    ],
],
```

##### Eigene Daten und eigenes Template: `Contracts\WorkflowMail`

Für alles, was mehr braucht als andere Wörter — ein Feld vom Beitrag holen, etwas
auflisten, anders layouten — nennt die Deklaration eine Klasse mit **einer**
Methode:

```php
class Pressefreigabe implements WorkflowMail
{
    public function compose(MailContext $c): array
    {
        return [
            'subject'  => __('Freigabe nötig: :t', ['t' => $c->title]),   // sonst mail_<typ>_subject
            'view'     => 'mail.pressefreigabe',                          // sonst die gemeinsame
            'deadline' => $c->entry->value('embargo'),                    // → Variable in der View
            'notes'    => $c->entry->value('press_notes'),
        ];
    }
}
```

`subject` und `view` liest das Addon, **alles andere landet als Variable im
Template**. Die Klasse wird über den Container aufgelöst, Constructor-Injection
funktioniert also.

Was `MailContext` mitbringt: `entry`, `workflow`, `definition` (die rohe YAML),
`mailType`, `scope` (`transition` oder `place`), `handle`, `state` (Place-Handle,
**unaufgelöst**), `recipient`, `title`, `url`, `workflowLabel`, `actorName` — plus
`recipientName()` und `text($key)` für die eigenen Sprachkeys des Typs.

Dazu `actor`: derselbe Mensch wie `actorName`, nur als `Statamic\Contracts\Auth\User`
statt als String — und `null` unter genau denselben Bedingungen (niemand hat
ausgelöst, oder der Account ist gelöscht). Für einen Composer, der mehr über den
Auslöser braucht als seinen Namen; der Anlass war die Funktion, also
`$c->actor?->groups()->map->title()`. Der Notifier löst ihn **einmal pro
Sendevorgang** auf, nicht pro Empfänger — ein Zugriff darauf kostet keine Abfrage.
Wer nur den Namen druckt, nimmt weiter `actorName` und merkt von dem Feld nichts.

Drei Dinge, die man vorher wissen will:

- **`compose()` läuft in der Sprache des EMPFÄNGERS.** `Mailable::send()` legt
  `withLocale($recipientLocale)` um den ganzen Render, und `compose()` wird aus
  `envelope()`/`content()` gerufen. `__()` darin ist also richtig — und ein String,
  der schon vorher übersetzt wurde, kommt in der Sprache des ABSENDERS heraus. Das
  ist der Grund, warum es eine Methode ist und nicht drei.
- **Das Template ist immer ein Markdown-Mailable.** Auch ein eigenes: so bekommt es
  `<x-mail::message>`, `<x-mail::button>`, `<x-mail::panel>` und `<x-mail::subcopy>`
  geschenkt — und dieselben Escaping-Regeln (`html_input => 'escape'`, rohes Markup
  in einem Wert landet als sichtbare spitze Klammern beim Leser).
- **Ein eigenes Template bekommt trotzdem alles Übliche**: `t` (die sieben
  übersetzten Sätze), `stateLabel`, `progress`, `title`, `url`, `actorName`,
  `recipientName`, `workflowLabel`, `context`. Zurückgegebene Schlüssel gewinnen.
  Es kann also behalten, was es mag, und nur ersetzen, wofür es da ist.

Eine Deklaration ist pro Empfänger **einmal** im Einsatz (`$context->recipient`),
personenbezogene Daten sind also erlaubt — und teure Arbeit aus demselben Grund
teuer.

**Verworfen statt repariert:** nennt eine Deklaration eine `mail`-Klasse, die es
nicht gibt oder die den Contract nicht implementiert, fällt der ganze Typ aus
`MailTypes::all()` — und der Writer lehnt danach jede YAML ab, die den Handle nennt
(`writer_transition_action_mail_unknown` / `writer_place_action_mail_unknown`).
Ein Tippfehler verfault so nicht zu einer Mail, die still als die falsche
rausgeht. Eingebaute Handles gewinnen eine Kollision.

Der Handle muss überhaupt deklariert sein, weil sich eine Sprachdatei nicht danach
befragen lässt, welche Typen es gibt, ohne Key-Namen zu erraten.


#### Eigene Action-Typen aus dem Projekt

Die sieben eingebauten Typen (fünf an Transitions, drei an Places — `mail` steht
in beiden Listen, weil es dieselbe Action ist) sind Verhalten,
das in diesem Addon steht. Ein Projekt ergänzt eigene über
`config/workflows.php → actions`, nach demselben Muster wie
`assignment_criteria`:

```php
'actions' => [
    'press_release' => [
        'label' => 'Pressemitteilung vorbereiten',
        'scope' => 'transition',            // transition | place — Pflicht
        'workflows' => ['postmanager'],     // weglassen = alle
        'handler' => \App\Workflows\PressRelease::class,   // optional
        'fields' => [
            'channel' => ['type' => 'select', 'label' => 'Kanal',
                          'options' => ['mail' => 'E-Mail', 'wire' => 'Ticker']],
        ],
    ],
],
```

In der YAML steht danach `{ type: press_release, values: { channel: mail } }`.
`values` ist ein eigener Beutel, damit ein Projektfeld nie mit den Schlüsseln der
eingebauten Typen (`site`, `mode`, `match`, `criteria`, `minutes`) kollidiert.

**Der Handler ist der einzige Punkt, an dem dieses Addon Projektcode aufruft.**
Es feuert sonst keine Events. Verdrahtet wird über den `type`-String: die Config
sagt WELCHE Klasse, die YAML sagt WO (an welcher Transition bzw. welchem Place),
und `WorkflowTransitioner::apply()` verbindet beides zur Laufzeit — erst die
Handler der Transition, dann die des Ziel-Places, jeweils in YAML-Reihenfolge.

Ein Handler läuft, **nachdem** die Transition vollzogen ist, und kann sie deshalb
nicht verhindern; eine Ausnahme wird gefangen und protokolliert, der nächste
Handler läuft trotzdem. Wer sperren will, nimmt `required_group` oder
`target_missing`. Und `$context->actorId` ist `null`, wenn der SLA-Scheduler die
Transition treibt — ein Handler, der einen angemeldeten Nutzer voraussetzt,
scheitert genau dort.

`handler` ist optional: ohne ihn ist die Action eine reine Markierung, die im
Editor pflegbar ist und die Projektcode über `TransitionActions::custom()` bzw.
`PlaceActions::custom()` selbst ausliest.

Eine unlesbare Deklaration wird **verworfen** — kein Label, kein oder falsches
`scope`, ein Handle, der einen eingebauten Typ überschreibt, oder eine
Handler-Klasse, die fehlt bzw. das Interface nicht erfüllt. Verworfen heißt: nicht
im Editor, und der Writer lehnt eine Definition ab, die sie nennt. Dieselbe laute
Linie wie bei den Zuweisungs-Kriterien mit falschem Feld-Handle. Siehe
`Workflow\CustomActions`.

**`target_missing` ist die explizite Form eines Checks, den es implizit schon
gibt.** Die Start-Transition eines Lokalisierungs-Workflows mit fester Ziel-Site
(`plain_language_site`) sperrt sich schon von selbst, sobald diese Site existiert
— das ist derselbe Mechanismus, nur nicht als `actions`-Eintrag sichtbar und nicht
auf eine andere Site oder eine andere Transition übertragbar. `target_missing`
macht die Regel zuweisbar: für einen eigenen Workflow, der z. B. „Veröffentlichen“
erst erlauben soll, wenn noch keine Version in einer bestimmten Site existiert,
ohne dafür einen Lokalisierungs-Workflow zu bauen.

**Eine Falle, die beide Wege lange teilten und die hier behoben ist:** ob eine
Ziel-Site „bereits existiert“, wurde bisher aus derselben gefilterten Liste
abgelesen, die auch entscheidet, welche Sites überhaupt noch als Ziel infrage
kommen (`localizableSitesFor()`). Fehlte die Site aus DIESER Liste aus einem
anderen Grund — nicht Teil der Collection, von einem anderen Manager beansprucht,
eine falsch geschriebene `plain_language_site` in einem frisch installierten
Projekt — behauptete die Meldung trotzdem „es gibt bereits eine Version“, obwohl
keine existierte. Beide Pfade fragen jetzt direkt `groupLocales()` — die echte
Site-Liste des Beitrags — und melden einen zweiten, eigenen Grund
(`target_site_unavailable`), wenn die Site aus einem anderen Grund nicht
erreichbar ist.

**Zwei Vorgänger-Schreibweisen werden weiterhin gelesen.** `type: user_modal` mit
einer `groups`-Liste, und davor der Solo-Key `assign_from_group: <handle>`. Beide
werden von `TransitionActions::for()` zu einer **manuellen** Zuweisung gefaltet,
mit `match: any` und je einem `group is <handle>` pro Eintrag — genau das, was die
Vereinigung der Gruppenmitglieder bedeutete. Nichts geht dabei verloren, und keine
Definition muss dafür neu gespeichert werden. Der Settings-Editor übernimmt beide
beim Laden und schreibt beim ersten Speichern nur noch die neue Form; der Writer
lehnt ein erneut gespeichertes `user_modal` mit eigener Meldung ab.

Eine einzige Verhaltensänderung: ein `user_modal` mit **leerer** `groups`-Liste
bot bisher niemanden an, künftig alle — siehe die Regel zur leeren Kriterienliste
unten.

#### Zuweisungs-Kriterien

Eine Zeile aus `criteria` ist ein Tripel: **welche Eigenschaft** des Nutzers
(`field`), **wie verglichen** wird (`operator`) und **womit** (`value`).

**`match` steht im Settings-Editor direkt neben dem Modus.** Standard für eine
neue Action ist `all` — jede Bedingung muss gelten, so liest sich ein Filter.
`any` ist kein Randfall: jede migrierte `user_modal`-Action trägt es, weil deren
Gruppenliste eine Vereinigung WAR (siehe oben). Wer so eine Action öffnet, muss
sehen können, dass sie „eine genügt" meint — und dass ein Umschalten auf `all`
sie auf „in beiden Gruppen zugleich" verengt, was praktisch niemanden trifft.

| `operator` | Wirkung |
| --- | --- |
| `is` | Der Wert **der Seite, um die es dem Kriterium geht**, enthält `value`. |
| `is_not` | Die Umkehrung. |
| `matches_entry` | Der Wert des Nutzers und der des **Beitrags** überschneiden sich. Trägt **kein** `value` — den liefert der Beitrag pro Durchlauf, und genau das macht „weise dem zu, der die Kategorie dieses Beitrags betreut" überhaupt ausdrückbar. |

**Welche Seite gelesen wird, entscheidet das Kriterium, nicht der Operator.**
`is` bedeutet in jeder Zeile dasselbe und liest trotzdem mal eine Person, mal
einen Beitrag — welche, sagt schon der Name des Kriteriums:

| Deklariert | `is` / `is_not` liest | Beispielzeile |
| --- | --- | --- |
| nur `user_field` | die Person | `Zugehörigkeit · ist gleich · nord` |
| nur `entry_field` | den Beitrag | `Kategorie des Beitrags · ist gleich · gesundheit` |
| beides | die **Person**, plus `matches_entry` | `Zuständigkeit · stimmt mit dem Beitrag überein` |

Bei „beides" gewinnt die Personen-Seite, weil das umgebende Feature Personen
filtert; die seitenübergreifende Frage hat mit `matches_entry` ihren eigenen
Operator. Wer zusätzlich einen festen Wert am Beitrag prüfen will, deklariert ein
zweites, reines Beitrags-Kriterium — eine Zeile Config.

Eine Bedingung, die nur den Beitrag liest, sagt nichts über Personen: sie gilt
für alle gleich oder für niemanden und wirkt damit als **Vorbedingung** für die
ganze Action („…aber nur bei Beiträgen der Kategorie Gesundheit").

Früher gab es dafür ein zweites Operatorenpaar, `entry_is`/`entry_is_not`. Es las
sich als Tautologie, sobald das Kriterium seinen Gegenstand ohnehin im Namen trug
(„Kategorie des Beitrags · am Beitrag ist gleich"), und machte das
Operator-Dropdown zu der Stelle, an der man erst nachsehen musste, worum eine
Bedingung eigentlich geht.

Wofür ein Kriterium **gedacht** ist, weiß weiterhin nur das Projekt — eines mit
beiden Feldern kann drei Dinge, während eines davon gemeint ist. Der optionale
Schlüssel **`operators`** schneidet die Liste darauf zu:

```php
'responsibility' => [
    'label' => 'Zuständigkeit',
    'user_field' => 'entity',
    'entry_field' => 'categories',
    'operators' => ['matches_entry'],
],
```

Immer Schnittmenge, nie Erweiterung: ein Operator, den die Felder nicht hergeben,
wird still verworfen — dieselbe Toleranz wie bei `options`. Die **Reihenfolge des
Projekts** gewinnt, womit die Liste auch bestimmt, auf welchem Operator eine neue
Bedingungszeile startet. Bleibt die Schnittmenge leer, verschwindet das Kriterium
ganz.

Zwei Kriterien bringt das Addon mit: **`group`** (`isInGroup`) und **`role`**
(`hasRole`). Beide haben kein `entry_field` — ein Beitrag hat weder Gruppe noch
Rolle —, kennen also nur `is` und `is_not`.

**Alles Weitere ist projektspezifisch** und wird in `config/workflows.php →
assignment_criteria` deklariert. Die interessanten Fragen sind projektförmig
(„deckt das Fachgebiet dieser Redakteurin die Kategorie des Beitrags?") und hängen
an Blueprint-Feldern, die das Addon nicht kennen kann:

```php
'assignment_criteria' => [

    // Beide Seiten — alle fünf Operatoren, inklusive matches_entry.
    'category' => [
        'label' => 'Kategorie',             // auch ein Lang-Key erlaubt; der Wert läuft
                                            // durch __(), und der ausgelieferte Default
                                            // nutzt workflows::messages.assignment_criterion_category
        'user_field' => 'fachgebiete',      // Feld im User-Blueprint
        'entry_field' => 'categories',      // Feld im Beitrags-Blueprint
        'options' => 'taxonomy:categories', // Vorschläge; auch 'groups'/'roles'/'sites' oder value=>label-Map
    ],

    // Nur der Beitrag — eine Vorbedingung, zu kombinieren mit einem Kriterium,
    // das tatsächlich Personen benennt.
    'post_category' => [
        'label' => 'Kategorie des Beitrags',
        'entry_field' => 'categories',
        'options' => 'taxonomy:categories',
    ],

],
```

**Mindestens eines** von `user_field` / `entry_field` ist Pflicht; ein Eintrag
mit keinem von beiden wird verworfen — er hat nichts zu lesen und könnte nie
jemanden treffen.

**Die Feld-Handles werden gegen die echten Blueprints geprüft** — `user_field`
gegen `User::blueprint()`, `entry_field` gegen die Vereinigung aller
Entry-Blueprints des Projekts. Ein Kriterium, das ein nicht existierendes Feld
nennt, wird **verworfen**: es erscheint nicht im Dropdown, und eine Workflow-YAML,
die es bereits nennt, lässt sich bis zur Anlage des Feldes nicht mehr speichern
(der Writer meldet „unknown assignment criterion").

Das ist bewusst härter als die Toleranz bei Gruppen-Handles, und der Grund ist
der Ausfallmodus: `is` gegen ein fehlendes Feld trifft **niemanden**, `is_not` —
seine bloße Verneinung — trifft **jeden**. Im automatischen Modus wäre das eine
stille Zuweisung an den gesamten Nutzerstamm, ohne Fehler und ohne Warnung. Ein
Gruppen-Handle kann das Addon nicht prüfen, ein Blueprint-Feld schon. Reihenfolge
also: erst das Feld anlegen, dann das Kriterium.

Weil `AssignmentCriteria::all()` damit Blueprints lädt und von `matches()` pro
Nutzer **und** pro Kriterium erreicht wird, ist es memoisiert — geschlüsselt auf
einen Hash der Config, damit ein `config()`-Wechsel im selben Prozess (Tinker,
Tests) nicht die alte Antwort bekommt.

##### Gruppen: UND und ODER gemischt

`match` gilt für die ganze Liste — alles UND oder alles ODER. Für die häufigste
echte Bedingung reicht das nicht: „in der Redaktion **und** (die Kategorie
**oder** die Entität des Beitrags passt zu meiner)". Deshalb darf ein Eintrag in
`criteria` selbst eine **Gruppe** sein, mit eigenem `match` und eigenen
Kriterien:

```yaml
actions:
  - type: user_assignment
    mode: automatic
    match: all
    criteria:
      - { field: group, operator: is, value: redaktion }
      - match: any
        criteria:
          - { field: category_user_entity, operator: matches_entry }
          - { field: entities_user_entity, operator: matches_entry }
```

Erkannt wird eine Gruppe daran, dass sie eine eigene `criteria`-Liste trägt —
kein Typ-Schlüssel, weil die beiden Formen keine gemeinsamen Schlüssel haben und
die Liste selbst damit die Unterscheidung IST
(`TransitionActions::isCriteriaGroup()`).

**Eine Ebene, und der Writer setzt das durch.** Der Abgleich in
`AssignmentCandidates::satisfies()` würde beliebig tief rekursieren, aber der
Settings-Editor zeichnet eine Ebene — und eine Bedingung, die im Editor nicht
lesbar ist, wäre schlimmer als eine, die das Format ablehnt. Eine leere Gruppe
wird ebenfalls abgelehnt: sie träfe auf niemanden zu und sagt nichts.

Im Editor ist die Gruppe ein eingerückter Kasten mit eigenem Verknüpfungs-Feld;
angelegt wird sie über „Gruppe hinzufügen" neben „Kriterium hinzufügen" und
startet auf `any` — der Grund, überhaupt eine Gruppe anzulegen, ist ein ODER
innerhalb eines UND.

**Der automatische Modus braucht zusätzlich mindestens ein Kriterium, das
Personen einschränkt**, und bei `match: any` müssen es alle tun: eine erfüllte
Beitrags-Bedingung lässt dort sonst jeden Nutzer durch. Der Writer lehnt beide
Fälle mit eigener Meldung ab, statt still den gesamten Nutzerstamm zuzuweisen.
Im manuellen Modus gilt die Regel nicht — dort entscheidet eine
Beitrags-Bedingung nur, ob der Dialog mit jemandem darin aufgeht, und ein Mensch
wählt weiterhin aus.

Diese Prüfung ist mit den Gruppen **rekursiv** geworden
(`WorkflowDefinitionWriter::narrowsUnder()`): eine Gruppe zählt als ein Knoten,
und ob sie einschränkt, ist dieselbe Frage an ihren Inhalt unter ihrem eigenen
`match`. Ohne das würde genau die Bedingung abgelehnt, für die es die Gruppen
gibt — ein `all` mit einer `any`-Gruppe darin.
`options` liefert nur **Vorschläge**: das Wert-Feld im Editor ist `taggable`, ein
Handle darf also getippt werden, den es noch nicht gibt. Genau diese Toleranz
hatten die Gruppen-Handles vorher schon, aus demselben Grund — eine Gruppe, eine
Rolle oder ein Term darf nach dem Workflow entstehen. Der **Kriterien-Handle**
dagegen wird geprüft: den legt niemand nachträglich an, und ein Tippfehler würde
still niemanden treffen.

**Werte werden als Listen und auf den nackten Slug reduziert verglichen.** Ein
`terms`-Feld speichert je nach Konfiguration `gesundheit` oder
`themen::gesundheit`; ein Nutzerfeld und ein Beitragsfeld unterscheiden sich
routinemäßig genau darin. Preis dieser Normalisierung: zwei verschiedene
Taxonomien mit gleichem Slug gälten als gleich — vertretbar, weil ein Kriterium je
Seite genau ein Feld benennt und beide der Admin auswählt. Aufgelöst wird das von
`Workflow\AssignmentCriteria`.

**Eine leere Kriterienliste bedeutet ALLE Nutzer, nicht keinen.** Der Filter ist
optional: eine Zuweisung ohne Kriterien heißt „die Redaktion darf frei wählen" und
bietet dieselbe Menge an wie der Assignee-Drawer. Im **automatischen** Modus lehnt
der Writer sie ab — dort hieße sie „weise den Beitrag der gesamten Nutzerbasis zu".

**Wer die Kandidaten sind, beantwortet ausschließlich
`Workflow\AssignmentCandidates`** — für den Dialog, für die Prüfung der zurück-
geschickten Auswahl, für die automatische Zuweisung und für die Sperre unten.
Vier Fragen, eine Quelle: so können „angeboten" und „akzeptiert" nicht
auseinanderlaufen.

**Der automatische Modus sperrt, wenn niemand passt.** `mechanicalBlocker()`
liefert dann `assignment_no_candidates`, der Button ist deaktiviert. Sonst liefe
eine Transition durch, deren ganzes Versprechen die Übergabe ist, und der Beitrag
läge in einer Queue, die niemand sieht. Der **manuelle** Modus sperrt bewusst
nicht: dort ist die leere Kandidatenliste etwas, das die Redaktion **sieht** (der
Dialog sagt es und lässt sich nicht bestätigen) — eine bessere Antwort als ein
deaktivierter Button mit einer Begründung im Tooltip.

#### Die Sprachauswahl ist nicht konfigurierbar — aber WELCHE Transition sie trägt, ist es

Sie steht in keiner `actions`-Liste und lässt sich auch nicht in eine schreiben —
der Writer lehnt ein `type: site_modal` mit eigener Meldung ab. Stattdessen leitet
`TransitionActions::opensSitePicker()` sie ab: **Lokalisierungs-Workflow
(`metadata.detail: translation`) + die Transition, die die Ziel-Version erzeugt**
(`startsLocalization()`).

**Welche Transition das ist, sagt genau eine Stelle:** die Action
`metadata.actions: [{type: start_localization}]`. Sie ist in einem
Lokalisierungs-Workflow **Pflicht** — der Writer weigert sich, einen ohne sie zu
speichern.

Es gab dafür einmal eine Ableitung: ohne Action galt die *erste Transition der
Liste*. Das ersparte die Pflichtangabe und kostete das Gegenteil von dem, was
eine Voreinstellung leisten soll — der Sprachdialog erschien an einem Schritt,
den niemand dafür ausgewählt hatte, und der einzige Weg das zu merken war, den
Knopf zu drücken und zuzusehen. Für etwas Sichtbares ist Position eine brauchbare
Vorgabe; für einen Dialog, der Inhalte in einer anderen Site anlegt, nicht.

Mit der Markierung an einer beliebigen Transition muss die erzeugende nicht mehr
die erste sein — ein Workflow kann der Quelle z. B. eine eigene Prüf-Transition
voranstellen (`plain_ready → plain_reviewed`, dann erst `start_plain_language`
aus `plain_reviewed`). `TransitionActions::explicitStartTransition()` findet sie;
`startsLocalization()` fragt nur noch sie.

**Im Übersetzungsmanager verschiebt sie damit die Sprachauswahl** — sie sitzt
dort, wo die Ziel-Version entsteht, und nirgends sonst. Im
Einfache-Sprache-Manager erscheint gar kein Dialog, weil `plain_language_site`
das Ziel schon benennt; die Markierung wird dort trotzdem gebraucht, damit
überhaupt etwas erzeugt wird.

Höchstens **eine** Transition darf die Markierung tragen, und nur in einem
Workflow mit `metadata.detail: translation` — der Writer lehnt beides ab, sonst
gäbe es zwei Stellen im Zustandsdiagramm, die dasselbe behaupten, oder eine
Markierung in einem Workflow, der überhaupt keine Ziel-Fassung erzeugt. Bewusst **nicht** geprüft: dass ihr `from` den
`initial_place` enthält. Das sähe wie eine sinnvolle Regel aus, ist aber falsch —
die Quelle sitzt nur so lange im `initial_place`, wie noch **keine** gespeicherte
Zeile für sie in diesem Workflow existiert (`derivedState()`s „die Quelle ist nie
fertig" ist der Rückfall, kein Dauerzustand); eine vorgeschaltete, ganz normale
Transition wie `review_ok` schreibt genau so eine Zeile und verschiebt die Quelle
auf `plain_reviewed` — exakt der Fall, den die Übersteuerung ermöglichen soll.

**Die Reihenfolge bleibt inhaltlich, solange keine Übersteuerung existiert.**
Damit „die erste Karte" nicht auf eine Transition zeigen kann, die mitten im
Ablauf steht, koppelt der Settings-Editor ihr `from` an den `initial_place` — der
Start-Place bleibt die Quelle der Wahrheit, die Reihenfolge entscheidet nur,
**welche** Transition aus ihm herausführt. Sobald eine `start_localization`-Action
irgendwo im Workflow gesetzt ist, hört diese Kopplung auf: die erste Karte wird
wieder eine gewöhnliche Transition (ihr `from`-Feld ist dann editierbar), und die
markierte Transition ist für ihr eigenes `from` selbst verantwortlich — geprüft
erst beim Speichern, indem sie schlicht nie einen Button zeigt, wenn ihr `from`
unerreichbar ist.

Praktische Folge ohne Übersteuerung: die Einstellungen eines
Lokalisierungs-Workflows zu öffnen setzt das `from` der ersten Karte sofort auf
den `initial_place` — sichtbar im deaktivierten Feld, gespeichert erst beim
Speichern. Wer umsortiert, verschiebt damit den Einstieg in den Workflow und
sollte das `from` der bisherigen Start-Transition prüfen.

Der alte Key `needs_site_pick: true` wird ignoriert statt gelesen — für jede
Definition, die ihn gesetzt hatte, benennt die Ableitung (Standard-Zweig)
dieselbe Transition, weil er immer an der ersten stand.

**Auf Workflow-Ebene** (`metadata`)

| Key | Wirkung |
| --- | --- |
| `nav.{label,slug,icon,order}` | Gibt dem Workflow seine Manager-Seite unter `/cp/workflows/<slug>` (verlinkt aus der Workflow-Spalte von `workflows_overview`, § 9) und einen Unterpunkt unter „Settings › Workflows" (nur Super-Admins), sortiert nach `order`. Eine eigene Nav-Sektion pro Workflow gibt es nicht mehr; `config('workflows.nav_section')` ist deprecated und wird nicht mehr gelesen. Ohne `nav` → keine Manager-Seite, kein Settings-Unterpunkt. **`label` gewinnt über die Übersetzung** und gilt dann in jeder CP-Sprache — das ist das Feld „Nav-Titel" im Settings-Editor. Leer lassen, damit `workflows::messages.nav.<slug>` greift und der Titel der CP-Sprache folgt; ohne beides erscheint kein Eintrag. |
| `default_assignee_group` | Auto-Assignment, wenn ein Subjekt entsteht („Neuer Beitrag" bzw. eine neue Localization). Gated außerdem, wer einen Entry in eine andere Site überführen darf (`canTransfer()`) — ein früherer separater `transfer_group`-Key ist darin aufgegangen. |
| `assign_creator: true` | Weist beim Entstehen eines Subjekts **zusätzlich** zu `default_assignee_group` dessen Ersteller zu. Wichtig, wenn der Ersteller nicht in dieser Gruppe ist (z. B. Super-Admin) — nur Assignees dürfen eine Transition ausführen. Gilt für **beide** Workflow-Arten: „Ersteller" ist beim Beitragsmanager wer den Beitrag anlegt (`storePost`), beim Übersetzungsmanager wer die Localization auslöst (`transfer` bzw. eine `site_modal`-Transition). Die Zuweisung endet mit der ersten Transition; ein reines „Speichern" des aktuellen Schritts ändert sie nicht. |
| `history_fields: [...]` | Whitelist von **Inhalts**-Feld-Handles für den Diff-View des Verlaufs. Sidebar-Felder (Taxonomien, sticky …) und die Meta-Attribute Slug, Datum, Veröffentlichung erscheinen immer, auch wenn sie hier nicht stehen (§ 5.10). Leer/fehlend = alle geänderten. |
| `detail: translation` | Markiert den Workflow als **Lokalisierungs-Workflow** — siehe unten. Aktiviert außerdem die alternative `TranslationInstanceDetail`-Ansicht (Quelle vs. Übersetzung nebeneinander). |
| `progress_orientation` | `horizontal` (Standard) oder `vertical`. Bestimmt **Ausrichtung und Position** der Fortschrittsleiste auf der Detailseite: waagerecht über dem Inhalt der Zusammenfassung, senkrecht in einer Spalte links davon. Im Settings-Editor als Auswahl bei den Workflow-Metadaten. Ein Wert daneben wird beim Speichern abgelehnt; eine hand-editierte YAML mit Müll fällt beim Lesen auf `horizontal` zurück. Siehe `Workflow\ProgressSteps::ORIENTATION`. |
| `post_creator_groups: [...]` | Nutzergruppen, die in diesem Workflow Beiträge anlegen dürfen. Im CP editierbar (Settings > Workflows > „Darf Beiträge anlegen"). Überschreibt `config → post_creator_groups`; eine **leere Liste ist eine Aussage** („nur Super-Admins") und wird deshalb geschrieben, nicht weggelassen. Fehlt der Key ganz, greift die Config. |
| `create_transition` | Handle einer Transition, die der Bestätigen-Button des „Neuer Beitrag"-Dialogs **direkt nach dem Anlegen** ausführt — der „Workflow-CTA". Der Button trägt dann das Label der Transition (z. B. „Entwurf erstellen"); der Dialog fragt den Titel ab, daraus entsteht auch der Slug (`uniqueSlug`, `-2`/`-3` bei Kollision). Nur eine echte (nicht `system`) Transition, die vom Start-Place ausgeht; nur in Beitrags-Workflows. Läuft durch dieselben Gates wie ein Button auf der Detailseite (`canApplyTransition`, `mechanicalBlocker`). Eine `manual`-Zuweisung fragt der Dialog gleich mit ab (Kandidatenliste wie im Picker der Detailseite, aufgelöst gegen einen Stub-Eintrag der Collection — Gruppen-/Rollen-Kriterien exakt, `matches_entry`-Kriterien finden vor dem Anlegen niemanden); die Auswahl wird **vor** dem Anlegen geprüft. Kann sie nicht laufen, wird der Beitrag **trotzdem angelegt** und bleibt im Start-Place, der Toast nennt den Grund. Fehlt der Key, heißt der Button „Anlegen" und legt nur an. Nach dem Anlegen bleibt man in jedem Fall in der Queue (kein Sprung in den Editor). Im CP editierbar (Settings > Workflows > „Workflow-Aktion beim Anlegen"); Umbenennen/Löschen der Transition im Editor pflegt den Verweis mit. |
| `site_fields: [...]` | Blueprint-Feld-Handles, über die ein Redakteur Sites von Hand wählen könnte (z. B. eine Portal-Taxonomie). Wird validiert und ausgelesen (`WorkflowResolver::siteFields()`), aber **aktuell nicht erzwungen** — siehe § 2 → Lokalisierungs-Workflows. |
| `sla.*` | Alterung veröffentlichter Beiträge: `needs_update_days` / `needs_update_place` und `outdated_days` / `outdated_place`. Tage und Schritt gelten nur zusammen; der Schritt ist ein **eigener Place dieses Workflows**, und in ihn muss eine Transition führen. Nur im Autoren-Workflow erlaubt. Siehe § 15. |
| `source_draft_place` | Nur im Lokalisierungs-Workflow: der Schritt, in den diese Fassung zurückfällt, wenn der Beitrag auf Statamic-Entwurf geht. Darf kein `allow_publish` tragen. Siehe § 15. |
| `seed_published_place` | Der Place, in den bereits veröffentlichte Einträge beim Backfill wandern (§ 5.5.2). Nötig, wenn der Workflow keinen `allow_publish`-Place hat — `translation` endet in `approved`. Muss ein existierender Place sein, der Writer lehnt sonst ab. Ohne Angabe: `publishPlaces()[0]`, sonst `initial_place`. |
| `plain_language_site` | **Macht den Workflow zum Einfache-Sprache-Manager** und nennt zugleich dessen feste Ziel-Site. Zwei Aussagen in einem Key, absichtlich — ein zusätzlicher `mode:`-Schlüssel könnte ihm widersprechen. Folgen: die Start-Transition fragt **nicht** nach einer Sprache (es gibt nur ein Ziel), die Texte gehen an den Dienst für Einfache Sprache statt an DeepL (`translatorFor()`), und die Site ist für **jeden anderen** Localization-Workflow kein Ziel mehr — weder im Site-Picker noch im „In andere Site überführen"-Dialog noch in deren serverseitigen Prüfungen. Optional, genau **eine** Site; Single-Select im Editor, damit „höchstens eins" strukturell gilt. Muss eine konfigurierte Site sein, sonst lehnt der Writer ab — anders als bei `post_creator_groups`/`site_fields`, weil ein Tippfehler die Funktion sonst still abschalten würde und Sites praktisch statisch sind. |
| `plain_language_completed_place` | Optional, nur mit `plain_language_site`. Sobald `PollPlainLanguageCommand` einen Auftrag erfolgreich abschließt (Text übernommen, keine `failures`), setzt es den Beitrag **direkt** in diesen Place — per `WorkflowStateStore::set()`, bewusst NICHT über eine Transition (der Scheduler läuft unbeaufsichtigt, es gibt also weder einen Klick, an dem eine `required_group`-Prüfung hängen könnte, noch die Assignee-/Publish-Nebeneffekte, die eine echte Transition mitbringt — das ist der Preis für die Einfachheit, nicht ein Versehen). Fehlt die Einstellung, bleibt es beim heutigen Verhalten: liegen bleiben bis zum manuellen Klick auf `submit_plain_language`. Bei einem fehlgeschlagenen Auftrag greift es nie. Muss ein existierender Place sein, der Writer lehnt sonst ab, wie bei `seed_published_place`. **Der einzige Weg, eine „Einfache Sprache ist fertig"-Mail zu bekommen:** ohne diese Einstellung ändert sich beim Eintreffen des Rewrites kein Status, es kommt also nirgends etwas an und nichts kann melden. Mit ihr trägt der genannte Place eine `mail`-Action (`mail_type: plain_language_ready`), und der Poll-Befehl löst sie beim Setzen aus — siehe Place-Action-Typen oben. |
| `plain_language_failed_place` | Optional, nur mit `plain_language_site`. Das Gegenstück zum Abschluss-Place: wohin `PollPlainLanguageCommand` eine Fassung setzt, deren Rewrite **endgültig** gescheitert ist — Auftrag vom Dienst abgelehnt, als `failed` gemeldet, unbrauchbares Ergebnis, oder nach dem Abfrage-Budget einer Einreichung nicht fertig geworden (`max_polls`, Default 5, env `PLAIN_LANGUAGE_MAX_POLLS`; ein Poll pro Minute). **Ein einzelner Fehlschlag parkt aber nicht mehr sofort:** der Poll reicht den ganzen Aufruf erneut ein, bis `max_attempts` Einreichungen verbraucht sind (Default 3, env `PLAIN_LANGUAGE_MAX_ATTEMPTS`, `1` schaltet Wiederholungen ab). Die Zählung trägt die Spalte `submission` der Job-Tabelle, die der Retry beim Anlegen der Ersatzzeile weiterschreibt — `attempts` daneben zählt Polls und beginnt pro Einreichung neu. Jeder verbrauchte Versuch schreibt ein `plain_language_retry`-Event (bewusst nicht `plain_language_failed`, sonst würde die Instanzseite bei jedem Zwischenversuch einen Fehler-Toast feuern). Erst der letzte gescheiterte Versuch parkt. Ebenfalls, wenn die **Status-Abfrage** selbst dauerhaft mit einem Fehlercode antwortet — bei einem Fehlercode wird pro Job einzeln nachgefragt, damit eine unbekannte ID nicht die gesunden Aufträge mitreißt; ist der Dienst gar nicht erreichbar, wird gewartet statt eskaliert. **Nicht** für den synchronen Fall: scheitert schon das Einreichen (500 vom Dienst in `applyTransition()`), wird die Lokalisierung wieder gelöscht und es bleibt gar nichts zurück — dort war nie ein Auftrag angenommen, es gibt also nichts zu parken. Ebenfalls per `WorkflowStateStore::set()`, also ohne Transition und ohne `required_group`-Prüfung. **Ohne diese Einstellung wird die unübersetzte Kopie gelöscht** — das ältere Verhalten, das bleibt, damit bestehende Projekte sich nicht ändern. Setzen lohnt: ein Place ist das einzige dauerhafte Signal, das das CP hat (ein Toast ist nach 3,5 s weg, und der Poll läuft ohne Session, in die er einen flashen könnte), er ist im Queue-Listing sichtbar, und eine Transition mit `plain_language_resubmit` aus ihm heraus macht den zweiten Versuch zu einem Klick. Muss ein existierender Place sein, der Writer lehnt sonst ab. |
| `plain_language_clear_fields` | Optional, nur mit `plain_language_site`, Liste von **Top-Level-Feld-Handles**. Diese Felder werden beim Rückschreiben eines fertigen Rewrites auf der Einfache-Sprache-Fassung **geleert** — gedacht für Inhalte, die eine Vollkopie mitbringt, die aber in Einfacher Sprache nichts zu suchen haben und die der Dienst von sich aus nie entfernt, weil sie gar nicht Teil des Dokuments sind, mit dem er antwortet. Der Anlass in diesem Projekt: der Quellen-Block (`dd_title`/`dd_text`) mit der wissenschaftlichen Referenzliste unter jedem Beitrag. **Geleert heißt beschattet, nicht gelöscht** — warum, siehe „Quellen überleben den Rewrite nicht" unten. Welche Felder das sind, ist eine **Projekt**-Entscheidung: das Addon weiß nicht, dass dieses Projekt seine Quellen `dd_text` nennt, und hardcodet nichts (dieselbe Haltung wie `site_fields`). Nicht gegen ein Blueprint geprüft (ein Workflow kann mehrere Collections mit verschiedenen Blueprints führen; ein unbekannter Handle beschattet ein Feld, das niemand liest, und ist harmlos) — die **Kopplung** an `plain_language_site` prüft der Writer dagegen schon, wie bei den beiden Place-Keys: ohne sie entsteht in diesem Workflow nie ein Rewrite, der Key sähe also wirksam aus und leerte nichts. `WorkflowResolver::plainLanguageClearFields()` gibt für jeden anderen Workflow ohnehin nichts heraus — **der Übersetzungspfad ist nicht betroffen**, eine englische Fassung behält ihre (übersetzten) Quellen. Im Settings-Editor gibt es kein Feld dafür; der Key wird in der YAML gesetzt und überlebt das Speichern im Editor unverändert (`normalize()`/`save()` kopieren `metadata` vollständig). Bestandsfassungen holt `workflows:backfill-plain-language` nach. |
| `translation_source_site` | Die Version, aus der übersetzt wird. Leer/fehlend = die **Main-Site** (Statamics Default-Site), und nur wenn der Beitrag dort keine Version hat, dessen Ursprungsversion — ein Beitrag, der direkt in einer anderen Site angelegt wurde, hat keine andere Quelle. Aufgelöst an genau einer Stelle: `WorkflowResolver::sourceSiteFor()`. Es gibt **eine** Quelle pro Manager: steht hier `simple`, entstehen alle Übersetzungen aus der Einfache-Sprache-Fassung und nicht mehr aus der deutschen. Fehlt einem Beitrag diese Version, ist die Start-Transition gesperrt (Begründung am Button, 422 am Endpoint) — kein stiller Rückfall. |
| `translation_source_pick: true` | Hebt die Verbindlichkeit auf: der Sprach-Dialog fragt dann zusätzlich nach der Quelle, vorbelegt mit `translation_source_site` (bzw. der Main-Site) und begrenzt auf die Fassungen, die der Beitrag hat. Für Projekte, die pro Übersetzung entscheiden wollen. Standard aus. Nur in einem Lokalisierungs-Workflow **ohne** `plain_language_site` erlaubt — der Einfache-Sprache-Manager fragt nichts, dort wäre das Flag wirkungslos, und der Writer lehnt die Kombination deshalb ab. Bei eingeschaltetem Flag sperrt eine fehlende Quell-Version die Transition nicht mehr: sie ist dann eine Option unter mehreren, und der Dialog bietet nur vorhandene an. Gesetzt z. B. auf `simple`, um jede Übersetzung auf der Einfache-Sprache-Version aufzubauen statt auf dem Original. Wirkt an **einer** Stelle (`sourceEntryFor()`) und damit einheitlich auf Übersetzungslauf, Quell-Panel der Detailansicht und Payload-Vorschau. Fehlt einem Beitrag die gewählte Version, bleibt die Transition gesperrt und nennt den Grund — es wird **nie** stillschweigend auf den Ursprung zurückgefallen, weil das Ergebnis dann falsch wäre und richtig aussähe. Muss eine konfigurierte Site sein und darf nicht gleich `plain_language_site` sein. |
| `translation_source_view: [...]` | Nur im Lokalisierungs-Workflow: wer die **Quellspalte** der Detailansicht wie sieht. Liste von Regeln, je `mode` (`hidden` = die Quelle wird gar nicht an den Browser geliefert, `collapsed` = die Seite startet einspaltig und ein Button blendet die Quelle ein), `by` (`group`, `role` oder `user`) und `handles` (Gruppen-/Rollen-Handles bzw. Benutzer-IDs). Wen keine Regel trifft, sieht die Gegenüberstellung wie bisher; treffen mehrere, gilt die strengere. **Darstellungsregel, keine Berechtigung** — Super-Admins sind nicht ausgenommen. Im CP editierbar (Übersetzungs-Abschnitt der Workflow-Einstellungen). Ausgewertet in `Workflow\SourceViewRules`, validiert vom Writer (`writer_source_view_*`). |
| `editor.{places,transitions,…}` | **Nur für den Graph-Editor, ohne Laufzeitwirkung.** Knotenpositionen als `places.<handle>: {x, y}` und `transitions.<handle>: {x, y}`; fehlen sie, legt der Editor automatisch aus. Daneben flache Ansichtswerte wie `viewport: {x, y, zoom}`. Der Writer bereinigt statt zu validieren (`normalizeEditorMeta()`): Positionen unbekannter oder auto-injizierter Handles fliegen raus (so verschwindet die Position eines gelöschten Knotens), `x`/`y` werden zu gerundeten Ganzzahlen in ±100000, nicht-numerische Positionen werden verworfen, andere Unterschlüssel behalten nur Skalare bzw. flache Skalar-Maps; ein leeres `editor` entfällt. Löst nie einen Validierungsfehler aus. Eine Speicherung, die nur `editor` ändert, schreibt **kein** `definition_saved`-Event. |

#### Der QS-Bericht des Einfache-Sprache-Dienstes

Die Antwort des Dienstes enthält neben `output_text` ein Feld **`qs_notes`** — was
vereinfacht, was ergänzt und, der Teil der über die Freigabe entscheidet, was
**weggelassen** wurde. `PollPlainLanguageCommand::recordNotes()` legt es als
Kommentar am Beitrag ab, verfasst von „System", mit
`kind: plain_language_qs` (Spalte `workflow_comments.kind`).

Der Kommentar ist der **dauerhafte** Datensatz: die Auftragszeilen, aus denen er
stammt, löscht `PlainLanguageJobStore::forget()`, sobald ein Rewrite abgeschlossen
ist. Gefunden wird er über `kind`, nicht über „der ohne Autor" — das ist heute
zufällig eindeutig und wäre es beim ersten weiteren System-Kommentar nicht mehr.

**Zusätzlich als Alert oben in der Zusammenfassung**, vor dem Inhalt:
`buildInstancePayload()` liefert dafür `qsNotes` (siehe `qsNotesFor()`), und zwar
**nur**, solange die Fassung im `plain_language_completed_place` steht. Sonst
wäre der Hinweis entweder verfrüht oder längst erledigt, und ein Alert, der nie
verschwindet, wird nicht mehr gelesen. Ohne `plain_language_completed_place` gibt
es keinen Alert — es fehlt der Moment, an dem er hängen könnte; im
Kommentar-Thread steht der Bericht weiterhin.

Die Überschrift trägt der Alert selbst, deshalb schneidet `qsNotesFor()` sie aus
dem Kommentar-Body heraus (`Str::after()`, das bei fehlendem Marker den ganzen
Text zurückgibt — ein handverändeter oder älterer Kommentar bleibt also lesbar).

Zwei Grenzen, die bleiben: Der Bericht wird **nur bei Erfolg** eingesammelt — ein
Auftrag mit unbrauchbarem `output_text` geht in den Fehlerzweig, etwaige
`qs_notes` derselben Antwort gehen verloren. Und er muss ein **String** sein;
liefert der Dienst ihn strukturiert, fällt er durch den Filter in `recordNotes()`.

#### Zugangsdaten der Übersetzungsdienste — bewusst nicht in der YAML

API-Schlüssel und Endpoint-URLs stehen ausschließlich in der `.env`, gelesen über
`config/workflows.php → translation`:

```
DEEPL_API_KEY=…            # config: workflows.translation.deepl.key
PLAIN_LANGUAGE_URL=…       # config: workflows.translation.plain_language.url
PLAIN_LANGUAGE_TOKEN=…     # config: workflows.translation.plain_language.token
```

Der Settings-Editor bietet dafür **kein Feld**, und genau das ist der Punkt: er schreibt
`resources/workflows/*.yaml`, und die Datei ist versioniert. Ein dort gespeicherter
Schlüssel läge in jedem Clone, Backup und CI-Log, alle Umgebungen teilten ihn, und
Rotation hieße Commit. Der Weg über `config()` statt `env()` sorgt zusätzlich dafür, dass
die Werte unter `php artisan config:cache` erhalten bleiben. Wer andere Variablennamen
braucht, publiziert die Config (`--tag=workflows-config`) und ändert die `env()`-Aufrufe.

#### Aufrufe des Dienstes mitschreiben — nur zur Diagnose

```
PLAIN_LANGUAGE_LOG_CALLS=true   # config: workflows.translation.plain_language.log_calls
```

Standard aus. Eingeschaltet schreibt `PlainLanguageTranslator::send()` pro **gelungenem**
Aufruf zwei Zeilen: `plain_language_request` mit dem vollständigen Payload, bevor der
Request rausgeht, und `plain_language_response` mit Status und Antwort-Body, wenn er
zurückkommt. Zwei statt einer, weil die erste die einzige Spur ist, die ein Aufruf
hinterlässt, der überhaupt nicht zurückkehrt (Hänger, abgeschossener Prozess).

Aus, weil der Payload der komplette Inhalt eines Beitrags ist — ein Artikel sind schnell
ein paar Dutzend Kilobyte, und die minütliche Abfrage würde das Log mit „noch nicht
fertig" zuschütten.

**Fehlschläge hängen nicht an diesem Schalter** und werden immer vollständig geloggt,
Payload inklusive: `plain_language_unreachable` (Verbindung) bzw. `plain_language_response`
als WARNING (abgelehnt, etwa ein 422, der das beanstandete Feld nennt). Wer erst
einschalten und den Fehler reproduzieren müsste, hätte den Log-Eintrag genau dann nicht,
wenn er ihn braucht.

Der Token taucht unter keiner Einstellung auf: er ist ein Header, kein Payload-Feld.

Was der Editor **zeigt**, ist die Sektion „Übersetzungs-APIs": je Variable eine Zeile,
ob sie gesetzt ist (`WorkflowController::envStatus()` → `EnvStatusLine.vue`) — nur ein
Boolean, nie der Wert. Kein Eingabefeld, kein Erklärtext; die Variablennamen sagen selbst,
zu welchem Dienst eine Zeile gehört. Ohne diese Anzeige fiele ein fehlender `.env`-Eintrag
erst beim ersten API-Call auf und sähe dort nach einer kaputten Anbindung aus statt nach
einer fehlenden Zeile.

Welcher DeepL-Endpoint angesprochen wird, ist **nicht** konfigurierbar: Free-Keys enden auf
`:fx`, genauso entscheiden die offiziellen DeepL-SDKs — eine zweite Einstellung könnte dem
nur widersprechen. `Translation\DeeplConfig` kennt beide Regeln und ist die einzige Stelle,
die sie kennt; der HTTP-Client wird dieselbe Klasse benutzen.

#### Die Fortschrittsleiste im Summary-Header

Auf der Instanz-Detailseite steht keine Status-Badge mehr, sondern eine
Stufenleiste über alle Places (`resources/js/components/WorkflowProgress.vue`).
Eine Badge benennt eine Position; auf einer Seite, die „Summary" heißt, ist die
Frage der Weg. Das Site-Kürzel, das die Badge als `append` mittrug, sitzt jetzt als
`.wf-site-code`-Chip am Titel — bewusst keine zweite Badge, weil eine Badge Status
bedeutet und die Site keiner ist.

Die Leiste sitzt in einer Statamic-`Card` (weißes Panel, `rounded-xl`, Hairline-Ring,
`shadow-ui-md`) — der Rahmen ist Teil des Entwurfs, und `Card` ist genau das, also
wird er nicht nachgebaut. Ein Punkt ist ein **Ring**, kein Kreis: gefüllte Scheibe mit
ausgestanztem Loch in der Card-Hintergrundfarbe (nicht Weiß, sonst leuchtet es im
Dark Mode) — das Loch ist auch, was einen grauen Punkt dort lesbar hält, wo die
Schiene hinter ihm durchläuft.

**Drei Zustände je Stufe, ein Bauteil.** Jeder Marker ist derselbe schmale
18-px-Ring mit 1,5 px Rand, zentriert in einer 24-px-Box; nur Farbe und Inhalt
wechseln: **erledigt** = das `check-circle`-Icon ganz, in Primary und leicht
durchlässig (`/85`), **aktuell** = Ring in der Place-Farbe mit Punkt darin plus Halo
(`ring-2`), **kommend** = leerer grauer Ring. Der zurückgelegte Teil der Schiene ist
Primary, der Rest grau — satt hinter dir, grau vor dir.

**Die Geometrie kommt aus dem Icon, nicht umgekehrt.** Das `check-circle`-Glyph
zeichnet seinen Kreis bei r=9 in einer 24er-Viewbox, ist bei `size-6` also 18 px
breit mit 1,5 px Strich — und genau das kopieren die anderen Marker. Ein
Zwischenstand hatte gefüllte 24-px-Scheiben, und das Icon dazwischen sah aus wie eine
andere Gattung: ein dünner Umriss neben massiven Punkten. Die Scheiben ans Icon
anzupassen statt umgekehrt behält die Form, die der Icon-Satz vorgibt. `DOT_PX`
bleibt trotzdem 24, weil `railStyle()` gegen diese Box rechnet.

Die Primary-Farbe wird als CSS-Variable angesprochen (`var(--theme-color-primary)`),
nicht als `primary`-Utility: eine solche Klasse gibt es nirgends — Statamic liefert
`--color-primary` als Theme-Variable, aber kein `.text-primary`/`.bg-primary`
(in beiden Stylesheets geprüft). Die Variable selbst setzt das CP-Head-Partial inline
je `:root` und `:root.dark`, folgt also von sich aus dem Theme **und** dem Farbmodus —
deshalb tragen diese zwei Klassen keine `dark:`-Variante.

Jeder Marker ist mit der Card-Farbe gefüllt. Das ist keine Dekoration: die Schiene
läuft dahinter, und ein hohler Ring hätte die Linie mitten durch sich.

Der **Anker einer Abzweigung** bekommt einen Haken, obwohl die Fassung nicht
weitergegangen ist: sie war dort und ist abgebogen. Sonst endete die Primary-Linie an
einem blassen grauen Punkt, der aussah wie ein nie erreichter Schritt.

Alles Übrige — die ungegangene Schiene, die kommenden Punkte, die übrigen
Labels — ist ein zurückgenommenes Grau (`gray-200`/`gray-300`/`gray-400`, im Dark Mode
`gray-700`/`gray-600`/`gray-500`, gegen die Card-Flächen gewählt statt blind
gespiegelt). Eine Zwischenstufe färbte einmal **jede** erreichte Stufe in ihrer
Place-Farbe, und in einem Workflow, der keine pflegt — der Beitragsmanager pflegt
keine — kamen dabei drei willkürlich wirkende Farben heraus: `stateColor()` fällt auf
eine eingebaute Handle-Map zurück, die zufällig `in_review`, `medical_review` und
`published` kennt und sonst nichts. Das las sich wie eine Aussage, die es nicht war.
Deshalb steht die Place-Farbe heute nur am aktuellen Schritt und der Fortschritt in
Primary.

**Die Schiene ist EIN Element**, absolut positioniert hinter den Punkten, um je eine
halbe Zelle von beiden Enden eingerückt — damit endet sie in der Mitte des ersten und
letzten Punkts statt am Rand. Vorher waren es zwei Halbsegmente je Zelle, und genau
das ließ die Linie gebrochen aussehen: `rounded-full` setzte jedem Halbsegment eine
Kappe an beide Enden, sodass jede Fuge einknickte, und benachbarte Zellen runden ihre
Breite unabhängig, was noch ein Subpixel-Loch dazugibt. Ein einzelnes Element kann
nicht brechen. Vertikal bleibt es bei Stücken je Zeile — die Zeilen sind
unterschiedlich hoch (Labels brechen um), es gibt also keinen festen Abstand, von dem
aus die Mitte des letzten Punkts messbar wäre; durchgehend sind sie trotzdem, weil
`flex-1`-Stücke in lückenlos aneinanderliegenden Zeilen ohne Kappen sitzen.

Strichstärken liegen bei **2 px** — Schiene, Abzweigungs-Stich und Halo. Bei 4 px
lasen sie sich als Rahmen um die Leiste statt als Linie durch sie. Sie stehen als
Konstanten (`RAIL_PX`, `RAIL_H`, `RAIL_W`, `HALO`) beieinander, weil `railStyle()`
die Schiene auf die Punktmitte rechnen muss: der `top`-Wert wird aus Punkt- und
Schienenbreite abgeleitet, statt danebengeschrieben zu werden.

**Platzierung folgt der Ausrichtung**, und nur ihr: `horizontal` heißt über dem
Content der Summary, `vertical` heißt Seitenspalte links davon. Ein Wert für beides,
weil zwei Einstellungen sich widersprechen könnten („senkrecht, oben") und keine der
beiden Antworten falsch genug wäre, um sie abzulehnen — das CP würde einfach etwas
zeichnen, das niemand gewählt hat.

Gesetzt wird das **je Workflow** in `metadata.progress_orientation`, pflegbar im
Settings-Editor bei den Workflow-Metadaten. Es hängt an der FORM der Definition:
sieben Stufen lesen sich als breites Band über dem Inhalt, vier daneben — und wer die
Places ordnet, ist die Person, die das beurteilen kann. Vorher war es eine Konstante
in den beiden Seiten, also für alle Workflows dieselbe Antwort und nur von einem
Entwickler änderbar.

Nur die Abweichung landet in der YAML: `horizontal` ist der Standard des Lesers, ein
Schlüssel dafür wäre eine Wiederholung. Die Seitenspalte greift erst ab `lg`; darunter
ist für zwei Spalten kein Platz, die Leiste stapelt sich also ohnehin über den
Content. `lg:self-start` hält die Card auf ihrer **eigenen** Höhe: eine Flex-Zeile
streckt ihre Elemente sonst auf das höchste, und die Leiste stand dann in einem
überwiegend leeren Panel neben dem Inhalt.

**`lg:flex-row!` — das `!` trägt Last.** Statamics Stylesheet lädt **nach** dem des
Addons und definiert `.flex-col` bei gleicher Spezifität; ohne das Wichtig-Flag
gewinnt die Spaltenrichtung, und die Leiste steht in ihrer schmalen Breite **über**
dem Content statt daneben. Das sieht wie ein Layout-Fehler aus und ist einer der
Kaskade. `lg:w-64` braucht kein Flag: um die Breite dieses Elements konkurriert im
CP-Stylesheet nichts. Ein Grid statt Flex hilft hier übrigens nicht — `.flex` würde
`lg:grid` genauso schlagen. Auf der Übersetzungsseite sitzt sie **nach** den Alerts: ein
fehlgeschlagener Rewrite muss das Erste sein, was man liest.

**Was eine Stufe ist, entscheidet der Server** (`Workflow\ProgressSteps`), nicht die
Komponente: die Regel ist drei Ebenen tief und zwei davon stehen schon in PHP. Eine
Kopie im Client wäre eine zweite Definition derselben Sache, von Hand
synchronzuhalten.

- **Reihenfolge = YAML-Reihenfolge**, sortierbar über die Pfeile in der Place-Karte
  des Settings-Editors. Nicht aus dem Transitions-Graphen berechnet: der ist
  zyklisch (jede `reject`-Transition zeigt zurück), eine topologische Ordnung
  bräuchte willkürliche Tie-Breaks und wäre trotzdem nicht „der Happy Path". Die
  Leiste ist damit eine **Annäherung** an das Diagramm, und die YAML-Reihenfolge ist
  die einzige, die jemand bewusst festgelegt hat.
- **Die Auto-Places sind implizite Abzweigungen.** `DefinitionLoader::raw()` hängt
  `pending_revision` **hinten an** die Place-Liste, und `metadata.sla` benennt zusätzlich
  die selbst gepflegten Alterungsschritte —
  als Stufen ergäbe das drei Schritte hinter dem Ende, und ein Workflow mit
  Revisionen würde scheinbar in „Unpublished changes" münden. Ihr Anker ist deshalb
  der Done-Place (`WorkflowResolver::donePlace()`, das auch `seed_published_place`
  berücksichtigt), nicht ihre Listenposition.
- **Ein Zustand, den die Definition nicht kennt**, wird freistehend gezeichnet, mit
  Hinweistext. Das passiert bei einer verwaisten State-Zeile — ein Place wurde
  entfernt, während eine Fassung noch darin stand. Absicht: so fällt der Rest auf,
  statt die Fassung von der Leiste zu kippen.
- **`current: null`** (Fassung noch nicht Teil des Workflows, etwa unveröffentlicht
  im Lokalisierungsmanager): alle Stufen blass, dazu `state_none` — genau der Fall,
  den die Badge vorher abfing.

Innerhalb der Komponente schaltet `orientation` zwischen Zeilen- und
Spaltenlayout, zusätzlich automatisch vertikal unter `sm` — eine waagerechte Leiste
mit sieben beschrifteten Stufen ist auf einem Telefon unlesbar. Ein ausdrückliches
`vertical` wird nie zurückgedreht.

**Falle für Nachfolger: dynamische Farbklassen funktionieren hier nicht.**
`resources/css/addon.css` nutzt `source(none)` mit einem einzigen
`@source '../js/**/*.vue'` und hat keine Safelist — eine Klasse entsteht nur, wenn
sie **wörtlich** in einer `.vue`-Datei steht. Weder `` `bg-${color}-500` `` noch
`var(--color-purple-500)` liefert etwas: Tailwind emittiert nur die Farbfamilien,
die im Addon vorkommen. Deshalb steht in `WorkflowProgress.vue` eine **statische
Literal-Map** über alle 20 Farben, die `stateColor()` zurückgeben kann — die 17 aus
`PLACE_COLORS` plus `amber`, `red` und `yellow`, die ein Projekt nicht wählen kann,
die eingebaute `STATE_COLORS`-Map in `states.js` aber benutzt. Wer eine Farbe
ergänzt, ergänzt sie dort — und prüft danach im gebauten CSS, dass sie erzeugt
wurde.

#### Die Stufen in der Mail

**Ganz unten, als Fußzeile**, steht dieselbe Auskunft in einer Zeile: „Schritt 3
von 5" plus der Pfad, erledigte Stufen mit `✓`, die aktuelle fett, eine Abzweigung
als eigene Zeile mit `↳`. Am Fuß und nicht oben, weil die Mail mit ihrer Bitte
anfangen soll und nicht mit einer Landkarte: „wie weit ist das hier" fragt man
nach der Nachricht, und wer es nicht braucht, überliest den Block, statt an ihm
vorbeilesen zu müssen.

Im `x-mail::subcopy`-Block: Haarlinie darüber, kleinere Schrift, alles vom Theme
und nichts von Hand — die Komponente IST der Fußbereich und sagt damit von selbst,
dass die Nachricht zu Ende ist und eine Randnotiz folgt. Dass die Fettung und die
Häkchen das überleben, ist nachgeprüft und keine Annahme: `subcopy` schickt seinen
Slot wie `panel` durch `Illuminate\Mail\Markdown::parse`.

**In jedem Template, auch in den eigenen eines Projekts.** `$progress` liegt in den
Defaults, die jede View bekommt (`Mail\WorkflowNotification::defaults()`); der
Block ist in einem Projekt-Template wortgleich zu wiederholen, damit jede Mail
dieser Installation gleich endet.

**Markdown, kein Markup.** Laravel parst den Mail-Body mit `html_input => 'escape'`;
ein `<span style>` käme beim Leser als sichtbare spitze Klammern an. Ein Häkchen, ein
Trenner und Fettung sind alles, was es braucht, und das überlebt jeden
Mail-Client. Farben gibt es hier bewusst nicht: die CP-Leiste färbt den aktuellen
Schritt in seiner Place-Farbe, das bräuchte die Theme-Variablen und die
20er-Klassen-Map — in einer Mail wäre es eine zweite, abweichende Vorstellung
desselben Bildes.

**Zwei Regeln teilen sich Mail und Leiste**, beide in `ProgressSteps`:
`reachedIndex()` (wie weit ist die Fassung gekommen) und `doneUntilIndex()` (was gilt
als fertig). Die zweite existiert nur, weil die Asymmetrie — auf der Linie ist der
aktuelle Schritt *nicht* fertig, auf einer Abzweigung der Anker *doch* — beim ersten
Anlauf zweimal ausgeschrieben war und die Mail-Kopie sie prompt falsch hatte: der
Anker war in der Leiste abgehakt und in der Mail nicht. `WorkflowProgress.vue` hält
den unvermeidlichen JS-Zwilling (`doneUntil`); ändert sich das eine, ändert sich das
andere.

Die Schritt-Labels werden in `content()` aufgelöst, nicht im Notifier — nur dort
greift die Locale des Empfängers. Ein Label ohne `state_<handle>`-Übersetzung fällt
auf das YAML-Label zurück und bleibt damit in dessen Sprache; das ist die übliche
Kette, in einer fremdsprachigen Mail fällt es nur eher auf.

### Governed Collections (`collections:`)

Das Top-Level-Array `collections:` bestimmt, welche Einträge ein Workflow
verwaltet. Es ist **im CP editierbar**: Settings → Workflows → `<Workflow>` →
Abschnitt „Collections". Der Editor schreibt direkt in die YAML; Handles werden
gegen `Collection::find()` validiert, unbekannte Handles werden mit 422 abgelehnt.

Ein leeres Array bedeutet, dass der Workflow keine Subjekte hat
(`isEntryWorkflow()` ist dann false, siehe § 5.1): seine Manager-Seite listet
nichts. Das Addon setzt an dieser Stelle **keine** Demo-Inhalte ein.

**Ownership bei Überlappung.** Mehrere Workflows dürfen dieselbe Collection
listen. `WorkflowResolver::forCollection()` liefert den **ersten** Treffer über
`DefinitionLoader::all()`, also alphabetisch nach Dateiname — dieser Workflow
„besitzt" die Collection. Daran hängt:

- Blueprint-Injection des `workflow_actions`-Felds (`InjectWorkflowFields`)
- das Publish-Gate (`WorkflowResolver::canPublish()`)
- wer über „Neuer Beitrag" in die Collection anlegen darf

Ein Workflow, der die Collection **nicht** besitzt, greift dort nur auf Einträge
zu, die einen explizit gespeicherten State haben (`entryRowsFlat()`). Genau so
läuft `translation` sekundär auf `posts` mit, während `postmanager` (alphabetisch
davor) es besitzt. Der Settings-Editor zeigt den Owner je gewählter Collection an.

**Collection entfernen ist destruktiv.** Die betroffenen Einträge fallen aus dem
Workflow; ihre States, Zuweisungen, Historie und Kommentare werden gelöscht
(`purgeWorkflowData()`). `saveSettings()` verweigert das ohne
`confirm_collection_removal` und antwortet mit 422 +
`needs_collection_confirmation` und der Anzahl betroffener Einträge, damit der
Editor vorher nachfragt. Die Einträge selbst bleiben unverändert.

**Collection hinzufügen** braucht keinen Backfill: Einträge ohne gespeicherten
State fallen über `defaultState()` auf `published` bzw. `initial_place` zurück.
Wer explizite Zeilen möchte, nimmt „Status nachtragen" an der Zeile in
Settings > Workflows oder `php artisan workflows:seed-states` (beides idempotent). Zu beachten: `forceRevisionsOnWorkflowCollections()`
(ServiceProvider) und `withRevisionStep()` (DefinitionLoader) greifen zur Boot-
bzw. Lesezeit — Revisions und der `pending_revision`-Schritt sind erst ab dem
nächsten Request aktiv.

Verwaltet ein Workflow **mehr als eine** Collection, teilt die Manager-Seite
„Alle Beiträge" in einen Tab pro Collection auf (§ 5.2). Bei genau einer
Collection bleibt es eine einzelne Liste.

### Lokalisierungs-Workflows (`detail: translation`)

`WorkflowResolver::isLocalizationWorkflow()` ist der einzige Ort, der das
entscheidet. Die Aussage dahinter: *das Subjekt dieses Workflows ist die
Localization relativ zu ihrer Quelle*. Daraus folgt viererlei:

1. **Er legt nichts an.** Kein „Neuer Beitrag"-Button (`workflow()`), und
   `storePost()` antwortet mit **403** — auch für Super-Admins, denn das Anlegen
   gehört dem Autoren-Workflow. Die Besitz-Prüfung allein genügt dafür nicht: ein
   Übersetzungs-Workflow kann durchaus der einzige sein, der eine Collection
   verwaltet, und würde sie damit besitzen.
2. **Er besitzt die Site-Verteilung.** Neue Localizations entstehen über
   `transfer()`, nicht von Hand im Editor. Deshalb wird Statamics „Sites"-Karte
   im Editor ausgeblendet.

   **Noch nicht erzwungen:** die in `metadata.site_fields` genannten Blueprint-Felder
   (z. B. eine Portal-Taxonomie) sind aktuell **nicht** gesperrt. Der erste Versuch
   setzte sie per `Blueprint::ensureFieldHasConfig()` auf `read_only` — das ist
   unbrauchbar, weil **Blueprints gecacht werden**: die Sichtbarkeit darf nicht vom
   aktuellen Nutzer abhängen, sonst entscheidet der erste Request, der das Blueprint
   auflöst, die Form für alle danach (in der Praxis bekam ein Super-Admin die
   read-only-Variante, und ein `read_only`-Relationship-Feld öffnete einen leeren
   Picker-Stack). Ein Ersatz muss entweder für **alle** gelten (dann ist das
   Blueprint der richtige Ort) oder beim **Speichern** greifen.
3. **Sein „fertig" hängt an der Site-Anzahl**, nicht am `published`-Flag
   (§ 5.5.1).
4. **Ihm gehört jede Localization, dem Autoren-Workflow die Quelle.**
   `WorkflowResolver::forEntry()` entscheidet das pro **Eintrags-Version**:
   `isRoot() === false` und es gibt einen Lokalisierungs-Workflow für die
   Collection → der; sonst `forCollection()`. Nötig, weil `forCollection()`
   bewusst versionsunabhängig ist und first-wins über die alphabetisch sortierte
   Definitionsliste auflöst — `postmanager` vor `translation`, wodurch der Editor
   einer englischen Übersetzung die Aktionen des Beitragsmanagers zeigte.

   Das gilt für alles, was von einer Eintrags-Version ausgeht: das
   `workflow_actions`-Panel, die Detailseite **ohne** `?workflow=`-Parameter und
   die Listener `LogDraftSave`, `SyncWorkflowOnUnpublish`,
   `SyncWorkflowOnRevision` — damit steht die History einer Übersetzung
   vollständig beim Übersetzungsmanager statt auf zwei Workflows verteilt. Ein
   explizit mitgeschicktes `?workflow=` gewinnt weiterhin, sonst könnten die
   beiden Manager ihren eigenen Kontext auf einer geteilten Collection nicht
   halten.

   **Nicht** umgestellt: `InjectWorkflowFields` (entscheidet nur, *ob* das
   Panel injiziert wird — Blueprints sind gecacht, siehe Punkt 2), die
   Listing-Filter, die Settings-/Installer-Pfade und das `AssignedPosts`-Widget.
   Das sind Fragen auf Collection-Ebene.

   **Folge:** „Übersetzung starten" (`start_translation`, `site_modal`) hängt
   an der **Quelle** und ist damit im Beitrags-Editor nicht mehr sichtbar. Der Weg
   dorthin führt über die Detailseite bzw. Queue des Übersetzungsmanagers, die
   `?workflow=` explizit mitschicken.

Im Settings-Editor eines Lokalisierungs-Workflows kommt **eine** zusätzliche
Sektion dazu, und die zeigt nur, was für **diesen** Manager gilt:

| | Einfache-Sprache-Manager | Übersetzungsmanager |
| --- | --- | --- |
| Sektions-Titel | „Einfache Sprache" | „Übersetzung" |
| `translation_source_site` | ja | ja |
| `plain_language_site` | ja (Ziel-Site) | **nein** |
| Status-Zeilen | `PLAIN_LANGUAGE_URL`, `PLAIN_LANGUAGE_TOKEN` | `DEEPL_API_KEY` |
| `plain_language_resubmit`-Action wählbar | ja | nein |
| `start_localization`-Action wählbar | ja | nein |
| Sprach-Dialog an der Start-Transition | nein | ja (nicht konfigurierbar) |

`plain_language_site` erscheint **nur, wo der Key schon gesetzt ist** — also im
Einfache-Sprache-Manager selbst. Sonst könnte man den Übersetzungsmanager per
Dropdown zum Einfache-Sprache-Manager umwidmen und die beiden würden lautlos die
Rollen tauschen. Welche Art ein Workflow ist, entscheidet die installierte
Definition. Leeren bleibt möglich: es ist die Notbremse für eine Definition, die
den Wert versehentlich trägt (ältere `translation.yaml` taten das). Alles darüber
— Nav, Gruppen, Places, Transitions, SLA, Collections — ist für beide gleich.

#### Zwei Lokalisierungs-Manager auf einer Collection

`translation` und `plainlanguage` verwalten beide `posts`. Das trägt, weil jede
Zeile in `workflow_states`, `workflow_assignees`, `workflow_events` und
`workflow_comments` auf `(subject_id, workflow)` schlüsselt — ein Beitrag hat pro
Manager einen eigenen Status, eigene Zuweisungen und eine eigene Historie, und
über `InstanceUrl` auch je eine eigene Detail-URL.

Auseinandergehalten werden sie über die **Site, die ein Manager beansprucht**:

- `WorkflowResolver::localizationWorkflowFor($collection, $site)` liefert (1) den
  Workflow, der `$site` über `plain_language_site` beansprucht, sonst (2) den
  Localization-Workflow **ohne** Anspruch (der allgemeine Übersetzungsmanager),
  sonst (3) den ersten. Damit gehört eine `simple`-Version dem
  Einfache-Sprache-Manager und eine `en`-Version dem Übersetzungsmanager — im
  Entry-Editor, in den Versions-Pills, in den Save-Listenern und beim Redirect der
  ID-only-Route.
- `forCollection()` bevorzugt den **Nicht**-Localization-Workflow. Sonst würde
  `plainlanguage` alphabetisch vor `postmanager` gewinnen und der Beitragsmanager
  verlöre „Neuer Beitrag", `storePost()` und das Dashboard-Widget.
- „Fertig" heißt pro Manager etwas anderes: Einfache Sprache ⇔ eine Version in der
  beanspruchten Site existiert; Übersetzung ⇔ es existiert eine Version außerhalb
  von Quell-Site und fremd beanspruchten Sites. „Beitrag liegt in mehr als einer
  Site vor" wäre für beide falsch (eine englische Übersetzung ist keine Einfache
  Sprache).
- **Handles müssen eindeutig sein.** Place-Labels, Place-Farben und
  Transition-Labels werden global pro Handle aufgelöst (first-wins über alle
  Definitionen). `plainlanguage.yaml` benutzt deshalb `plain_ready`,
  `in_plain_language`, `final_approvement`, `published` und eigene
  Transition-Handles, nicht die des Übersetzungs-Workflows.
- Der Pivot eines Lokalisierungs-Managers zeigt nur die Spalten, für die er
  zuständig ist: **Quell-Site zuerst, dann die Ziele**. Die Quell-Spalte folgt
  `translation_source_site` — steht die auf `simple`, ist die Einfache-Sprache-Site
  die Spalte mit „Quelle bereit", und die Main-Site fällt aus diesem Manager heraus
  (sie ist Subjekt des Beitragsmanagers und Ziel des Einfache-Sprache-Managers).
  Eine fremd beanspruchte Site bleibt drin, wenn sie die eigene Quelle ist: eine
  Fassung trägt pro Workflow einen eigenen Status, dieselbe `simple`-Version ist
  also gleichzeitig „Freigegeben" im Einfache-Sprache-Manager und „Quelle bereit"
  im Übersetzungsmanager.
- Die große Liste eines Lokalisierungs-Managers heißt **„Übersetzbare Beiträge"**
  und enthält nur Beiträge, deren Quell-Fassung existiert UND in IHREM EIGENEN
  Workflow den finalen Place erreicht hat (`WorkflowResolver::sourceIsReady()`,
  dieselbe Prüfung wie am Start-Button) — eine Fassung, die dort noch mitten in
  der Prüfung steckt, ist noch nichts Fertiges zum Übersetzen, und die Start-Transition
  würde sie ohnehin ablehnen. Ohne Quelle gibt es erst recht nichts zu übersetzen.
  „Meine Warteschlange" und „Von mir erstellt" folgen
  derselben Regel, zusätzlich pro Fassung: eine Fassung in einer Site, die dieser
  Manager weder liest noch schreibt, erscheint dort nicht mehr. Das räumt zugleich
  Statuszeilen aus dem Blickfeld, die von einer früheren Quell-Einstellung stammen. Ohne konfigurierte Quelle fällt dadurch nichts weg (der
  Rückfall ist die Ursprungsfassung, die es per Definition gibt); mit
  `translation_source_site: simple` schrumpft die Liste auf die Beiträge, die eine
  Einfache-Sprache-Fassung haben.
- **Alle Links zeigen auf die Fassung, mit der dieser Manager arbeitet.** Der Klick
  auf eine Pivot-Zeile öffnet die Quell-Fassung (bei Quelle `simple` also die
  Einfache-Sprache-Fassung, nicht die Wurzel), und die Versions-Pills bleiben im
  aktuellen Manager, solange er für die Fassung zuständig ist — sonst zeigten sie in
  einen anderen Manager und dazu dessen Status, der dem Pivot widersprach.
- **Genau eine Fassung pro Beitrag startet eine Übersetzung**: die Quell-Fassung,
  und erst, wenn ihr eigener Workflow sie freigegeben hat. Auf jeder anderen Fassung
  ist die Start-Transition gesperrt und nennt die zuständige
  (`transition_start_at_source`) — sonst übersetzte man von der Fassung eines
  anderen Managers aus. Ebenso ist „neu erzeugen" an die beanspruchte Site
  gebunden: auf der deutschen Fassung angewendet würde es das Original mit
  Einfacher Sprache überschreiben.

##### Sets über den Dienst hinweg erhalten

Der Einfache-Sprache-Dienst liefert ein **Dokument**, nicht eine Antwort pro Feld. Es
gibt keinen Block, der `content_sets.2` entspricht, deshalb ersetzt das Ergebnis das
Inhaltsfeld vollständig — und dabei ginge die Set-Struktur verloren, wenn nichts sie
markiert. `PlainLanguageDocument` markiert sie in **beide** Richtungen:

| Richtung | Was passiert |
| --- | --- |
| hinaus (`texts()`) | Der Titel bekommt `<h1>`, das Intro **einen** `<div class="intro">`, jedes Segment innerhalb eines Sets ein `<div class="<set-handle>">`. Bei Sets eine Markierung pro **Segment**: ein Set mit Bard-Feld wird knotenweise extrahiert (`…values.content.0`, `.1`), jeder Knoten behält seinen eigenen Wrapper. **Das Intro ist die Ausnahme:** `intro` ist ebenfalls ein Bard, seine Absätze werden aber zu **einem** Block zusammengefasst — die Liste ist deshalb kürzer als die Segmentliste und nicht mehr index-gleich mit ihr. |
| herein (`apply()`) | Ein Wrapper wird wieder das Set, das er nennt. Benachbarte Wrapper desselben Sets verschmelzen zu **einem** Set. Ein `<h1>` wandert ins Titelfeld, ein `<div class="intro">` ins Intro**feld** — und ist damit aus `content_sets` heraus. |

Warum das Intro zusammengefasst wird: ein zweiabsätziges Intro extrahiert als
`intro.0` und `intro.1`, und je ein eigener Wrapper schickte **zwei** Blöcke los, die
beide behaupteten, die Einleitung zu **sein** — während `generate_intro` im Body dem
Dienst sagt, er solle diese Einleitung selbst schreiben. Eine Anweisung, zwei
Kandidaten: der Endpunkt musste raten. Zusammengefasst stellt sich die Frage nicht.
Dass die gesendete Liste dadurch kürzer wird als die Segmentliste, kostet auf diesem
Pfad nichts — der Dienst antwortet mit einem Dokument, nicht mit einer Antwort pro
Text, also ordnet niemand nach Position zu (`apply()` liest die Marker, die
`segment_keys` am Job sind Diagnose). Auf dem DeepL-Pfad wäre es ein Bruch, und genau
deshalb läuft der nie durch diese Klasse.

**Die Intro-Markierung geht hin und zurück.** Kommt sie in der Antwort wieder, nimmt
`document()` diesen Chunk aus dem Inhaltsdokument heraus und parst ihn mit dem
**Bard des `intro`-Feldes** statt mit dem von `content_sets` — die umgeschriebene
Einleitung landet also im Intro**feld** und sonst nirgends. `intro` wird dabei
ausdrücklich *nicht* als Set von `content_sets` gesucht: es ist keins, und genau die
Unbekannte-Klasse-Regel, die den Wrapper früher auffing (Wrapper weg, Inhalt bleibt
Prosa), war das Problem. Sichtbar war es auf der Seite: das Host-Template rendert
`intro` als **eigenen Block über** dem Inhalt (`_content-w-intro.blade.php`) — ein
Leser der Einfache-Sprache-Fassung bekam also erst die **deutsche** Einleitung und
darunter die umgeschriebene noch einmal als ersten Absatz. Das ist weg.

Geschrieben wird die Feld-eigene Form: eine Liste von ProseMirror-Knoten
(`[{type: paragraph, content: [{type: text, text: …}]}, …]`), dieselbe Form, in der
eine redaktionell getippte Einleitung im Entry-YAML liegt. Über `prose()`, also mit
denselben zwei Korrekturen wie loser Text sonst auch: nackter Text wird zum Absatz
(Bards Schema verwirft ihn sonst), und eine Überschrift wird nur dort zum
`heading`-Set, wo **dieses** Feld eines deklariert — das `intro`-Feld tut das nicht,
sie bleibt also ein einfacher Knoten und erbt nicht die Nummerierung des Inhaltsfelds.

**Degradation = das alte Verhalten.** Der Dienst *muss* den Wrapper nicht
zurückgeben. Fehlt er, gibt es keinen Intro-Chunk, es wird **nichts** ins Feld
geschrieben, und die umgeschriebene Einleitung ist wieder das, was sie vorher war: der
erste Prosa-Block in `content_sets`, während das Intro-Feld den deutschen Wert der
Kopie behält. Dasselbe greift bei einem Blueprint **ohne** `intro`-Feld und bei einem
Wrapper, dessen Inhalt zu nichts parst. Ein leeres Ergebnis überschreibt das Feld nie —
ein geleertes Intro wäre beim nächsten Resubmit nicht mehr von einem gewollten zu
unterscheiden.

**Kein Backfill für Bestandsfassungen.** Deren Einleitung steckt bereits als Prosa in
`content_sets` und lässt sich dort nachträglich nicht zuverlässig von den Absätzen
daneben trennen. Ein Resubmit schreibt einen solchen Beitrag ohnehin aus der Quelle neu
und bringt ihn dabei von selbst in Ordnung — das ist der vorgesehene Weg, nicht ein
Kommando.

Der Marker ist außerdem Voraussetzung für `"generate_intro": true` im Body
(`BODY_EXTRAS`, als JSON-Boolean): der Dienst schreibt die Einleitung selbst, statt die
mitgeschickte umzuschreiben — ohne die Markierung wüsste er nicht, welcher der Texte
die Einleitung ist. Hat ein Beitrag kein `intro`-Feld, geht auch keine Markierung mit,
das Flag hat nichts, worauf es sich beziehen kann, und es gibt auch nichts
zurückzuschreiben. Auf dem DeepL-Pfad gibt es den Marker nicht: der übersetzt Feld für
Feld und braucht keine Struktur im Text (siehe `TranslationRunner`, der die Segmente
ohne `PlainLanguageDocument` sendet).

Welche Sets es gibt und welches ihrer Felder den Text trägt, entscheidet die
Set-Konfiguration des Bard-Feldes selbst (`TranslatableText::setFields()`, erstes Feld
vom Typ bard/markdown/textarea/text) — nicht eine Liste im Addon. Folgen:

- **Unbekannte Klasse → ignoriert.** Der Wrapper fällt weg, sein Inhalt bleibt Prosa.
  Ein Set zu erfinden, das der Blueprint nicht hat, wäre schlimmer.
- **Set ohne Textfeld → ignoriert.** Ein `video` trägt URL und Embed-Code, kein Textfeld;
  gesendet wird es ohnehin nie, und aus der Quelle kommt es über
  `withUntranslatableSets()` an seinem Index zurück.
- **`<h2>`/`<h3>` → `heading`-Set**, wo das Feld eines deklariert. So drückt dieses
  Projekt eine Überschrift aus, und `heading_index` (die Nummerierung, die das
  Inhaltsverzeichnis druckt) wird in Dokumentordnung neu vergeben — sie ist eine
  Zählung, keine Ebene.
- **Nackter Text wird zu einem Absatz**, und zwar VOR dem Parsen: Bards Schema hat für
  Text auf oberster Ebene keinen Platz und verwirft ihn stillschweigend, der Satz wäre
  sonst verschwunden. Deshalb liest die Zerlegung die Kinder eines eigenen
  Container-Elements — mit `LIBXML_HTML_NOIMPLIED` allein verwirft libxml Text schon
  beim Laden.

Der Dienst ist nicht verpflichtet, die Wrapper zurückzugeben. Lässt er sie weg, ist das
Ergebnis das flache Dokument von vorher: Überschriften werden noch aus `<h2>` erkannt,
alles andere landet als Absätze. Es degradiert, es bricht nicht.

Gegenprobe für den ganzen Weg (Dienst gibt zurück, was er bekam): 35 Quellknoten mit 8
`heading`-Sets und einem `video` ergeben 37 Knoten mit 8 `heading`-Sets,
`heading_index` 1–8, einem `video` und keinem nackten Text-Knoten.

**Nicht gelöst:** Felder außerhalb des Inhaltsfelds gehen mit (`meta_keywords`) und ihr
umgeschriebener Text landet im Dokument, also in `content_sets` — die Felder selbst
behalten den kopierten deutschen Wert. Für Einfache Sprache wären nur `title` +
`intro` + `content_sets` sinnvoll; `intro` ist inzwischen gelöst (siehe oben), die
übrigen sind es nicht. Zwei Ausnahmen sind gezogen:
`PlainLanguageDocument::NEVER_SENT` (`seo`) wird auf diesem Pfad **nie** gesendet, weil
sonst die Meta-Beschreibung als Fließtext im Beitrag stünde. Deutsch bleibt `seo`
deshalb trotzdem nicht: `title` und `description` werden nach dem Rückschreiben aus
dem Ergebnis **abgeleitet** — siehe „Einfache Sprache leitet title/description ab".
Und `metadata.plain_language_clear_fields` leert Felder, die gar nicht erst umgeschrieben
werden sollen — siehe direkt unten.

##### Quellen überleben den Rewrite nicht

Eine Lokalisierung entsteht als **Vollkopie**. Jedes Feld, auf das der Rewrite keine
Antwort hat, behält damit den deutschen Text der Quelle. Für die meisten ist das
richtig. Für den Quellen-Block dieses Projekts (`dd_title` „Quellen" + `dd_text` mit der
wissenschaftlichen Referenzliste, eigener Blueprint-Tab „Quellen", Fieldset
`set_dropdown.yaml`) ist es das Gegenteil von dem, was die Seite verspricht: eine in
Einfacher Sprache geschriebene Seite mit einem Absatz voller DOI-Links darunter. Und der
Dienst kann das nicht beheben — diese Felder sind nicht Teil des Dokuments, mit dem er
antwortet.

Deshalb leert `apply()` nach Inhalt, Intro und SEO die Felder, die der Workflow unter
`metadata.plain_language_clear_fields` nennt (hier: `dd_title`, `dd_text` — gesetzt in
`resources/workflows/plainlanguage.yaml` des Host-Projekts).

**Geleert heißt beschattet, nicht entfernt.** Den Schlüssel aus den eigenen Daten einer
Lokalisierung zu nehmen leert das Feld nicht, es **stellt es wieder her**: Statamic löst
einen Wert als `$this->has($key) ? $this->get($key) : $origin->value($key)` auf
(`Data\HasOrigin`), und `has()` ist ein `array_key_exists` auf dem eigenen Array. Ohne
Schlüssel fällt also jeder Leser — Seite, CP-Formular, der Extractor des nächsten
Rewrites — wieder auf das deutsche Original durch. Ein **ausdrücklich leerer eigener
Wert** hält den Schlüssel da und antwortet allen dreien „leer". (Nullwerte überleben das
Speichern auf einer Lokalisierung übrigens auch: `Entry::fileData()` wirft sie nur
`if ($this->isRoot())` weg.)

**Aber nicht `null`** — ein `default:` im Blueprint füllt den wieder auf. `dd_title`
deklariert `default: Quellen`, und ein Null-Wert augmentiert zurück zu „Quellen":
beschattet in den Daten, sichtbar auf der Seite. Der leere Wert kommt deshalb aus der
**Form des Feldes**: ein listenförmiger Fieldtype (bard und Verwandte) wird `[]`, alles
andere `''`, und nur ein Handle, den kein Blueprint kennt, fällt auf `null` zurück —
dort gibt es nichts abzuleiten, und ein Feld, das niemand deklariert, hat auch keinen
Default, der es wieder auffüllen könnte (`PlainLanguageDocument::emptyValueFor()`).

Zwei Sicherungen:

- **Die drei Felder, die `apply()` selbst schreibt** (`content_sets`, `title`, `intro`),
  sind nie leerbar. Ein Workflow, der `content_sets` dort nennte, würde sonst genau den
  Rewrite löschen, den er gerade bekommen hat.
- **Nur der Einfache-Sprache-Manager.** `WorkflowResolver::plainLanguageClearFields()`
  gibt für jeden Workflow ohne `plain_language_site` eine leere Liste zurück, und der
  Writer lehnt die Kombination ohnehin ab. **Der DeepL-Pfad ist nicht berührt** — er
  kommt gar nicht erst durch diese Klasse: er antwortet pro Feld, schreibt jede Antwort
  an ihren Platz, und eine übersetzte Referenzliste ist eine korrekte Referenzliste.

Am Frontend hängt nichts daran: `<x-_sources>` rendert nur, wenn Titel **und** Text
gefüllt sind, ein leerer Wert schaltet den Block also von selbst ab.

##### SEO-Texte gehen nur an DeepL

Ein SEO-Pro-Feld (`seo`) wird auf dem **Übersetzungspfad** mit übersetzt und feldweise
zurückgeschrieben. Auf dem Einfache-Sprache-Pfad ist es ausgeschlossen (`NEVER_SENT`,
siehe oben) — dieser Dienst antwortet mit einem Dokument, und alles was hineingeht,
kommt darin auch wieder heraus.

Welche Unterschlüssel gehen mit: **`title`, `description`, `og_title`,
`twitter_title`, `twitter_description`** (`TranslatableText::SEO_TEXT_KEYS`).

Das ist die **eine** Stelle, an der nicht die Feldkonfiguration entscheidet — und das
ist Absicht. SEO Pro verpackt jeden Unterschlüssel in denselben
`seo_pro_source`-Fieldtype, und die inneren Typen trennen Sätze nicht von Tokens:
`canonical_url`, `og_type`, `twitter_handle`, `site_name_separator` und `priority` sind
ebenfalls `text`. Würde man den Fieldtype fragen, käme `article` als „Artikel" und eine
URL als Prosa zurück.

Zwei Schutzregeln:

- **`@seo:<handle>`-Werte gehen nicht mit.** Ein solcher Wert *verweist* auf ein anderes
  Feld („nimm das Intro"); übersetzt wäre der Verweis Prosa und die Vererbung still
  kaputt. Gemeldet als `seo_pro_reference` in der Payload-Vorschau.
- **Nicht-Text-Schlüssel** (`image`, `robots*`, `json_ld_schema`, `sitemap`, URLs,
  Handles) behalten ihren Wert. Sie erscheinen als `seo_pro_source` in der
  Übersprungen-Liste, aber **nur wenn sie etwas enthalten** — zwei Dutzend leere
  Robots-Zeilen würden die lesenswerten Zeilen begraben.

Ist SEO abgeschaltet (`seo: false`) oder nie berührt (`null`), gibt es nichts zu
laufen und nichts zu melden.

Die Texte fahren als `plain`-Segmente, also escaped hinaus und auf dem Rückweg
`strip_tags()` + Entity-Decode — ein `<strong>`, das ein Dienst der Meta-Description
hinzufügt, hat im `<title>`-Tag nichts zu suchen.

**Achtung Zeichenlimits.** SEO Pro begrenzt `title` auf 60 und `description` auf 160
Zeichen. Die Übersetzung wird nicht gekürzt — sie kann länger werden als das Original,
und das Feld beschwert sich erst beim nächsten Speichern im Publish-Formular.

##### Einfache Sprache leitet title/description ab

Auf dem Einfache-Sprache-Pfad geht `seo` nicht mit (siehe oben) — die Fassung startet
aber als deutsche Vollkopie, und das Write-back schrieb nur `title` + `content_sets`.
Folge: eine Fassung in Einfacher Sprache zeigte dem Leser Einfache Sprache und der
Suchmaschine die Sätze des Originals.

Deshalb werden die beiden Texte, die in einem Suchergebnis stehen, **aus der Antwort
abgeleitet** — kein zweiter Auftrag an den Dienst, denn beide sind schon drin
([PlainLanguageSeo](src/Translation/PlainLanguageSeo.php), aufgerufen in
`PlainLanguageDocument::apply()`):

- **`seo.title`** = der Titel, den die Fassung anzeigt: die umgeschriebene `<h1>`, und
  wo die Antwort keine hatte, der vom Ursprung geerbte Titel.
- **`seo.description`** = die umgeschriebene **Einleitung**, in dieser Reihenfolge
  gesucht: (1) die Intro-Knoten, die derselbe Aufruf gerade ins `intro`-**Feld**
  zurückgeschrieben hat, (2) sonst der **erste nicht-leere Top-Level-`paragraph`** der
  fertigen `content_sets`. Beides ist derselbe Text; welcher von beiden ihn trägt,
  hängt daran, ob der Dienst den Intro-Wrapper zurückgegeben hat
  (`PlainLanguageDocument::INTRO_FIELD`). Mit Wrapper ist die Einleitung ein eigenes
  Feld und **nicht mehr** der erste Absatz des Dokuments — der ist dann der Einstieg
  des Artikels und beschreibt die Seite schlechter. Ohne Wrapper wird nichts übergeben
  und es antwortet die Absatz-Regel, genau wie vorher.

Nie gelesen wird der **gespeicherte** `intro`-Wert. Eine Fassung, die keinen Wrapper
zurückbekommen hat, trägt dort weiter die deutsche Einleitung ihrer Kopie — daraus eine
Meta-Description abzuleiten wäre genau der Fehler, für den es diese Klasse gibt.
Angeboten werden nur Intro-Knoten aus **dieser** Antwort, deshalb sind sie ein
Parameter und nichts, was `PlainLanguageSeo` sich selbst holt. (Der Backfill übergibt
sie folgerichtig nicht: er läuft über Fassungen von vor dem Intro-Rückschreiben.)

Nur Top-Level-Absätze, und nur Absätze — für beide Quellen dieselbe Regel: der Text
eines `heading`-Sets ist eine Frage, keine Zusammenfassung, und Prosa in einer Hintbox
ist ein Detail. Ein Intro aus **Sets** statt Prosa (die Einleitung pro Entität) liefert
hier also nichts und fällt auf den Inhalt durch, was richtig ist — es gibt dann keine
eine Einleitung zum Zitieren. Hat **keine** der beiden Quellen einen Absatz
(Video-Beitrag), bleibt die Description, wie sie war — ein geerbter deutscher Satz ist
eine schlechte Description, eine leere eine schlechtere.

**Gemergt wird das ganze Array**, nicht die zwei Schlüssel. Statamics Origin-Durchgriff
arbeitet pro Top-Level-Key: eine Lokalisierung hat entweder ein eigenes `seo`-Array oder
erbt das des Ursprungs komplett — es gibt keine Vererbung pro Unterschlüssel. Nur
`{title, description}` zu schreiben würde also nicht „zwei Keys ergänzen", sondern ein
geerbtes `seo` **ersetzen** und `canonical_url`, OG-Bild und Robots-Flags mitnehmen.
Basis ist deshalb das eigene Array, wo es eines gibt, sonst das des Ursprungs (dessen
übrige Unterschlüssel damit materialisiert werden und erhalten bleiben), sonst `[]`.

Zwei Werte werden **nie** angefasst — dieselben zwei wie auf dem DeepL-Pfad:

- `seo: false` (SEO Pro für diesen Eintrag aus): ein Umschreiben würde das Feld als
  Nebenwirkung wieder einschalten;
- ein `@seo:<handle>`-Verweis: er *zeigt* auf ein anderes Feld, überschrieben wäre er
  ein Literal und die Vererbung still kaputt (`TranslatableText::SEO_REFERENCE`, hier
  gelesen statt neu geschrieben).

**Die Description wird gekürzt, der Titel nicht.** Eine umgeschriebene Einleitung ist
Prosa für einen Leser und läuft regelmäßig über die 160 Zeichen, und hier hat sie
niemand geschrieben, der sie kürzen könnte — also mb-sicher an der letzten Wortgrenze
auf ≤ 160 Zeichen inklusive „…", Satzzeichen am Schnitt fallen weg. Der Titel bleibt
ungekürzt wie auf dem DeepL-Pfad: er ist der Titel der Seite, und ein abgeschnittener
wäre falsch statt nur zu lang. Ein Beitrag mit langem Titel überschreitet damit SEO Pros
60-Zeichen-Empfehlung — sichtbar im Publish-Formular, wie bei einer Übersetzung.

**Ein Resubmit leitet neu ab**, absichtlich: derselbe Aufruf ersetzt den Inhalt, den
diese Texte beschreiben, komplett. Eine Handkorrektur der Redaktion geht dabei verloren
— das ist der Preis dafür, dass die Meta-Texte nicht etwas beschreiben, was der Beitrag
nicht mehr sagt, und er wird nur bei einem ausdrücklichen „nochmal umschreiben" fällig.
Der Backfill für Bestandsdaten ist genau umgekehrt gebaut (siehe unten).

**Am Englischen ändert das nichts.** Der Übersetzungspfad übersetzt `seo` weiter feldweise
mit DeepL; keine Codezeile dieses Pfads ist angefasst.

##### Bestandsdaten: `workflows:backfill-plain-language`

Der Live-Pfad greift erst für Umschreibungen ab jetzt. Alles, was schon im Projekt
liegt, trägt weiter die deutschen Meta-Texte **und** den deutschen Quellen-Block seiner
Kopie — dafür gibt es das einmalige Kommando
([BackfillPlainLanguageCommand](src/Console/BackfillPlainLanguageCommand.php)):

```bash
# Was würde passieren? (before → after je Unterschlüssel bzw. Feld)
php artisan workflows:backfill-plain-language --dry-run

# Nur ein Einfache-Sprache-Workflow
php artisan workflows:backfill-plain-language --workflow=plainlanguage

# Auch redaktionell geänderte Werte überschreiben
php artisan workflows:backfill-plain-language --force
```

**Zum Namen:** das Kommando hieß `workflows:backfill-plain-seo`, solange SEO alles war,
was es tat. Mit dem Leeren der `plain_language_clear_fields` wurde der Name falsch, und
weil das Kommando noch nicht ausgeliefert war, wurde es umbenannt statt mit einer
irreführenden Bezeichnung weiterzuleben. Es ist ab jetzt die Stelle, an der „was der
Live-Pfad getan hätte" auf das angewendet wird, was vor ihm entstand — ein Durchlauf
über die Einträge ist billiger als ein Kommando pro Regel.

Zwei Regeln in einem Durchlauf:

1. **`seo.title` / `seo.description`** aus dem eigenen Inhalt der Fassung ableiten;
2. **die Felder aus `metadata.plain_language_clear_fields` leeren** — mit demselben
   leeren Wert, den der Live-Pfad schreibt (`PlainLanguageDocument::emptyValueFor()`,
   also `''` bzw. `[]` je nach Feldform, und `set()` statt `remove()`: siehe „Quellen
   überleben den Rewrite nicht" für die Beschattung).

Gelaufen wird pro Workflow mit `metadata.plain_language_site` über alle Fassungen in
genau dieser Site. Entschieden wird durch **dieselben** Klassen wie live
(`PlainLanguageSeo::plan()`, `PlainLanguageDocument::emptyValueFor()`) — ein Backfill,
der es „fast wie" der Live-Pfad täte, hinterließe zwei Wahrheiten im Projekt.

Die Intro-Knoten werden dabei bewusst **nicht** an `plan()` übergeben: der Live-Pfad
bevorzugt die gerade zurückgeschriebene Einleitung, aber jede Fassung, über die dieses
Kommando läuft, ist älter als diese Regel — ihr Intro-Feld trägt noch die deutsche
Kopie, dort ist nichts zu bevorzugen.

Drei Tore vorher, jedes mit eigener Report-Zeile: eine noch **laufende** Umschreibung
(ihr Write-back leitet selbst ab), eine Fassung **ohne abgeschlossene** Umschreibung
(leere Kopie, Testfixture) und `seo: false`. Das dritte gilt **nur für die SEO-Hälfte** —
der Quellen-Block unter einer Seite in Einfacher Sprache ist auch dann falsch, wenn SEO
Pro für den Eintrag ausgeschaltet ist, also läuft das Leeren weiter und der Report sagt
`seo: switched off`. „Abgeschlossen" ist das
`plain_language_completed`-Event; für die Fassungen von vor diesem Event greift ein
Fallback: eigene `content_sets` vorhanden **und** vom Ursprung abweichend (eine
Lokalisierung entsteht als Vollkopie, eigener Inhalt allein beweist also nichts —
*abgewichener* Inhalt ist entweder eine Umschreibung oder redaktionelle Arbeit an der
Fassung, und beides ist ein Dokument, aus dem sich ableiten lässt). Der Titel wird
mitgeprüft, für den Fall, dass ein Projekt `content_sets` vom Lauf ausschließt.

**Eine Regel gehört nur dem Backfill: Skip-if-edited**, pro SEO-Unterschlüssel **und**
pro geleertem Feld. Ein Wert, den nach der Umschreibung jemand geändert hat, bleibt
stehen und wird als `edited` gemeldet. Der Live-Pfad überschreibt beim Resubmit (er
ersetzt im selben Aufruf den Inhalt, den die Werte beschreiben) — ein Backfill läuft
über Inhalte, die seit Monaten online sind, und wer dort eine Meta-Description getunt
oder die Quellenliste von Hand in Einfache Sprache umgeschrieben hat, hat das mit
Absicht getan. `--force` ist der ausdrückliche Weg daran vorbei. Geschrieben wird als
„edited" nur, was **nicht leer** und **nicht byte-identisch zur Kopie des Ursprungs**
ist.

Beim Leeren werden vier Fälle in dieser Reihenfolge gefragt: der eigene Wert **ist schon
der leere** → `unchanged` (das macht den zweiten Lauf still); **kein** eigener Wert und
beim Ursprung ebenfalls nichts → `unchanged` (es gibt nichts zu beschatten, und jeden
Eintrag dafür anzufassen wäre Unfug); **kein** eigener Wert oder einer, der
byte-identisch zum Ursprung ist → `write` (beides ist die Kopie, nicht jemandes Arbeit —
ohne eigenen Wert erbt die Fassung sie sogar live); ein eigener Wert, der vom Ursprung
**abweicht** → `edited`.

Ohne Workflow-Event, wie beim Timestamp-Backfill: das ist die mechanische Korrektur
eines Werts, den die Pipeline hätte liefern sollen, und keine redaktionelle Änderung —
eine History-Zeile pro Eintrag würde „geändert" an einem Tag behaupten, an dem niemand
etwas getan hat.

**Idempotent**: der zweite Lauf meldet alles als `unchanged`. Die Reihenfolge der Prüfungen
trägt das — „schon gleich" wird **vor** „editiert" gefragt, denn nach dem ersten Lauf weicht
der gespeicherte Titel ja gerade von der Kopie des Ursprungs ab. Beim Leeren gilt
dasselbe aus demselben Grund: der gespeicherte Wert **ist** dann der leere.

Gemessener Lauf in diesem Projekt (7 Fassungen in `simple`, 4 `zz-`-Fixturen ohne
abgeschlossene Umschreibung übersprungen): `dd_title: 'Quellen' → ''` auf allen sieben,
`dd_text: [n node(s)] → [empty]` auf den dreien, deren deutscher Ursprung überhaupt eine
Referenzliste trägt, `dd_text: unchanged (—)` auf den übrigen (dort hat auch der
Ursprung keine). Die SEO-Zeilen standen schon auf `unchanged`, weil der SEO-Teil vorher
gelaufen war; eine handkorrigierte Description wurde korrekt als `edited` stehen
gelassen. Der zweite Lauf: `Updated 0 version(s); 7 unchanged, 4 skipped.`

##### Ohne Veröffentlichung kein Status

Statamic-veröffentlicht ist die Obergrenze: ein Lokalisierungs-Workflow beginnt erst,
wenn der Beitrag öffentlich ist. Vorher hat der Beitrag dort **keinen Status** —
`derivedState()` antwortet `null`, was eine echte Antwort ist und nicht das Fehlen
einer: die Pivot-Zelle bleibt leer wie bei einer Site ohne Fassung, keine Transition
führt heraus, der Seeder schreibt keine Zeile, und die Detailseite nennt den Zustand
„Nicht gestartet". Ohne diese Antwort landete jeder unveröffentlichte Entwurf beim
Nachtragen in `source_ready` und behauptete, übersetzbereit zu sein.

Maßgeblich ist das Flag der **Ursprungsfassung**, nicht der Fassung selbst:
Localizations entstehen als Entwurf, und die beiden Lokalisierungs-Workflows haben
gar keinen `allow_publish`-Place — nach ihrem eigenen Flag gefragt, wäre eine
Einfache-Sprache-Fassung nie veröffentlicht und der Manager dauerhaft leer.

Eine **gespeicherte** Zeile gewinnt weiter über die Ableitung: was jemand angefangen
hat, behält seinen Status, auch wenn der Beitrag später entveröffentlicht wird.
Altlasten aus einem Backfill vor dieser Regel räumt `workflows:prune-states` als
Kategorie `unstarted` weg (im Referenzprojekt 35 Zeilen), und der Seeder legt nur noch
Zeilen für Sites an, die der Manager verantwortet — sonst hätte er genau die Zeilen
neu geschrieben, die der Prune als `foreign` entfernt.

Auf `null` vorbereitet sind: Pivot-Zellen und `_states`, die flachen Listen (eine
Zeile ohne Status ist keine Aufgabe), die Versions-Pills, die Detailseite,
`entryActions()` (keine Aktionen ohne Status), der Entry-Editor-Panel, das
Dashboard-Widget, `WorkflowTransitioner` (eigene Fehlermeldung statt „not allowed
from []") und `applyTransition()` (422 mit `workflow_not_started`).

##### „Quelle bereit" heißt: die Quelle ist fertig

Übersetzt wird nur aus abgeschlossener Arbeit. Die Quell-Fassung muss im **letzten
Place der Liste** stehen, die der Workflow definiert, **der sie verwaltet** — nicht
der, der übersetzen will:

| Quelle | verwaltet von | nötiger Status |
| --- | --- | --- |
| `de` (Main) | Beitragsmanager | `published` — der Beitrag muss veröffentlicht sein |
| `simple` | Einfache-Sprache-Manager | der letzte Place seiner Definition (live: `published`) |

`WorkflowResolver::finalPlace()` liest dazu den letzten Place der Definition, nachdem
`WorkflowDefinitionWriter::RESERVED_PLACE_HANDLES` abgezogen sind: `DefinitionLoader`
**hängt** `pending_revision` hinten an, und die konfigurierten Alterungsschritte stehen
meist ebenfalls hinter dem Publish-Place; ein nacktes
`array_key_last()` würde also auf einen Place zeigen, den niemand in den Settings
sieht. Der letzte Place ist bewusst Ordnung statt Flag — die sichtbare, im Editor
verschiebbare Reihenfolge. **Der Preis dafür:** hängst du einen Place ans Ende, der
zeitlich nach dem Abschluss kommt („Wird übersetzt"), wandert „fertig" stillschweigend
mit — und mit ihm jeder Leser von `sourceIsReady()`. Das andere Ende des Flows wurde
früher genauso aus der Position gelesen und wird es nicht mehr (`start_localization`
benennt die erzeugende Transition); dieses noch. Er ist **nicht** dasselbe wie `donePlace()`, das für
das Backfill zuständig ist und über `metadata.seed_published_place` gesteuert wird;
in allen mitgelieferten Definitionen fallen beide zusammen.

`WorkflowResolver::sourceReadiness()` ermittelt das über `forEntry()` und liefert dem
Aufrufer gleich die Bestandteile der Begründung. Zwei Sonderfälle:

- **`pending_revision` gilt als bereit** — die Live-Fassung *ist* veröffentlicht, es
  liegt nur eine unveröffentlichte Arbeitskopie daneben. Erkannt am Handle **und** am
  Metadatum: der Place existiert nur, solange die Collection Revisions hat, eine
  gespeicherte Zeile überlebt das Umschalten aber.
- **Kein verwaltender Workflow, oder der fragende ist es selbst** (eine Collection,
  die nur ein Lokalisierungs-Workflow verwaltet): Rückfall auf den
  Veröffentlicht-Status des Eintrags. Ohne diesen Rückfall stünde die Quelle dort per
  Definition immer im `initial_place` und wäre nie bereit.

Gilt für „Übersetzung starten" **und** „Einfache Sprache neu erzeugen" — ein Rewrite
liest dieselbe Quelle. Bei eingeschaltetem `translation_source_pick` sperrt es den
Knopf erst, wenn **keine** Fassung freigegeben ist; die Auswahlliste bietet ohnehin
nur freigegebene an.

Die Listen filtert das **nicht**: die Zeile bleibt sichtbar, nur der Knopf ist
gesperrt und nennt den Grund. Sonst verschwände ein Beitrag samt laufender
Übersetzungen aus dem Manager, sobald jemand die Quelle zurückwirft.

**Nebenwirkung, bewusst:** ein Beitrag, den die Alterung in eine ihrer Stufen
gesetzt hat, ist nicht bereit — er kann veröffentlicht bleiben, ist laut Workflow
aber überholt, und eine Übersetzung würde den überholten Stand festschreiben. Das
gilt automatisch, weil `finalPlace()` die konfigurierten Alterungsschritte abzieht
(§ 15).
- „Fertig" zählt weder die Quell-Site noch die **Ursprungsfassung** als Beleg. Ohne
  die zweite Ausnahme galt jeder unübersetzte Beitrag als fertig, sobald die Quelle
  eine andere Site als der Ursprung war — die deutsche Fassung war dann der Beleg.
- **Die Quell-Fassung selbst ist nie „fertig"**: sie leitet immer den `initial_place`
  ab. Sonst sprang sie mit der ersten Übersetzung in den Fertig-Place, und aus dem
  führt `start_translation` nicht heraus — eine zweite Sprache war dann nicht mehr
  startbar. Folge für den Einfache-Sprache-Manager: die „neu erzeugen"-Transition
  muss `initial_place` in ihrem `from` führen, weil die Quell-Fassung dort steht.
  An ihr liegen dann beide Schritte, und jeweils einer ist mit Begründung gesperrt —
  „erzeugen", solange eine Fassung existiert, „neu erzeugen", solange keine
  existiert.

Die Start-Transition des Einfache-Sprache-Managers öffnet **keinen** Dialog. Weil
damit auch die Auswahlliste als Duplikat-Schutz wegfällt, sperrt
`canApplyTransition()` sie, sobald die Ziel-Site schon eine Version hat (und
`transfer()` bietet die beanspruchte Site gar nicht mehr an, sonst entstünde eine
Version, die fertig aussieht und nie umgeschrieben wurde). Für einen misslungenen
Rewrite gibt es stattdessen eine Transition mit der Action
`plain_language_resubmit`: sie legt nichts an, sondern schickt den
Text erneut an den Dienst und überschreibt die bestehende Version — gesperrt,
solange noch ein Auftrag offen ist.

Privilegierte Nutzer (Super-Admin + `config/workflows.php → privileged_groups`)
sind von den Sperren aus 2. ausgenommen — konsistent mit allen anderen
Workflow-Gates, und der Editor ist die letzte Reparaturmöglichkeit für eine
kaputte Localization. Punkt 1 gilt dagegen für **alle**.

Gilt für **jeden** Lokalisierungs-Workflow, der die Collection verwaltet, nicht
nur den besitzenden — ein sekundärer Übersetzungs-Workflow besitzt die
Site-Verteilung genauso.

**Zur „Sites"-Karte:** die ist der eine Teil ohne server-seitige Naht.
`LocalizationsCard` ist in Statamics `PublishForm` **lokal** registriert (nicht
über `Statamic.component()` überschreibbar) und hängt am Computed
`showLocalizationSelector` = `localizations.length > 1`, gefüttert direkt aus
`EntriesController::edit`. `WorkflowActionsFieldtype.vue` blendet sie daher im DOM
aus und findet das Panel über Statamics eigene, server-seitig aufgelöste
Übersetzung (`sites_label`) statt über einen fest verdrahteten Text. Findet es
nichts, bleibt die Karte sichtbar — lieber nichts verstecken als das falsche
Panel.

### Automatischer Revisions-Loop

Wenn eine governed Collection Revisions aktiv hat, injiziert
`DefinitionLoader::withRevisionStep()` beim Laden zusätzlich:

- Place `pending_revision` (Label: „Unveröffentlichte Änderungen").
- Zwei `system`-Transitions: `mark_pending` (`published → pending_revision`) und
  `publish_revision` (`pending_revision → published`).

Diese Transitions werden **nie** über Buttons ausgelöst — sie sind der Kanal für
die Auto-Sync-Listener (siehe § 6).

### Bundled vs. Projekt-YAML

- Das Projekt (`resources/workflows/*.yaml`) gewinnt gegenüber den Bundled-Defaults
  des Addons (`addons/.../workflows/resources/workflows/*.yaml`).
- Alles was das Projekt nicht liefert, wird aus den Defaults geladen. So bleibt
  das Addon in andere Projekte hebbar.

Standard-Workflows im Addon:

- **post_update** — Idee → Draft → Editorial → Medical → Final → Korrektorat → Published (+ Reject).
- **translation** — Quelle → Übersetzung → Sprachlich → Medizinisch → Freigegeben (+ „Änderungen nötig"). Nutzt `detail: translation`.

---

## 3. ServiceProvider — Bootstrapping

**`src/ServiceProvider.php`**

Was er tut, in der Reihenfolge:

- **`register()`**
  - Registriert die Connection `workflow` (SQLite-Datei in `database/workflow.sqlite`).
  - Bindet vier Services `scoped` — siehe „Request-scoped Services" am Ende
    dieses Abschnitts.
  - Setzt `statamic.revisions.enabled = true` global (falls nicht per ENV
    explizit auf `false`), damit der Publish-Flow generell revisions-fähig ist.
- **`bootAddon()`**
  - `ensureDatabaseExists()` — legt die SQLite-Datei an, falls sie fehlt.
  - `loadMigrationsFrom(...)` — lädt die vier Migrationen.
  - `forceRevisionsOnWorkflowCollections()` — schaltet auf jeder governed Collection
    zur Laufzeit `revisionsEnabled(true)` an (nicht persistent in der Collection-YAML —
    das läuft per Request). Ohne Pro-Lizenz bleibt es effektiv aus (die
    Collection-eigene Prüfung faltet Pro wieder rein).
  - `bootNav()` — registriert nur noch den Super-Admin-Eintrag „Settings ›
    Workflows" (Index-Seite `workflows.settings.index`), mit einem Kind je
    Workflow mit `metadata.nav`, das direkt in dessen Settings-Editor führt.
    Die frühere eigene Nav-Sektion mit einem Eintrag pro Workflow (samt
    Anker-Trick für ihre Position über „Inhalt") ist entfernt;
    `config('workflows.nav_section')` wird nicht mehr gelesen. Die Manager
    bleiben unter `/cp/workflows/{slug}` erreichbar, Einstieg ist das
    Dashboard-Widget `workflows_overview` (§ 9).

    Registriert wird in `Nav::extend`, nicht eager: `cp_route()` wirft zur
    Boot-Zeit (Statamic lädt seine Routen in der Zeile *nach*
    `Statamic::runBootedCallbacks()`, Addon-Routen noch später), die CP-Locale
    steht erst ab der `statamic.cp.authenticated`-Middleware, und `NavBuilder`
    verändert die übergebenen `NavItem`-Objekte **in place** — deshalb frische
    Objekte bei jedem Build. Das `isSuper()`-Gate steht ebenfalls im Callback:
    `User::current()` gibt es zur Boot-Zeit nicht, und „nur Super Admin" lässt
    sich nicht als `can()` ausdrücken, weil Statamics Super-Admin-Bypass nur für
    registrierte Permissions greift.

    Nebeneffekt: die Workflow-YAMLs werden weiter nur bei `Nav::build()`
    gelesen, ein Frontend-Request liest also keine.
- **`$listen`** (Event-Handler)
  - `EntrySaved` → `SyncWorkflowOnUnpublish`, `LogDraftSave` (§ 6).
  - `RevisionSaved` + `RevisionDeleted` → `SyncWorkflowOnRevision` (§ 6).
  - `EntryBlueprintFound` → `InjectWorkflowFields` (§ 6).
- **`$policies`** — überschreibt `EntryPolicy` (§ 6).
- **`$widgets`** — `AssignedPosts`, `CreatedByMe`, `MyQueue`, `QueueStats`,
  `UserHeader`, `WorkflowStatus` (§ 9).
- **`$commands`** — `workflow:dump` (§ 9).
- **`$routes`** — mountet `routes/cp.php` unter dem CP-Prefix.
- **`$vite`** — kompiliert `resources/js/addon.js` + `resources/css/addon.css`.

### Request-scoped Services (`app()->scoped(...)`)

Vier Klassen sind in `register()` als `scoped` gebunden:

| Klasse | Was sie merkt | Wer sie mehrfach fragt |
| --- | --- | --- |
| `AssignmentCandidates` | die gefilterte Nutzerliste, pro Nutzer und Kriterium | die Instanzseite, zweimal pro Transition (`can_apply`, `pickable_assignees`) |
| `PersonalWorkItems` | fertige Zeilensätze, pro `assignedTo`/`createdBy` + Nutzer + Workflow-Einschränkung | `MyQueue` und `QueueStats`-Karte 1 fragen **dieselben** Zeilen |
| `WorkflowStateStore` | `statesFor($workflow)`, pro Workflow | `QueueStats` (Freigabe-Karte, `FailedTranslationRetry`) und jeder Ring in `WorkflowStatus` |
| `WorkflowEventStore` | `creatorsByWorkflow()` und `latestEventTimesByWorkflow()`, pro Workflow | die Meta-Zeile der Queue-Zeilen, `CreatedByMe`, die Karten-Notiz von `QueueStats` |

**Warum die Bindung überhaupt nötig ist.** Alle Aufrufer holen sich diese
Klassen über `app(Foo::class)`. Ohne Bindung ist das kein Container-Lookup,
sondern ein `new` — der Container baut jedes Mal eine frische Instanz. Ein Memo
auf einer Instanz-Property wäre damit wertlos, sobald zwei Widgets dieselbe
Frage stellen: jedes bekäme sein eigenes, leeres Memo. Die vier Bindungen sind
also nicht die Optimierung, sie sind ihre Voraussetzung.

**Warum `scoped` und kein persistenter Cache.** Ein `scoped` Binding lebt genau
einen Request (unter Octane: bis zum Ende des Requests, nicht bis zum Ende des
Workers) und stirbt mit der Response. Das ist der ganze Punkt: das Dashboard
soll **eine** Antwort zweimal benutzen, nicht die Antwort von gestern. Ein
Cache-Eintrag müsste über jeden Schreibweg der Anwendung invalidiert werden —
Transitions, Listener, `workflows:seed-states`, direkte DB-Eingriffe — und wäre
beim ersten vergessenen Pfad eine Zeitreise im Dashboard. Ein Request-Memo hat
dieses Problem nicht: es kann höchstens so alt werden wie der Request selbst.
`singleton` wäre aus demselben Grund falsch — unter Octane überlebte es den
Request und würde genau zum Cache, den wir nicht wollen.

**Was die Memos leert.** Innerhalb eines Requests kann sehr wohl geschrieben
werden (der Retry-Endpoint transitioniert Beiträge und zählt danach im selben
Request nach, was übrig ist). Deshalb wirft jede Schreibmethode das Memo des
betroffenen Workflows weg:

- `WorkflowStateStore`: `set()`, `migrateState()`, `deleteForSubjects()` →
  `forget($workflow)`.
- `WorkflowEventStore`: `log()` (**vor** dem Insert — ein Memo einen Moment zu
  früh wegzuwerfen kostet einen Re-Read, einen Moment zu spät verstecken es die
  gerade geschriebene Zeile), `deleteForSubjects()` → `forget($workflow)`.
- `PersonalWorkItems` hat keine Schreibmethoden; seine Zeilen hängen an den
  beiden Stores, die sich selbst leeren.

Beide `forget()` sind `public`, damit ein Aufrufer, der an den Stores vorbei in
die Tabellen schreibt (eine Migration, ein Test), das sagen kann.

**Nicht memoisiert, mit Absicht.** Die Einzel-Lookups (`WorkflowStateStore::get()`,
`WorkflowEventStore::forSubject()`, `latestOfType()`, `latestSnapshot()`) bleiben
ungecacht: es sind indizierte Ein-Zeilen-Abfragen, und es sind genau die, die
eine Transition unmittelbar nach ihrem eigenen Schreibvorgang liest. Gemessen im
Referenzprojekt: 282 `get()`-Aufrufe in einem Dashboard-Durchlauf kosten
zusammen 11 ms.

**Effekt**, ein Dashboard-Durchlauf mit sechs Widgets im Referenzprojekt
(282 zugewiesene Beiträge), gemessen in einem Prozess, einmal mit pro Widget
verworfenem Scope und einmal mit einem Scope für alle sechs:

| Widget | ohne Scope | Queries | mit Scope | Queries |
| --- | ---: | ---: | ---: | ---: |
| `QueueStats` | 88,5 ms | 1156 | 88,3 ms | 1156 |
| `WorkflowStatus` | 0,6 ms | 6 | 0,4 ms | 0 |
| `MyQueue` | 87,2 ms | 1140 | 0,2 ms | 0 |
| `CreatedByMe` | 0,5 ms | 6 | 0,4 ms | 6 |
| `UserHeader` | 0,0 ms | 0 | 0,0 ms | 0 |
| `AssignedPosts` | 80,1 ms | 1102 | 80,5 ms | 1102 |
| **Summe** | **256,9 ms** | **3410** | **169,8 ms** | **2264** |

`MyQueue` fällt komplett weg — es stellt exakt die Frage, die `QueueStats` als
erste Karte schon gestellt hat. `WorkflowStatus` spart seine sechs
`statesFor()`-Abfragen. `AssignedPosts` profitiert nicht: es geht über
`WorkflowAssigneeStore` (kein Memo) und löst seine Einträge selbst auf.

---

## 4. Routes

Datei: `routes/cp.php`. Alle Routes laufen unter dem CP-Prefix (Default `/cp`)
mit CP-Auth und Inertia-Middleware. Route-Namen bekommen `statamic.cp.` vorne
angehängt.

**Inertia-Seiten (Vue via Inertia)**

| Pfad | Handler | Zweck |
| --- | --- | --- |
| `GET /cp/workflows` | `queue` | Leitet aufs Dashboard um (`workflows.queue` bleibt für Bookmarks und alte Links). Die workflow-übergreifende Liste ist jetzt das Widget `workflows_overview` (§ 9). |
| `GET /cp/workflows/{slug}` | `workflow` | Manager für einen bestimmten Workflow (per `nav.slug`). Kein Nav-Eintrag mehr — erreichbar per URL und über die Workflow-Spalte von `workflows_overview`. |
| `GET /cp/workflows/{slug}/instances/{id}` | `showInWorkflow` | Detailseite (Content, History, Kommentare, Aktionen), kanonische URL (`InstanceUrl`). Der Zurück-Link führt aufs Dashboard. |
| `GET /cp/workflows/instances/{id}` | `show` | Id-only-Form für Bookmarks/ältere Links; leitet auf die kanonische URL um. |
| `GET /cp/workflows/settings` | `settingsIndex`* | Super-Admin-Übersicht aller Workflows (auch inaktive), „Settings > Workflows". |
| `GET /cp/workflows/settings/{workflow}` | `editSettings`* | Settings-Editor, per **Handle** (YAML-Name, `workflows.settings.edit`) — damit sind auch Workflows ohne `metadata.nav` editierbar. Ein Nav-Slug an dieser Stelle wird per 301 auf die Handle-URL umgeleitet; weder Handle noch Slug → 404. |

**JSON-API** (aufgerufen vom Vue-Frontend)

| Pfad | Handler | Beschreibung |
| --- | --- | --- |
| `GET  workflows/api/listing` | `listing` | Tabellendaten für die Queue (Suche, Filter, Sortierung, Pagination). `workflow=<name>` für einen Manager; `all_workflows=1` für die workflow-übergreifende Liste des Widgets `workflows_overview`, optional eingeengt per `workflows=a,b` — Widget-Config bzw. der eine per Button gewählte Workflow (unbekannte/inaktive Namen fallen still weg). Dazu optional `preset=awaiting_approval\|failed_translations\|published_this_week` (nur mit `all_workflows`): nur die Zeilen, hinter denen eine Karte der Kennzahlreihe steht (`StatsPresets`, § 9); unbekannte Namen werden ignoriert. |
| `GET  workflows/api/instances/{id}/actions` | `actionsJson` | Verfügbare Transitions ab dem aktuellen State. |
| `GET  workflows/api/instances/{id}/history/{event}/diff` | `historyDiff` | Diff eines History-Events vs. voriger/erster Snapshot. |
| `POST workflows/api/instances/{id}/transitions/{name}` | `applyTransition` | Transition anwenden. |
| `POST workflows/api/instances/{id}/assignees` | `setAssignees` | Assignees für diese Localization ersetzen. |
| `POST workflows/api/instances/{id}/state` | `setState` | State manuell setzen (nur Super/Admins). |
| `POST workflows/api/posts` | `storePost` | Neuen Post anlegen (ein Klick, nur `collection`). |
| `POST workflows/api/instances/{id}/transfer` | `transfer` | Localization in weitere Site(s) übertragen. |
| `POST workflows/api/instances/{id}/comments` | `storeComment` | Kommentar anlegen. |
| `POST workflows/api/instances/{id}/comments/read` | `markCommentsRead` | Passage als „gesehen" markieren. |
| `DELETE workflows/api/instances/{id}/comments/{comment}` | `deleteComment` | Kommentar löschen (nur Autor). |
| `GET  workflows/api/definitions` | `definitions` | Rohdaten aller Definitions für Diagramme/Debug. |
| `POST workflows/api/stats/retry-failed` | `retryFailedTranslations` | Sammel-Neustart aller geparkten Übersetzungen, die der Betrachter neu starten darf (Dashboard, § 9). |
| `GET  workflows/api/stats/state-subjects` | `stateSubjects` | Die Beiträge in einem (Workflow, State)-Paar, seitenweise — lazy nachgeladen von der Statusreihe (§ 9). Read-only, `?workflow=&state=&page=`, Antwort `{items, meta, manager_url}`. |
| `POST workflows/api/settings/install` | `installWorkflow` | Mitgelieferten Workflow-Typ ins Projekt kopieren. Nur noch **nicht** installierte Typen. |
| `POST workflows/api/settings/{workflow}/active` | `setWorkflowActive` | Workflow an-/abschalten (`active` in der YAML). |
| `POST workflows/api/settings/{workflow}/seed` | `seedWorkflowStates` | Status für bestehende Einträge nachtragen (nur aktive Workflows). |
| `POST workflows/api/settings/{workflow}/definition` | `saveDefinition`* | Speichern des Graph-Editors (Name `workflows.settings.save`). Payload `{version, definition: {places, transitions, metadata, collections, initial_place, marking_store, type}, confirm_collection_removal?, force?}`, Antwort `{data: {definition, version, purged, seeded, state_usage}}`. Siehe Versionierung und Backups unten. |
| `DELETE workflows/api/settings/{workflow}` | `destroyWorkflow` | Definition löschen; DB-Daten bleiben. |
| `POST workflows/api/{workflow}/settings` | `saveSettings`* | Editor-Payload speichern. `{workflow}` = Handle (Slug als Fallback). |
| `POST workflows/api/{workflow}/places/{place}/delete` | `deletePlace`* | Place löschen, ggf. mit `migrate_to`. `{workflow}` = Handle (Slug als Fallback). |

\* Die Super-Admin-Settings-Routen (Übersicht, Install, an/aus, Seed, Löschen,
Editor samt Save/Place-Löschen) laufen über `WorkflowSettingsController`, alles
andere über `WorkflowController`. Gemeinsame Helfer (`isSuperAdmin`,
`workflowBySlug`, `siteHandles`, `siteName`, `workflowForCollection`, `t`) liegen
im Trait `Http/Controllers/Concerns/WorkflowControllerHelpers.php`.

**Versionierung (409).** Die Editor-Seite bekommt `version` =
`WorkflowDefinitionWriter::fingerprint()` (`sha1_file` der YAML) und schickt sie
mit jedem `saveDefinition` zurück. Weicht sie vom aktuellen Fingerabdruck ab —
jemand anderes hat gespeichert, ein Deploy oder eine Hand-Änderung kam
dazwischen —, antwortet der Endpoint **409** mit `{message, conflict: true,
version, definition}` (aktueller Stand) statt zu überschreiben; `force: true`
überschreibt bewusst. Weitere Antworten: **422** `places_mismatch` (mit
`missing_places`), wenn `definition.places` einen bestehenden Place weglässt —
Places werden nie durch Weglassen gelöscht; **422** wie bei `saveSettings` für
unbekannte Collections, die Collection-Entfernungs-Bestätigung und
Writer-Validierungsfehler.

**Backups.** Bevor der Writer eine Workflow-YAML überschreibt oder löscht
(`persist()`/`save()`, `setActive()`, `delete()`, `resetToExample()`, außerdem
`WorkflowInstaller::rewriteCollections()`), kopiert `backup()` die aktuelle Datei
nach `storage/app/workflows/backups/<handle>/<Ymd_His_u>.yaml` und behält die
neuesten `config('workflows.editor_backups')` (Default 20, 0 = aus). Ist der
Inhalt identisch mit dem neuesten Backup, entsteht kein neues. Dort überleben
auch die Kommentare, die der YAML-Dump des Editors verliert.
`restoreBackup($name, $file)` spielt eines zurück.

---

## 5. Der Controller: `WorkflowController` im Detail

Datei: `src/Http/Controllers/WorkflowController.php`. Erbt von Statamics
`CpController` (bringt CP-Auth mit).

Ich gruppiere die Methoden nach Rolle.

### 5.1 Entry-basiert — ohne Ausnahme

Jedes Subjekt eines Workflows ist ein echter Statamic-Entry. Das Addon
**generiert keine Inhalte**. Frühere Releases hatten daneben einen Fixture-Pfad
(`resources/fixtures/dashboard.json` mit vier erfundenen Instanzen, dazu ein
`ai_video_generation`-Beispiel, das nur zu deren Anzeige existierte). Der ist
vollständig entfernt — inklusive der Endpoints `instancesJson`/`instanceJson`
und des `demo: true`-No-Ops in `applyTransition()`.

- `isEntryWorkflow($workflow)` — true, wenn die YAML ein nicht-leeres
  `collections:` hat. Andernfalls hat der Workflow keine Subjekte und
  `rowsFor()` liefert eine leere Liste.

Wer eine ID anfragt, die kein Entry ist, bekommt 404 — vorher konnte eine
Fixture-ID einen echten Entry überdecken, weil `findInstance()` **vor**
`Entry::find()` geprüft wurde.

### 5.2 Listing-Zeilen

- `entryRows($workflow, $collection = null)` — **Pivot**-Zeilen für den Manager:
  eine Zeile pro logischem Post (gruppiert über `root()->id()`), pro
  konfigurierter Site eine Spalte mit dem jeweiligen State. `$collection` engt auf
  eine der governed Collections ein — das schickt der Collection-Tab als
  `?collection=<handle>`. Ein Handle, den der Workflow nicht verwaltet, fällt auf
  „alle" zurück statt eine leere Liste zu liefern.
> **Pivot und persönliche Listen beantworten verschiedene Fragen — und filtern
> deshalb verschieden.** Der Pivot ist ein Angebot („woran kann ich anfangen") und
> wirft in einem Lokalisierungs-Workflow die **ganze Gruppe** raus, sobald die
> Quell-Fassung ihren eigenen Workflow nicht abgeschlossen hat
> (`sourceIsReady()`, `entryRows()`). Die persönlichen Listen sind eine
> Aufgabenliste („was liegt bei mir") und dürfen das nicht: eine Fassung, an der
> schon gearbeitet wird, verschwindet sonst aus der Queue ihrer Zuständigen, nur
> weil jemand das Original depubliziert hat. Deshalb fragt `entryRowsFlat()`
> zuerst den **Store** und ruft `sourceIsReady()` überhaupt nicht auf.
>
> Das sah lange wie ein Fehler aus, weil der Store-Blick nicht funktionierte:
> `$states` fehlte in der `use`-Klausel der Filter-Closure, `isset()` verschluckte
> die undefinierte Variable ohne Warnung, und die Prüfung fiel still auf
> `defaultState()` zurück — das für jede Fassung eines unveröffentlichten Posts
> `null` liefert.

- `entryRowsFlat($workflow)` — **flache** Zeilen (eine pro Localization) für
  „Meine Warteschlange" (`mine=1`) und „Von mir erstellt" (`created_by_me=1`).
  Filtert nur Localizations rein, die im gefragten Workflow angemeldet sind (via
  Store-State oder Collection-Ownership). Bleibt bewusst über alle Collections
  gemerged (persönliche To-do-Sicht) und führt dafür ein `collection`-Feld mit dem
  Collection-Titel.
  - `_created_by` kommt aus `WorkflowEventStore::creatorsByWorkflow()`, und das
    liest **zwei** Event-Typen: `created` (neuer Beitrag via `storePost`) und
    `localized` (Übersetzungsaufgabe via `transfer` bzw. `localizeInto`). Nur mit
    dem zweiten funktioniert „Von mir erstellt" im Übersetzungsmanager — dessen
    Subjekte bekommen nie eine `created`-Zeile. Innerhalb **eines** Workflows
    trägt ein Subjekt immer nur einen der beiden: ein Lokalisierungs-Workflow
    kann nicht anlegen, und `storePost` schreibt `created` für den Workflow, der
    die Collection *besitzt*.
- `rowsFor($workflow, $collection = null)` — dispatcht zwischen den drei je nach
  Kontext.
- `overviewRows($workflows)` — die Pivot-Zeilen **mehrerer** Workflows in einer
  Liste, für `listing?all_workflows=1` (Widget `workflows_overview`, § 9). Der
  Scope kommt aus `overviewWorkflows()`: alle aktiven Entry-Workflows aus
  `DefinitionLoader::all()` in Definitionsreihenfolge, optional eingeengt per
  `workflows=a,b` bzw. Widget-Config. Ein Post, den zwei Workflows steuern,
  erscheint **einmal je Workflow** — sein Stand in jedem ist eine andere Frage.
  Die Zeilen-ID wird deshalb zu `"{workflow}:{rootId}"`; verlinkt wird nicht über
  sie, sondern über `url`/`urls`. Jede Zeile trägt zusätzlich `workflow`,
  `workflow_label`, `workflow_color` (derselbe Punkt wie in den anderen Karten),
  `manager_url` (null ohne `metadata.nav`) und `sites` — die Sites, die IHR
  Workflow listet, damit die Tabelle „nicht die Spalte dieses Workflows" von
  „keine Fassung in dieser Site" unterscheiden kann.

### 5.3 Site-Handling (Multi-Site)

- `siteHandles()` — alle konfigurierten Statamic-Sites, umsortiert so, dass
  Sprachvarianten direkt neben ihrer Basis stehen (`main`, `main_en`, `main_fr`
  → aufeinanderfolgend).
- `siteGroup($handle)` → `[baseSite, langCode]`. Konvention: `{base}_{lang}`;
  falls kein `_`-getrennter Base-Site existiert, ist der Handle sein eigener
  Base und die Sprache kommt aus dem Site-Locale.
- `flagCountry($locale)` — mappt einen Sprachcode auf einen ISO-3166-Code für
  `flag-icons` (nur `en → gb`, sonst identisch).
- `siteName($handle)` — Statamic-Site-Name für die Anzeige.

### 5.4 Columns / Filter

- `columns($workflow, $mine)` — Spalten-Definition für die Listing-Komponente.
  Pivot (Entry-Workflow, nicht „mine"): Titel + je Site eine State-Spalte
  (gruppiert nach Base-Site). Sonst: Titel/State/Site/Assignees. „My Queue" beim
  Entry-Workflow ist wieder flach — und bekommt bei mehr als einer governed
  Collection zusätzlich eine `collection`-Spalte, weil diese Liste über alle
  Collections hinweg gemerged bleibt.
- `applyColumnVisibility($columns, $param)` — der CP-Column-Customizer sendet
  die sichtbaren Spalten als CSV. Titel bleibt immer sichtbar.
- `filters($rows, $workflow, $scope = null)` — baut je nach Kontext `SiteFilter`,
  `StateFilter`, `AssigneeFilter`. Pivot-View hat nur den State-Filter (Site ist
  ja die Spalte). Mit `$scope` (Übersicht) dieselben zwei: `StateFilter` mit der
  **Vereinigung** der Places aller Workflows im Scope (geteilte Places einmal)
  und `StaleFilter`. Einen Workflow-Filter gibt es nicht — das Einengen auf
  einen Workflow machen die Buttons des Widgets über `workflows=<name>` (§ 9).
- `overviewColumns($workflows)` — Spalten der Übersicht: Titel, `workflow_label`
  (Typ `workflow`; nur bei mehr als einem Workflow im Scope sichtbar), eine
  State-Spalte je Site, die mindestens ein Workflow im Scope listet
  (`overviewSites()`, in sites.yaml-Reihenfolge), und `content_updated_at`
  (sichtbar, sobald ein Nicht-Lokalisierungs-Workflow im Scope ist).
- `overviewProps($only, $default = null)` — Startprops des Widgets
  `workflows_overview`: `columns`/`filters` des ganzen Scopes („Alle"),
  `workflowOptions` (`[{value, label, color, columns, filters}]` je Workflow im
  Scope, nach `metadata.nav.order` sortiert — Button plus Spalten/Filter der auf ihn eingeengten Liste; die Zeilen
  werden dafür einmal gebaut und je Workflow geteilt), `defaultWorkflow`,
  State-Farben/-Labels und `creates` (je Workflow, in dem der User anlegen darf,
  dieselben Create-Props wie die Manager-Seite, aus `createOptions()`). Null bei
  leerem Scope.
- `overviewDefaultWorkflow($scope, $preferred)` — der beim Laden aktive Button:
  `$preferred` (Widget-Config `default_workflow`), wenn im Scope; sonst der erste
  Nicht-Lokalisierungs-Workflow, der eine Collection besitzt (der
  Beitragsmanager — unabhängig vom Anlegerecht des Users), dann der erste
  Nicht-Lokalisierungs-Workflow, dann der erste.
- `filterOptions()` / `filterBadges()` — Options + Aktive-Filter-Anzeige.
- `decodeFilters()` — dekodiert die base64/JSON-`filters`-Query.

### 5.5 State / Assignee / Berechtigung

- `defaultState($raw, $entry)` — dünner Wrapper auf
  `WorkflowResolver::derivedState()`, siehe § 5.5.1.
- `assigneeIds($entry, $workflow, $map=null)` / `assigneeNames()` — pro
  Localization; nutzen den optionalen `byWorkflow`-Map, damit Listings nicht pro
  Zeile query'n.
- `stateLabel($state)` / `transitionLabel($name, $fallback)` — lokalisierte
  Labels: erst `workflows::messages.state_<X>` / `action_<X>`, dann YAML-Label,
  dann Humanized-Handle.
- `isPrivilegedUser()` — Super-Admin **oder** Mitglied der Gruppe `admins`.
- `canCreatePost($raw)` — Super-Admins immer; sonst Mitgliedschaft in einer der
  Gruppen aus `postCreatorGroups($raw)`: `metadata.post_creator_groups` des
  Workflows (im CP editierbar) schlägt `config/workflows.php →
  post_creator_groups`. **Beide sind standardmäßig leer** — Inhalte anlegen ist
  eine Berechtigung, die das Addon nicht von sich aus vergibt. Eine leere Liste
  in der YAML ist eine bewusste Aussage („nur Super-Admins") und überschreibt die
  Config; sie wird deshalb als `[]` geschrieben und nicht weggelassen.
- `canApplyTransition($transition, $entry, $workflow)` —
  1. Privileged → immer ja.
  2. Sonst: Nutzer muss Assignee dieser Localization sein.
  3. Falls die Transition `required_group` deklariert: Mitgliedschaft in einer
     dieser Gruppen erforderlich.
- `transitionReason(...)` — lokalisierter Grund für ein `disabled` (fehlender
  Assignee-Status oder fehlende Gruppe).
- `requiredGroups($transition)` — normalisiert String/Array-Form.
- `canTransfer($workflow)` — Privileged oder Mitglied in der
  `default_assignee_group` des Ziel-Workflows. Fehlt der Key, darf nur Privileged
  überführen.

#### 5.5.1 Status-Ableitung — eine einzige Autorität

Ein Eintrag, der es schon gab, bevor ein Workflow ihn verwaltete, hat **keine
Zeile** in `workflow_states`. Wer den Status braucht, fragt
`WorkflowResolver::stateFor($entry, $workflow, $raw)` — gespeicherte Zeile, sonst
`derivedState()`.

**`derivedState($raw, $entry)` ist die einzige Stelle, die „welcher Status ohne
Zeile" beantwortet.** Sie wird von den Lesepfaden (Listings, Summary,
`workflow_actions`-Fieldtype, Publish-Gate, Widget) **und** vom
`WorkflowStateSeeder` benutzt. Genau das ist der Punkt: eine angezeigte und eine
persistierte Ableitung dürfen nie auseinanderlaufen. Vorher gab es vier
verschiedene Varianten — der Transitioner hatte gar keine, was zu einem 500er
`Transition [x] not allowed from []` führte (die leeren Klammern waren ein
`null`).

Die Antwort kann auch **`null`** sein — „gehört (noch) nicht zu diesem Workflow".
Das ist eine echte Antwort, keine fehlende: siehe § „Ohne Veröffentlichung kein
Status" bei den Lokalisierungs-Managern.

**Lokalisierungs-Workflow** (`metadata.detail: translation`), in dieser Reihenfolge:

| Fall | Ergebnis |
|---|---|
| Beitrag (Ursprungsfassung) nicht veröffentlicht | **`null`** |
| Die Fassung IST die Quelle dieses Workflows | `initialPlace()` — eine Quelle ist nie „fertig" |
| Eine Site, die dieser Manager verantwortet, existiert | `donePlace()`, sonst `initialPlace()` |
| sonst | `initialPlace()` |

„Verantwortet" heißt: beanspruchte Ziel-Site beim Einfache-Sprache-Manager, alles
Unbeanspruchte außer Quelle und Ursprung beim Übersetzungsmanager
(`ownsAnySite()`). Früher stand hier „existiert in mehr als einer Site" — das zählte
eine englische Übersetzung als Einfache Sprache und umgekehrt.

**Jeder andere Workflow** — maßgeblich ist der `published`-Flag:

| Fall | Ergebnis |
|---|---|
| Eintrag veröffentlicht | `donePlace()`, sonst `initialPlace()` |
| Eintrag unveröffentlicht | `initialPlace()` |

Bulk-Aufrufer, die die Gruppe schon kennen (`entryRows()`, `entryVersions()`, der
Seeder), geben die vorhandenen Site-Handles als dritten Parameter mit und sparen
den Descendants-Lookup. Ein Boolean „spannt mehrere Sites" wäre zu wenig: die
Frage ist site-genau, und zwei Hinweise, die sich widersprechen können, sind
schlimmer als einer.

- `initialPlace($raw)` — `initial_place`, sonst der **erste deklarierte Place**.
  Nie das Literal `'draft'`: ein Handle, den der Workflow nicht kennt, hätte keine
  ausgehende Transition und der Eintrag wäre festgefahren.
- `donePlace($raw)` — was „fertig" bedeutet, jeder Schritt gegen `places`
  validiert: `metadata.seed_published_place` → sonst `publishPlaces()[0]` → sonst
  `null`. Nötig, weil „fertig" nicht immer ein Publish-Place ist: `translation`
  endet in `approved` und hat gar kein `allow_publish`.

**Ausnahme — die beiden State-Listener.** `SyncWorkflowOnUnpublish` und
`SyncWorkflowOnRevision` lesen den Store absichtlich direkt und steigen bei
`null` aus. Mit Fallback würden sie eine Zeile *anlegen* und damit einen Entwurf
in den Workflow „wiederbeleben", nur weil eine Arbeitskopie angefasst wurde.

#### 5.5.2 Seeding: `WorkflowStateSeeder`

`seed(string $workflow, ?array $collections = null): int` trägt Status für
vorhandene Einträge nach. Der Wert kommt aus `derivedState()`. Ein Query für die
ganze Menge (`statesFor()`), Skip bei vorhandener Zeile → **idempotent**. Eine
unfilterte Entry-Query liefert jede Localization als eigenen Eintrag mit eigener
Id — also genau die Granularität von `workflow_states.subject_id`.

Schreibt **nur** State-Zeilen: kein `syncPublishedFlag` (Seeding darf nichts
veröffentlichen), keine Assignees, keine Events, keine Revisions. Ein Backfill
über hunderte Einträge soll keine hunderte History-Einträge für etwas erzeugen,
das kein Mensch getan hat.

Vier Aufrufer:
1. `workflows:seed-states [--workflow=] [--redo]` — manuell, warnt wenn ein
   Workflow keinen Fertig-Place hat. Einzige Stelle mit `--redo`.
2. `workflows:install` bzw. die Backfill-Checkbox im CP-Installer, beide über
   `WorkflowInstaller::backfill()` — nur bei der **Erst**installation.
3. `saveSettings()` — beim **Hinzufügen** einer Collection; Gegenstück zum Purge
   beim Entfernen. Anzahl steht als `seeded` in der Antwort.
4. `seedWorkflowStates()` — „Status nachtragen" je Zeile in der
   Settings-Übersicht, für den ganzen Workflow und jederzeit wiederholbar
   (§ 5.9). Nur bei aktiven Workflows.

Warum das nötig ist: ohne Zeile nimmt ein Eintrag **nicht** an der SLA-Alterung
teil (`TransitionAgedPostsCommand` arbeitet über `subjectsInState()`), und ein
Workflow, der die Collection nicht besitzt, zeigt eine leere persönliche
Warteschlange — Teilnahme wird über einen gespeicherten Status signalisiert
(`entryRowsFlat()`).

##### `--redo`: bestehende Zeilen neu ableiten

Idempotenz hat eine Kehrseite: ändert sich die Ableitungsregel (oder wird
`seed_published_place` nachgetragen), korrigiert ein erneuter Lauf die schon
vorhandenen Zeilen **nicht** — sie werden übersprungen. Dafür gibt es `--redo`.

Der Schutz dabei ist das Event-Log: Seeding schreibt keine Events, also ist
„dieses Subjekt hat **keinen** Event in diesem Workflow" ein exakter Beweis, dass
der aktuelle Status nur aus einem früheren Seed stammen kann.
`WorkflowEventStore::subjectsWithHistory()` liefert die Gegenmenge, und alles
darin bleibt unangetastet — ein Beitrag, den jemand per Transition oder
`set_state` bewegt hat, wird nie überschrieben. Zeilen, deren Wert schon stimmt,
werden ebenfalls nicht angefasst, damit die Zählung ehrlich bleibt.

**SLA-Nebenwirkung:** eine frisch geseedete Zeile trägt `updated_at = jetzt`.
`TransitionAgedPostsCommand` misst das Alter aus
`workflow_events.to_state = <publish>` und fällt sonst auf
`workflow_states.updated_at` zurück — für Altbestand ohne Publish-Event startet
die Alterung damit ab dem Seeding, nicht ab der ursprünglichen
Veröffentlichung.

##### Gegenstück: `workflows:prune-states`

```bash
php artisan workflows:prune-states [--workflow=] [--dry-run] [--force]
```

Seeding trägt nach, was ein Workflow dazubekommt; dieser Befehl entfernt, was er
verloren hat. Drei Gründe, getrennt ausgewiesen, weil sie unterschiedlich viel
Vertrauen verdienen:

| Kategorie | Bedeutung |
| --- | --- |
| `deleted` | Der Eintrag existiert nicht mehr. Kein Weg führt zurück, also bedingungslos. |
| `ungoverned` | Die Collection des Eintrags steht nicht mehr in `collections`. Dasselbe, was `saveSettings()` beim Entfernen einer Collection aufräumt — hier für von Hand editierte YAML. |
| `foreign` | Die Fassung liegt in einer Site, für die dieser Workflow nicht zuständig ist (`WorkflowResolver::managedSites()`). Trifft einen Lokalisierungs-Manager, wenn die Quell-Site wechselt: mit `translation_source_site: simple` ist der Übersetzungs-Status jeder deutschen Fassung ein Rest der vorherigen Einstellung. |

`ungoverned` und `foreign` verschonen jedes Subjekt mit Historie, Zuweisungen oder
Kommentaren in diesem Workflow — dieselbe Schutzregel wie bei `--redo`, aus derselben
Quelle (`subjectsWithHistory()`). Die Anzahl steht als `protected` in der Tabelle.

**Bewusst nicht** entfernt werden Zeilen, die nur wiederholen, was `derivedState()`
ohnehin berechnet. Gespeicherter Status ist klebend, abgeleiteter folgt dem Eintrag:
löschte man sie, fiele der Beitragsmanager beim nächsten Entveröffentlichen still auf
den Startplace zurück. Das wäre keine Bereinigung, sondern eine Verhaltensänderung.

Ohne `--dry-run` fragt der Befehl einmal nach; `--force` überspringt das für Cron.
Er läuft auch über abgeschaltete Workflows: `active: false` heißt nicht gelöscht,
und deren Reste sind genauso tot.

Gelöscht wird über `WorkflowDataPurger` — dieselbe Klasse, die das Entfernen einer
Collection und der `EntryDeleted`-Listener benutzen. Sie kennt alle sechs Tabellen
(States, Assignees, Events, Kommentare samt Lesemarken, Einfache-Sprache-Aufträge);
eine siebte kommt dort dazu und gilt sofort für alle drei Aufrufer.

**Damit Kategorie `deleted` nicht neu entsteht**, räumt
`Listeners\PurgeWorkflowDataOnEntryDeleted` beim Löschen eines Eintrags dessen Zeilen
in allen Workflows weg. Vorher blieben sie liegen: im Referenzprojekt 32 State-, 95
History- und 9 Zuweisungszeilen, die im CP niemand mehr erreichen konnte.

### 5.6 Detailseite (`show`)

Die Vue-Detailseite bekommt via Inertia diese Props (echter Entry-Pfad):

- `instance` — id, workflow-name, subject (Kind: `entry`, id, collection, title,
  site, site_name, lang, cp_url), current_state, assignee_ids/names.
- `content` — Preview-Blocks (§ 5.10).
- `versions` — pro Base-Site die vorhandenen Sprachversionen mit State +
  Deep-Link (`entryVersions`).
- `history` — chronologisches Event-Log (`entryHistory`).
- `historyDiffUrlTemplate` — Templatstring, den die UI mit der Event-ID füllt.
- `comments` — Kommentare inkl. `is_new`/`can_delete`. `is_new` markiert die Sets mit
  ungelesenen Kommentaren (§ 5.9).
- `commentUrl` — Endpoint zum Anlegen weiterer Kommentare.
- `actions` — verfügbare Transitions (`entryActions`).
- `applyUrls` — je Transition der Apply-Endpoint.
- `assigneeUrl` / `assignableUsers` — Assignee-Drawer.
- `canSetState` / `stateUrl` / `places` — Admin-Escape-Hatch „State manuell setzen".
- `queueUrl` — Zurück-Link zum passenden Manager.

Bei `metadata.detail === 'translation'` werden zusätzlich `source`, `sourceName`,
`sourceLang`, `isSource`, `canTransfer`, `transferUrl`, `transferSites` gesetzt
und die Vue-Seite `WorkflowsTranslationInstance` gerendert (Quelle links,
Übersetzung rechts).

#### Workflow-Kontext: `?workflow=` ist Pflicht

`buildInstancePayload()` löst den Workflow über
`workflowFor($entry, $request->query('workflow'))` auf: der angefragte Workflow
gewinnt, wenn er existiert **und** die Collection des Entrys verwaltet — sonst
greift First-Wins (§ 2 → Governed Collections). Da eine Collection von mehreren
Workflows verwaltet werden kann, muss jeder Aufrufer seinen Kontext mitschicken.
Der Summary-Stack der Manager-Seite tut das über `summaryUrlTemplate`, das der
Controller bereits mit `?workflow=<name>` ausliefert.

**Regel:** *jede* URL im Instance-Payload trägt den aufgelösten `$workflow`. Der
Client postet diese URLs unverändert zurück und kann einen falschen Kontext nicht
korrigieren — eine fehlende Angabe erzeugt also keinen Fehler, sondern schreibt
stillschweigend State in den falschen Workflow. Genau daran ist der Bug
entstanden, dass der Übersetzungsmanager im Stack die Places und Transitions des
Beitragsmanagers zeigte.

Einzige Ausnahme: `historyDiffUrlTemplate` — `historyDiff()` liest den Workflow
aus der `WorkflowEvent`-Zeile, die ist selbstbeschreibend.

Der Summary-Endpoint (`showJson`) liefert `component` weiterhin wahrheitsgemäß,
aber `WorkflowQueue.vue` wertet es nicht aus: die Queue rendert immer den
generischen Stack (`WorkflowInstanceDetail`), weil der generische Prop-Satz für
jeden Workflow vollständig gebaut wird und die Detail-Extras nur angehängt
werden. Die spezialisierten Ansichten bleiben der Vollseiten-Route vorbehalten.

### 5.7 Actions ausliefern (`entryActions`)

`entryActions($entry, $workflow, $current)`:

- Filtert `transitions` auf solche, die (a) nicht `system: true` sind und (b)
  vom aktuellen State ausgehen.
- Für jede: `transition.name`, `transition.label`, `can_apply` (bool),
  `reason` (nullable string), `needs_pick` (bool, ob eine **manuelle**
  `user_assignment`-Action gesetzt ist — eine automatische öffnet nichts und
  lässt das Flag `false`), `pickable_assignees` (die Kandidaten der Action mit
  `site_allocation`, leer wenn kein Dialog geöffnet wird).

### 5.8 Transition anwenden (`applyTransition`)

Reihenfolge im Server:

1. Entry laden, Workflow bestimmen, YAML laden.
2. Prüfen ob `current in transition.from` — wobei `current` aus
   `WorkflowResolver::stateFor()` kommt (§ 5.5.1), nicht roh aus dem Store.
   Sonst **422**.
3. `canApplyTransition` — sonst **403** mit `transitionReason`.
4. Falls `user_assignment`-Action:
   - `mode: manual` — Request muss `assignees` enthalten, und **jeder** genannte
     User muss in der Kandidatenmenge liegen (`AssignmentCandidates`, dieselbe
     Quelle, aus der der Dialog gefüllt wurde). Sonst **422**.
   - `mode: automatic` — mitgeschickte Assignees werden **ignoriert**; zugewiesen
     wird die Kandidatenmenge. Ist sie leer, **422** (unerreichbar, weil
     `mechanicalBlocker()` schon vorher sperrt).
5. **State setzen** (`WorkflowStateStore::set`).
6. **`syncPublishedFlag`** — setzt `entry->published()` gemäß State und speichert
   den Entry. Drei Ausstiege, in dieser Reihenfolge: `revision_pending` →
   unangetastet; **`publishPlaces()` leer → unangetastet**, weil der Workflow das
   Veröffentlichen dann nicht steuert (dieselbe Regel wie `canPublish()` und
   `SyncWorkflowOnUnpublish`); sonst `allow_publish` → true, alles andere → false.

   Der zweite Ausstieg ist wichtig: ohne ihn depubliziert **jede** Transition
   eines sekundären Workflows live Inhalte — `translation` deklariert kein
   `allow_publish`, also war `shouldPublish` dort immer `false`.
8. Assignees:
   - Bei einer `user_assignment` → **nur** die gewählten (manuell) bzw. die
     getroffenen (automatisch) User als Assignees.
   - Sonst **gar nichts** — die Verantwortlichen bleiben, wie sie waren.
9. `WorkflowEventStore::log('transition', …)` mit `data_snapshot` des frischen
   Entry.
10. `createStatamicRevision(...)` — best-effort, schluckt Fehler.
11. Response: `{ data: { id, current_state } }`.

### 5.9 Weitere Mutations-Endpoints

- **`setAssignees`** — ersetzt die Assignees der Localization; validiert, dass
  die User existieren.
- **`setState`** — nur für Privilegierte. Setzt den State direkt, syncht den
  Publish-Flag, ruft `syncPlaceAssignees`, loggt als `set_state`, schreibt eine
  Statamic-Revision. Escape-Hatch für Korrekturen.
- **`transfer`** — ruft je Ziel-Site `localizeInto()`. Berechtigung: Privileged
  oder die `default_assignee_group` des Ziel-Workflows. Ziel-Sites müssen zur
  Collection gehören und noch nicht existieren.
  - `localizeInto()` ist die **einzige** Stelle, an der eine Localization
    entsteht — auch `applyTransition()` benutzt sie für eine
    `site_modal`-Transition. Sie legt die Localization an (published=false),
    setzt den `initial_place`, **weist zu** (`initialAssignees()`, dieselbe Regel
    wie bei `storePost`), loggt `localized` und schreibt eine Revision.
  - Genau ein Unterschied zwischen den zwei Aufrufern, über `$copyData`
    ausgedrückt: der Übersetzungsstart kopiert die Origin-Daten 1:1 in die
    Localization (sonst liefert Statamic sie leer und merged erst beim Lesen —
    dann würde das Bearbeiten *eines* Feldes nur dieses ablösen), `transfer`
    erbt weiterhin vom Origin.
  - Vorher schrieb `transfer` **keine** Assignees. Damit blieben im
    Übersetzungsmanager „Meine Warteschlange" und „Von mir erstellt" leer, und
    weil `canApplyTransition()` auf Assignee-Sein prüft, konnten nur
    privilegierte Nutzer eine Übersetzung weiterbewegen.
- **`storePost`** — legt einen neuen Beitrag als Draft an. Der Index fragt nur
  noch die Collection ab (ein Klick → Editor), alles andere entscheidet der
  Endpoint: Platzhalter-Titel aus `messages.untitled_post`, **kein Slug**, eine
  Site. Auto-Assignees: alle Mitglieder von `default_assignee_group`, plus der
  Ersteller bei `metadata.assign_creator: true`. Response signalisiert via
  `open_entry`, ob die UI direkt in den Editor springen soll.
  - *Kein Slug — bewusst.* Statamics `slug`-Feld generiert nur, solange der Slug
    leer ist. Ein aus dem Platzhalter abgeleiteter Slug würde den Beitrag also
    dauerhaft auf `ohne-titel-7` festnageln; leer gelassen bildet das
    Publish-Form ihn live aus dem echten Titel. Die Datei entsteht trotzdem —
    `Entry::buildPath()` fällt auf die ID zurück. Wird der Stache neu indexiert,
    *bevor* der Beitrag erstmals gespeichert wurde, liest ihn Statamic bei
    `requires_slugs: true` als ID zurück und der Slug muss von Hand korrigiert
    werden.
  - *Eine Site.* Genommen wird die erste Site der Collection (in CP-Reihenfolge),
    die der Ersteller betreten darf — sonst landet er nach `open_entry` in einer
    Site, die er nicht öffnen kann. Weitere Sites entstehen über `transfer`.
  - Collections mit `title_format` (`autoGeneratesTitles()`) bekommen keinen
    Platzhalter-Titel ins Data-Array geschrieben, der würde den berechneten
    Titel verdecken.
- **`storeComment` / `deleteComment` / `markCommentsRead`** — Kommentar-Layer.
  Löschen nur durch den Autor.

#### Workflows installieren, abschalten, löschen (Settings-Übersicht)

Die drei Endpoints gehören zusammen und teilen sich die Regel: **installieren ist
ein Einmal-Vorgang.** Ab dem Moment, in dem die YAML im Projekt liegt, gehört sie
dem Projekt — die Collections bearbeitet man im Settings-Editor des Workflows,
nicht durch ein erneutes Installieren.

- **`installWorkflow`** — bietet nur Typen an, die das Projekt **nicht** hat
  (`WorkflowInstaller::isInstalled()`). Die UI blendet installierte Typen aus, der
  Endpoint lehnt sie mit 422 ab. Das interaktive CLI (`workflows:install`) darf
  weiter über einen installierten Typ laufen — es fragt vorher und schreibt auch
  dort nur `collections:`.

  **Was installierbar ist, ergibt sich aus dem Verzeichnis.** Jede YAML in
  `resources/workflows/examples/` ist ein Typ; heute sind das `postmanager`,
  `translation` und `plainlanguage` (Einfache Sprache). Ein neuer Typ braucht nur
  die Datei — es gibt keine Liste im Addon, die man mitpflegen müsste, und keinen
  Fall im Kommando.

  Beschriftet werden die Optionen aus der Definition selbst
  (`WorkflowInstaller::describedTypes()`), beides durch die Übersetzungen: der Name
  über `DefinitionLoader::navLabel()` — also dieselbe Regel wie im CP-Menü, damit
  Modal und Sidebar denselben Namen zeigen —, die Langform als Beschreibung über
  `workflows::messages.install_description_<type>` mit `metadata.label` als
  Rückfall für Beispiele, die ein Projekt selbst ergänzt. Der Handle steht daneben
  — er ist der Dateiname und der Schlüssel, den jede Statuszeile trägt. Vorher
  stand dort nur der rohe Dateiname, was `plainlanguage` zu einer Option ohne
  Erklärung machte.

  Das Kommando installiert **mehrere Typen in einem Lauf** (Multiselect,
  `postmanager` vorausgewählt, sofern noch nicht installiert), und fragt Collections
  und Backfill pro Typ ab. Vorher war es ein Typ pro Lauf — den zweiten Manager
  bekam man nur, indem man die ganze Installation erneut startete.
- **`setWorkflowActive`** — schreibt den Top-Level-Key `active: true|false` in die
  YAML. Gefiltert wird an **einer** Stelle: `DefinitionLoader::all()` liefert nur
  aktive Workflows, und weil alles Laufzeitverhalten darüber geht, fällt ein
  abgeschalteter Workflow in einem Zug aus Dashboard-Widgets, Settings-Nav, Manager-Routen,
  `workflow_actions`-Panel im Beitrags-Editor, `WorkflowResolver` und den
  Console-Commands. Definition und Verlauf bleiben unangetastet.
- **`destroyWorkflow`** — löscht nur die YAML. Status, Assignees, History und
  Kommentare bleiben in der DB: sie hängen am Workflow-**Namen**, eine erneute
  Installation greift also ihre eigene Historie wieder auf, und ein Klick in den
  Settings zerstört nichts, was Redakteure erarbeitet haben.
- **`seedWorkflowStates`** — CP-Gegenstück zu
  `php artisan workflows:seed-states --workflow=<name>`: trägt Status für
  Einträge nach, die älter als der Workflow sind. Ruft `WorkflowStateSeeder::seed()`
  direkt (nicht per `Artisan::call`), liefert also die Anzahl geschriebener Zeilen
  plus `lacks_done_place` zurück — denselben Hinweis, den auch das Kommando gibt.
  Idempotent, deshalb ohne Rückfrage und beliebig oft klickbar.
  - *Nur bei aktiven Workflows.* `WorkflowStateSeeder::seed()` prüft gegen
    `DefinitionLoader::all()` und würde bei einem abgeschalteten Workflow ein
    korrektes, aber nutzloses `0` melden. Die Übersicht liefert `seed_url` daher
    nur für aktive Zeilen **mit** Collections, und der Endpoint lehnt inaktive mit
    422 ab. Aus demselben Grund unterscheidet `SeedStatesCommand` jetzt zwischen
    „gibt es nicht" und „ist abgeschaltet" — vorher hieß beides `not found`.
  - *`--redo` bleibt CLI-only.* Bestehende Status neu ableiten ist der
    Reparaturfall; im CP gibt es nur das idempotente Nachtragen.

Automatisch passiert das Seeding außerdem beim Speichern im Settings-Editor, aber
nur für **neu hinzugefügte** Collections (`saveSettings`). Der Button in der
Übersicht ist der Weg, es für den ganzen Workflow nachzuholen.

Was die Settings-Seite trotzdem sehen muss: `DefinitionLoader::allIncludingInactive()`
liefert jede YAML unabhängig vom Schalter. Darauf laufen die Settings-Übersicht,
die Handle-Auflösung des Editors und `workflowBySlug()` — ein abgeschalteter Workflow muss editierbar und wieder
einschaltbar bleiben. Ebenfalls bewusst über `allIncludingInactive()`:
`resolvePlaceLabel()`, `placeLabels()` und `placeColors()`, damit History-Zeilen
eines abgeschalteten Workflows ihre Labels und Farben behalten. Kurz: `active` steuert
*Verhalten*, nicht *Lesbarkeit* — und `raw()` funktioniert für inaktive Workflows
unverändert.

Zwei Randfälle, die im Code festgeschrieben sind:

- Der `queue_url` einer inaktiven Zeile ist `null` — die Manager-Route löst über
  die Aktivliste auf und würde 404 liefern. Der Link wird also nicht angeboten.
- `WorkflowDefinitionWriter::resetToExample()` stellt `active: false` nach dem
  Kopieren wieder her. Ein Reset betrifft Places und Transitions, nicht die Frage,
  ob der Workflow läuft.

#### Ungelesene Kommentare sichtbar machen

„Neu" heißt überall dasselbe: von **jemand anderem** geschrieben (ein `user_id`,
das nicht das eigene ist — `null` gilt als jemand anderes), auf einer Passage, die
der Nutzer seit diesem Kommentar nicht mehr angesehen hat
(`workflow_comment_reads.seen_at`). Definiert in
`WorkflowController::commentResource()` als `is_new`.

Drei Ebenen zeigen es an:

| Ort | Träger | Anzeige |
|---|---|---|
| **Index-Zeile** | `comment_counts` + `new_comments` (Pivot, je `site => Anzahl`) bzw. `comment_count` + `new_comment_count` (flach) | graues Flag `💬 3 \| +2` an der Status-Zelle bzw. am Titel |
| Passage-Kopf + Kommentar-Drawer | `comments[].is_new` | „neu"-Pill am betroffenen Set |
| Versions-Pills | `versions[].has_new_comments` | „neu" pro Site-Version (nur bei >1 Site) |

Der Index sagt **wie viele**, die Zusammenfassung sagt **wo**. Deshalb steht im Kopf
der Zusammenfassung bewusst *keine* Gesamtzahl: dort zählt nur, welches Set
kommentiert wurde, und das leisten die Passage-Marker.

Das Flag trägt beides: die Gesamtzahl grau (user-unabhängig, aus
`countBySubject()`), und nur wenn etwas ungelesen ist zusätzlich `+N` in Amber.
Nach dem Lesen bleibt das graue Flag stehen und das `+N` verschwindet — die
Kommentare sind ja weiterhin da.

**URLs kommen fertig vom Server.** Der Payload liefert `commentUrl` (anlegen),
`commentReadUrl` (gelesen markieren) und `commentDeleteUrlTemplate` (`__COMMENT__`
als Platzhalter) als **drei getrennte** Named Routes. Der Client hat früher an
`commentUrl` angehängt — und weil die auf `?workflow=<name>` endet, landete das
Suffix *in der Query*:

```
…/comments?workflow=postmanager/read   → trifft storeComment(), 422 (body fehlt)
…/comments?workflow=postmanager/42     → DELETE ohne Route, Frontend-Catch-all, 404
```

Beide Fehlschläge verschluckte der `catch` im Client. Read-Marker wurden dadurch
**nie** persistiert (`is_new` blieb dauerhaft true) und Kommentar-Löschen war
ebenso wirkungslos. Nie an `commentUrl` etwas anhängen.

**Refresh nach dem Stack.** Wer im Stack einen Kommentar liest, ändert die Zahlen
der Zeile dahinter. Statamics `<Listing>` beobachtet aber weder `url` noch
`additionalParameters` — von außen ist kein Refetch auslösbar. Der einzige Griff ist
die `refresh`-Funktion auf dem `ListingContext`, und den stellt `Listing` selbst
bereit, ein Parent kann ihn also nicht injizieren. Deshalb sitzt in
`WorkflowListing.vue` ein renderloser `ListingRefresh` **innerhalb** des Slots, der
eine Änderung an `refreshToken` in `refresh()` übersetzt; `WorkflowQueue` zählt den
Token in `onSummaryClose()` hoch. Ein Seiten-Reload wäre die Alternative gewesen,
hätte aber Suche, Filter, Sortierung und Seite verworfen.

**Gelesen heißt: Drawer geöffnet.** Nur `openDrawer()` ruft `markRead()`, also
verschwindet der Marker genau an dem Set, dessen Kommentare man aufgemacht hat.
`WorkflowInstanceDetail` hat das früher in `onMounted` für **alle** Passagen auf
einmal gemacht — was den Sinn der Marker aufhob: sie verschwanden im selben Moment,
in dem die Seite erschien, man konnte also nie sehen, wo kommentiert wurde. Das ist
entfernt.

**Kosten.** `newCommentCount()` braucht pro Eintrag zwei Queries **plus** einen
kompletten Content-Parse (`EntryContentPreview::blocks`), um Kommentare auf
inzwischen verschwundenen Passagen auszusortieren — sonst bliebe ein `+1` stehen,
das niemand mehr wegklicken kann. Für ein Listing über hunderte Zeilen ist das zu
teuer. Davor sitzt deshalb `WorkflowCommentStore::newCountBySubject()`: **zwei**
Queries für den ganzen Workflow, die beantworten „könnte hier überhaupt etwas
ungelesen sein". Nur die so geflaggten Einträge laufen in den teuren Pfad. Gemessen
an 103 Pivot-Zeilen mit zwei geflaggten Einträgen: 14 Queries statt 200+.

Beide Lookups sind pro Request memoized (`$newCommentCountsCache`,
`$newCommentCountCache`), weil `listing()` seine Zeilen **zweimal** baut — einmal
für die Seite, einmal für die Filter-Badges.

### 5.10 Content-Preview & History-Diff

- Preview-Blocks kommen aus `EntryContentPreview` (§ 7). Sie liefern `handle` +
  eine stabile `uuid` pro Passage. Diese UUID landet in
  `workflow_comments.passage_ref` und bindet Kommentare an genau eine Stelle im
  Beitrag.
#### Snapshots: `EntrySnapshot`

Jedes Event, das an einem Entry hängt, speichert einen Snapshot in
`workflow_events.data_snapshot`. Erzeugt wird er **ausschließlich** über
`src/Content/EntrySnapshot::take($entry)`, damit alle Log-Stellen dieselbe Form
schreiben.

`$entry->data()` allein reicht nicht: Statamic hält `slug`, `date` und
`published` **außerhalb** der Daten — der Stache zieht sie beim Laden per
`Arr::pull` aus dem Front Matter bzw. leitet sie aus dem Dateinamen ab
(`Stache\Stores\CollectionEntriesStore`). Ohne sie wäre eine Slug-Umbenennung
oder eine Datumsverschiebung im Diff unsichtbar. Statamic hat dasselbe Problem
bei Revisionen und löst es genauso (`Entry::revisionAttributes()`).

- ergänzt `slug`, `published` und — nur bei datierten Collections — `date`
- entfernt `updated_by`/`updated_at`: die ändern sich bei **jedem** Save und wären
  sonst der einzige „Treffer" jedes Diffs
- bleibt **flach** (kein verschachtelter `data`-Key wie bei `revisionAttributes`),
  weil `historyDiff()` die Vereinigung der Top-Level-Keys diffed und jeden davon
  als Blueprint-Handle nachschlägt

Taxonomien, `sticky`, `authors` und alle anderen Sidebar-Blueprint-Felder waren
schon immer in `data()` — die fehlten nicht im Snapshot, sondern wurden von der
Whitelist verworfen.

Auch `revision_sync` (Arbeitskopie angelegt/veröffentlicht) schreibt einen
Snapshot. Ohne den wäre so ein Event eine Sackgasse: es wird als Vergleichspunkt
für das **nachfolgende** Event gewählt, das dann ebenfalls `no_diff_data` meldet.

#### History-Diff (`historyDiff($id, $event)`)

- Vergleich `vs=previous` (Default) oder `vs=original`.
- Fehlt ein Snapshot auf einer der beiden Seiten → `note: no_diff_data`.
- **Anker bei `vs=previous`** hängt am Typ des Ziel-Events (`SAVE_TYPES`):
  - ein **Schritt** (`transition`, `set_state`, `created`, `localized`,
    `revision_sync`) vergleicht gegen den vorherigen **Schritt** und überspringt
    die `draft_save`-Zeilen dazwischen. Die Schritt-zu-Schritt-Aussage bleibt
    dadurch erhalten, auch wenn zwischendurch zehnmal gespeichert wurde.
  - ein **`draft_save`** vergleicht gegen seinen direkten Vorgänger, zeigt also
    genau die Änderungen dieser einen Speicherung.

  Beide Lesarten addieren sich: die Schritte erzählen die grobe Geschichte, die
  Speicherungen brechen sie auf.
- Die Diff-Engine selbst liegt in `src/Content/SnapshotDiffer.php`, nicht im
  Controller — sie hat einen zweiten Aufrufer mit einer anderen Frage
  (`DraftSaveLogger`, siehe unten). `changes()` liefert die Liste, `hasChanges()`
  bricht beim ersten Treffer ab. Beide laufen durch denselben privaten
  `collect()`, damit „geändert" nicht zweimal definiert wird.
- **Sichtbarkeit:** `metadata.history_fields` whitelistet nur noch
  **Inhaltsfelder**. Immer sichtbar, unabhängig von der Whitelist, sind
  - die Meta-Attribute `slug`, `date`, `published` (`META_HANDLES`), und
  - jedes Blueprint-Feld im Tab `sidebar` (`Blueprint::hasFieldInTab()`).

  `sidebar` ist dabei keine Konvention dieses Projekts:
  `Collection::ensureEntryBlueprintFields()` legt `slug`, `date` und **jedes**
  Taxonomie-Feld gezielt in einen Tab dieses Namens. Ohne den Key gilt weiter:
  alle geänderten Handles. `updated_by`/`updated_at` sind hart ausgeschlossen
  (`SNAPSHOT_NOISE`), weil sie in Alt-Snapshots noch stecken.
- `changeSummary($type, …, $handle)` bucketet je Fieldtype:
  - Scalars/Choice → before/after als String.
  - Date/Time → beide Seiten über `formatDateValue()` auf `Y-m-d H:i`, sonst
    würde der Wort-Diff schon an unterschiedlicher Formatierung anschlagen.
  - Assets/Video → File-Pfade extrahiert.
  - Bard/Replicator/Grid → `extractRichText`/`flattenRichNode` flatten das
    ProseMirror-Bäumchen zu Klartext, inklusive `[Bild: alt]`-Markern und
    `[setHandle] …`-Markern für Set-Blöcke. Rein set-ID-Änderungen (Bard
    speichert bei jedem Save neue UIDs) werden ausgefiltert, wenn der Klartext
    identisch ist.
  - Relationships (`terms`, `taxonomies`, `users`, `entries`) →
    `resolveRelationshipLabels()` löst die gespeicherten Ids zu Namen auf, also
    „Erwachsene → Erwachsene, Jugendliche" statt „1 → 2 verknüpfte Elemente".
    Term-Ids sind der Sonderfall: ein Feld mit einer einzigen Taxonomie speichert
    den nackten Slug, `termTitle()` präfixiert ihn mit dem Taxonomie-Handle —
    dieselbe Normalisierung wie in Statamics `Fieldtypes\Terms::toItemArray()`.
    Nicht auflösbare Ids bleiben als Id stehen; löst sich **nichts** auf, greift
    der alte Anzahl-Einzeiler.
  - Toggle → „Aktiviert"/„Deaktiviert"; `published` bekommt mit
    `change_published_*` eigene Wortwahl.
- `resolveChoiceLabels` löst Option-Handles zu Anzeigelabels auf.
- `fieldLabel($blueprint, $handle)` — Blueprint-`display`, sonst der Lang-Key
  `field_<handle>`, sonst humanisiert. Der Lang-Key ist für die Meta-Attribute
  nötig: `published` hat kein Blueprint-Feld und hieße sonst „Published".

#### Entwurfs-Speicherungen: `draft_save`

Zwischen zwei Schritten war früher nichts zu sehen — alles, was ein Redakteur
zwischen „Zur Prüfung" und „Freigeben" gemacht hat, tauchte gesammelt erst beim
späteren Schritt auf. `draft_save` füllt diese Lücke, **aber nur wenn eine
Speicherung wirklich etwas geändert hat.** Eine Speicherung ohne Änderung
hinterlässt keine Spur, sonst würde jeder Klick auf „Speichern" (und jeder
CP-Autosave eines noch dirty Formulars) eine leere Zeile produzieren.

Die Entscheidung liegt komplett in `src/Workflow/DraftSaveLogger::attempt()`.
Abbruchbedingungen der Reihe nach: aktiver `EventLogSuppressor`, kein
`WorkflowState`-Row (Entry nimmt noch nicht am Workflow teil), leerer Snapshot,
keine anzeigbare Änderung. Der ganze Rumpf liegt in `try/catch` — eine
fehlgeschlagene History-Notiz darf den Save des Redakteurs niemals mit einem 500
abbrechen (dieselbe Haltung wie `createStatamicRevision()`); die Exception wird aber
per `report()` geloggt, denn eine History, die heimlich aufhört zu protokollieren,
sieht aus wie eine, in der nichts passiert ist.

**Sonderfall „noch keine Baseline".** `workflows:seed-states` schreibt States, aber
keine Events — jeder Bestands-Eintrag hat also einen State und null Events. Fehlt
eine Snapshot-Baseline, wird die Speicherung **ungeprüft** geloggt und ist damit
selbst die Baseline. Sie abzulehnen hieße: in einem bestehenden Projekt wird nie
eine einzige Speicherung protokolliert, bis jeder Eintrag zufällig mal durch einen
Workflow-Schritt läuft — das Feature täte auf dem vorhandenen Inhalt gar nichts.
Preis ist **eine** Zeile pro Eintrag, deren Diff-Panel „Kein früherer Schritt zum
Vergleich" meldet; ab der zweiten Speicherung greift die Regel exakt.

„Geändert" heißt: was der Diff **anzeigen würde**. Deshalb läuft der Check über
`SnapshotDiffer::hasChanges()` und nicht über einen Snapshot-Vergleich. Roh sind
zwei Snapshots nämlich fast immer verschieden — Bard und Replicator schreiben bei
jedem Save neue Set-UIDs, Ungleichheit ist also der Normalfall und beweist nichts.
Aus demselben Grund gilt die `history_fields`-Whitelist auch hier: eine Zeile,
deren Diff-Panel dann „Keine Änderungen an Feldern" meldet, wäre schlimmer als
keine Zeile.

Statamic hat **zwei** Save-Pfade, und der Verlauf braucht beide
(`EntriesController::update`):

| Situation | Statamic-Event | Wer loggt |
|---|---|---|
| Entry ist ein Entwurf (unveröffentlicht) | `EntrySaved`, kein Revisions-Event | `Listeners\LogDraftSave` |
| Entry ist veröffentlicht, Revisionen an → Save landet in der Arbeitskopie | `RevisionSaved` (`action: working`), kein `EntrySaved` | `Listeners\SyncWorkflowOnRevision::logWorkingCopySave()` |

Der Arbeitskopie-Fall sitzt bewusst **im** bestehenden Revisions-Listener und nicht
in einem zweiten `RevisionSaved`-Listener: Statamic garantiert keine Reihenfolge
zwischen Listenern desselben Events, und die beiden Zeilen müssen in der richtigen
Folge stehen — erst der Schritt („Arbeitskopie angelegt"), dann was die Speicherung
geändert hat. Gesnapshottet wird dort `$entry->fromWorkingCopy()`: der gespeicherte
Entry hält noch die Live-Version, ein Snapshot davon würde für immer „keine
Änderung" melden. Vorher gab es hier nur die *erste* Arbeitskopie-Speicherung als
`revision_sync` (der State-Flip); jede weitere flippte nichts und war unsichtbar.

`draft_save` schreibt **kein** `from_state`/`to_state`. Eine Speicherung ist kein
Zustandswechsel, und der aktuelle Platz in `to_state` würde Speicherungen für alles
wie Transitions aussehen lassen, was diese Spalte abfragt —
`TransitionAgedPostsCommand` liest `to_state`, um zu bestimmen, wann ein Beitrag
zuletzt in seinen Publish-Platz gelaufen ist. Die Aussage der Zeile steckt im
`note` (`event_note_draft_saved` / `event_note_entry_saved`; letzteres nur in
Collections ohne Revisionen, wo ein Save direkt den Live-Beitrag schreibt).

**Achtung bei `workflows:seed-states --redo`.** `subjectsWithHistory()` steuert, welche
Einträge der Seeder neu ableiten darf („noch nie bearbeitet"). Sobald eine
Speicherung geloggt ist, hat der Eintrag History und wird vom `--redo` verschont.
Das ist inhaltlich richtig — er *wurde* bearbeitet —, verkleinert aber die Menge, die
ein `--redo` noch anfasst, mit der Zeit deutlich.

#### `EventLogSuppressor`

`src/Support/EventLogSuppressor.php` markiert die Saves, die das Addon selbst
macht. Transition, manueller State-Override, Übersetzung und „neuer Beitrag"
speichern den Entry und/oder schreiben eine Revision, und **jeder von ihnen loggt
schon sein eigenes Event mit eigenem Snapshot**. Ohne den Guard käme zusätzlich
eine `draft_save`-Zeile — bei der Transition sogar **vor** dem Schritt, zu dem sie
gehört, weil `WorkflowTransitioner` das Published-Flag synct, bevor es das Event
loggt.

Ein Tiefenzähler statt eines Booleans, damit eine verschachtelte Operation den
Guard nicht vorzeitig aufhebt; `finally` hält den Zähler auch bei Exceptions
korrekt. Umschlossen sind `WorkflowTransitioner::apply()` sowie in
`WorkflowController` die Blöcke von `setState()`, `transfer()` und `storePost()`.
`TransitionAgedPostsCommand` läuft durch `apply()` und ist damit mitversorgt;
`WorkflowStateSeeder` und `SyncWorkflowOnUnpublish` speichern nichts.

#### Rendering

`resources/js/components/WorkflowHistory.vue` rendert Liste und Diff-Panel und
wird von **beiden** Detailansichten benutzt (`WorkflowInstanceDetail`,
`TranslationInstanceDetail`). Ausgelagert statt kopiert, weil dort der
LCS-Wort-Diff liegt. Der Client kennt nur den Schalter `showValues` — jede
Wertformatierung passiert serverseitig.

Speicher-Zeilen (`type` in `SAVE_TYPES`) rendern eingerückt, gedämpft und ohne
`from → to`-Pfeil, damit die Schritte weiterhin das sind, was man zuerst liest. Das
`type`-Feld kommt aus `entryHistory()`; ohne es könnte der Client Schritt und
Speicherung nicht unterscheiden.

### 5.11 Neue User im Assignee-Drawer

- `assignableUsers()` — alle User, sortiert. Enthält Gruppen (Titel), aber **nicht**
  den Super-Admin-Status (bewusst versteckt), plus `site_allocation` (Blueprint-Feld
  am User: welche Site der Nutzer betreut).
- `pickableAssignees($transition, $entry)` — die Kandidaten einer **manuellen**
  `user_assignment`-Action, über `AssignmentCandidates`. Der Beitrag muss mit
  hinein, weil `matches_entry` gegen ihn vergleicht.

---

## 6. Event-Listener und Policy

**`InjectWorkflowFields`** (auf `EntryBlueprintFound`)

- Prepended dem Blueprint jeder governed Entry-Blueprint das computed Feld
  `workflow_actions` (visibility=computed, keine Display-Anzeige). So braucht keine
  App-Blueprint-YAML den Workflow zu kennen — beim Deinstallieren des Addons ist
  das Feld weg.

**`SyncWorkflowOnUnpublish`** (auf `EntrySaved`)

- Wenn ein Entry mit governter Collection nun `published=false` ist **und** der
  Workflow-State noch ein Publish-Place oder `pending_revision` ist:
  → State zurück auf `initial_place`. Verhindert, dass ein manuell im Editor
  entfernter Publish-Flag zu einem hängengebliebenen Workflow-State führt.

**`LogDraftSave`** (auf `EntrySaved`)

- Loggt ein `draft_save`-Event, wenn eine Speicherung wirklich etwas geändert hat.
  Die ganze Entscheidung liegt in `DraftSaveLogger` (§ 5.10) — der Listener löst
  nur den Workflow zur Collection auf.
- Deckt den Direkt-Save-Pfad ab (Entwürfe). Der Arbeitskopie-Pfad hängt an
  `RevisionSaved` und sitzt daher in `SyncWorkflowOnRevision`.

**`SyncWorkflowOnRevision`** (auf `RevisionSaved` + `RevisionDeleted`)

- `syncState()` rekonziliert gegen `hasWorkingCopy()`:
  - Entry ist in einem Publish-Place + hat eine Working-Copy → `pending_revision`.
  - Entry hat keine Working-Copy mehr + State ist `pending_revision` → zurück in
    den (ersten) Publish-Place.
- Idempotent: reagiert auf den Ist-Zustand, nicht auf das konkrete Event, damit
  die Hin-und-Her-Bewegung durch beide Event-Typen sauber funktioniert.
- Ändert nie einen noch unfertigen State (Draft/Review), auch wenn zufällig eine
  Working-Copy angelegt wurde.
- `logWorkingCopySave()` loggt zusätzlich die Entwurfs-Speicherungen einer
  Arbeitskopie (§ 5.10). Steht **außerhalb** von `syncState()`, weil eine
  Speicherung auch dann protokolliert werden soll, wenn der Workflow das
  Veröffentlichen nicht gated (dort steigt `syncState()` früh aus) — aber
  **danach**, damit die Zeilen in Leserichtung stehen.

**`EntryPolicy`** (`Policies\EntryPolicy`)

- Erbt `Statamic\Policies\EntryPolicy`.
- Überschreibt `publish`: nur Super **oder** Gruppe `admins` dürfen die
  Ability. Alle anderen sind gezwungen, per Workflow zu veröffentlichen.
- `before()` gibt bei `ability === 'publish'` bewusst `null` zurück, damit
  Statamics Standard-Before-Kette den Publish-Check nicht kurzschließt.

---

## 7. Content-Preview: `EntryContentPreview`

Baut die read-only Vorschau der Editorial-Inhalte für die Detailseite. Datei:
`src/Content/EntryContentPreview.php`.

- Reihenfolge: `intro` (Bard) → `content_sets` (Bard mit Sets) → `dd_title`/`dd_text`
  („Sources"-Feld) → Podcast-Link/Embed.
- Für Bard-Sets (`multisite_text`, `hintbox`, `heading`, `video`, `pdf`, `code`) wird
  jeweils ein eigenes Block-Objekt gepusht.
- Jedes Block hat:
  - `kind`: `html` / `heading` / `text` / `video`
  - `handle`: Fieldset-/Feldhandle (z. B. `hintbox`)
  - `uuid`: **stabile** ID (Bard-Set-ID falls vorhanden, sonst `handle:i`) — genau die
    ID, die als `passage_ref` in Kommentaren landet.
- `videoEmbed($url)` erzeugt Player-URLs für Vimeo/YouTube (mit `youtube-nocookie`).

Diese Preview ist **nicht** editierbar — der Editor selbst öffnet sich via
Deep-Link (`instance.subject.cp_url`).

---

## 8. Fieldtypes

### `RevisionStatus` (`revision_status`)

- Nur-Anzeige. Selektierbar in Blueprints via YAML.
- `preProcessIndex` und `preProcess` berechnen `entryHasWorkingCopy()`. Damit
  erscheint das orange „Unveröffentlichte Änderungen"-Badge in der nativen
  Entries-Listing (via `RevisionStatusIndex.vue`) und als read-only Anzeige im
  Editor (`RevisionStatusFieldtype.vue`).
- Companion-Filter `Query\Scopes\Filters\RevisionStatus` liefert dieselbe Info
  als Filter für die native Listing — er fragt den `RevisionRepository` nach allen
  `working`-Revisions und schränkt die Query über deren IDs ein.

### `WorkflowActions` (`workflow_actions`)

- Wird via Listener automatisch in **jedem Blueprint** einer governed Collection
  eingehängt (siehe § 6).
- `preProcess($data)` liefert das initiale Payload für die Vue-Komponente
  `WorkflowActionsFieldtype.vue`:
  - `entry_id`, `workflow` — der Workflow kommt aus
    `WorkflowResolver::forEntry()`, hängt also an der **Eintrags-Version**: eine
    Übersetzung zeigt den Übersetzungs-Workflow, ihre Quelle den Autoren-Workflow
    (§ 2 → Lokalisierungs-Workflows, Punkt 4). Alle URLs unten tragen den Namen
    als Query-Parameter, damit Actions, Apply und Weiterleitung im selben Kontext
    bleiben.
  - `is_privileged` — steuert, ob der native Publish-Button versteckt wird (über
    eine Body-Klasse aus der Vue-Seite).
  - `actions_url` — hier holt der Client die aktuellen Actions per Fetch.
  - `apply_url_template` — Template für den Apply-Endpoint (Client ersetzt
    `__NAME__`).
  - `revisions_enabled` / `revisions_url` — optionaler Best-Effort-Post nach
    einer erfolgreichen Transition, damit die Statamic-Revision-History ebenfalls
    einen Marker bekommt.
  - `workflow_url` — Weiterleitung nach erfolgreichem Übergang (auf die
    Workflow-Detailseite, damit Verlauf/Aktionen sichtbar bleiben).

Die Vue-Seite teleportiert Buttons + State-Badge in den Header des
Entry-Editors, direkt neben Save/Publish.

**Site-Wechsel ohne Reload.** Ein Klick in der „Sites"-Karte tauscht die
Localization an Ort und Stelle: die Publish-Form holt deren Edit-Payload und
ersetzt `values` komplett, ohne Seitenwechsel. Unser Payload fährt dabei mit,
weil Statamic das Blueprint vor `preProcess()` auf die **gezeigte Version**
parentet (`EntriesController::edit()` → `setParent($entry)` →
`extractFromFields()`). Die Komponente beobachtet deshalb
`entry_id` + `workflow` und lädt bei einer Änderung die Actions neu — sonst
blieben die Aktionen der vorher geöffneten Version stehen, bis jemand F5 drückt.

Der Header-Takeover wird dabei bewusst mit neu aufgesetzt (`restoreHeader()` +
`hijackHeader()`) und nicht dem `allowPublish`-Watcher überlassen: der greift nur,
wenn die beiden Versionen das Veröffentlichen unterschiedlich gaten, während der
Slot, den wir in Statamics Header hängen, ein Fremdkind eines Teilbaums ist, den
Vue beim Wechsel neu rendert.

---

## 9. Dashboard-Widgets

Acht Widgets, alle im ServiceProvider registriert (`$widgets`). Ohne eigene
Einträge in `config/statamic/cp.php → widgets` (Statamic-Default: leer) setzt das
Addon das Layout aus `workflows.dashboard_widgets` (`ServiceProvider::defaultDashboardWidgets()`,
in `register()`, damit CoreNav den Dashboard-Nav-Punkt sieht). Sobald das Projekt dort
irgendein Widget einträgt, gilt nur dessen Liste — es wird nichts gemischt;
`'dashboard_widgets' => false` schaltet den Fallback ab. Widget-Preferences pro
User/Rolle gehen beidem vor. Der Default (gleichzeitig das empfohlene Layout für eine
eigene Liste):

```php
'widgets' => [
    ['type' => 'workflows_user_header', 'width' => 100],
    ['type' => 'workflows_queue_stats', 'width' => 100],
    ['type' => 'workflows_my_queue', 'width' => 100, 'limit' => 7],
    ['type' => 'workflows_watched', 'width' => 50, 'limit' => 5],
    ['type' => 'workflows_created_by_me', 'width' => 50, 'limit' => 5],
    ['type' => 'workflows_overview', 'width' => 100, 'per_page' => 25],
    ['type' => 'workflows_status', 'width' => 100],
],
```

Die Reihenfolge geht vom Persönlichen zum Ganzen: wer bin ich → wie viel liegt
an → was muss ich tun → meine übrigen Beiträge → alle Beiträge → wie verteilt sich
der Bestand (Auswertung zum Schluss).

`workflows_overview` ist zugleich der Einstieg in die Manager-Seiten: die
Workflows haben keinen eigenen Nav-Eintrag mehr (§ 3), `/cp/workflows` leitet
aufs Dashboard um.

Widgets einer Dashboard-Zeile sind gleich hoch: Statamics Widget-Raster ist eine
`flex-wrap`-Zeile, die ihre Zellen ohnehin auf die höchste streckt; die
Addon-Karten füllen ihre Zelle (`h-full` am `Widget`), Leerzustände zentrieren
sich, der Pager sitzt unten. Paginierte Karten halten auf der kurzen letzten
Seite die Höhe einer vollen (§ 11), damit der Pager nicht springt.

### `QueueStats` (`workflows_queue_stats`)

`src/Widgets/QueueStats.php` — die Kennzahlreihe ganz oben: vier Karten, reine
Anzeige bis auf **einen** Button (Karte 3, „erneut starten" — siehe unten).
**Ein** Widget für die ganze Reihe, nicht vier: die
vier Zahlen sind eine Aussage, sie müssen bündig stehen und gleich hoch
bleiben — vier Dashboard-Slots hätten das Layout der Breitenarithmetik des
Dashboards überlassen. Config: `workflows` (Teilmenge aktiver Workflow-Namen,
Default alle) über denselben Trait wie die zwei Listen-Karten
(`PresentsPersonalEntries`). Dieselbe Liste gehört in die Config von
`workflows_overview`, sonst spannen Kartenzahl und gefilterte Tabelle
verschiedene Workflows.

**Jede Karte ist ein Link** auf die Beiträge hinter ihrer Zahl (Details unten,
„Karten als Links"): Karte 1 springt zur Karte „Meine Warteschlange", Karten 2–4
filtern „Alle Beiträge" auf genau die gezählten Beiträge und scrollen dorthin.

**Karten 2–4 zählen Beiträge, nicht Fassungen** — die Zeilen, die ihr Filter in
„Alle Beiträge" übrig lässt (eine Zeile je Workflow + Ursprungseintrag),
`WorkflowController::matchingPostCount()`. Vorher zählten sie Subjects (eine je
Site-Fassung); zwei Sprachfassungen eines Beitrags am selben Gate hätten „2" über
einer Tabelle mit einer Zeile bedeutet. Was dabei herausfällt, fällt auch aus der
Tabelle: Waisen-Zustände (Eintrag gelöscht) und Fassungen, die ein Manager gar
nicht listet (Site ohne Spalte, Lokalisierungs-Gruppen ohne fertige Quelle). Im
Referenzprojekt: „Wartet auf Freigabe" 6 Subjects → **3** Beiträge; die drei
übrigen sind `source_ready`-Zustände deutscher Fassungen im
Übersetzungsmanager, dessen Quelle inzwischen `simple` ist — Reste einer
früheren Quell-Einstellung, die kein Manager zeigt.

**Zählsemantik.** Karte 1 ist persönlich, Karten 2–4 sind projektweit:

| Karte | Zählt | Notiz |
| --- | --- | --- |
| „In meiner Warteschlange" (indigo) | `PersonalWorkItems::assignedTo()->count()` | — |
| „Wartet auf Freigabe" (blau) | Beiträge mit einer Fassung, deren gespeicherter Zustand ein **Freigabe-Gate** ihres Workflows ist (`TransitionGates::gatePlaces()`) | „älteste seit N Tagen" |
| „Fehlgeschlagene Übersetzungen" (rot) | Beiträge mit einer Fassung im `plain_language_failed_place` ihres Workflows | „erneut starten" — als Button, wo der Betrachter darf |
| „Diese Woche veröffentlicht" (grün) | Beiträge mit einer Fassung, die diese Kalenderwoche in einen `green`/`emerald`-Place lief | „+N/−N zur Vorwoche" (Vorwoche ebenfalls in Beiträgen) |

Die Subject-Mengen der Karten 2–4 liefert **`src/Workflow/StatsPresets.php`**
(`subjects($preset, $only)`, `scope($preset, $only)`, `published()` für das
Vorwochenfenster) — aus dem Widget herausgezogen, weil der Listing-Filter
dieselben Mengen lesen muss.

Karte 1 nimmt **denselben Codepfad wie `MyQueue`** — ein Aufruf von
`PersonalWorkItems::assignedTo()`, gezählt —, nicht die billigere Zählung über
die Assignee-Zeilen. Die zwei Karten liegen auf demselben Dashboard und werden
verglichen, dürfen sich also nicht widersprechen: `assignedTo()` verwirft
Assignee-Zeilen, deren Eintrag es nicht mehr gibt, eine rohe Zeilenzählung würde
diese Waisen als Arbeit melden. Die Summe der Tab-Zähler von `MyQueue` und der
Wert von Karte 1 sind damit strukturell dieselbe Zahl.

Keine Karte kennt eine fest verdrahtete Liste von Place-Handles — aber die drei
projektweiten lesen **verschieden**, und der Unterschied ist der Punkt.

**Karte 2 liest die Struktur des Workflows.** Ein Place ist ein *Freigabe-Gate*,
wenn eines von beidem gilt (`TransitionGates::gatePlaces($raw)`, einmal pro
Workflow aus dem rohen YAML berechnet, danach werden die gespeicherten Zustände
dagegen gematcht):

1. **Eine ausgehende Transition nennt ein Publikum** — `required_group` oder
   `required_role` in ihren `metadata` (dieselbe Lesart, die
   `canApplyTransition()` gatet; beide gehen durch `TransitionGates::groups()`
   / `::roles()`, damit der deaktivierte Button und die Zahl auf dem Dashboard
   nicht über denselben Workflow verschiedener Meinung sein können). Das
   Gate ist ausgeschrieben, da ist nichts zu interpretieren.
2. **Der Place hat zwei oder mehr ausgehende Transitions** und ist **kein**
   `metadata.branch: true`. Ein Ausgang ist eine Übergabe — die Arbeit geht
   weiter, offen ist nur wann. Zwei Ausgänge sind eine *Entscheidung*
   (freigeben oder ablehnen), und wer dort liegt, wartet darauf, dass jemand sie
   trifft. Das fängt den häufigen Fall, in dem ein Workflow **allein über die
   Zuweisung** gatet: die `user_assignment`-Action hat den Beitrag schon an die
   richtigen Leute gegeben, also musste nie zusätzlich ein `required_group`
   danebenstehen.

Der `branch`-Ausschluss gilt nur für Signal 2; ein ausdrückliches Gate zählt
überall. `branch: true` ist das eigene Wort der Definition für „Abzweig von der
Linie" (§ 15): die Alterungsstufen, der Fehler-Park. Die haben zwei Ausgänge aus
einem anderen Grund — zurück auf die Linie oder in die Überarbeitung — und
niemand hat sie zur Freigabe eingereicht. Sie mitzuzählen würde die echten Gates
ersäufen: im Bestandsprojekt liegen dort 61 Beiträge gegen **einen** in der
finalen Freigabe. System-Transitions zählen nirgends mit, sie werden ja auch
nicht als Button angeboten.

> **Warum nicht mehr die Farbe.** Karte 2 las früher `blue`/`sky`. Auf dem
> Bestandsprojekt zählte sie damit 278 — weil der Initial-Place des
> Einfache-Sprache-Managers blau ist und 277 Beiträge schlicht noch nie
> übersetzt wurden. Auf keinen davon wartet jemand. Eine Farbe sagt, wie ein
> Place **aussieht**; nur die Transitions sagen, ob jemand handeln muss.

**Karte 3 liest den einen Place, den der Workflow benennt:**
`metadata.plain_language_failed_place` (der Park, in den
`PollPlainLanguageCommand` eine endgültig gescheiterte Fassung setzt — § 22),
ersatzweise das Handle `failed_translation` der mitgelieferten Beispiele.
`FailedTranslationRetry::failedPlace()` ist die eine Stelle, die das beantwortet.
Auch hier war die Farbe zu grob: `red` fing im Bestandsprojekt die 39 veralteten
Beiträge der SLA-Stufe mit, die nichts fehlgeschlagen haben.

**Karte 4 liest weiterhin die Statusfarbe** (`DefinitionLoader::stateColor()`,
also `metadata.color` am Place, dann die eingebauten Defaults) — dort ist sie
richtig: „fertig" ist genau das, was `color: green` sagt, und dieselbe Lesart
treibt schon die Dringlichkeitssortierung in `PersonalWorkItems::URGENCY`. Die
Farbfamilie steht als Konstante in `StatsPresets` (`DONE_COLORS`).

**Der Wochenvergleich kommt aus dem Event-Log**, nicht aus den gespeicherten
Zuständen: „diese Woche veröffentlicht" ist ein Ereignis, und ein Beitrag, der
Montag rausging und Donnerstag zurück in die Überarbeitung, ist diese Woche
trotzdem rausgegangen — der `WorkflowStateStore` kann das nicht mehr sagen, das
Log schon. Dafür gibt es `WorkflowEventStore::subjectsEnteringStates(array
$states, Carbon $from, Carbon $to): int`: DISTINCT `subject_id` über
`whereIn('to_state', …)` im Zeitfenster, workflow-übergreifend (die
Place-Handles sammelt der Aufrufer aus den aktiven Workflows). Distinct, weil
ein Beitrag in einer Woche mehrfach in einen grünen Place laufen kann und die
Karte Beiträge zählt, keine Übergänge. Die Wochenfenster sind ISO-Wochen
(Montag–Sonntag) über Carbons `startOfWeek()`/`endOfWeek()`. Die IDs derselben
Menge liefert das Geschwister `subjectIdsEnteringStates()`, an das der Zähler
delegiert — siehe unten.

Die Notiz von Karte 2 rechnet in **ganzen Tagen** und geht über einen
`trans_choice`-Key: „seit" verlangt im Deutschen den Dativ, und der steckt nur
in einer Pluralform, die wir selbst schreiben — ein zusammengesetztes
`'seit ' . $carbonDiff` ergab „seit 4 Tage". (`PersonalWorkItems::meta()` löst
dasselbe Problem andersherum, indem Carbon die ganze Wendung rahmt; das geht
dort, weil es das relative „vor 4 Tagen" will.)

**Randfälle.** Ohne eingeloggten User gibt `component()` `null` zurück, und da
es kein `html()` gibt, verwirft der `DashboardController` die Kachel. Zähler 0
heißt Wert „0" ohne Notiz (kein Platzhalter, kein Strich) — bei Karte 4 gilt das
nur, wenn auch die Vorwoche 0 war, sonst bleibt der Vergleich stehen. Ein
Rückgang bei Karte 4 ist kein Fehler und bleibt neutral gefärbt; nur ein Zuwachs
bekommt `tone: positive`.

#### Karten als Links

Früher öffnete jede Zahl einen `Stack` mit bis zu 50 Beiträgen (`items`/`more`,
`SubjectLinks::forSubjects()`, `PresentsPersonalEntries::overflow()`). Das hat
schlecht verdoppelt, was das Dashboard schon kann — die Warteschlangen-Karte mit
Tabs und Pager, „Alle Beiträge" mit Suche, Filter und Paginierung. Stack, Cap,
`forSubjects()`, `overflow()` und `widget_more_in` sind entfernt; jede Karte
trägt stattdessen `link`:

| Karte | `link` |
| --- | --- |
| 1 | `{target: 'workflows-my-queue', fallback}` |
| 2–4 | `{target: 'workflows-overview', fallback, preset, workflows}` |

- **`target`** ist die Element-ID der Zielkarte (`QueueStats::MY_QUEUE_ANCHOR` /
  `::OVERVIEW_ANCHOR`; `MyQueueWidget.vue` und `WorkflowsOverviewWidget.vue`
  tragen sie mit `tabindex="-1"`). Die Karte ist ein echtes `<a href="#…">`.
  Der Klick wird abgefangen: Preset setzen (Karten 2–4), dann
  `scrollIntoView` — `smooth`, bei `prefers-reduced-motion: reduce` sofort — und
  Fokus auf die Zielkarte (`preventScroll`), wie ein In-Page-Link für Tastatur
  und Screenreader. Modifier-Klicks bleiben dem Browser.
- **`preset`/`workflows`:** der Name für den Listing-Parameter `preset` (§ 4) und
  die Workflows, aus denen er schöpfen kann (`StatsPresets::scope()`, aus der
  Definition gelesen, nicht aus den heutigen Daten — die Karte zeigt auf dieselbe
  Tabelle, ob sie 0 oder 30 sagt). Ein Workflow → „Alle Beiträge" wählt dessen
  Button; mehrere → „Alle".
- **`fallback`**, wenn die Zielkarte nicht auf diesem Dashboard liegt: Karte 1
  verlinkt die Manager-Seite des ersten Workflows mit Arbeit, mit
  `#workflows-my-queue` (die „Meine Warteschlange"-Sektion dort trägt dieselbe
  ID); bei leerer Queue gibt es kein Ziel. Karten 2–4 verlinken die
  Manager-Seite, wenn das Preset genau **einen** Workflow hat (z. B. die
  Fehlschläge → Einfache-Sprache-Manager; ungefiltert), sonst nichts — aus
  mehreren Workflows einen zu raten wäre geraten. Ohne Ziel und ohne Fallback
  ist die Karte ein schlichtes `div`.

Ob die Zielkarte da ist, beantwortet **`resources/js/support/dashboardLinks.js`**:
ein modulweiter reaktiver Store, in den sich die Zielkarten beim Mounten
eintragen (`useLinkTarget()`), dazu das aktive Preset (`applyPreset()`,
`clearPreset()`, `activePreset()`). Ein Store statt eines `window`-Events, weil
er auch die Frage beantwortet, die ein Event nicht kann — „gibt es die andere
Karte überhaupt?" —, für eine Karte, die vor ihr rendert.

**Markup:** der Link umfasst Label und Zahl; sein `::after` ist über die ganze
(`relative`) Karte gespannt, sodass die ganze Karte klickbar ist. Der
Retry-Button ist ein **Geschwister** des Links, mit `relative z-10` über dem
Overlay — keine verschachtelten Bedienelemente, und sein Klick erreicht den Link
nie. Ein `sr-only`-Zusatz sagt, wohin der Link führt
(`widget_stats_jump_to_queue` / `widget_stats_filter_overview` /
`widget_stats_open_manager`). Die Karte, deren Preset „Alle Beiträge" gerade
zeigt, bekommt einen blauen Ring und `aria-current`.

#### Layout der Reihe

Vier Karten in **einer** Zeile auf jeder Breite: `grid-cols-4` ohne kleinere
Stufe (vorher `grid-cols-2 lg:grid-cols-4`). Bezahlt wird das in den Zellen —
`min-w-0` plus `truncate` am Label —, damit eine schmale Ansicht die Wörter
kürzt statt die Reihe umzubrechen. Der Wert steht eine Stufe kleiner
(`Heading size="xl"` statt `2xl`; das Kit kennt `base`/`lg`/`xl`/`2xl`).

> **Abweichung.** Kompakter wird die Karte über `py-3!` und `space-y-1!` als
> Utilities, weil das Kit nichts Sanfteres anbietet: `Card` hat genau einen
> Padding-Schalter, `inset`, und der ist alles-oder-nichts (er entfernt
> `px-4 sm:px-4.5 py-5 space-y-2` **und** den Abstand zwischen den Zeilen, der
> Farbpunkt läge dann an der Kartenkante). Das horizontale Padding bleibt
> deshalb das des Kits, überschrieben wird nur der vertikale Rhythmus. Die
> Ausrufezeichen sind nötig, nicht Deko — siehe `TONE_CLASSES`: die
> Addon-Utilities liegen im niedrig priorisierten Layer `addon-utilities`.

#### „erneut starten" — die eine Aktion der Reihe

Karte 3 trägt zusätzlich `action`: `{url, label}` oder `null`. Ist sie gesetzt,
rendert die Vue-Komponente die Notiz als `Button` (`variant="subtle"`,
`size="xs"`, `inset`) statt als `Description` — die Notiz **ist** die Aktion,
zweimal dieselben Worte (einmal als Rat, einmal als Bedienelement) wären zwei
verschiedene Dinge. Sonst bleibt die Reihe unangetastet Anzeige.

`action` kommt nur, wenn es etwas zu starten gibt **und der Betrachter
mindestens eines davon starten dürfte** —
`FailedTranslationRetry::retryableBy()` prüft das serverseitig beim Bau des
Widgets und bricht beim ersten Treffer ab (die Fehlermenge ist naturgemäß klein,
ein geparkter Rewrite ist ein Vorfall, keine Warteschlange). Ein Button, der beim
Klick 403 gibt, ist schlechter als ein Satz: wer nicht Assignee der geparkten
Fassung ist, sieht weiterhin die reine Notiz.

**Endpoint:** `POST cp/workflows/api/stats/retry-failed`
(`workflows.api.stats.retry` → `WorkflowController::retryFailedTranslations()`).
Ohne Parameter — die Menge *ist* „alles, was fehlgeschlagen ist", deshalb hängt
die Route an `stats` und nicht an einer Instanz. Antwort:
`{retried, skipped, remaining_failed}`; `retried`/`skipped` zählen Subjects,
`remaining_failed` ersetzt der Endpoint durch die Einheit der Karte, Beiträge
(`matchingPostCount()`).

Die Arbeit macht **`src/Workflow/FailedTranslationRetry.php`**, und zwar pro
Subject:

- Eintrag auflösen (gelöscht → übersprungen);
- den **einen** Ausgang aus dem Fehler-Place bestimmen (`retryStep()`). Genau
  einer: bei keinem gibt es nichts anzubieten, bei zweien hat die Definition den
  Ausweg zu einer Entscheidung gemacht (neu starten oder verwerfen?), und die
  darf ein Sammel-Button nicht stellvertretend treffen → übersprungen;
- `canApplyTransition()` für den aktuellen User — dieselbe Methode, die auch den
  Button auf der Instanzseite gatet, und **deshalb ist sie public** (kein Route,
  Statamic mountet nur, was `routes/cp.php` nennt; die Alternative wäre eine
  Kopie der Prüfung im Widget gewesen, also genau die Drift, gegen die es sie
  gibt);
- anwenden — **über `WorkflowController::applyTransition()`**, mit einem selbst
  gebauten `Request` (`?workflow=…` in der Query, dort liest die Methode ihn).

Der letzte Punkt ist der wichtige. Der Schritt aus dem Fehler-Place ist im
Einfache-Sprache-Manager keine bloße Statusänderung: er trägt
`plain_language_resubmit`, und `applyTransition()` beantwortet das mit einem
eigenen Zweig — Ziel- und Quellfassung auflösen, ablehnen wenn der Dienst nicht
konfiguriert ist, die Transition auf die **Zielfassung** anwenden, veraltete
Job-Zeilen per `PlainLanguageJobStore::forget()` wegräumen und den Rewrite neu
einreichen (§ 22). `WorkflowTransitioner` direkt zu rufen würde davon das erste
tun und die übrigen vier nicht: die Fassung läge in „wird übersetzt" ohne Auftrag
dahinter und käme dort nie wieder heraus. Über den Controller kommen dagegen
Revision, Event und Mails identisch heraus wie bei einem Klick auf der
Instanzseite. Diese Kopplung sitzt bewusst gebündelt in dieser einen Klasse.

Alles, was nicht geht, wird **übersprungen statt abgelehnt** — eine Sammelaktion
mit Teilergebnis ist die ehrliche Auskunft, und `remaining_failed` wird nach dem
Lauf **neu aus dem Store gezählt**, nicht von `retried` abgezogen: die Zahl muss
die sein, die ein Reload zeigen würde. Der Endpoint hat keinen eigenen
Permission-Kurzschluss außer „eingeloggt": die Prüfung pro Subject *ist* die
Prüfung. Wer nichts starten darf, bekommt `{retried: 0, skipped: n}` statt einer
403 — die ehrliche Antwort auf „starte, was du kannst", wenn das nichts ist.

#### Vue-Seite

Wie `UserHeader` und die zwei Listen-Karten ein **Vue-Komponenten-Widget**:
`component()` liefert `['name' => 'workflows-queue-stats-widget', 'props' =>
['cards' => [...]]]`, aufgelöst über `resources/js/components/QueueStatsWidget.vue`
(registriert in `resources/js/addon.js`). Grund sind hier die Kit-Komponenten
`Card`/`Heading`/`Description`, zu denen es kein Blade-Gegenstück gibt. Die
Komponente rechnet nichts: Label, Wert, Notiz und Ton kommen fertig übersetzt aus
`QueueStats::component()`, damit es genau eine Stelle gibt, an der „was zählt
diese Karte" entschieden wird. Einzige Eigenbau-Stelle im Markup ist der 7-px-
Farbpunkt neben dem Label — dafür hat das Kit keine Komponente.

Auch der Retry entscheidet clientseitig nichts: die Komponente postet (axios über
Vues Global Properties, mit `fetch`+CSRF als Fallback — dieselbe Form wie
`WorkflowsSettingsIndex.vue`), schreibt `remaining_failed` in die Karte zurück
und meldet über `Statamic.$toast` (`widget_stats_retry_done` /
`widget_stats_retry_error`). Bei 0 verliert die Karte Button **und** Notiz. Die
Karten liegen dafür als lokale Kopie (`rows`) neben den Props — Props sind
read-only, und das Dashboard liefert sie ohnehin nur einmal.

### `WorkflowStatus` (`workflows_status`)

`src/Widgets/WorkflowStatus.php` — „Inhalte nach Status": eine Karte je aktivem
Workflow, jede mit einem Chart.js-Doughnut der Statusverteilung dieses Workflows
und einer Legende, die jeden Status mit seiner absoluten Zahl benennt. **Ein**
Widget für die ganze Reihe, aus demselben Grund wie bei `QueueStats`: die Karten
sind eine Aussage, müssen bündig stehen und gleich hoch bleiben. Config:
`workflows` (Teilmenge aktiver Workflow-Namen, Default alle) über denselben Trait
`PresentsPersonalEntries`. Projektweit, nicht persönlich — die Frage ist, was der
Bestand tut, nicht was auf dem Schreibtisch des Betrachters liegt.

**Nichts davon steht im Widget.** Welche Workflows: `DefinitionLoader::all()`.
Welche Status: was `WorkflowStateStore::statesFor()` tatsächlich enthält — ein
Status, in dem nichts liegt, bekommt kein Segment; der Ring zeigt, was IST, nicht
was die Definition erlaubt. Beschriftung und Farbe: `stateLabel()` /
`stateColor()`, also folgt der Ring dem Settings-Editor.

**Statusfarben.** Server-seitig reist ein Statamic-Farb**name** (`green`, `blue`,
…), nie ein Hex. Die Umsetzung in Hex passiert genau einmal, in
`colorHex()` in `resources/js/support/widgetColors.js` — Chart.js malt in ein
Canvas und nimmt Werte statt Klassen entgegen, ein `.wf-dot--blue` hat dort
nichts, woran es hängen könnte. Die Legendenquadrate binden dieselbe Tabelle per
Inline-`style`: ein Legendenquadrat IST der Farbschlüssel des Rings, aus zwei
Tabellen könnten die beiden auseinanderlaufen. Die Werte liegen dicht bei, aber
nicht auf den Punkt-Hexes in `addon.css` (`green`, `blue`, `orange` weichen ab) —
Ringflächen nebeneinander lesen sich anders als 6-px-Punkte. Nah beieinander
liegende Namen teilen sich einen Wert (green/emerald, purple/violet,
fuchsia/pink, amber/yellow), weil zwei Nachbartöne im Ring wie ein Ton plus
Rendering-Artefakt aussehen.

**Reihenfolge.** Ring und Legende lesen dieselbe Liste, sortiert **server-seitig**
(`WorkflowStatus::COLOR_ORDER`) — eine zweimal gerechnete Ordnung ist eine
Ordnung, die sich widersprechen kann. Sortiert wird nach dem Farb**namen** des
Status, nicht nach dem Place-Handle: die Farbe ist die eine Eigenschaft, die
jeder Place hat und jedes Projekt einstellen kann. Die Skala läuft fertig → in
Arbeit → wartend → kaputt: Grüntöne (green, emerald, lime, teal, cyan) → Blautöne
(blue, sky, indigo) → Lilatöne (purple, violet, fuchsia, pink) → neutral
(default, gray) → warm (amber, yellow, orange) → red, rose. Das ist die
**Umkehrung** von `PersonalWorkItems::URGENCY`, und zwar absichtlich: eine
Warteschlange beantwortet „was mache ich als Nächstes", eine Verteilung „wo ist
alles gelandet". Gleichstände (zwei Blau im selben Workflow) brechen über das
Place-Handle, damit die Reihenfolge über Requests stabil bleibt statt der
Datenbank zu folgen. Ein unbekannter Farbname sortiert ans Ende, statt zu
verschwinden.

**Klickziele.** Die Reihe ist nicht nur Anzeige — jede Zahl führt zu den
Beiträgen dahinter:

- **Legendenzeile** und **Ring-Segment** öffnen dasselbe: einen `Stack` mit den
  Beiträgen in genau diesem Status, betitelt „{Workflow} · {Status}". Beides ruft
  `openSegment()`; das Segment findet seinen Eintrag über den Arc-Index, denn die
  Reihenfolge des Datasets **ist** die Segment-Reihenfolge (der Server sortiert
  einmal, Ring und Legende lesen dieselbe Liste). Über einem Arc wird der Cursor
  per `onHover` auf `pointer` gesetzt — ob der Zeiger über einer Fläche oder im
  Loch steht, ist eine Canvas-Tatsache, die CSS nicht kennt.
- **Die Gesamtzahl im Loch** ist ein einfacher Link auf die Manager-Seite des
  Workflows (`card.manager_url`, null ohne `metadata.nav` → bleibt reine Zahl).
  Kein Stack: die Summe ist der ganze Workflow, und „alles davon" ist eine Seite
  mit Filtern und Sortierung, keine Liste von 300 Titeln in einem Slide-over. Der
  Overlay-Container bleibt `pointer-events-none`, nur der Link holt sich die
  Events zurück — er liegt im Loch, wo ohnehin kein Arc zu treffen ist.

**Lazy, nicht im Payload.** Die Listen reisen **nicht** mit dem Dashboard.
Jedes Segment trägt nur `links_url`; geklickt wird
`GET workflows/api/stats/state-subjects?workflow=…&state=…&page=N`
(`workflows.api.stats.state-subjects` → `WorkflowController::stateSubjects()`).
Andernfalls lägen die Beiträge **jedes** Status **jedes** Workflows im
Payload — auf dem Referenzprojekt rund 600 Entry-Lookups pro Dashboard-Aufruf,
für Listen, die niemand öffnen muss. Während des Ladens zeigt der Stack
`Skeleton`-Zeilen. Schlägt der Request fehl, schließt sich der Stack wieder und
ein Toast sagt `widget_status_load_error` — ein leeres Panel mit Titel wäre ein
Versprechen, das das Widget nicht halten kann; gecacht wird nichts, der nächste
Klick versucht es erneut.

**Echte Seiten, kein Cap.** Die Antwort ist `{items, meta, manager_url}`:
`meta` ist das `meta` einer Laravel-API-Resource
(`current_page`, `last_page`, `per_page`, `total`, `from`, `to`), gebaut aus
einem `LengthAwarePaginator` in `SubjectLinks::paginate()` — genau das, was die
`Pagination`-Komponente des CP als `resourceMeta` erwartet, also ist der Fuß des
Stacks der Pager, den das CP überall benutzt (seine Texte kommen aus Statamics
eigenen Übersetzungen, nicht aus diesem Addon). Seitengröße 25
(`SubjectLinks::PER_PAGE`; die Konstante im Vue-File ist nur die Prop für die
Anzeige — die Wahrheit steht auf dem Server). Kein „+N mehr" mehr: hier ist
**ein** Workflow und **ein** Status gefragt, also ist jeder Beitrag erreichbar.
`manager_url` reist einmal oben mit und wird im `#footer-end` als sekundärer
Ausgang angeboten (`widget_stack_open_manager` — ein eigener, kürzerer Text als
der `widget_open_manager` der übrigen Widget-Füße, weil hier nur eine schmale
Zeile neben dem Pager frei ist), dem Pager gegenüber.

Sortiert wird **nach Titel** (natürlich, case-insensitiv, Gleichstand über die
Subject-ID). Ein Pager braucht eine Gesamtordnung, die zwei Requests überlebt,
und die Zeilenreihenfolge des Stores ist keine — sie ist, was die Datenbank ohne
`ORDER BY` zurückgab; zwei titelgleiche Beiträge könnten zwischen Seite 1 und
Seite 2 die Plätze tauschen, und genau so zeigt ein Pager einen Beitrag zweimal
und einen nie. Deshalb werden **alle** Einträge der Menge aufgelöst, bevor die
Seite geschnitten wird: nach einem Titel lässt sich nicht sortieren, ohne ihn zu
lesen. Derselbe Durchgang macht die Gesamtzahl ehrlich — verwaiste State-Zeilen
(Eintrag gelöscht, Zeile geblieben) fallen aus `total`, die Seiten bleiben voll
und die letzte Seite liegt da, wo sie steht (auf dem Referenzprojekt: 277 Zeilen
in `plain_ready`, `total` 276). Kosten: ein Durchgang über die Stache, die die
Einträge im Speicher hält — ~60 ms für 277 IDs, einmal pro Klick. Die Seite
selbst wird clientseitig pro `links_url` **plus Seitennummer** gecacht; Blättern
hin und zurück kostet keinen zweiten Request. Eine Seite über der letzten ist
Laravels Standardantwort: `current_page` ist die angefragte, `items` leer (der
Pager zeigt dem Leser damit, dass er über das Ende hinaus ist, statt ihn
stillschweigend zu verschieben); unter 1 wird auf 1 geklemmt.

Der Endpoint ist read-only und für jeden angemeldeten CP-User offen: er sagt
nichts, was das Dashboard nicht schon als Zahl zeigt, und jeder Link landet auf
einer Seite mit eigener Rechteprüfung. Beide Parameter werden gegen
serverseitige Mengen validiert — der Workflow muss **aktiv** sein, der Status ein
Place davon **oder** ein Status, den der Store dieses Workflows tatsächlich hält
(sonst 404). Die zweite Hälfte ist Absicht: ein Place kann im Settings-Editor
gelöscht werden, während Einträge noch darin geparkt sind — das Widget zeichnet
diese Zeilen (es liest den Store, nicht die Definition), und eine Legendenzeile,
die beim Klick 404 gibt, wäre genau dann kaputt, wenn jemand diese Beiträge
sucht.

**Tooltip.** Der Tooltip ist ein HTML-Element am `<body>`, kein Canvas-Tooltip:
Chart.js zeichnet seinen eigenen INS Canvas, und in einem 116-px-Quadrat wurde er
an dessen Kante abgeschnitten und verschwand hinter der absolut positionierten
Gesamtzahl im Loch des Rings. Also `plugins.tooltip.enabled: false` plus der
dokumentierte `external`-Handler, der ein einzelnes, von allen drei Ringen
geteiltes `<div>` positioniert (`position: fixed`, weil `caretX/caretY` auf
`getBoundingClientRect()` addiert werden und das viewport-relativ ist;
`pointer-events: none`, sonst flackert der Hover). Die Callbacks bleiben, wo sie
waren — Chart.js baut `title`/`body` auch bei abgeschaltetem Canvas-Tooltip und
reicht sie an den Handler durch. Gestylt wird inline (das Element liegt außerhalb
des Widget-DOM, die Kaskade aus `addon.css` erreicht es nicht), Text per
`textContent`, weil der Titel ein Status-Label aus den Settings ist. Abgeräumt
wird es in `onUnmounted` neben `chart.destroy()` — es hängt außerhalb dessen, was
Vue selbst unmountet.

**Leerer Ring.** Ein Workflow ohne getrackte Subjects wird **nie** ausgeblendet:
er reist mit `total: 0` und ohne Segmente, und die Komponente zeichnet einen
einzelnen grauen Vollring (`#e5e7eb` hell, `#374151` dunkel) ohne
`external`-Handler, also ohne Tooltip, plus die Zeile `widget_status_empty` an
der Stelle der Legende. Eine
fehlende Karte läse sich als „diesen Workflow gibt es nicht" statt als „da liegt
noch nichts" — und bei einem frisch installierten Workflow ist Zweiteres die
interessante Aussage.

**Chart.js** kommt per npm (`chart.js@^4`) und geht durch den vorhandenen
Vite-Build des Addons — kein CDN, und kein `Statamic::script()`, weil das Addon
ohnehin schon genau ein Bundle ausliefert. Registriert wird tree-shaken
(`Chart.register(DoughnutController, ArcElement, Tooltip)`), nicht über
`chart.js/auto`: ein Doughnut braucht keine einzige Skala. Kosten im Bundle: rund
140 kB roh (173 kB → 313 kB, gzip 88 kB). Die Chart-Instanzen werden in
`onUnmounted` zerstört — Chart.js hält sie in einer globalen Registry am Canvas
und hängt Resize-Listener daran, beides bliebe sonst liegen. Global gesetzt wird
**nichts** (insbesondere keine `Chart.defaults.font.family`): das CP hat seinen
eigenen Font-Stack, Größen und Farben stehen pro Chart in den Options.

Die zwei Farben, die im Canvas festgebacken sind, hängen am Theme: die Trennlinie
zwischen den Segmenten ist der durchscheinende Karten-Hintergrund und wird zur
Laufzeit vom Element abgelesen (`getComputedStyle`, den Baum hoch bis zur ersten
gemalten Fläche — `Card` ist `bg-white dark:bg-gray-850`, und diese zwei Werte
sollen nicht noch einmal in JS stehen), der leere Ring wählt seinen Grauwert nach
der `dark`-Klasse. Das CP schaltet das Theme live um, deshalb hängt ein
`MutationObserver` auf `<html class>` und zeichnet mit `chart.update('none')`
nach.

### `AssignedPosts` (`workflows_assigned_posts`)

`src/Widgets/AssignedPosts.php` — Dashboard-Kachel mit den dem aktuellen User
zugewiesenen Beiträgen EINES Workflows; der Workflow wird zur Laufzeit über die
konfigurierte `collection` (Default `posts`) aufgelöst. Konfigurierbar per
`limit` (Default 5). Rendert `resources/views/widgets/assigned-posts.blade.php`.

### `MyQueue` (`workflows_my_queue`)

`src/Widgets/MyQueue.php` — „Meine Warteschlange" workflow-ÜBERGREIFEND: alle
Beiträge, denen der aktuelle User als Assignee eingetragen ist, über sämtliche
aktiven Workflows. Die Semantik ist absichtlich identisch zum `mine=1`-Filter
der Manager-Listen — nur direkte Zuweisungen, keine Gruppen-Expansion — damit
Widget und Manager nie verschiedene Antworten geben. Config: `limit` (Zeilen je
Seite, Default 7) und `workflows` (Array von Workflow-Namen zur
Einschränkung; Default alle aktiven, unbekannte Namen werden still
übersprungen).

**Komponenten-Widget** (`component()`, kein `html()`):
`['name' => 'workflows-my-queue-widget', 'props' => ['tabs' => …, 'rows' => …, 'limit' => …]]`,
aufgelöst über die in `resources/js/addon.js` registrierte Komponente
`resources/js/components/MyQueueWidget.vue`. Grund ist die **Tab-Zeile**: die
Karte filtert nach Workflow, und das muss ohne Dashboard-Reload gehen.

- `tabs` — je Workflow, DER ZEILEN HAT, in Workflow-(= Nav-)Reihenfolge:
  `['key' => <workflow>, 'label', 'color', 'count' => <Gesamtzahl>]`. `count`
  steht im Zähler-`Badge` des Tabs. Der Tab „Alle" (`widget_tab_all`) wird
  clientseitig vorangestellt, seine Zahl ist die Summe.
- `rows` — ALLE Zeilen, serverseitig nach (`urgency`, Titel natürlich) sortiert
  (`MyQueue::payload()`). Damit sortiert der Client nie: „Alle" blättert durch
  diese Liste, ein Workflow-Tab durch einen Filter darüber.
- `limit` — die Seitengröße.

**Paginierung clientseitig** (gilt für alle drei persönlichen Karten — MyQueue,
`CreatedByMe`, `Watched`): `PersonalWorkItems` baut die Liste ohnehin vollständig
(es kürzt nicht, das taten bisher erst die Widgets), also reist sie ganz mit und
der Client blättert `limit` Zeilen je Seite. Kein Endpoint, kein Round-Trip;
der Preis ist die Payload — im Referenzprojekt ~144 KB für einen User mit 283
Zuweisungen, eingebettet ins Dashboard-HTML. Wird das je zu viel, wäre ein
Listing-Endpoint der Schritt, nicht ein Cap (der würde Seiten unerreichbar
machen). Der Pager ist `resources/js/components/WidgetPagination.vue`: die
Kit-`Pagination`, die auch `ListingPagination` der „Alle Beiträge"-Karte rendert,
mit deren Defaults (Summenzeile „1–7 von 283" plus Seitenzahl-Buttons) im selben
`px-4 py-2`-Rahmen wie die `bare`-Listing — nur der Per-Page-Selector ist aus
(seine Optionen sind die globalen Listing-Größen des CP, `limit` gehört nicht
dazu; die Seitengröße ist die Widget-Config) und `scroll-to-top` auch (scrollt
das Fenster, also das ganze Dashboard). Passt alles auf eine Seite, rendert er
nichts. Ist die Liste paginiert, füllt die Karte eine kurze Seite mit
unsichtbaren Platzhalter-Zeilen (Kopien einer echten Zeile, `placeholder`) auf
`limit` Zeilen auf, damit Karte und Pager nicht springen; MyQueue tut das auf
jedem Tab, sobald „Alle" mehr als eine Seite hat, und hält dann auch die Höhe
des Pagers (unsichtbar). Die Seiten-Logik (`page`, Slice, `meta` im Laravel-Resource-Format für
`resourceMeta`, Klemmen beim Schrumpfen der Liste) ist das Composable
`useClientPagination()` in `resources/js/support/pagination.js`. In MyQueue
blättert der Pager den aktiven Tab; ein Tab-Wechsel springt auf Seite 1.

**Zeilen-Sortierung = Dringlichkeit**, abgeleitet aus der STATE-FARBE, nicht aus
dem Place-Handle: `PersonalWorkItems::URGENCY` bildet Farbnamen auf einen Rang ab
(rot 0 → rose → orange/amber/yellow → blau/sky → indigo/purple → neutral 9 →
cyan/teal → lime/emerald → grün 12). Die Farbe hat jeder Place und jedes Projekt
kann sie über `metadata.color` stellen — wer einen Place rot malt, hat damit
schon gesagt, dass er zuerst dran ist; das Widget folgt, ohne eine
projektspezifische Tabelle zu brauchen. Unbekannte Namen landen auf dem
neutralen Rang, ändern also die Farbe einer Zeile, nie ihre Position.

**Meta-Zeile** (nur MyQueue): die dritte, schrumpfende Spalte der Meta-Zeile,
gebaut aus dem NEUESTEN Workflow-Event des Subjects
(`WorkflowEventStore::latestEventTimesByWorkflow()` — ein Aggregat je Workflow,
`max(created_at)` in SQL, keine Query je Zeile). Zwei Lesarten desselben
Zeitstempels, weil die Frage je State eine andere ist: alles noch in Arbeit
zeigt eine DAUER („vor 6 Tagen"), alles Fertige ein DATUM („heute 09:14", sonst
`31.08.2026`). „Fertig" heißt hier grün — die State-Farben mappen
published/approved/done auf grün, also braucht die Karte keine Liste von
Place-Handles. Subjects ohne Event bleiben leer (ein geseedeter State hat keine
Historie). Die Dauer kommt als RELATIVE Carbon-Form, und das ist Grammatik, nicht
Stil: Deutsch dekliniert nach Präposition, und Carbon führt den Dativ nur in den
Relativ-Keys (`day_ago` = „:count Tagen", das nackte `day` = „:count Tage") —
`DIFF_ABSOLUTE` plus eigenes „seit " ergab „seit 6 Tage". Also rahmt Carbon, und
`widget_meta_since` gibt durch (`:time`).

### `CreatedByMe` (`workflows_created_by_me`)

`src/Widgets/CreatedByMe.php` — „Von mir erstellt" workflow-übergreifend.
Ersteller-Semantik identisch zu `created_by_me=1` der Manager: ältestes
`created`/`localized`-Event, geliefert von
`WorkflowEventStore::creatorsByWorkflow()` (die Auswahllogik lebt dort und
wird nicht dupliziert). Config wie `MyQueue` (`limit` = Seitengröße, Default 5,
und `workflows`).

Ebenfalls Komponenten-Widget:
`['name' => 'workflows-created-by-me-widget', 'props' => ['rows' => …, 'limit' => …]]`
→ `resources/js/components/CreatedByMeWidget.vue`, `rows` vollständig (siehe
Paginierung oben). Hier nicht wegen Interaktivität — einziger Client-State ist
die Seite —, sondern wegen der
geteilten Zeilen-Komponente: beide Karten liegen nebeneinander und müssen unter
dem Header pixelgleich sein. Sortiert nach `last_modified` DESC (Header-Subline
`widget_created_subline`, „Zuletzt bearbeitet"), nicht nach Dringlichkeit: die
Frage dieser Karte ist „was habe ich angefasst, und wo steht es", da ist
Aktualität die nützliche Ordnung. Deshalb rendert sie auch **weder Rail noch
Meta-Text** — die Rail kodiert Dringlichkeit und würde eine Reihenfolge
versprechen, der die Liste nicht folgt; der Meta-Text würde den Sortierschlüssel
je Zeile wiederholen. Weil nur `assignedTo()` die Meta-Zeile anfordert
(`rows(..., withMeta: true)`), zahlt `createdBy()` den Event-Lookup gar nicht.

Beide Listen-Widgets teilen sich den Row-Builder
`src/Workflow/PersonalWorkItems.php` (`assignedTo()` / `createdBy()`; die
Assignee-Abfrage läuft über das gezielte
`WorkflowAssigneeStore::subjectIdsForUser()`) und die Zeilen-Komponente
`resources/js/components/PersonalWidgetRow.vue` (die alte gemeinsame Blade-View
`widgets/personal-entries.blade.php` ist entfallen).

**Kit für die Mechanik, Spec-Markup für die Zeilen.** Der Kartenrahmen ist die
`Widget`-Komponente aus `@statamic/cms/ui` — die kanonische Dashboard-Kachel mit
Header und `footer`-Slot; nichts zeichnet hier eine eigene Card. Die Tab-Zeile von
MyQueue ist `Tabs`/`TabList`/`TabTrigger`/`TabContent` (dieselben, die das
Publish-Formular benutzt): `Tabs` hält per `v-model` den aktiven Key, je Tab ein
`TabTrigger :name` mit Label plus Zähler-`Badge` und ein `TabContent :name` mit
der Scheibe dieses Tabs — `Tabs` hängt die inaktiven Panels aus, es steht also
immer nur eine Scheibe im DOM. Ihr Aussehen (blauer 2px-Indikator, Abstände) wird
über den Marker `wf-widget-tabs` und die `data-ui-tabs-*`-Hooks der Komponenten in
`resources/css/addon.css` nachgezogen. Leerzustände sind `Description`.

**Die Möblierung der Zeile dagegen ist das Markup des Designs.** Ein
Zwischenschritt hatte Rail, Dot und State durch Kit-`Badge`s ersetzt; das ergab
eine andere Karte — flacher, höhere Zeilen, kein Dringlichkeitssignal — und ist
für Rail, Site-Chip, Dot und Meta-Text zurückgenommen. Die Regel für diese zwei
Kacheln lautet seither: Kit überall, wo Verhalten dranhängt (Shell, Tabs, Buttons,
Popover) — und für den State —, Spec-Markup für die übrige Zeilenoptik.

**Der State bleibt das Kit-`Badge`,** genau wie in den Manager-Listen:
`<Badge :text="row.state_label" :color="row.state_color" size="sm" />`, dasselbe
Muster wie in `WorkflowListing.vue`. Ein State muss überall gleich aussehen, und
die Manager sind der Ort, an dem er am häufigsten gelesen wird — zwei Darstellungen
desselben Zustands im selben CP sind schlimmer als eine, die nicht exakt der Spec
folgt. `state_color` ist schon ein Statamic-Badge-Farbname und wandert deshalb
direkt in `:color`. Es gab dafür kurzzeitig eine eigene `.wf-pill--*`-Trio-Familie
in `addon.css`; die ist entfernt und soll nicht zurückkommen.

Eine Zeile trägt von links nach rechts: die 3px-**Urgency-Rail** in der
State-Farbe (nur MyQueue, `with-rail`), dann den Titel (Link via `InstanceUrl` auf
die Instanz-Detailseite, 13.5px in Link-Blau) und darunter die Meta-Zeile aus
Site-Chip (nur Multisite, `wf-site-code` mit dem versalisierten Site-Handle —
dieselbe monospaced Optik wie die Versions-Pills), farbigem 6px-**Dot** plus
Workflow-Label und dem Meta-Text (mit „· " abgesetzt), und rechts das
**State-`Badge`** in der Place-Farbe aus den Settings
(`DefinitionLoader::stateColor()`: `metadata.color` des Place, dann die
Built-in-Defaults — serverseitiger Spiegel von `stateColor()` in
`resources/js/support/states.js` —, dann grau). In der Meta-Zeile darf nur der
Meta-Text schrumpfen: Site-Code und Workflow-Label sind Identifikatoren, die halb
abgeschnitten nichts bedeuten.

**Nur der Titel ist der Link,** nicht die ganze Zeile wie in der ersten Fassung.
Das kostet Klickfläche und ist trotzdem richtig: die Zeile trägt inzwischen
mehrere eigene Ziele, und ein `<a>` um alles herum verschluckt deren Hover- und
Fokuszustände — beim `Badge` buchstäblich, dessen Hover-Stile als `[a]:hover:…`
geschrieben sind und nur greifen, wenn es selbst der Anker ist.

Die zwei Spec-Farben laufen über **Farbnamen → CSS-Klassen**:
`resources/js/support/widgetColors.js` (`railClass()`, `dotClass()`) bildet einen
Statamic-Badge-Farbnamen auf `.wf-rail--<name>` bzw. `.wf-dot--<name>` in
`resources/css/addon.css` ab; eine Komponente kennt nie einen Hex. Klassen statt
Inline-Styles, weil die Rail eine Dark-Mode-Entsprechung braucht, die ein
`style`-Attribut nicht tragen kann; und literale Hexes statt der
`--theme-color-*`-Tokens des CP, weil diese Palette die des Designs ist (ihr
„green" ist Tailwinds EMERALD, ihr „purple" ist VIOLET). Die zwei Familien sind
bewusst getrennt: derselbe Name heißt in jeder etwas anderes — `red` ist ein
sattes 500 in der Rail (bei 3px Breite muss es sich durchsetzen) und ein 500 als
Dot, und die Rail fasst Töne zusammen, die der Dot auseinanderhält. Namen, die
`widgetColors.js` nicht kennt, fallen auf `default` (grau) zurück, ein vertipptes
`metadata.color` degradiert also sichtbar statt unsichtbar. Der State-Farbname
geht diesen Weg NICHT — er landet ungefiltert in `Badge`, wie überall sonst im
Addon.

Im **Footer**-Slot des `Widget` steht nur noch der Pager (siehe Paginierung
oben). Die frühere „● +3 Beiträge ● +1 Übersetzungen"-Leiste
(`PersonalWidgetFooter.vue`, je Workflow auf die Manager-Seite verlinkt) ist
entfallen: sie zählte und verlinkte, was `limit` verdeckte — mit Paginierung ist
nichts mehr verdeckt, und die Manager-Seiten erreicht man über die Nav.
(`PresentsPersonalEntries::overflow()` ist mit dem Stack der Kennzahlreihe
entfallen.)

Die Karte trägt die ID `workflows-my-queue` (+ `tabindex="-1"`): Ziel der
Kennzahl-Karte „In meiner Warteschlange" (siehe `QueueStats`).

Die Workflow-Farbe (Dot) kommt aus `metadata.color` im Workflow-YAML (jeder
Statamic-Badge-Farbname); ohne Deklaration vergibt
`PersonalWorkItems::workflowColor()` stabil eine Farbe aus einer Palette nach
Position in `DefinitionLoader::all()` — bewusst andere Töne als die State-Farben,
damit Workflow- und State-Farbe nicht verwechselt werden. Im Referenzprojekt sind
sie gesetzt: `postmanager: emerald`, `plainlanguage: indigo`,
`translation: pink`. Dasselbe Subject kann je einmal pro beteiligtem Workflow
erscheinen (Owning- + Lokalisierungs-Workflow) — Dot und Workflow-Label
disambiguieren, wie in den Managern.

### `Watched` (`workflows_watched`)

`src/Widgets/Watched.php` — die Beiträge, die der aktuelle User über den
Merken-Button der Detailseiten beobachtet, über alle aktiven Workflows. Gebaut
wie `CreatedByMe` (dieselbe Zeilen-Komponente, sortiert nach `last_modified`);
jede Zeile trägt zusätzlich die URL zum Nicht-mehr-Beobachten. Config: `limit`
(Seitengröße, Default 5) und `workflows`. Props `rows` (vollständig) und
`limit`; paginiert wie oben. Nicht-mehr-Beobachten entfernt die Zeile
clientseitig, die Paginierung läuft über den Rest: die nächste Zeile rückt in
die Seite nach, und wird die letzte Seite leer, springt der Pager eine zurück.

### `WorkflowsOverview` (`workflows_overview`)

`src/Widgets/WorkflowsOverview.php` — „Alle Beiträge": die Pivot-Liste der
Manager-Seiten über **alle** aktiven Workflows in einer Tabelle. Die Workflows
kommen aus `DefinitionLoader::all()`; ein neu installierter oder aktivierter
Workflow taucht also ohne Config-Änderung auf. Config: `workflows` (Teilmenge
aktiver Workflow-Namen, Default alle, über `PresentsPersonalEntries`),
`per_page` (Zeilen je Seite, Default 15) und `default_workflow` (Handle des beim
Laden aktiven Workflows, Default der Authoring-Workflow, siehe
`overviewDefaultWorkflow()` in § 5.4). Ist der Scope leer, liefert
`component()` null und die Karte entfällt.

**Komponenten-Widget**: `['name' => 'workflows-overview-widget', 'props' => …]`
→ `resources/js/components/WorkflowsOverviewWidget.vue`. Die Karte IST das
Listing: dieselbe `WorkflowListing` wie auf den Manager-Seiten (`bare`, also ohne
eigene Panel-Hülle in der `Widget`-Shell), die gegen `workflows.listing` mit
`all_workflows=1` (und ggf. `workflows=a,b`) pagt, sucht und filtert. Die
Startprops kommen aus `WorkflowController::overviewProps()` — Spalten, Filter und
Create-Buttons können so nicht von dem abweichen, was der Endpoint antwortet
(§ 5.2, § 5.4).

- **Workflow-Buttons:** über der Tabelle eine `ButtonGroup` (ab zwei Workflows
  im Scope): je Workflow ein Button mit seinem farbigen Punkt + Label, sortiert
  nach `metadata.nav.order` (ohne Order ans Ende, sonst Definitionsreihenfolge),
  zuletzt
  „Alle Workflows" (`all_workflows`). Einfachauswahl; aktiv beim Laden ist
  `defaultWorkflow` (in der Praxis der Beitragsmanager) — die Auswahl wird nicht
  gemerkt. Ein Workflow schickt `workflows=<name>`, „Alle" die
  `workflows`-Config (oder nichts): der Endpoint engt Zeilen, Site-Spalten und
  State-Filter ein. Die Liste ist nach der Auswahl **gekeyt** und wird beim
  Wechsel neu gemountet, mit `columns`/`filters` aus dem passenden
  `workflowOptions`-Eintrag: Listing würde zwar bei geänderten
  `additionalParameters` selbst neu laden, schickt aber die alte
  Spalten-Sichtbarkeit mit (`applyColumnVisibility()` blendet dann die neuen
  Site-Spalten aus) und behält Zell-Slots, Filter und Seite. So startet jede
  Auswahl auf Seite 1 mit genau ihren Spalten und Places; Suche und Filter
  gehen beim Wechsel verloren (wie bei den Collection-Tabs der Manager-Seite).
  **Ohne Layout-Shift:** die neue Liste wird zunächst unsichtbar, absolut
  positioniert und `inert` HINTER der alten gemountet (die gedimmt stehen
  bleibt) und erst nach ihrem ersten erfolgreichen Request (`requestCompleted`,
  Fallback nach 10 s) gegen die alte getauscht — kein Skeleton im Bild. Der
  Bereich darunter hält per `min-height` die größte bisher gemessene Höhe, die
  Karte schrumpft also bei keinem Klick (auch nicht bei weniger Zeilen oder
  Spalten); Preis: eine kurze Liste lässt dann Leerraum. `pressed` und `default`
  sind beim Kit-`Button` dieselbe Box, die Buttons selbst bewegen sich nicht.
- **Spalten:** Titel, Workflow (farbiger Punkt + Label, verlinkt auf die
  Manager-Seite, wo es eine gibt; nur bei „Alle" sichtbar), eine State-Spalte je
  Site aus der Vereinigung der Sites aller Workflows im Scope, „Inhaltlich
  aktualisiert". Eine Zelle in einer Site, die der Workflow der Zeile nicht
  verwaltet, bleibt **leer** — nicht der „nicht begonnen"-Strich.
- **Filter:** Status (Places des gewählten Workflows, bei „Alle" ihre
  Vereinigung), veraltet (`StaleFilter`). Die Suche trifft auch den
  Workflow-Namen.
- **Mehrfach-Zeilen:** Ein Post, den zwei Workflows steuern (z. B.
  Beitragsmanager + Übersetzungsmanager), steht einmal je Workflow da; Zeilen-ID
  `"{workflow}:{rootId}"`.
- **„Neuer Beitrag":** im Kopf je ein Button (`CreatePostButton.vue`) für jeden
  Workflow, in dem der User anlegen darf — dieselben Regeln wie auf der
  Manager-Seite (`createOptions()`: Collection-Owner, kein
  Lokalisierungs-Workflow, `post_creator_groups`). Ab zwei Buttons tragen sie den
  Workflow im Text (`new_post_in`, „Beitragsmanager: neuer Beitrag").
- **Filter aus der Kennzahlreihe:** die Karte trägt die ID `workflows-overview`
  (+ `tabindex="-1"`). Ein Klick auf Karte 2–4 von `QueueStats` setzt über
  `support/dashboardLinks.js` ein Preset; die Karte wählt dann den Button des
  einen Workflows, aus dem das Preset schöpft, sonst „Alle", schickt
  `preset=<name>` zusätzlich zu den Parametern der Auswahl und zeigt neben der
  `ButtonGroup` einen entfernbaren Chip („Gefiltert: Wartet auf Freigabe ✕",
  `overview_preset_active` / `overview_preset_clear`, ein `Badge as="button"`).
  Chip entfernen → ungefilterte Tabelle der aktuellen Auswahl. Ein anderer
  Workflow-Button **behält** das Preset, wenn der Workflow zu dessen Scope gehört
  (oder „Alle" ist), und **verwirft** es sonst — „fehlgeschlagene Übersetzungen"
  im Beitragsmanager wäre immer leer, und eine leere Tabelle unter einem aktiven
  Chip sieht nach Fehler aus. Preset und Auswahl bilden zusammen den Key der
  Liste (`stageKey()`), jeder Wechsel läuft also durch denselben
  Hintergrund-Tausch wie ein Button-Klick — kein Layout-Shift. Der Chip steht in
  der Zeile der Buttons und verschiebt die Tabelle nicht (nur bei einem einzigen
  Workflow im Scope entsteht die Zeile erst mit dem Chip).
- **Sichtbarkeit:** für alle CP-User, wie das Listing selbst (§ 12).

### `UserHeader` (`workflows_user_header`)

`src/Widgets/UserHeader.php` — persönlicher Kopfbereich: Name des Users als
Überschrift, darunter drei Label/Werte-Zeilen. Zuerst „Funktionen" (die
Statamic-Gruppen des Users), dann:

**„Zugehörigkeit"** — eine einzige Zeile, die alle Werte einsammelt, die die
Einträge aus `config/workflows.php → assignment_criteria` mit einem
`user_field` an der Person lesen (im Referenzprojekt `entity`). Eine Zeile
statt einer pro Kriterium oder Feld: mehrere Kriterien lesen dasselbe Feld — je
eines pro Entry-Seite, gegen die sie vergleichen —, die Person hat aber nur den
einen Wert. Intern nach Feld gruppiert, damit die Werte jedes Feldes über die
Optionsquellen genau der Kriterien beschriftet werden, die es lesen
(`AssignmentCriteria::options()`, also Taxonomie-Titel statt Slugs; gelöschte
Terms fallen auf den Roh-Slug zurück).

**„Verantwortlicher"** — wer für diese Zugehörigkeit zuständig ist, gesteuert
über den Config-Schlüssel `header_responsibles`:

```php
'header_responsibles' => [
    'group' => null,       // die Gruppe, in der die Verantwortlichen stehen
    'user_field' => null,  // das Feld, das auf BEIDEN Seiten gelesen wird
],
```

Ein Mitglied von `group` gilt als verantwortlich, wenn seine Werte in
`user_field` mit denen des aktuellen Users im **selben** Feld überlappen —
verglichen über den nackten Slug, also genau wie bei den Assignment-Kriterien
(`themen::gesundheit` trifft `gesundheit`). Angezeigt werden die Anzeigenamen,
natürlich sortiert; der User selbst wird immer herausgenommen, niemand ist sein
eigener Ansprechpartner. Beide Werte sind per Default `null`, die Zeile
existiert also ohne Projekt-Config nicht — die Handles kann das Addon nicht
erfinden. Im Referenzprojekt: `group: themenverantwortliche`,
`user_field: entity`.

Bewusst eigene Config statt aus den Kriterien abgeleitet: die Kriterien sagen,
wem ein **Beitrag** zugewiesen werden darf, diese Zeile sagt, wen eine
**Person** fragt. Gleicher Vergleich, andere Frage, potenziell andere Felder.

Zeilen ohne Werte werden weggelassen — die Zugehörigkeit, wenn der User in
keinem Kriterienfeld Werte trägt, die Verantwortlichen, wenn die Config aus
ist, die Gruppe nicht existiert, der User keine Werte hat oder niemand
überlappt. Sonst keine Widget-Config. Erweiterungspunkt für weitere Zeilen
bleibt `lines()`; die Vue-Komponente iteriert die Zeilen generisch und muss
dafür nicht angefasst werden.

Anders als die übrigen Widgets rendert `UserHeader` **nicht** per Blade, sondern
als Vue-Komponenten-Widget: `component()` statt `html()` liefert
`['name' => 'workflows-user-header-widget', 'props' => ['user' => …, 'lines' => …]]`,
das Dashboard löst den Namen über die in `resources/js/addon.js` registrierte
Komponente `resources/js/components/UserHeaderWidget.vue` auf. Grund ist allein
der Avatar: er kommt jetzt aus der Statamic-UI-`Avatar`-Komponente — dieselbe
wie bei den Zuweisungen —, samt Squircle-Form, Bild-Fallback und dem aus dem
Namen abgeleiteten Farbverlauf; ein handgebauter Initialen-Kreis im Blade
erreicht die Optik nicht. Ohne eingeloggten User gibt `component()` `null`
zurück, und da es kein `html()` gibt, verwirft der `DashboardController` die
Kachel — so versteckt sie sich selbst. Die Label/Werte-Zeilen bleiben
unverändert PHP-seitig in `lines()`/`userValues()`/`responsibles()`; die
Slug-Normalisierung, die beide brauchen, steht als `bareValues()` daneben —
dieselbe wie `AssignmentCriteria::valueList()`, die dort privat bleibt.

**Ganze Zeile, halbe Karte.** Das Widget wird als `'width' => 100` konfiguriert,
belegt also eine Dashboard-Zeile allein — damit die zwei Listen-Karten darunter
als Paar stehen und die Lesefolge „wer bin ich → was liegt auf mir → was habe ich
angefangen" erhalten bleibt. Gezeichnet wird die Karte aber nur über die halbe
Breite: sie trägt einen Namen, ein Avatar und zwei, drei kurze Zeilen, und über
die volle Dashboard-Breite gestreckt wird daraus ein leeres Band mit Inhalt in
der linken oberen Ecke. Umgesetzt über die Klasse `.wf-user-header-card`
(`resources/css/addon.css`) am äußeren Karten-`div` von `UserHeaderWidget.vue`:
`max-width: 100%`, ab `@container widgets (min-width: 42rem)` dann `50%`. Eine
Container-Query, weil das Widget-Grid selbst eine ist — Statamics Dashboard
rahmt die Kacheln in `.widgets.@container/widgets` und drückt jede Widget-Breite
in `@2xl:`/`@4xl:`/`@7xl:`-Varianten dagegen aus. 42rem ist genau Tailwinds
`@2xl`, also der Punkt, ab dem eine 50er-Kachel von voller Breite auf `w-1/2`
springt: die Kopfkarte wird schmal, sobald die Karten darunter nebeneinander
liegen, und darunter greift der Deckel gar nicht.

---

## 10. CLI-Kommando: `workflow:dump`

`src/Console/DumpWorkflowCommand.php`

```bash
php artisan workflow:dump <name> --format=mermaid
```

Gibt die Symfony-Workflow-Definition als Mermaid-Diagrammquelle aus. In
Kombination mit `npm run workflows:build` (siehe `README.md`) entstehen daraus
SVGs unter `docs/workflows/*.svg`.

---

## 11. Vue-Frontend (Aufbau)

Datei `resources/js/addon.js` registriert per `Statamic.booting`:

- Inertia-Pages
  - `WorkflowsQueue` → `pages/WorkflowQueue.vue` — die Manager-Seite eines
    Workflows: Meine Warteschlange + Alle Beiträge, plus „Neuer Beitrag"-Button
    (`CreatePostButton.vue`). Der legt direkt an; eine Collection-Auswahl gibt es
    nur, wenn der Workflow mehr als eine besitzt.
  - `WorkflowsInstance` → `pages/WorkflowInstanceDetail.vue` — 949 Zeilen große
    Detail-Ansicht: Header (Titel, State-Badge, Assignees), Versionen,
    Content-Preview, Aktionen, Assignee-Drawer, History mit Diff-Toggle,
    Kommentarleiste.
  - `WorkflowsTranslationInstance` → `pages/TranslationInstanceDetail.vue` — die
    zweispaltige Übersetzungsansicht.
  - `WorkflowsSettingsIndex` → `pages/WorkflowsSettingsIndex.vue` — eine Zeile je
    Workflow mit Status-Badge und den Zeilen-Aktionen Einstellungen, Queue,
    „Status nachtragen", Aktivieren/Deaktivieren und Löschen (mit
    `ConfirmationModal`). Der „Workflow installieren"-Button ist deaktiviert,
    sobald alle mitgelieferten Typen installiert sind. Alle Aktionen laufen über
    zwei kleine Helper (`request()` liefert das `data`-Envelope,
    `errorMessage()` den Fallback-Text), damit der axios/fetch-Split an einer
    Stelle liegt.
- Fieldtypes
  - `revision_status-fieldtype-index` → `RevisionStatusIndex.vue`.
  - `revision_status-fieldtype` → `RevisionStatusFieldtype.vue`.
  - `workflow_actions-fieldtype` → `WorkflowActionsFieldtype.vue`.
- Komponenten
  - `WorkflowListing.vue` — dünner Wrapper über Statamics `Listing` mit
    passenden Slots. Props u. a. `perPage` (an `Listing` durchgereicht, sonst
    dessen Default) und `bare` (ohne `Panel`/`PanelFooter`-Hülle, für einen Host,
    der selbst eine Karte ist; dort hält der Scroll-Container per
    `min-height` die größte gemessene Höhe, solange paginiert wird — die kurze
    letzte Seite schrumpft nicht, der Pager bleibt stehen). Zelltyp `workflow`: Workflow-Punkt + Label, als
    Link auf `manager_url`. State-Zellen einer Site, die nicht in `row.sites`
    steht, bleiben leer. Verwendet:
    - `GroupedListingTable.vue` — Zwei-Zeilen-Header (Site-Gruppe + Sprach-Flag)
      für den Pivot-View.
  - `CreatePostButton.vue` — „Neuer Beitrag"-Button samt Modal (Titel,
    Collection-Auswahl, gekoppelte Transition mit Assignee-Picker), aus
    `WorkflowQueue.vue` herausgelöst. Props `createUrl`, `createCollections`,
    `createCta`, `workflowName`, optional `text`/`size`. Die Form-ID ist pro
    Instanz, weil das Dashboard-Widget mehrere Buttons (und Modals) rendert.
  - `workflows-overview-widget` → `WorkflowsOverviewWidget.vue` — das
    Dashboard-Widget `workflows_overview` (§ 9): `Widget`-Shell, im
    `#actions`-Slot ein `CreatePostButton` je `creates`-Eintrag, darunter die
    Workflow-`ButtonGroup` (Punkt aus `dotClass()`, `variant="pressed"` für den
    aktiven), daneben ggf. der Preset-Chip der Kennzahlreihe, und
    `WorkflowListing` mit `bare` und `perPage`, nach Auswahl + Preset gekeyt.
  - `WorkflowHistory.vue` — History-Liste samt Diff-Panel und dem LCS-Wort-Diff.
    Von **beiden** Detailansichten benutzt (§ 5.10).
  - `workflows-queue-stats-widget` → `QueueStatsWidget.vue` — die Kennzahlreihe
    `workflows_queue_stats` (§ 9): vier `Card`s in einem festen `grid-cols-4`
    (eine Zeile auf jeder Breite), je Karte Punkt + `Description` als Label,
    `Heading size="xl"` als Wert und eine optionale `Description` als Notiz —
    bzw., wo die Karte ein `action` mitbringt, ein `Button` an deren Stelle, der
    `workflows.api.stats.retry` postet und die Karte aus der Antwort neu schreibt
    (§ 9). Jede Karte ist ein Link (`link`): zur Warteschlangen-Karte bzw. auf
    „Alle Beiträge", gefiltert per Preset (`support/dashboardLinks.js`, § 9).
    Rechnet sonst nichts — alles kommt
    fertig übersetzt aus dem Widget. Die Ton- und
    Punktfarben stehen als ausgeschriebene Klassennamen in zwei Lookup-Objekten,
    damit Tailwind sie beim Scannen der Datei findet; die Ton-Klassen tragen ein
    `!`, weil `Description` eine eigene Textfarbe mitbringt und die
    Addon-Utilities im niedrig priorisierten Layer `addon-utilities` liegen.
  - `workflows-status-widget` → `WorkflowStatusWidget.vue` — die Statusreihe
    `workflows_status` (§ 9): je aktivem Workflow eine `Card` in einem
    `grid-cols-3`, im Kopf 8-px-Workflow-Punkt + `Heading`/`Subheading`, im Body
    links ein 116-px-Ring (Chart.js-Doughnut im Canvas, Gesamtzahl absolut
    zentriert in seinem Loch) und rechts die Legende mit Farbquadrat, Label und
    absoluter Zahl. Rechnet nur die zwei Dinge, die der Server nicht wissen
    kann: den Hex hinter einem Farbnamen (`colorHex()`) und die Prozentzahl im
    Tooltip. Der Tooltip selbst ist ein geteiltes HTML-Element am `<body>`
    (Chart.js' `external`-Handler), weil der Canvas-Tooltip im 116-px-Quadrat
    abgeschnitten wurde (§ 9). Legendenzeile und Ring-Segment öffnen **einen**
    gemeinsamen `Stack` (`v-model:open`, kein `#trigger` — nur eine Auswahl kann
    offen sein), dessen Inhalt beim ersten Öffnen von `segment.links_url` geholt
    und danach pro URL **und Seite** gecacht wird; die Zeilen darin sind exakt
    das Markup, das früher die Stacks der Kennzahlreihe hatten, im Fuß der Kit-`Pagination`
    (`resourceMeta` aus der Antwort, `scroll-to-top` aus — der Default scrollt
    das FENSTER, und diese Liste sitzt in einem Slide-over, das selbst scrollt)
    und gegenüber der Link auf die Manager-Seite. Chart.js wird tree-shaken registriert, Instanzen
    und Tooltip-Element in `onUnmounted` abgeräumt; ein `MutationObserver` auf `<html class>` zeichnet
    Trennlinien- und Leerring-Farbe beim Theme-Wechsel nach (§ 9).
  - `workflows-user-header-widget` → `UserHeaderWidget.vue` — das
    Dashboard-Widget `workflows_user_header` (§ 9).
  - `workflows-my-queue-widget` → `MyQueueWidget.vue` — das Dashboard-Widget
    `workflows_my_queue` (§ 9): `Widget`-Shell, `Tabs`/`TabList`/`TabTrigger`/
    `TabContent` als Filter (Optik über `wf-widget-tabs` in `addon.css`),
    paginiert je Tab.
  - `workflows-created-by-me-widget` → `CreatedByMeWidget.vue` — das
    Dashboard-Widget `workflows_created_by_me` (§ 9); dieselbe `Widget`-Shell,
    einziger Client-State ist die Seite.
  - `workflows-watched-widget` → `WatchedWidget.vue` — das Dashboard-Widget
    `workflows_watched` (§ 9); wie CreatedByMe plus Nicht-mehr-Beobachten je
    Zeile.
    Alle drei verwenden:
    - `PersonalWidgetRow.vue` — die gemeinsame Zeile der zwei Karten: Rail,
      Site-Chip, Dot + Label und Meta-Text im Spec-Markup, der State als
      Kit-`Badge` wie in den Manager-Listen (§ 9); `with-rail` / `with-meta` sind
      der einzige Unterschied zwischen den Karten.
    - `WidgetPagination.vue` — der Pager im `footer`-Slot des `Widget`: die
      Kit-`Pagination` wie in `ListingPagination` (Summen + Seitenzahlen, ohne
      Per-Page-Selector und `scroll-to-top`), unsichtbar bei nur einer Seite;
      gefüttert von `useClientPagination()` (§ 9).
- Support
  - `support/states.js` — zentrale `stateColor()` und `humanize()`.
  - `support/pagination.js` — `useClientPagination(items, perPage, { reserve })`:
    clientseitiges Blättern über eine vollständig geladene Liste (`page`,
    `pageItems`, `meta` als Laravel-Resource-`meta` für die Kit-`Pagination`,
    `setPage()`); klemmt die Seite, wenn die Liste schrumpft. `fillers` sind die
    Platzhalter-Zeilen bis zur vollen Seite, solange `reserved`. Für die drei
    persönlichen Karten (§ 9).
  - `support/dashboardLinks.js` — die Verbindung zwischen den Dashboard-Karten:
    `useLinkTarget()` / `hasLinkTarget()` (welche Zielkarte liegt auf der
    Seite), `jumpTo()` (scrollen + fokussieren, `prefers-reduced-motion`
    beachtet) und das Preset der Kennzahlreihe für „Alle Beiträge"
    (`applyPreset()`, `clearPreset()`, `activePreset()`) (§ 9).
  - `support/widgetColors.js` — Farbname → `.wf-rail--` / `.wf-dot--`-Klasse für
    die zwei Dashboard-Karten, mit Whitelist und `default`-Fallback. Der State
    steht NICHT darin, der ist ein Kit-`Badge` (§ 9). Dazu `colorHex()`: Farbname
    → Hex für die Ringe der Statusreihe — die einzige Stelle im Addon, an der JS
    einen Hex kennen darf, weil ein Canvas Werte statt Klassen entgegennimmt
    (§ 9).

#### Modal-Konventionen

Statamics `Modal` rendert den Default-Slot in einem Container mit
`overflow-auto max-h-[60vh] p-4`, den `#footer`-Slot dagegen **außerhalb**. Daraus
folgen zwei Regeln, die jedes Modal im Addon einhält:

- **Buttons gehören in `<template #footer>`**, nicht ans Ende des Bodys. Sonst
  scrollen sie mit dem Inhalt weg. Look: `flex justify-end gap-2 px-2 pt-3`,
  Abbrechen als `variant="ghost"`.
- **Überschriften bekommen `<ModalTitle class="wf-modal-head">`.** Die Klasse in
  `resources/css/addon.css` macht den Header `sticky`, gibt ihm per
  `background-color: inherit` den Body-Hintergrund und blutet mit negativen
  Margins in dessen 1rem-Padding, damit gescrollter Inhalt *unter* dem Header
  verschwindet statt daneben. Eine Klasse statt eines wiederholten
  Utility-Strings, weil dieselbe Überschrift in neun Modals über fünf
  Komponenten steht.

Ebenfalls projektweit: Multiselects (`Select`/`Combobox` mit `multiple`) setzen
`close-on-select`. Der `Combobox` defaultet `closeOnSelect` auf `!multiple`, würde
also nach jeder Auswahl offen bleiben. Beide Attribute reisen bei `Select` über
`useAttrs()` zum darunterliegenden `Combobox` — `Select` deklariert weder
`multiple` noch `closeOnSelect` selbst.

### Kein Inline-Editor im Stack

Der „Bearbeiten"-Button im Summary-Stack **navigiert** zum Beitrags-Editor
(`instance.subject.cp_url`). Er hat früher Statamics `InlineEditForm` in einem
verschachtelten Stack geöffnet — das ist bewusst entfernt:

Diese Komponente verlangt, dass der Aufrufer Statamics **private** Prop-Map für
`entry-publish-form` von Hand mitbringt (19 Keys, in Statamic aus dem Fieldtype-
`meta` gefüttert). Das Addon musste sie kopieren und bei jedem Statamic-Release
nachziehen. In der Praxis rannte das nested Publish-Form in einen Zustand ohne
aktive Localization und warf bei jedem Öffnen
`Cannot read properties of undefined (reading 'status')` bzw. `'livePreviewUrl'` —
Statamic liest `localizations.find(l => l.active).livePreviewUrl` ohne Null-Guard,
und `livePreviewUrl` wird im Render unbedingt ausgewertet, nicht erst hinter dem
Live-Preview-Button.

Ein Link kann nicht aus dem Tritt geraten. Mit dem Ausbau fielen auch
`ENTRY_FORM_PROPS`, der `updated`-Emit und `WorkflowQueue`s `refreshSummary()`
weg — Transitionen laden die Seite ohnehin neu.

**Wenn das je zurückkommen soll:** `component`, `componentProps` und `stackSize`
gehören dann aus dem Server-Payload (wie Statamics Fieldtype-`meta` sie liefert),
nicht als Konstante im Client.

---

## 12. Berechtigungs-Cheatsheet

| Aktion | Erlaubt für |
| --- | --- |
| **Alles Schreibende an einem Entry** | Setzt **immer** `access <site> site` für die Site des Entrys voraus (`canAccessEntrySite()`). Wird von `privileged_groups` **nicht** umgangen. |
| Direkt aus dem Editor veröffentlichen | Super-Admin, Gruppe `admins`. |
| Transition anwenden | Site-Zugriff **und** (Privileged **oder** (Assignee dieser Localization **und** ggf. Mitglied in `required_group`)). |
| Assignees ändern | Site-Zugriff. Darüber hinaus kein Gate. |
| Neuen Beitrag anlegen | Super-Admins, plus die Gruppen aus `metadata.post_creator_groups` des Workflows (CP: Settings > Workflows > „Darf Beiträge anlegen"), sonst `config → post_creator_groups`. Beide Defaults leer. Kein Lokalisierungs-Workflow (§ 2). |
| State manuell setzen | Super, `admins` — plus Site-Zugriff. |
| In andere Site überführen | Site-Zugriff **und** (Super, `admins`, oder Mitglied in der `default_assignee_group` des Ziel-Workflows). Ohne diesen Key nur Privileged. |
| Kommentar schreiben | Site-Zugriff. |
| Workflow-übergreifende Liste sehen (Widget `workflows_overview`, `listing?all_workflows=1`) | Jeder CP-User, wie die Listen der Manager-Seiten. „Neuer Beitrag"-Buttons darin nur nach der Zeile „Neuen Beitrag anlegen". |
| Settings › Workflows (Nav-Eintrag + Seiten) | Nur Super-Admins. |
| Kommentar löschen | Nur der Autor. |
| „Bearbeiten"-Button im Summary sehen | Wer den Entry bearbeiten darf **und** `access <site> site` für dessen Site hat (`canOpenEntryEditor()`). |

### Site-Zugriff ist eine harte Vorbedingung

`canAccessEntrySite($entry)` prüft `access <handle> site` über Statamics
`SitePolicy` und steht **vor** dem `isPrivilegedUser()`-Bypass in
`canApplyTransition()`, plus als `abort_unless(…, 403)` in `setState()`,
`setAssignees()`, `transfer()` und `storeComment()`.

Die Regel dahinter: `config/workflows.php → privileged_groups` darf die **eigenen**
Regeln des Addons lockern — Assignee-Pflicht und `required_group` — aber **nie**
mehr gewähren als Statamic selbst. Wer eine Site nicht einmal ansehen darf, hat
dort nichts zu transitionieren, zuzuweisen oder zu kommentieren. Super-Admins
passieren ohnehin, `isSuper()` erfüllt jede Berechtigung.

`transitionReason()` nennt den Site-Grund **zuerst** und mit dem Site-**Handle**
(nicht dem Anzeigenamen), weil genau das in der Berechtigung steht — sonst sucht
ein Admin nach `access MCD site` statt `access mcd site`.

Bewusst **nicht** gegated: `markCommentsRead()`. Das schreibt nur den eigenen
„gesehen"-Marker und hat keine Wirkung auf andere.

### Warum der Editor-Link an der Site-Berechtigung hängt

`EntriesController::edit` baut die `localizations` des Publish-Formulars aus den
Sites, die der Nutzer betreten darf (`SitePolicy::view` →
`hasPermission("access <handle> site")`). Fehlt die Berechtigung für die **eigene**
Site des Eintrags, ist kein Eintrag `active` — und Statamic liest
`localizations.find(l => l.active).status` bzw. `.livePreviewUrl` **ohne
Null-Guard**. Ergebnis: der Render bricht ab, das Formular bleibt leer, und in der
Konsole stehen zwei `TypeError`.

Deshalb liefert `buildInstancePayload()` `subject.cp_url` nur, wenn
`canOpenEntryEditor()` zustimmt; sonst `null`, und beide Detailansichten blenden
den Button per `v-if` aus. Dieselbe Prüfung hängt an `open_entry` in `storePost()`
— sonst landet man nach dem Anlegen in einer Site, die man nicht betreten darf,
direkt im leeren Formular.

Symptom in einem Projekt mit unvollständigen Rollen: eine Rolle mit nur
`access main site` sieht Beiträge aller Portale in der Warteschlange, kann aber
nur die aus `main` öffnen. Der Button verschwindet dann für die anderen — das ist
die gewollte Anzeige, nicht der Fehler. Der Fehler wäre der Link.

---

## 13. Lebenszyklus in einem Bild

```
[storePost]  → Entry angelegt (Platzhalter-Titel, kein Slug, eine Site),
                State=initial_place,
                Assignees=default_assignee_group (+ Ersteller bei assign_creator),
                EventLog: created.

[localizeInto] → Localization angelegt (published=false),
                 State=initial_place des Lokalisierungs-Workflows,
                 Assignees=dieselbe Regel wie oben,
                 EventLog: localized.
                 Aufrufer: transfer (Bulk) und applyTransition (site_modal).

Nutzer klickt Transition-Button (Editor-Header oder Detailseite)
    → POST /workflows/api/instances/{id}/transitions/{name}
    → Guards (from-state, canApply, ggf. user_assignment-Pick)
    → ab hier alles in EventLogSuppressor::while() — keine draft_save-Zeilen
    → State-Store: set()
    → syncPublishedFlag: Entry->published(true|false)->save()
    → syncPlaceAssignees oder expliziter Pick
    → WorkflowEventStore::log('transition', {snapshot: entry.data})
    → createStatamicRevision (best-effort)

Entwurf im Editor gespeichert (unveröffentlicht)
    → EntrySaved
    → LogDraftSave → DraftSaveLogger: nur bei echter Änderung
      WorkflowEventStore::log('draft_save', {snapshot: entry.data})

Manuelle Änderung der Publish-Flag im Editor
    → EntrySaved
    → SyncWorkflowOnUnpublish setzt State evtl. auf initial_place.
    → LogDraftSave protokolliert die Änderung (published ist im Snapshot).

Revisions-Collection: Working-Copy angelegt oder erneut gespeichert
    → RevisionSaved (action: working)
    → SyncWorkflowOnRevision::syncState() setzt beim ERSTEN Mal
      published → pending_revision (spätere Saves flippen nichts).
    → logWorkingCopySave() → DraftSaveLogger mit dem Snapshot der
      Arbeitskopie (fromWorkingCopy), nur bei echter Änderung.

Working-Copy publiziert (working-copy weg)
    → RevisionSaved/Deleted
    → SyncWorkflowOnRevision setzt State pending_revision → published.
```

---

## 14. Wo drehe ich woran?

- **Neuen Workflow hinzufügen** → YAML in `resources/workflows/<name>.yaml`.
  Er erscheint automatisch im Dashboard-Widget `workflows_overview` (sofern
  dessen `workflows`-Config ihn nicht ausschließt); für eine eigene Manager-Seite
  und den Unterpunkt unter Settings › Workflows `metadata.nav` setzen.
- **Workflow-Übersicht aufs Dashboard** → `['type' => 'workflows_overview',
  'width' => 100]` in `config/statamic/cp.php → widgets` (§ 9).
- **Collections eines Workflows ändern** → Settings → Workflows → `<Workflow>` →
  „Collections". Achtung beim Entfernen: die Workflow-Daten der betroffenen
  Einträge werden gelöscht (§ 2 → Governed Collections).
- **Zusatz-Feld im Diff-View** → `metadata.history_fields` in der Workflow-YAML.
  Nur für Inhaltsfelder nötig; Sidebar und Meta sind ohnehin drin (§ 5.10).
- **Feld-Label im Diff-View anpassen** → Blueprint-`display`, oder der Lang-Key
  `field_<handle>` in `lang/{de,en}/messages.php`.
- **„Sites"-Karte im Editor ausblenden** → `metadata.detail: translation`
  (§ 2 → Lokalisierungs-Workflows).
- **Wer den Beitrag als Nächstes übernimmt** → `user_assignment`-Action an der
  Transition, die die Übergabe vollzieht. Places weisen nicht mehr zu.
- **Zugewiesene sollen eine Mail bekommen** → `mail`-Action an dieselbe Transition
  wie die `user_assignment`, Mail-Typ „Beitrag zugewiesen". Der Editor sagt an der
  Action, ob der eingerichtete Mailer überhaupt zustellt. Texte in
  `lang/{de,en}/messages.php` unter `mail_assigned_*`, Layout in
  `resources/views/mail/notification.blade.php`.
- **Eine Mail beim ERREICHEN eines Status, ohne Klick** → **dieselbe** `mail`-Action
  an den **Place** hängen (Place-Karte → Actions), mit dem passenden Mail-Typ. Der
  einzige Weg für „die Einfache Sprache ist fertig": diesen Übergang macht der
  Poll-Befehl ohne Transition. Voraussetzung ist, dass der Workflow
  `metadata.plain_language_completed_place` setzt — ohne den bleibt die Fassung
  liegen, es kommt nirgends etwas an, und nichts kann melden.
- **Eine weitere Mail-Sorte, nur andere Wörter** → Handle in
  `config/workflows.php → mail_types`, dann `mail_<handle>_subject` / `_intro`
  (Rest erbt von `assigned`) und `mail_type_<handle>` in
  `lang/vendor/workflows/{de,en}/messages.php`. Kein PHP.
- **Eine Mail-Sorte mit eigenen Daten oder eigenem Template** → dazu eine Klasse mit
  `Contracts\WorkflowMail::compose()`, in der Deklaration als `mail` (und optional
  `view`) genannt. Siehe § 2 → Mail-Typen. Einen Typ, der ins Addon selbst gehört,
  trägt man stattdessen in `Mail\MailTypes::BUILT_IN` ein — gleiches Interface.
- **Erstzuweisung beim Anlegen ändern** → `metadata.default_assignee_group`.
- **Ersteller soll sein neues Subjekt mit-zugewiesen bekommen** →
  `metadata.assign_creator: true`, im Settings-Editor als Checkbox. Gilt für den
  Beitragsmanager (wer anlegt) und den Übersetzungsmanager (wer die Localization
  auslöst).
- **Status für Alt-Einträge nachtragen** → in den Settings „Status nachtragen" an
  der Zeile (oder `php artisan workflows:seed-states`, dort zusätzlich mit
  `--redo`). Idempotent, beliebig oft wiederholbar.
- **Workflow vorübergehend stilllegen** → in den Settings die Zeile auf
  „Deaktivieren" (schreibt `active: false`). Nimmt ihn aus Dashboard-Widgets, Settings-Nav, Manager-Seiten,
  Beitrags-Editor und Commands; Definition und Verlauf bleiben.
- **Workflow ganz entfernen** → in den Settings „Löschen". Entfernt nur die YAML;
  Status, Zuweisungen, History und Kommentare bleiben in der DB und sind nach
  einer Neuinstallation wieder da.
- **Fortschrittsleiste waagerecht oder senkrecht** → im Settings-Editor bei den
  Workflow-Metadaten das Feld „Fortschrittsleiste"
  (`metadata.progress_orientation`). Entscheidet auch die Position: waagerecht über
  dem Inhalt, senkrecht links daneben.
- **Reihenfolge der Stufen in der Fortschrittsleiste ändern** → Pfeile an der
  Place-Karte im Settings-Editor. Die Leiste folgt der YAML-Reihenfolge.
- **Zustand aus der Fortschrittsleiste heraushalten** → Checkbox „Abzweigung" an
  der Place-Karte (`metadata.branch`). Der Place erscheint dann nur noch, wenn eine
  Fassung darin steht, und hängt versetzt an seinem Vorgänger.
- **Neue Rolle im Workflow-Gate** → `required_group` an Place/Transition.
- **Zwingende Personenauswahl beim Übergeben** → im Settings-Editor eine
  `user_assignment`-Action an der Transition, Modus „Manuell auswählen", mit den
  Bedingungen, die die Auswahl eingrenzen.
- **Übergabe ohne Rückfrage** → dieselbe Action, Modus „Automatisch zuweisen".
  Mindestens eine Bedingung ist Pflicht, und passt niemand, sperrt die Transition.
- **Eigenes Zuweisungs-Kriterium** (z. B. Fachgebiet des Nutzers gegen die
  Kategorie des Beitrags) → `config/workflows.php → assignment_criteria`
  ergänzen; es erscheint danach im Kriterien-Dropdown des Settings-Editors. Siehe
  „Zuweisungs-Kriterien" in § 2.
- **Weitere Sprache/Site** → Statamic-Seite anlegen + Collection dort
  aktivieren. Die Site erscheint im Pivot automatisch.
- **State-Farbe/Label anpassen** → `resources/js/support/states.js` (Farbe) +
  `lang/{de,en}/messages.php` (Label `state_<X>`) und ggf.
  `action_<X>` für Buttons.
- **Fehlermeldung des Definitions-Editors anpassen** → `writer_<X>` in
  `lang/{de,en}/messages.php`. `WorkflowDefinitionWriter::fail()` löst den Key auf,
  der Controller reicht die Meldung als 422-Body durch, und der Editor zeigt sie als
  Toast — die Meldungen folgen also der CP-Sprache. Die darin genannten
  YAML-Schlüssel, Handles und Action-Typen bleiben in jeder Sprache wortgleich: das
  sind die Bezeichner, die der Autor im Editor korrigieren muss.
- **Neue automatische Sync-Regel** → weiteren Listener in `src/Listeners/`
  ergänzen und im ServiceProvider `$listen` eintragen.
- **Diagramme regenerieren** →
  ```bash
  cd addons/takepart-media/workflows
  npm run workflows:build
  ```

---

## 15. SLA — Alterung veröffentlichter Beiträge

**Ziel.** Ein Beitrag, der zu lange unverändert online steht, wandert automatisch
weiter — in Schritte, die der Autor selbst gebaut hat. Nimmt einer dieser Schritte
den Beitrag offline, folgen seine Übersetzungen.

Drei Bauteile, jedes einzeln nutzbar:

| | |
| --- | --- |
| **Die Uhr** | `content_updated_at` am Eintrag. Wird gestellt, wenn eine Fassung einen Publish-Place betritt. |
| **Die Alterung** | `metadata.sla` im Beitragsmanager: nach X Tagen in Schritt Y, über die Transition, die dorthin führt. |
| **Die Kaskade** | `metadata.source_draft_place` im Lokalisierungs-Workflow: liegt der Beitrag im Entwurf, fällt die Fassung dorthin zurück. |

### Die Uhr sitzt am Eintrag

`ContentTimestamp` besitzt das Feld `content_updated_at`, das
`InjectWorkflowFields` in jede Collection eines **Autoren**-Workflows hängt:
`type: date`, `time_enabled`, `localizable: true`, `visibility: hidden`. Die
Lokalisierungen sind Einträge derselben Collection und tragen das Feld damit
ebenfalls — jede mit ihrem **eigenen** Wert, was der ganze Zweck von
`localizable` hier ist.

**Sichtbar, aber nur für Super-Admins änderbar.** Das Feld steht im
Publish-Formular; `visibility` ist `visible` für einen Super-Admin und `read_only`
für alle anderen. `computed` scheidet aus — solche Werte verwirft
`Fields::values()` beim Speichern, was für das Actions-Panel richtig ist (es
speichert nichts) und hier fatal wäre.

Durchgesetzt wird die Regel aber **nicht** vom Blueprint, sondern von
`ProtectContentTimestamp` auf `EntrySaving`: ein gerendertes `read_only` ist eine
Höflichkeit des Clients, Statamics Entry-Save lehnt read-only-Werte nicht ab, und
die Feldkonfiguration entsteht pro Request. Wer nicht Super-Admin ist, speichert
also mit — nur behält der Eintrag den bisherigen Wert. Die eigenen Schreibvorgänge
des Addons erkennt der Listener am aktiven `EventLogSuppressor` und lässt sie
durch; ohne diese Prüfung würde er genau die Stempel zurücknehmen, von denen die
SLA lebt.

Damit ist auch die Warnung im Kopf von `InjectWorkflowFields` gewahrt, obwohl das
Blueprint hier vom Nutzer abhängt: sie schützt davor, dass eine gecachte Form zur
einzigen Verteidigung wird. Das ist sie nicht mehr — im schlimmsten Fall sieht ein
Super-Admin ein Feld, in das er nicht tippen kann, oder ein Redakteur tippt in
eines, dessen Wert danach unverändert bleibt.

**Die Position selbst bestimmen.** Das injizierte Feld landet im ersten Tab, erste
Sektion. Wer es woanders haben will, deklariert den Handle im Blueprint — mit dem
Typ und **sonst nichts**:

```yaml
-
  handle: content_updated_at
  field:
    type: date
```

`ensureField()` dupliziert dann nicht, sondern merged, und zwar per
`array_merge($config, $existingField['field'])` — **die YAML gewinnt** für jeden
Schlüssel, den sie nennt, und die Position kommt aus ihr. Deshalb steht dort nur
`type`: alles andere (Beschriftung, Hinweis, `localizable`, `listable`) bleibt beim
Addon und damit übersetzbar.

Und deshalb gehört **`visibility` nicht in die YAML**: der Schlüssel ist die
Rechteprüfung. Steht er dort, gilt er für alle — ein `read_only` sperrt dann auch
den Super-Admin aus, ein `visible` macht das Feld für jeden anklickbar (schreiben
kann trotzdem nur ein Super-Admin, dafür sorgt der Listener). In einem Beispielprojekt steht
der Handle in `resources/blueprints/collections/posts/post.yaml` in der Sidebar
hinter `date`.

**Gestellt wird sie an genau zwei Stellen**, beide mit derselben Regel — wer einen
Publish-Place betritt, ist jetzt aktuell:

- `WorkflowTransitioner::stampContentTimestamp()` — deckt das gewöhnliche
  „Veröffentlichen" und jede selbst gepflegte „wieder aktuell"-Transition ab;
- `SyncWorkflowOnRevision` — eine veröffentlichte Arbeitskopie ist eine
  Überarbeitung und setzt die Frist zurück.

`revision_pending` ist ausgenommen: „veröffentlicht mit offener Arbeitskopie" ist
keine Freigabe, und Stempeln dort würde die Frist bei jedem Entwurf neu starten.

**Nicht** beim gewöhnlichen CP-Speichern. Statamics eigenes `updated_at` bewegt
sich dort (`EntriesController::update()` ruft `updateLastModified()`), diese Uhr
nicht — ein Tippfehler-Fix verlängert also keine Frist. Genau deshalb ist es ein
eigener Handle: `updated_at` wäre von jedem Speichern überschrieben und in der
Augmentation von `AugmentedEntry::updatedAt()` verdeckt.

**Zwei Leser, ein Unterschied, der alles trägt.** `own()` liest nur den eigenen
Wert (`ContainsData::get()`), `for()` fällt auf Statamics `lastModified()` zurück.
Eine Lokalisierung ohne eigenen Wert gilt als „noch nie aktualisiert" — hätte man
`value()` genommen, würde sie den Wert des Originals erben und sich damit als so
aktuell ausgeben wie die Quelle, die sie gerade überholt hat.

**Bestandsdaten**: `php artisan workflows:backfill-content-timestamp` schreibt
einmalig `lastModified()` in das Feld, wo noch kein eigener Wert steht
(`--dry-run`, `--workflow=`, idempotent). Nötig ist das nicht — `for()` liefert
denselben Wert auch ohne —, aber erst mit eigenen Werten je Fassung funktioniert
der Vergleich unten.

### Die Alterungsschritte gehören dem Autor

Früher hängte `DefinitionLoader::withSlaSteps()` zwei feste Places
(`needs_update`, `outdated`) und drei Transitions zur Laufzeit an, mit
reservierten Handles, Labels aus den Sprachdateien und einer eigenen
Zuweisungs-Sprache (`sla.by` / `assign_to` / `dismiss_by`) parallel zum
Aktions-System. Das ist ersatzlos weg.

Jetzt legt der Autor die Stufen als **normale Places** an — eigenes Handle, Label,
`color` (auch `amber`, `yellow`, `red`, die dafür wieder freigegeben sind),
`branch: true`, `allow_publish` — und die Transitions dorthin und zurück wie jede
andere. Damit tragen sie automatisch `user_assignment`, `mail`,
`required_group`/`required_role` und `variant: danger`.

`metadata.sla` sagt nur noch, **wann welcher** dieser Schritte angesteuert wird:

```yaml
metadata:
  sla:
    needs_update_days: 365
    needs_update_place: needs_update
    outdated_days: 540
    outdated_place: outdated
```

Beide Stufen sind optional, **Tage und Schritt aber nur gemeinsam gültig**: eine
Frist ohne Ziel könnte nie feuern, ein Ziel ohne Frist nie erreicht werden. Der
Writer lehnt beides ab, und der Settings-Editor schickt eine halb gefüllte Stufe
gar nicht mit.

**Der Writer prüft** (nur im Autoren-Workflow, `sla` in einem
Lokalisierungs-Workflow wird abgelehnt): Schritt existiert in **diesem** Workflow,
Tage sind leer oder eine Ganzzahl ab 0, und `outdated_days >= needs_update_days`.
Eine hinführende Transition wird **nicht** verlangt — siehe oben.

**Achtung, `allow_publish` an der ersten Stufe.** Ein Alterungs-Place ohne
`allow_publish` nimmt den Beitrag beim Erreichen offline — und zieht über
`source_draft_place` auch die Übersetzungen mit. Wer die erste Stufe nur als
Hinweis meint („zu aktualisieren, aber weiter online"), muss ihr
`allow_publish: true` geben; das injizierte `needs_update` von früher hatte es.

**Warum die zwei Schritte aus dem Diagramm fallen.** `SlaSettings::places()`
liefert die konfigurierten Handles, und sowohl `WorkflowResolver::finalPlace()` als
auch `ProgressSteps::offLine()` ziehen sie ab. Das ist Pflicht: `finalPlace()` ist
der **letzte deklarierte** Place, und eine nach `published` angelegte Alterungsstufe
würde sonst zum „Fertig" des Workflows — mit ihr wandert `sourceIsReady()` und
damit das Startrecht jedes Lokalisierungs-Managers.

### Ausführung: die Transition des Autors, nicht eine eigene

`php artisan workflows:transition-aged`, stündlich via `Schedule::command(...)` im
ServiceProvider (abschaltbar über `config('workflows.sla_scheduler')`).

Der Lauf je Workflow mit `metadata.sla` (Lokalisierungs-Workflows werden mit einer
Meldung übersprungen):

1. Beiträge im Publish-Place und in der ersten Stufe einsammeln.
2. Alter = `now - ContentTimestamp::for($entry)`.
3. Ziel bestimmen — **die spätere Stufe zuerst**, weil beide Fristen ab derselben
   Uhr zählen: was für beide alt genug ist, gehört direkt in die zweite.
4. Bewegen — auf einem von zwei Wegen:
   - **gibt es eine Transition**, deren `from` den aktuellen Place enthält und
     deren `to` das Ziel ist, wird sie ganz normal angewendet (`actorId: null`).
     Zuweisung, Mail, Published-Flag und History sind dann das, was in der
     Definition steht;
   - **gibt es keine**, zieht `PlaceMover` den Beitrag direkt hinüber: State,
     Published-Flag nach `allow_publish` des Ziels, History-Eintrag `sla_aged` und
     die Ankunfts-Actions des Places. Was dabei fehlt, ist die Zuweisung — ohne
     Transition sagt nichts, wer übernehmen soll, also bleibt die Zuständigkeit
     stehen.

**Der zweite Weg ist der Normalfall**, und ihn zu verlangen war ein Fehler: den Weg
*in* eine Alterungsstufe klickt niemand — dafür gibt es den Scheduler. Die
Transitions, die ein Autor wirklich schreibt, führen wieder **heraus** („wieder
aktuell", „an die Autorin geben"). Eine hinführende Transition ist trotzdem
sinnvoll, wenn beim Altern jemand zugewiesen oder benachrichtigt werden soll —
dann steht das an ihr statt am Place.

`--dry-run` und `--workflow=` wie gehabt. **Voraussetzung** bleibt der Cron:
```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

### Der Entwurf des Beitrags zieht die Fassungen mit

Auslöser ist der **Statamic-Published-Flag des Beitrags**, nicht ein bestimmter
Place. Wer seine zweite Stufe ohne `allow_publish` baut, bekommt die Kaskade; wer
sie online lässt, nicht. Dieselbe Kaskade greift, wenn jemand den Beitrag von Hand
zurückzieht — was richtig ist: eine Übersetzung eines zurückgezogenen Beitrags soll
nicht online bleiben.

Wohin, entscheidet die Fassung selbst:

```yaml
# plainlanguage.yaml
metadata:
  source_draft_place: plain_ready
```

Der Beitragsmanager sagt **wann**, jeder Lokalisierungs-Manager sagt **wo**. Die
Places gehören ihm, also kann der Writer sie auch prüfen: nur in einem
Lokalisierungs-Workflow, muss dort existieren, und darf **kein** `allow_publish`
tragen — sonst widersprächen sich Place und Kaskade.

`StaleLocalizations::cascade()`, ausgelöst vom Listener `CascadeSourceDraft` auf
`EntrySaved` (nur `isRoot()` und unveröffentlicht — daher auch keine Rekursion),
geht die `descendants()` durch und je Fassung:

1. zuständigen Workflow über `forEntry()`, dessen `source_draft_place` lesen; leer
   → nichts tun;
2. schon dort und schon offline → überspringen (der Vorgang hängt an `EntrySaved`
   und muss idempotent sein);
3. **läuft eine Umschreibung, überspringen** — ein Place mit der
   `pending_rewrite_spinner`-Action bedeutet einen offenen Cortex-Auftrag, und die
   Fassung dort wegzuziehen ließe den Job verwaisen, während der Poller weiter auf
   ihn wartet;
4. **erst `WorkflowStateStore::set()`, dann der Published-Flag** — in dieser
   Reihenfolge, sonst sieht `SyncWorkflowOnUnpublish` eine unveröffentlichte
   Fassung, deren Store-State noch `published` ist, und wirft sie in ihren
   **Initial**-Place statt in den konfigurierten;
5. Event `source_draft` mit Notiz und Snapshot, dann `notifyPlaceArrival()` — so
   kann der Ziel-Place per Mail-Action benachrichtigen, ohne dass es dafür eine
   Transition braucht.

Ein direkter State-Set und keine Transition, aus den Gründen, die
`PollPlainLanguageCommand::moveToCompletedPlace()` nennt: das läuft unbeaufsichtigt,
es gibt keinen Klick, den ein `required_group` gaten könnte, und zu jedem denkbaren
Ziel-Place muss keine Transition existieren.

**Zurück kommt niemand automatisch.** Die Fassung steht wieder in der Queue ihres
Managers und geht ihren eigenen Weg nach vorn — bei Einfacher Sprache also neu
generieren. Genau das ist der Wert der Rückstufung: sie erzeugt einen Auftrag, ein
bloßes Kennzeichen tut das nicht.

### Ohne Kaskade: das abgeleitete „veraltet"

Für eine Stufe, die den Beitrag online lässt, bewegt sich keine Fassung — veraltet
sind sie trotzdem. `WorkflowResolver::contentStale()` vergleicht dafür **zwei
Zeitstempel** statt einen State zu spiegeln:

1. die Wurzel ist der Beitrag und nie „veraltet" — für sie gibt es echte Places;
2. nur eine Fassung, die in **ihrem eigenen** Publish- oder Final-Place steht. Eine
   in Arbeit ist in Arbeit, nicht veraltet; eine per Kaskade zurückgestufte zeigt
   ihren echten neuen State und nicht denselben Sachverhalt zweimal;
3. `ContentTimestamp::isStale()` — Uhr des Beitrags neuer als der eigene Wert der
   Fassung, oder die Fassung hat keinen.

Gelesen wird das in `entryRows()` (`stale` je Site-Feld), `entryVersions()` (an den
Versions-Pills), als Spalte „Inhaltlich aktualisiert" (im Beitragsmanager
standardmäßig an, in den Lokalisierungs-Managern aus, weil dort auf jeder Zeile
die Uhr der Quelle steht und die Frage nach dem Rückstand das „veraltet"-Zeichen
beantwortet; sortiert über den privaten Roh-Zeitstempel `_content_updated_at`) und als Filter „Nur mit
veralteter Fassung" — angeboten nur, wenn es überhaupt eine gibt.

Angezeigt als kleines Zeichen **neben** dem State-Badge, nicht als State: der
Schritt der Fassung stimmt, ihr Inhalt ist nur nicht mehr der aktuelle.

##### Woher ein Badge seinen Namen nimmt

Die Listen-Badges werden im Browser gerendert, `humanize()` in
`resources/js/support/states.js`. Die Kette, in dieser Reihenfolge:

1. `workflows::messages.state_<handle>` aus den Sprachdateien;
2. das `label` des Places aus der Definition — geliefert als Prop `stateLabels`
   aus `DefinitionLoader::placeLabels()`, registriert wie die Farben über
   `registerStateLabels()`;
3. der Handle, in Großbuchstaben.

**Sprachdatei vor Definition**, und zwar dieselbe Reihenfolge wie serverseitig in
`stateLabel()` — beide müssen übereinstimmen, weil der Filter-Chip vom Server kommt
und das Badge daneben aus dem Browser. Der Grund für den Vorrang: nur die
Sprachdatei kann zweisprachig sein. Ein YAML-`label` ist eine feste Zeichenkette
und zeigt einem Redakteur im englischen CP deutschen Text. Acht der fünfzehn
Live-Places deklarieren beides und unterscheiden sich (`draft` vs. „Draft",
`medical_review` vs. „Autorenfreigabe") — die Definition zu bevorzugen hätte sie
alle auf einmal umbenannt.

Stufe 2 ist der Teil, der bis zum Umbau fehlte: ein selbst angelegter Place hat
keinen Sprachkey, also fiel das Badge auf den Handle zurück — ein Place mit dem
Label „Zu aktualisieren" stand als „Needs Update" in der Liste, während der
Filter-Chip daneben das Richtige sagte. Folge, bewusst: ein selbst benannter Place
zeigt in jeder CP-Sprache sein YAML-Label. Wer ihn zweisprachig will, legt einen
`state_<handle>`-Key in `lang/de` **und** `lang/en` an — dann greift Stufe 1. Das
ist auch der Weg, einen Place umzubenennen, dessen Handle das Addon schon kennt:
den Key ändern, nicht das Label.

**Das Badge im Editor-Header** hängt nicht an den Inertia-Props, sondern am Payload
des `workflow_actions`-Fieldtypes — es braucht seine eigene Zuleitung. Das Label
kommt fertig aufgelöst vom Server (`current_state_label`, also dieselbe Kette), die
Farben als `state_colors` im Payload, registriert beim Mount über
`registerStateColors()`. Die Farbe war dort vorher ein fest verdrahtetes `switch`
über fünf Handle-Namen: ein Place, den der Autor gelb gestellt hatte, blieb grau,
und ein selbst angelegter Place war grau in jedem Fall. In den Live-Definitionen
betraf das sieben der acht gefärbten Places.

**Nicht geprüft wird die Kette.** Der Übersetzungsmanager hat
`translation_source_site: simple`, seine unmittelbare Quelle ist also die
Einfache-Sprache-Fassung. Gemessen wird trotzdem gegen die Uhr des **Beitrags**,
weil er die Grundlage für alles ist. Folge: wird nur die Einfache-Sprache-Fassung
überarbeitet, ohne den Beitrag neu freizugeben, gilt die englische Übersetzung
nicht als veraltet.

### Manuell testen

```bash
# Was würde passieren?
php artisan workflows:transition-aged --dry-run

# Nur ein Workflow, echt ausführen
php artisan workflows:transition-aged --workflow=postmanager

# Uhren für Bestandsinhalte nachtragen
php artisan workflows:backfill-content-timestamp --dry-run
```

Ein Referenzfall steht in `resources/workflows/examples/postmanager.yaml`: zwei
Alterungs-Places, drei Transitions, der `sla`-Block dazu.

## 16. Bekannte Sonderfälle & Fallstricke

- **State-Filter im Pivot arbeitet „irgendeine Site hat diesen State"**
  (`_states`) — nicht pro Spalte.
- Bei fehlender Statamic-Pro-Lizenz bleibt `revisionsEnabled` per
  Collection-Getter False; der `pending_revision`-Loop wird dann nie eingehängt.
- Ein Workflow ohne `collections` hat keine Subjekte: seine Manager-Seite ist
  leer. Früher sprang dort der Fixture-Pfad ein und zeigte erfundene Beiträge —
  das ist entfernt (§ 5.1).
- Der `WorkflowActions`-Fieldtype hängt an jedem Blueprint, dessen Collection im
  `collections:`-Array **irgendeines** Workflows steht — das entscheidet
  `InjectWorkflowFields` auf Collection-Ebene. *Welchen* Workflow das Panel
  dann zeigt, ist eine zweite, versionsabhängige Frage (§ 2 → Punkt 4). Neue
  Collections werden über Settings → Workflows → „Collections" ergänzt, nicht
  mehr per Hand in der YAML.
- **Eine Übersetzung veröffentlichen braucht einen `allow_publish`-Place im
  Übersetzungs-Workflow.** Seit das Panel pro Eintrags-Version auflöst, erreicht
  eine Localization die `publish`-Transition des Beitragsmanagers nicht mehr — und
  der mitgelieferte Übersetzungs-Workflow endet in `approved` **ohne**
  `allow_publish`. Für nicht-privilegierte Nutzer versteckt der Fieldtype damit
  auch Statamics eigenen Publish-Button (`allow_publish: false`). Entweder
  `allow_publish: true` an den Ziel-Place hängen (dann veröffentlicht die finale
  Freigabe die Übersetzung), oder das Veröffentlichen bleibt bewusst Super-Admins
  und `admins` vorbehalten.
- `data_snapshot` wurde nachträglich ergänzt (Migration `2026_07_20_000001`).
  Für Events aus der Zeit davor liefert der Diff-View `note: no_diff_data`.
- **Assignee ist an die Localization gebunden**, nicht an den logischen Post.
  Dieselbe Idee kann in DE andere Assignees haben als in EN.
