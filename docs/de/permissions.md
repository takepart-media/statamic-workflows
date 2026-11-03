# Berechtigungen

🇬🇧 [English](../en/permissions.md)

Diese Seite erklärt, wer in einem Workflow was darf und wie Statamics eigene Rechte, die Config des Addons und die Einstellungen jedes Workflows zusammenspielen.

## Die Bausteine

| Baustein | Wo er gesetzt wird | Was er entscheidet |
| --- | --- | --- |
| **Super-Admin** | Statamic-Nutzer | Darf alles, auch den Graph-Editor. |
| **Site-Zugriff** (`access <site> site`) | Statamic-Rolle | Harte Voraussetzung für jede Änderung an einem Eintrag. Siehe [Site-Zugriff kommt zuerst](#site-zugriff-kommt-zuerst). |
| **Statamic-Eintragsrechte** (`view`, `edit` … entries) | Statamic-Rolle | Ob sich der Eintrags-Editor überhaupt öffnet. Das Addon schränkt sie höchstens ein. |
| **Privilegierte Gruppen** | `privileged_groups` in `config/workflows.php` (Standard `['admins']`) | Umgehen die eigenen Sperren des Workflows: jede Transition ausführen, einen Status von Hand setzen, in andere Sites überführen, aus dem Eintrags-Editor veröffentlichen. |
| **Zuständige** | Je Eintrag und Workflow | Nur Zuständige (und Privilegierte) dürfen Transitions ausführen. |
| **Transition-Sperren** | `required_group` / `required_role` an einer Transition | Grenzen ein, welche Zuständigen einen Schritt gehen dürfen. |
| **Workflow-Einstellungen** | `metadata` des Workflows | Wer anlegt, zuweist, bearbeitet, Revisionen veröffentlicht, Kommentare erledigt und löscht. |
| **Config-Gruppen** | `config/workflows.php` | Projektweite Vorgaben: `post_creator_groups`, `assigner_groups`, `history_groups`, `comment_moderator_groups`. |

> [!IMPORTANT]
> `privileged_groups` lockert die eigenen Sperren des Workflows. Es gewährt **keinen** Zugriff auf den Verlauf, keine Kommentar-Moderation, kein Zuweisen, kein Anlegen und keinen Graph-Editor. Dafür gibt es jeweils eine eigene Einstellung.

## Berechtigungsmatrix

„Site“ heißt: Die Person braucht zusätzlich Zugriff auf die Site des Eintrags. Super-Admins bestehen jede Site-Prüfung.

| Aktion | Super-Admin | Privilegierte Gruppe | Alle anderen | Gesetzt über |
| --- | --- | --- | --- | --- |
| Manager-Seiten und Detailseiten öffnen | Ja | Ja | Jeder CP-Nutzer | — |
| Einträge anlegen („Neuer Beitrag“) | Ja | Nur wenn eingetragen | Mitglieder der Anlege-Gruppen | `metadata.post_creator_groups`, sonst Config `post_creator_groups` |
| Eine Transition ausführen | Ja, Site | Ja, Site | Zuständige, die die Sperre der Transition erfüllen, Site | `required_group`, `required_role` |
| Einen Status von Hand setzen | Ja, Site | Ja, Site | Nein | `privileged_groups` |
| Zuständige ändern | Ja, Site | Nur wenn eingetragen | Mitglieder der Zuweisungs-Gruppen, Site | `metadata.assigner_groups`, sonst Config `assigner_groups` |
| In andere Sites überführen (Übersetzungsmanager) | Ja, Site | Ja, Site | Mitglieder der `default_assignee_group`, Site | `metadata.default_assignee_group` |
| Aus dem Eintrags-Editor veröffentlichen | Ja | Ja, außer solange eine Arbeitskopie existiert und `revision_publishers` gesetzt ist | Nur `revision_publishers`, nur solange eine Arbeitskopie existiert | `metadata.revision_publishers` |
| Den Eintrag bearbeiten | Statamic-Rechte | Statamic-Rechte plus Bearbeitungssperre | Statamic-Rechte plus Bearbeitungssperre | `metadata.assignees_only_edit`, `metadata.edit_exempt` |
| Den Workflow-Verlauf sehen | Ja | Nur wenn eingetragen | Mitglieder der Verlaufs-Gruppen | Config `history_groups` |
| Einen Kommentar schreiben | Ja | Site | Site | — |
| Einen Kommentar bearbeiten | Eigene | Eigene | Eigene | — |
| Einen Kommentar löschen | Ja | Eigene, plus Moderatoren | Eigene, plus Moderatoren | `metadata.comment_deleters`, sonst Config `comment_moderator_groups` |
| Einen Thread erledigen oder wieder öffnen | Ja | Siehe [Kommentare](#kommentare) | Siehe [Kommentare](#kommentare) | `metadata.comment_resolvers` |
| Den QS-Bericht der Einfachen Sprache sehen | Ja, außer abgeschaltet | Siehe [QS-Hinweise](#qs-hinweise) | Siehe [QS-Hinweise](#qs-hinweise) | `metadata.qs_notes_visible`, `metadata.qs_notes_audience` |
| **Einstellungen > Workflows** und den Graph-Editor nutzen | Ja | Nein | Nein | — |

## Site-Zugriff kommt zuerst

Jede Änderung an einem Eintrag setzt `access <site> site` für die Site des Eintrags voraus: Transition ausführen, Status setzen, Zuständige ändern, überführen, kommentieren, Threads erledigen, Kommentare löschen und beobachten. Diese Prüfung läuft **vor** der Ausnahme für Privilegierte. `privileged_groups` darf die eigenen Regeln des Addons lockern, aber nie mehr gewähren als Statamic selbst.

Verwandte Regeln:

- Der Button **Bearbeiten** auf einer Detailseite erscheint nur, wenn die Person den Eintrag ansehen darf, Zugriff auf seine Site hat und nicht von der Bearbeitungssperre ausgeschlossen ist.
- Der Button **Live-Vorschau** braucht nur Site-Zugriff. So sehen auch Prüfende ohne Eintragsrechte die gerenderte Seite.
- Erwähnen (@) lassen sich nur Nutzer mit CP-Zugriff und Zugriff auf die Site des Eintrags.
- Kommentare als gelesen markieren ist nie gesperrt: Es speichert nur die eigene „gesehen“-Markierung.

## Transitions ausführen

Klickt jemand eine Transition, prüft das Addon in dieser Reihenfolge:

1. **Site-Zugriff.** Ohne ihn wird der Schritt für alle abgelehnt, auch für Privilegierte.
2. **Technische Hindernisse.** Gründe, aus denen der Schritt gar nicht laufen kann, egal wer fragt: eine `target_missing`-Site, die schon existiert, eine automatische `user_assignment` ohne Kandidaten oder eine Lokalisierung, die nicht starten kann. Auch Super-Admins sind dann gesperrt.
3. **Privilegierte** (Super-Admins und `privileged_groups`) dürfen den Schritt gehen.
4. Alle anderen müssen dieser Fassung des Eintrags in diesem Workflow **zugewiesen** sein.
5. Nennt die Transition `required_group` und/oder `required_role`, muss die Person in **einer** der Gruppen sein **oder** **eine** der Rollen haben. Ohne beide darf jede zuständige Person den Schritt gehen.

Der gesperrte Button nennt den Grund im Tooltip, und der Server prüft dasselbe.

> [!NOTE]
> `required_group` an einem **Place** sperrt nichts. Nur der Schlüssel an der Transition zählt.

Schritte, die das Addon selbst ausführt, sind nicht gesperrt, weil niemand sie klickt: der SLA-Scheduler, `auto_transition`, der Abruf der Einfachen Sprache, der eine Fassung in ihren Abschluss- oder Fehler-Place setzt, und die System-Transitions der Revisionsschleife.

### Warum Zuweisungen zählen

Eine Transition ohne `user_assignment`-Action lässt die Zuständigen, wie sie sind. Weil nur Zuständige Transitions ausführen dürfen, bleibt ein Workflow, dessen Transitions den Eintrag nie übergeben, bei denen, die beim Anlegen zugewiesen wurden (`default_assignee_group` und mit `assign_creator` die anlegende Person). Hänge an jede Transition, die Arbeit an jemand anderen übergibt, eine `user_assignment`. Siehe [Workflows definieren](workflows.md#user_assignment).

### Zuweisungs-Sperren

Zwei optionale Workflow-Einstellungen schränken ein, **an wen** ein Klick einen Eintrag übergeben darf. Sie ändern die Kandidaten einer `user_assignment`, nicht, wer klicken darf.

| Einstellung | YAML | Regel |
| --- | --- | --- |
| **Selbstzuweisung verhindern** | `self_assignment` | Die klickende Person fehlt in der manuellen Auswahl. Bei automatischer Zuweisung bleibt sie in einer Gruppe, der sie nur angehört, weil dort auch andere sind. |
| **4-Augen-Prinzip** | `four_eyes` | Die Person, die den Eintrag zuletzt weitergegeben hat (wer seine letzte manuelle Transition ausgeführt hat), ist nie Kandidat, weder manuell noch automatisch. |

Lässt eine Regel niemanden übrig, öffnet sich die Auswahl stattdessen mit der Ersatz-Zielgruppe dieser Regel (ohne die ausgeschlossenen Personen) und zeigt deren Hinweis. Unbeaufsichtigte Läufe wie der SLA-Scheduler sind von beiden Regeln nicht betroffen.

```yaml
metadata:
  four_eyes:
    by: group
    handles: [managing_editors]
    hint: 'Wähle jemanden aus der Chefredaktion für die Freigabe.'
```

## Zuständige zuweisen

Die Auswahl der Zuständigen auf einer Detailseite ist eine Koordinationsentscheidung. Deshalb hängt sie an einer Gruppe, nicht an der Zuständigkeit:

- Super-Admins dürfen immer.
- Sonst muss die Person in einer der `assigner_groups` des Workflows sein. Fehlt der Schlüssel, gilt `assigner_groups` aus `config/workflows.php`.
- Beide sind standardmäßig leer, das heißt: **nur Super-Admins**.
- Site-Zugriff ist ebenfalls nötig.

`default_assignee_group` ist etwas anderes: Es sagt, **wem** ein Eintrag beim Anlegen übergeben wird.

## Einträge anlegen

Den Button „Neuer Beitrag“ auf einer Manager-Seite sehen:

- Super-Admins und
- Mitglieder der `post_creator_groups` des Workflows bzw. der `post_creator_groups` aus der Config, wenn der Workflow keinen solchen Schlüssel hat.

Beide sind standardmäßig leer (nur Super-Admins). Der Button erscheint nur in dem Workflow, der die Collection besitzt, nie in einem Lokalisierungs-Workflow, und nur für Sites, auf die die Person Zugriff hat.

## Veröffentlichen

Das Addon ersetzt Statamics `publish`-Recht für Einträge. Aus dem Eintrags-Editor veröffentlichen dürfen:

- Super-Admins,
- Mitglieder der `privileged_groups`.

Alle anderen veröffentlichen **über den Workflow**: Eine Transition in einen Place mit `allow_publish: true` veröffentlicht den Eintrag, der Wechsel in jeden anderen Place zieht ihn zurück. Das gilt nur, wenn der Workflow mindestens einen Place mit `allow_publish` hat.

> [!NOTE]
> Das `publish`-Recht wird für jeden Eintrag so beantwortet, auch für Einträge in Collections, die kein Workflow verwaltet. Das Statamic-Recht „Einträge veröffentlichen“ einer Rolle lässt nicht-privilegierte Nutzer also nicht aus dem Eintrags-Editor veröffentlichen.

### Revisionen

Sind Statamic-Revisionen an, erzeugt das Bearbeiten eines veröffentlichten Eintrags eine Arbeitskopie, und der Workflow wechselt auf „Unveröffentlichte Änderungen“. Die Kopie geht über Statamics eigenen Veröffentlichen-Button live.

- Ohne `revision_publishers` gilt die übliche Regel: Super-Admins und privilegierte Gruppen.
- Mit `revision_publishers` dürfen **solange eine Arbeitskopie existiert** nur Super-Admins und diese Zielgruppe veröffentlichen. Privilegierte Gruppen gehören dann nicht mehr dazu.
- Ohne Arbeitskopie ändert sich nichts: Entwürfe gehen nur über den Workflow live.

Statamic liest das Recht beim Laden der Seite. Wer die Arbeitskopie gerade erst angelegt hat, sieht den Button nach dem Neuladen.

## Bearbeiten

Das Addon gewährt nie ein Bearbeiten, das die Rolle nicht erlaubt. Mit `assignees_only_edit: true` schränkt es Statamics Antwort ein:

- Super-Admins dürfen immer bearbeiten.
- Die aktuell Zuständigen der Fassung in ihrem Workflow dürfen bearbeiten.
- Wer auf `edit_exempt` passt, darf bearbeiten.
- Alle anderen sehen den Eintrag schreibgeschützt. Ansehen geht weiterhin, ebenso das Workflow-Panel samt Transitions.

Die Sperre gilt nur für Einträge, die der Workflow verwaltet, und nur, solange die Fassung in einem Place steht. **Privilegierte Gruppen sind nicht ausgenommen**; trage sie in `edit_exempt` ein, wenn sie es sein sollen.

```yaml
metadata:
  assignees_only_edit: true
  edit_exempt:
    - by: group
      handles: [managing_editors]
```

## Verlauf

Den Button **Verlauf anzeigen**, die Verlaufs-Leiste und ihre Feld-Diffs sehen:

- Super-Admins und
- Mitglieder der `history_groups` in `config/workflows.php` (Standard `['admins']`).

Eine leere Liste heißt: nur Super-Admins. `privileged_groups` gewährt keinen Zugriff auf den Verlauf.

## Kommentare

| Aktion | Regel |
| --- | --- |
| Schreiben | Site-Zugriff. |
| Bearbeiten | Nur eigene Kommentare. |
| Löschen | Eigene Kommentare immer. Beliebige Kommentare: Super-Admins, dazu `comment_deleters`, wenn der Workflow es setzt, sonst die `comment_moderator_groups` der Config (Standard `['admins']`; leer heißt nur Super-Admins). |
| Thread erledigen / wieder öffnen | Ohne `comment_resolvers`: die Person, die den Thread begonnen hat, die Kommentar-Moderatoren und die aktuell Zuständigen des Eintrags. Mit `comment_resolvers`: **nur** Super-Admins und diese Zielgruppe. |

```yaml
metadata:
  comment_resolvers:
    - by: group
      handles: [editors]
  comment_deleters:
    - by: role
      handles: [editor_in_chief]
```

## QS-Hinweise

Der Prüfbericht des Dienstes für Einfache Sprache auf der Detailseite:

| `qs_notes_visible` | `qs_notes_audience` | Wer ihn sieht |
| --- | --- | --- |
| fehlt oder `true` | fehlt | Alle |
| fehlt oder `true` | gesetzt | Super-Admins und die Zielgruppe |
| `false` | — | Niemand |

`translation_source_view` (wer die Quellspalte ausgeblendet oder eingeklappt sieht) ist eine Darstellung, kein Recht: Es gilt auch für Super-Admins.

## Übersetzungsmanager

- **In andere Sites überführen**: Privilegierte oder Mitglieder der `default_assignee_group` des Workflows. Site-Zugriff ist nötig. Ohne `default_assignee_group` dürfen nur Privilegierte überführen.
- **Sites-Karte im Eintrags-Editor**: Verwaltet ein Lokalisierungs-Workflow die Collection, ist Statamics Karte „Sites“ für nicht-privilegierte Nutzer ausgeblendet. Fassungen entstehen dann über den Workflow.

## Der Graph-Editor

**Einstellungen > Workflows**, der Graph-Editor sowie das Anlegen, Speichern, Prüfen, Ein- und Ausschalten, Nachtragen und Löschen von Workflows sind **nur für Super-Admins**. Weder `privileged_groups` noch die Gruppe `admins` reichen.

## Zielgruppen-Zeilen

Mehrere Einstellungen nehmen eine Liste von Zeilen. Jede Zeile nennt eine Art und ihre Handles:

```yaml
- by: group      # group | role | user
  handles: [editors, reviewers]
- by: user
  handles: ['a1b2c3']   # Nutzer-IDs
```

Wer auf **irgendeine** Zeile passt, ist dabei. Handles werden nicht gegen bestehende Gruppen oder Rollen geprüft; du kannst also eine Gruppe nennen, die du erst später anlegst. Eine Zeile ohne Art oder ohne Handles wird beim Speichern abgelehnt.

## Nächste Schritte

- [Workflows definieren](workflows.md): wo jeder Schlüssel in der YAML steht.
- [Workflow-Editor](editor.md#berechtigungen): dieselben Einstellungen im Control Panel.
- [Konfiguration](configuration.md): die projektweiten Gruppen in `config/workflows.php`.

---

← [Inhaltsalterung (SLA)](sla.md) · [Doku-Start](README.md) · [Befehle & Scheduler](commands.md) →
