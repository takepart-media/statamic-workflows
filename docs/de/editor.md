# Workflow-Editor

🇬🇧 [English](../en/editor.md)

Mit dem Graph-Editor im Control Panel bauen und ändern Super-Admins Workflows visuell, ohne YAML anzufassen.

## Den Editor öffnen

Öffne **Einstellungen > Workflows**. Der Eintrag und alles darunter sind **nur für Super-Admins** sichtbar; `privileged_groups` und die Gruppe `admins` reichen nicht.

Die Übersicht listet jeden Workflow aus `resources/workflows/` mit Handle, Collections und Status (**Aktiv** / **Inaktiv**). Jede Zeile bietet:

| Button | Was er tut |
| --- | --- |
| **Einstellungen** | Öffnet den Graph-Editor für diesen Workflow. |
| **Warteschlange** | Öffnet die Manager-Seite des Workflows (nur aktive Workflows mit `nav.slug`). |
| **Status nachtragen** | Gibt jedem verwalteten Eintrag ohne gespeicherten Status jetzt einen. Entspricht `php artisan workflows:seed-states`. |
| **Aktivieren** / **Deaktivieren** | Schreibt `active: true` / `active: false`. Ein inaktiver Workflow verschwindet aus dem Menü, den Manager-Seiten und dem Beitrags-Editor. Definition und Verlauf bleiben erhalten. |
| **Löschen** | Entfernt die YAML aus `resources/workflows/`. Status, Zuweisungen, Verlauf und Kommentare bleiben in der Datenbank; legst du denselben Handle wieder an, sind sie wieder da. |

Workflows mit `metadata.nav` erscheinen außerdem als Unterpunkte von **Einstellungen > Workflows** und führen direkt in ihren Editor.

## Einen Workflow anlegen

1. Klicke auf **Workflow anlegen**.
2. Gib einen **Name** ein. Der **Handle** wird daraus abgeleitet, bis du ihn selbst änderst.
3. Wähle einen **Ausgangspunkt**:
   - **Leer**: zwei Places, „Entwurf“ und „Veröffentlicht“, zum Ausbauen.
   - **Vorlage**: eines der mitgelieferten Beispiele (`postmanager`, `translation`, `plainlanguage`) unter deinem neuen Handle.
   - **Kopie von**: ein Duplikat eines bestehenden Workflows aus diesem Projekt.
4. Wähle die **Collections**. Bei einer Vorlage oder Kopie kannst du die Collections der Quelle übernehmen.
5. Klicke auf **Anlegen**.

Der Handle ist der Dateiname und der Schlüssel jeder gespeicherten Status-Zeile und lässt sich später nicht ändern. Er muss mit einem Kleinbuchstaben beginnen, darf nur `a-z`, `0-9` und `_` enthalten und höchstens 48 Zeichen lang sein. `install`, `create`, `settings`, `api` und `instances` sind reserviert.

> [!NOTE]
> Neue Workflows starten **deaktiviert**. Prüfe sie, schalte sie ein und übernimm bestehende Einträge dann mit **Status nachtragen**.

Das Gegenstück auf der Kommandozeile ist `php artisan workflows:make`. Siehe [Befehle & Scheduler](commands.md).

## Der Editor im Überblick

Der Editor hat drei Bereiche:

- **Werkzeugleiste** oben.
- **Arbeitsfläche** in der Mitte: Places sind Karten, Transitions sind Knoten auf den Linien dazwischen.
- **Seitenleiste** rechts: die **Übersicht**, wenn nichts ausgewählt ist, sonst das Panel des gewählten Place oder der gewählten Transition.

### Werkzeugleiste

| Element | Was es tut |
| --- | --- |
| **Aktiv** | Schaltet den Workflow ein oder aus. Wirkt sofort, unabhängig vom Speichern. |
| **Place hinzufügen** / **Transition hinzufügen** | Legt einen neuen Knoten an. |
| Rückgängig / Wiederholen | Geht in deinen Änderungen zurück und vor. |
| Verkleinern / Vergrößern / **Alles anzeigen** | Zoom der Arbeitsfläche. |
| **Automatisch anordnen** | Ordnet alle Knoten automatisch an. |
| **Übersichtskarte** | Zeigt eine Übersichtskarte der Arbeitsfläche. |
| **Systemschritte anzeigen** | Zeigt die Schritte, die das Addon selbst ergänzt (etwa die Revisionsschleife). Sie sind nicht bearbeitbar. |
| Probleme | Anzahl der Fehler und Warnungen. Öffnet die Problemliste. |
| **Workflow-Einstellungen** | Öffnet die Einstellungsbereiche. Siehe [Workflow-Einstellungen](#workflow-einstellungen). |
| **Speichern** | Prüft und schreibt die YAML. |

### Auf der Arbeitsfläche arbeiten

- Den nächsten Schritt fügst du mit dem **+** neben einem Knoten hinzu, mit **Tab** oder indem du eine Linie aus dem Ausgang eines Knotens ziehst.
- Ein Doppelklick auf die freie Fläche legt einen neuen Place an.
- Rechtsklick auf einen Knoten bietet **Öffnen**, **Nächsten Schritt hinzufügen (Tab)**, **Als Start setzen** und **Löschen**. Rechtsklick auf eine Verbindung bietet **Verbindung trennen**. Rechtsklick auf die freie Fläche bietet **Neuer Status hier** und **Neuer Übergang hier**.
- Klicke einen Place oder eine Transition an, um ihn bzw. sie in der Seitenleiste zu bearbeiten.

Manche Verbindungen sind nicht möglich, und der Editor sagt, warum:

- Ein Place lässt sich nicht direkt mit sich selbst verbinden. Eine Schleife führt über eine Transition.
- Eine Transition führt immer in einen Place, nie in eine andere Transition.
- Systemschritte lassen sich nicht verbinden.
- Die erste Transition beginnt immer im Start-Place. Ändere stattdessen den Start-Place oder die Reihenfolge der Transitions.

### Tastenkürzel

| Tasten | Aktion |
| --- | --- |
| Strg/⌘ + S | Speichern (auch beim Tippen) |
| Strg/⌘ + Z | Rückgängig |
| Strg/⌘ + Umschalt + Z, Strg + Y | Wiederholen |
| Strg/⌘ + , | Workflow-Einstellungen öffnen oder schließen |
| Esc | Einstellungen schließen, Menü schließen, Auswahl aufheben, Seitenleiste einklappen (in dieser Reihenfolge) |
| Entf / Rücktaste | Auswahl löschen |
| Tab | Hinzufügen-Menü neben dem fokussierten Knoten öffnen |
| Enter | Panel des fokussierten Knotens öffnen |
| Pfeiltasten | Fokussierten Knoten um 10 px verschieben (mit Umschalt: 50 px) |
| 1 / 0 / + / - | Alles anzeigen / 100 % / vergrößern / verkleinern |
| Umschalt + L | Automatisch anordnen |

Einzelne Tasten wirken nie, während du in einem Feld, einem Menü oder einem Dialog tippst.

## Einen Place bearbeiten

Wähle einen Place, um sein Panel zu öffnen. Es hat drei Tabs.

### Allgemein

| Feld | YAML |
| --- | --- |
| **Handle** | Der Schlüssel unter `places`. Bei einem neuen Place aus dem Namen abgeleitet. Nach dem Speichern fest, weil Status, Verlauf und Übersetzungen ihn nutzen. |
| **Name** | `label` als Map je Sprache (`en`, `de`). Lässt du eine Sprache leer, gilt der Name aus der Sprachdatei (grau angezeigt). |
| **Farbe** | `metadata.color` |
| **Veröffentlichen erlaubt** | `metadata.allow_publish` |
| **Abzweigung** | `metadata.branch`. Wird für den Start-Place nicht angeboten. |
| **Reihenfolge** | Position des Place in `places`. Bestimmt Fortschrittsleiste, Fertig-Status und Filter, unabhängig von der Position im Graph. |
| **Startzustand** | **Als Start setzen** setzt `initial_place`. |

### Aktionen

Das Verhalten, solange ein Eintrag in diesem Place steht (`metadata.actions`). Klicke auf **Action hinzufügen** und wähle einen Typ:

| Typ im Editor | YAML-`type` |
| --- | --- |
| **Mail verschicken** | `mail` (mit **Mail-Typ**) |
| **Erinnerung alle X Tage (Mail)** | `reminder` |
| **Automatisch weiter nach X Tagen** | `auto_transition` |
| **Aktualisieren nach X Minuten (mit Modal)** | `refresh_interval` |
| **Ladeanzeige während Umschreibung** | `pending_rewrite_spinner` (nur im Einfache-Sprache-Manager) |

Jeder Typ lässt sich je Place einmal hinzufügen. Die Mail-Actions zeigen, welcher Mailer eingerichtet ist und ob er tatsächlich zustellt: Mit `MAIL_MAILER=log` gelingen Transitions, aber niemand bekommt eine Mail. Was jede Action tut, steht unter [Workflows definieren](workflows.md#place-actions).

### Verbindungen

Zeigt unter **Eingehende Übergänge** und **Ausgehende Übergänge** die Verbindungen, unter **Verwendet als**, wofür der Place sonst noch dient (etwa als SLA-Ziel oder Backfill-Place), und unter **Beiträge**, wie viele Einträge gerade darin stehen.

#### Einen Place löschen

Klicke im Tab „Verbindungen“ auf **Löschen**. Der Start-Place lässt sich nicht löschen; setze zuerst einen anderen Place als Start.

Stehen Einträge im Place, wähle ein Ziel unter **Verschieben nach**. Der Editor markiert den Place als „Beim Speichern gelöscht“ und löst seine Verbindungen sofort. Mit **Wiederherstellen** holst du ihn vor dem Speichern zurück. Beim Speichern wandern die Einträge in den Ziel-Place, und ihre SLA- und Auto-Übergangs-Uhren beginnen von vorn.

## Eine Transition bearbeiten

Wähle eine Transition, um ihr Panel zu öffnen. Es hat drei Tabs.

### Allgemein

| Feld | YAML |
| --- | --- |
| **Handle** | Der Schlüssel unter `transitions`. Nach dem Speichern fest. |
| **Anzeigename** | `metadata.label`. Leer nimmt den Text aus der Sprachdatei (`action_<handle>`), sonst den Handle. |
| **Button-Variante** | `metadata.variant`, mit Live-Vorschau des Buttons. |
| **Von** | `from`. Mehrere Ausgangs-Places sind möglich. Bei der ersten Transition folgt `from` dem Start-Place. |
| **Nach** | `to` |
| **Reihenfolge** | Position der Transition in `transitions`. Die erste Transition ist der Start des Workflows. |

Badges kennzeichnen besondere Transitions: **Start** (erster Schritt des Workflows bzw. die Transition mit `start_localization`), **CTA** (die `create_transition`, die „Neuer Beitrag“ ausführt) und **System** (läuft automatisch und kann nicht geklickt werden).

### Berechtigung

**Ausführen darf** legt fest, wer die Transition klicken darf, zusätzlich zur Zuständigkeit für den Eintrag:

| Option | YAML |
| --- | --- |
| **Alle Zuständigen** | Keiner der beiden Schlüssel. |
| **Gruppe** + Handles | `metadata.required_group` |
| **Rolle** + Handles | `metadata.required_role` |

Der Editor speichert genau einen der beiden Schlüssel. Ein Wechsel des Typs leert die Auswahl. Eine handgeschriebene YAML darf beide nennen (eine Vereinigung); der Editor zeigt dann die Rolle, und nur sie bleibt beim nächsten Speichern übrig. Brauchst du die Vereinigung, pflege diese Transition in der YAML.

### Aktionen

Die Schritte rund um die Transition (`metadata.actions`):

| Typ im Editor | YAML-`type` |
| --- | --- |
| **Benutzerzuweisung** | `user_assignment` |
| **Mail verschicken** | `mail` |
| **Sperre: Site muss noch fehlen** | `target_missing` |
| **Erzeugt die Ziel-Fassung (mit Sprachauswahl)** | `start_localization` (nur in Lokalisierungs-Workflows, einmal je Workflow) |
| **Einfache Sprache neu erzeugen** | `plain_language_resubmit` (nur im Einfache-Sprache-Manager, nicht an der Start-Transition) |

Bei der **Benutzerzuweisung** wählst du **Manuell auswählen …** oder **Automatisch zuweisen …** und dann **wenn alle folgenden Bedingungen erfüllt sind** oder **wenn eine der folgenden Bedingungen erfüllt ist**. **Bedingung hinzufügen** ergänzt ein Kriterium; **Gruppe hinzufügen** ergänzt eine verschachtelte Gruppe mit eigener Verknüpfung. Als Wert kannst du jeden Handle eingeben, nicht nur die Optionen im Dropdown.

Die Typ-Liste bietet nur an, was die Transition noch tragen kann: eine Benutzerzuweisung, eine Mail und `start_localization` nur, wo keine andere Transition sie schon hat. Projekteigene Actions aus `config/workflows.php` erscheinen in derselben Liste. Siehe [Actions](extending/actions.md).

## Workflow-Einstellungen

Klicke in der Werkzeugleiste auf **Workflow-Einstellungen** (oder drücke Strg/⌘ + ,). Die Einstellungen sind in Bereiche gegliedert; jeder zeigt, wie viele Probleme er enthält. Jede Änderung läuft über Rückgängig/Wiederholen und wird mit **Speichern** gesichert.

### Allgemein

| Label | YAML |
| --- | --- |
| **Nav-Titel** | `metadata.nav.label` |
| **Nav-Icon** | `metadata.nav.icon` |
| **Nav-Reihenfolge** | `metadata.nav.order` |
| **Nav-Slug** | `metadata.nav.slug` (nur lesbar) |
| **Startzustand** | `initial_place` |
| **Fortschrittsleiste** | `metadata.progress_orientation` |
| **Fertig-Place (für Backfill)** | `metadata.seed_published_place`. Nur sichtbar, wenn kein Place das Veröffentlichen erlaubt oder ein Wert gespeichert ist. |

### Berechtigungen

| Label | YAML |
| --- | --- |
| **Standard-Zuweisungsgruppe** | `metadata.default_assignee_group` |
| **Ersteller automatisch zuweisen** | `metadata.assign_creator` |
| **Selbstzuweisung verhindern** | `metadata.self_assignment` (Schalter, Zielgruppe und optionaler Hinweis) |
| **4-Augen-Prinzip** | `metadata.four_eyes` (Schalter, Zielgruppe und optionaler Hinweis) |
| **Nur Zuständige dürfen bearbeiten** | `metadata.assignees_only_edit` |
| **Ausgenommen** | `metadata.edit_exempt`. Sichtbar, solange „Nur Zuständige dürfen bearbeiten“ an ist. |
| **Kommentare als erledigt markieren dürfen** | `metadata.comment_resolvers` |
| **Kommentare löschen dürfen** | `metadata.comment_deleters` |
| **Darf Beiträge anlegen** | `metadata.post_creator_groups`. Nur Eintrags-Workflows. |
| **Workflow-Aktion beim Anlegen** | `metadata.create_transition`. Nur Eintrags-Workflows. |
| **Darf Verantwortliche zuweisen** | `metadata.assigner_groups` |

Die Zielgruppen-Felder arbeiten mit Zeilen: Je Zeile wählst du eine Art (**Gruppe**, **Rolle** oder **Benutzer**) und dazu die Einträge. Wer auf irgendeine Zeile passt, ist dabei. Wie diese Einstellungen zusammenspielen, steht unter [Berechtigungen](permissions.md).

### Lokalisierung

Nur für Lokalisierungs-Workflows (`detail: translation`). Der Bereich passt sich der Art des Managers an.

| Label | YAML | Sichtbar in |
| --- | --- | --- |
| **Quell-Site** | `metadata.translation_source_site` | beiden |
| **Ziel-Site** | `metadata.plain_language_site` | Einfache Sprache |
| **Ziel-Place nach Job-Abschluss** | `metadata.plain_language_completed_place` | Einfache Sprache |
| **Ziel-Place bei fehlgeschlagener Übersetzung** | `metadata.plain_language_failed_place` | Einfache Sprache |
| **Nicht umschreiben** | `metadata.plain_language_skip_fields` (kommagetrennt) | Einfache Sprache |
| **QS-Hinweise anzeigen** | `metadata.qs_notes_visible` | Einfache Sprache |
| **QS-Hinweise anzeigen für:** | `metadata.qs_notes_audience` | Einfache Sprache |
| **Quelle beim Übersetzen wählbar** | `metadata.translation_source_pick` | Übersetzung |
| **Quellansicht je Zielgruppe** | `metadata.translation_source_view` | beiden |
| **Zurück in Schritt** | `metadata.source_draft_place` | beiden |

Unter den Feldern zeigt **Übersetzungs-APIs**, ob der DeepL-Schlüssel bzw. URL und Token des Dienstes für Einfache Sprache eingerichtet sind. Siehe [Übersetzung & Einfache Sprache](localization.md).

### Collections

| Label | YAML |
| --- | --- |
| **Collections** | `collections` |
| **Revisionen** | `metadata.revisions`. Wird beim Speichern in die YAML jeder Collection geschrieben. Benötigt Statamic Pro. |
| **Revisionen veröffentlichen dürfen** | `metadata.revision_publishers`. Sichtbar, solange Revisionen an sind. |

Für jede Collection, die schon ein anderer Workflow besitzt, sagt der Bereich das. Neu hinzugefügte Collections werden beim Speichern mit Status befüllt.

> [!WARNING]
> Entfernst du eine Collection, musst du mit **Entfernen und Daten löschen** bestätigen: Status, Zuweisungen, Verlauf und Kommentare ihrer Einträge in diesem Workflow werden endgültig gelöscht.

### Inhalt

**Inhaltsfelder** (`metadata.content_fields`): welche Felder der Inhalt dieses Workflows sind, in Lesereihenfolge. Ziehe sie oder nutze die Pfeile, um umzusortieren. Bleibt die Auswahl leer, liest jede Collection ihr automatisch erkanntes Feld; der Bereich zeigt, welches das ist. Siehe [Inhaltsfelder](extending/content-fields.md).

### SLA

Nur für Eintrags-Workflows.

| Label | YAML |
| --- | --- |
| **Erste Stufe nach X Tagen** | `metadata.sla.needs_update_days` |
| **Erste Stufe: Schritt** | `metadata.sla.needs_update_place` |
| **Erste Stufe: zuständig** | `metadata.sla.needs_update_assign` |
| **Zweite Stufe nach X Tagen** | `metadata.sla.outdated_days` |
| **Zweite Stufe: Schritt** | `metadata.sla.outdated_place` |
| **Zweite Stufe: zuständig** | `metadata.sla.outdated_assign` |

Siehe [Inhaltsalterung (SLA)](sla.md).

### Reihenfolge

Sortiere **Places** und **Transitions** per Ziehen um. Die Reihenfolge der Places bestimmt Fortschrittsleiste, Fertig-Status und Filter. Die erste Transition ist der Start des Workflows.

### Erweitert

- **Positionen zurücksetzen** ordnet alle Knoten neu an; gespeichert wird beim nächsten Speichern.
- **Gespeicherte Daten (Vorschau)** zeigt nur lesbar, was beim Speichern gesendet würde.

### Einstellungen nur in der YAML

Der Editor behält jeden Schlüssel, den er nicht kennt; du kannst also beide Wege kombinieren. Diese Schlüssel haben kein Feld im Editor: `metadata.label`, `metadata.detail`, `metadata.history_fields`, `metadata.translation_exclude_fields`, `metadata.plain_language_clear_fields`, `metadata.site_fields` und `system` an Transitions. Siehe [Workflows definieren](workflows.md).

## Prüfung

Der Editor prüft deine Änderungen schon während der Arbeit gegen den Server.

- **Fehler** sind, was das Speichern ablehnen würde, etwa ein unbekannter Place, ein fehlendes `start_localization` in einem Lokalisierungs-Workflow oder eine automatische Zuweisung ohne Kriterien.
- **Warnungen** sind erlaubt, aber vermutlich nicht gewollt, etwa ein Place, den keine Transition erreicht, ein Place ohne Ausweg, zwei Transitions mit gleichem Von und Nach oder eine verwaltete Collection ohne Revisionen.

Probleme erscheinen im Zähler der Werkzeugleiste, am Knoten, am Feld und als Zahl an jedem Einstellungsbereich. Läuft gerade eine Prüfung, zeigt die Werkzeugleiste **Wird geprüft …**.

Speicherst du trotz Fehlern, fragt der Editor **Es gibt N Fehler – trotzdem speichern?**. Der Server prüft noch einmal und lehnt ab, was nicht geht; deine Änderungen bleiben dann im Editor, und die Meldung nennt das Problem.

## Speichern

Klicke auf **Speichern** oder drücke Strg/⌘ + S. Beim Speichern

1. prüft der Editor, dass niemand den Workflow inzwischen geändert hat,
2. prüft er die Definition,
3. sichert er die aktuelle YAML und schreibt die neue,
4. verschiebt er die Einträge gelöschter Places und bereinigt entfernte Collections,
5. schreibt er `metadata.revisions` in die Collections.

Schlägt ein Schritt nach dem Schreiben fehl, wird die vorherige YAML aus dem Backup wiederhergestellt.

### Konflikte

Hat jemand anderes den Workflow gespeichert oder sich die Datei geändert, nachdem du ihn geöffnet hast, erscheint **Workflow wurde geändert**. Wähle **Neu laden**, um deine Änderungen zu verwerfen und die aktuelle Fassung zu laden, oder **Trotzdem überschreiben**, um sie zu ersetzen.

### Backups

Bevor eine Workflow-YAML neu geschrieben wird (beim Speichern, beim Ein- oder Ausschalten, beim Löschen oder durch `workflows:install`), wird die aktuelle Datei kopiert nach:

```
storage/app/workflows/backups/<handle>/<timestamp>.yaml
```

Gleicht die Datei dem neuesten Backup, entsteht keine Kopie. Wie viele Kopien je Workflow erhalten bleiben, legt `editor_backups` in `config/workflows.php` fest (Standard `20`; `0` schaltet Backups ab). Einen Wiederherstellen-Button gibt es nicht: Kopiere ein Backup zurück nach `resources/workflows/<handle>.yaml`, um es wiederherzustellen.

> [!TIP]
> Die YAML-Ausgabe des Editors enthält keine Kommentare. Kommentierst du deine Workflow-Dateien von Hand, überleben diese Kommentare in den Backups.

## Nächste Schritte

- [Workflows definieren](workflows.md): die vollständige YAML-Referenz hinter jedem Feld.
- [Berechtigungen](permissions.md): was die Berechtigungs-Einstellungen bewirken.
- [Mit Workflows arbeiten](using.md): die Manager-Seiten und die Detailseite.

---

← [Workflows definieren](workflows.md) · [Doku-Start](README.md) · [Mit Workflows arbeiten](using.md) →
