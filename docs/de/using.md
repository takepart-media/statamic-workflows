# Mit Workflows arbeiten

🇬🇧 [English](../en/using.md)

Diese Seite führt Redakteure und Admins durch das Control Panel: das Dashboard, die Manager-Seiten, die Workflow-Übersicht eines Eintrags und den Eintrags-Editor.

## Das Dashboard

Die tägliche Arbeit beginnt auf dem Dashboard. Die Workflow-Manager haben keinen eigenen Navigationspunkt; du erreichst sie über die Dashboard-Widgets.

Das Addon bringt acht Widgets mit. Sieben davon bilden das Standard-Layout, in dieser Reihenfolge:

| Widget | Was es zeigt |
| --- | --- |
| Nutzerkopf (`workflows_user_header`) | Deinen Namen und Avatar, deine „Funktionen“ (Benutzergruppen), deine „Zugehörigkeit“ und die für dich „Verantwortlichen“. |
| Kennzahlen (`workflows_queue_stats`) | Vier Karten: „Mir zugewiesen“ (persönlich), „Wartet auf Freigabe“, „Fehlgeschlagene Übersetzungen“ und „Diese Woche veröffentlicht“ (alle projektweit). „Wartet auf Freigabe“ zeigt zusätzlich, wie lange der älteste Eintrag schon wartet, „Diese Woche veröffentlicht“ vergleicht mit der Vorwoche. Wo du darfst, bietet „Fehlgeschlagene Übersetzungen“ den Button „erneut starten“. Jede Karte verlinkt auf die Einträge hinter ihrer Zahl. |
| Mir zugewiesen (`workflows_my_queue`) | Alle Fassungen, die dir gerade zugewiesen sind, über alle aktiven Workflows, mit einem Tab je Workflow plus „Alle“. |
| Beobachtet (`workflows_watched`) | Die Einträge, die du beobachtest (siehe [Beobachten](#beobachten)). |
| Meine Beiträge (`workflows_created_by_me`) | Einträge, deren `author`-Feld dich nennt, egal wer sie angelegt hat oder wem sie zugewiesen sind. |
| Alle Beiträge (`workflows_overview`) | Die Liste aller Workflows in einer Tabelle. Eine Button-Reihe darüber engt sie auf einen Workflow ein oder zeigt „Alle Workflows“; die Workflow-Spalte verlinkt auf die passende Manager-Seite. Dieses Widget ist der Einstieg in die Manager. |
| Inhalte nach Status (`workflows_status`) | Ein Ringdiagramm je Workflow, das zeigt, wie sich seine Einträge auf die Status verteilen. Ein Klick auf ein Segment oder eine Legendenzeile listet die Einträge in diesem Status. |

Das achte Widget, `workflows_assigned_posts`, listet deine zugewiesenen Einträge in einem einzelnen Workflow. Es ist registriert, aber nicht Teil des Standard-Layouts.

Wie du das Layout änderst, Widgets auf bestimmte Workflows beschränkst oder den Standard abschaltest, steht unter [Dashboard-Widgets](extending/dashboard-widgets.md).

## Manager-Seiten

Jeder Workflow mit einem Nav-Eintrag (`metadata.nav`) hat eine Manager-Seite unter `/cp/workflows/<slug>`, zum Beispiel `/cp/workflows/post-manager`. Du öffnest sie über das Widget „Alle Beiträge“.

Der Seitenkopf zeigt den Namen des Workflows und, wenn du darin Inhalte anlegen darfst, den Button „Neuer Beitrag“ (siehe [Beiträge anlegen](#beiträge-anlegen)). Darunter folgen drei Listen.

| Liste | Inhalt |
| --- | --- |
| Mir zugewiesen | Jede Fassung eines Eintrags in diesem Workflow, die dir zugewiesen ist. Eine Zeile je Fassung. |
| Meine Beiträge | Jede Fassung, deren `author`-Feld dich nennt. Eine Zeile je Fassung. |
| Alle Beiträge | Eine Zeile je Eintrag, mit einer Status-Spalte je Site. Ein Lokalisierungs-Manager nennt diese Liste „Übersetzbare Beiträge“ und listet nur Einträge, deren Quelle fertig ist. |

Verwaltet der Workflow mehr als eine Collection, zeigt „Alle Beiträge“ einen Tab je Collection. Jeder Tab behält seine eigene Suche, Filter, Sortierung und Spalteneinstellung.

### Spalten

„Alle Beiträge“ hat diese Spalten:

| Spalte | Beschreibung |
| --- | --- |
| Titel | Verlinkt auf die Workflow-Übersicht des Eintrags. Immer sichtbar. |
| Eine Spalte je Site | Der Status der Fassung in dieser Site als farbiges Badge. Der Kopf zeigt den Site-Namen mit einem kurzen Site-Code; Sprachvarianten einer Site stehen neben ihrer Basis-Site. Ein Strich bedeutet, dass es in dieser Site noch keine Fassung gibt. |
| Inhaltlich aktualisiert | Wann der Inhalt zuletzt freigegeben wurde (siehe [Inhaltsalterung](sla.md)). In einem Autoren-Workflow standardmäßig sichtbar, in Lokalisierungs-Managern ausgeblendet. Sortierbar. |

Die persönlichen Listen („Mir zugewiesen“, „Meine Beiträge“) zeigen Titel, Status und Website, dazu „Sammlung“, wenn der Workflow mehr als eine Collection verwaltet.

Jede Status-Zelle kann zusätzliche Markierungen tragen:

- einen Live-Vorschau-Button, der diese Fassung in einem eigenen Fenster öffnet,
- die Markierung „veraltet“, wenn der Eintrag nach der Freigabe dieser Fassung überarbeitet wurde (siehe [Inhaltsalterung](sla.md#das-abgeleitete-veraltet)),
- ein Kommentar-Flag mit der Zahl offener Kommentare, dazu `+N` in Amber für Kommentare, die du noch nicht gelesen hast,
- das Datum, seit dem die Fassung in ihrem aktuellen Status steht („seit …“).

Mit der Spaltenauswahl rechts in der Werkzeugleiste blendest du Spalten ein und aus. Die Titelspalte bleibt immer sichtbar.

### Filter

„Alle Beiträge“ bietet eine Suche und diese Filter. Ein Filter ohne Optionen entfällt.

| Filter | Engt ein auf |
| --- | --- |
| Status | Einträge mit einer Fassung im gewählten Status. |
| Zugewiesen an | Einträge mit einer Fassung, die der gewählten Person zugewiesen ist. |
| Autor | Einträge, deren `author`-Feld die gewählte Person nennt. |
| Wartet länger als | Einträge mit einer Fassung, die seit mehr als 3, 7, 14 oder 30 Tagen in einem offenen Status wartet. End-Status wie „Veröffentlicht“ zählen nicht als Warten. |
| Aktualität | „Nur mit veralteter Fassung“. Nur angeboten, wenn es eine solche Fassung gibt. |

Die persönlichen Listen haben keine Werkzeugleiste.

## Die Workflow-Übersicht

Klicke auf den Titel eines Eintrags oder auf ein Status-Badge in einer Site-Spalte, um die Workflow-Übersicht dieser Fassung zu öffnen. Auf dieser Seite bewegst du einen Eintrag durch seinen Workflow.

### Kopf und Aktionen

Eine mitlaufende Leiste oben enthält „Zurück“ und einen Button je Transition, die du aus dem aktuellen Status ausführen kannst. Ein deaktivierter Button nennt den Grund im Tooltip, etwa dass nur zugewiesene Personen die Aktion ausführen dürfen oder dass eine bestimmte Gruppe nötig ist.

Unter der Leiste findest du:

- den Titel des Eintrags und einen Site-Code,
- „Im Status „…“ seit …“, das Datum, an dem die Fassung ihren aktuellen Status erreicht hat,
- „Bearbeiten“, das den Eintrags-Editor öffnet. Erscheint nur, wenn du den Eintrag bearbeiten darfst (siehe [Berechtigungen](permissions.md)),
- „Live-Vorschau“ oder „Live-Seite“ (siehe [Vorschau](#vorschau)),
- „Beobachten“ (siehe [Beobachten](#beobachten)),
- „Verlauf anzeigen“, wenn du den Verlauf sehen darfst (siehe [Verlauf](#verlauf-und-inhalts-diff)),
- „Verantwortlich:“ mit den Avataren der aktuell Zugewiesenen.

### Fortschritt

Die Fortschrittsleiste zeigt die Schritte des Workflows vom Anfang bis zum Ende: erledigte Schritte mit Haken, den aktuellen Schritt mit einem Punkt, kommende Schritte in Grau. Schritte, die von der Hauptlinie abzweigen, etwa die Alterungsschritte aus der [Inhaltsalterung](sla.md), hängen an dem Schritt, von dem aus sie erreicht wurden. Der Workflow legt fest, ob die Leiste waagerecht über dem Inhalt oder senkrecht daneben läuft.

### Eine Transition ausführen

Klicke auf einen Transition-Button. Was dann passiert, hängt von der Transition ab:

- **Ohne Zuweisungsschritt:** Die Transition läuft sofort. Die bisher Zugewiesenen bleiben verantwortlich.
- **Automatische Zuweisung:** Die Transition läuft sofort und übergibt den Eintrag an alle, die die Kriterien der Transition erfüllen.
- **Manuelle Zuweisung:** Zuerst öffnet sich der Dialog „Person zuweisen“ (siehe unten).
- **Site-Auswahl:** Transitions, die eine neue Sprachfassung anlegen, etwa „Übersetzung starten“, öffnen einen Dialog, in dem du die Zielsprache wählst. Je nach Workflow wählst du auch die Quell-Site.

Nach der Transition lädt die Seite neu und zeigt den neuen Status.

### Die Personenauswahl

Der Dialog „Person zuweisen“ listet alle, die die Zuweisungskriterien der Transition erfüllen. Wähle eine oder mehrere Personen und klicke auf „Bestätigen“.

- Ein blaues Badge neben einem Namen zeigt die Site-Zuordnung der Person, falls der Workflow eine nutzt.
- Verschickt der Schritt eine Mail, erscheint der Schalter „Benachrichtigung per E-Mail senden“. Schalte ihn aus, um den Eintrag ohne Mail zu übergeben. Wer eine Mail bekommt, ist mit „Erhält E-Mail“ markiert.
- Würde eine automatische Zuweisung den Eintrag allein an dich zurückgeben, öffnet sich stattdessen der Dialog und erklärt, warum.

Um ohne Transition zu ändern, wer verantwortlich ist, klicke auf den Stift neben „Verantwortlich:“. Die Schublade listet alle Nutzer mit Suche und Gruppenfilter. Den Stift sehen nur Nutzer, denen der Workflow das Zuweisen erlaubt (siehe [Berechtigungen](permissions.md)).

### Inhalt und Kommentare

Der Bereich „Inhalt“ zeigt die Inhaltsfelder des Eintrags als lesbare Passagen. Kommentare stehen wie in einer Textverarbeitung in einer Randspalte neben dem Inhalt. Mit „Kommentare einblenden“ / „Kommentare ausblenden“ schaltest du die Spalte um; dein Browser merkt sich die Wahl.

So kommentierst du:

- **Eine ganze Passage:** Klicke auf den `+`-Button am Rand daneben.
- **Eine Textmarkierung:** Markiere Text in einer Passage und klicke auf das Kommentar-Symbol, das daneben erscheint („Kommentar zur Markierung“). Die Markierung bleibt im Text hervorgehoben.
- **Allgemein:** Kommentare ohne Passage erscheinen unter „Allgemein“.

Schreibe deinen Kommentar und klicke auf „Kommentieren“ oder drücke <kbd>Strg</kbd>/<kbd>⌘</kbd> + <kbd>Enter</kbd>. Tippe `@`, um jemanden zu erwähnen; die Liste bietet nur Personen mit CP-Zugang zur Site des Eintrags an. Erwähnte bekommen eine Mail (siehe [Benachrichtigungen](notifications.md#kommentar-erwähnungen)).

Kommentare bilden Threads: ein Kommentar an einer Passage, darunter die Antworten. In einem Thread kannst du:

| Aktion | Wer |
| --- | --- |
| Antworten | Alle, die die Übersicht öffnen können. Eine Antwort auf einen erledigten Thread öffnet ihn wieder. |
| Bearbeiten | Nur der Autor des Kommentars. |
| Löschen | Der Autor, Super-Admins und die Kommentar-Moderatoren des Workflows. Wer den ersten Kommentar eines Threads löscht, löscht seine Antworten mit. |
| Als erledigt markieren / Wieder öffnen | Standardmäßig der Autor des Threads, die aktuell Zugewiesenen des Eintrags, Kommentar-Moderatoren und Super-Admins. Ein Workflow kann das auf eine gewählte Auswahl beschränken. |

Die Randspalte filtert Threads nach „Offen“, „Erledigt“ und „Alle“. Erledigte Threads zählen in den Listen nicht mehr zum Kommentar-Flag.

Ein Kommentar zeigt einen Hinweis, wenn sich der Text, auf den er sich bezieht, seitdem geändert hat: „Bezieht sich auf eine ältere Version dieser Passage“ oder „In diesem Bereich wurde seit dem Kommentar etwas geändert“. Kommentare, die du noch nicht gelesen hast, sind mit „neu“ markiert. Eine Passage gilt als gelesen, sobald du ihre Kommentare öffnest.

Super-Admins können „Technische Details“ einschalten, um Feld-Handles und Passagen-IDs zu sehen.

> [!NOTE]
> Wer Kommentare löschen und als erledigt markieren darf, legst du je Workflow und global fest. Siehe [Berechtigungen](permissions.md).

### Beobachten

„Beobachten“ legt den Eintrag in dein Dashboard-Widget „Beobachtet“. Das ist ein persönliches Lesezeichen und macht dich nicht verantwortlich. Solange du einen Eintrag beobachtest, steht auf dem Button „Beobachtet“; ein weiterer Klick beendet das Beobachten.

Wer einem Eintrag zugewiesen wird, beobachtet ihn automatisch. Beobachter bekommen die Mail „Veröffentlicht (an Beobachter)“, wenn ein Workflow sie verschickt (siehe [Benachrichtigungen](notifications.md)).

### Verlauf und Inhalts-Diff

„Verlauf anzeigen“ öffnet eine Schublade mit dem Protokoll der Fassung: jede Transition, jeder manuell gesetzte Status, jede Entwurfsspeicherung, Arbeitskopie, Erinnerung und jedes Kommentar-Ereignis, mit Zeit und Person. Ereignisse, die niemand ausgelöst hat, etwa geplante Verschiebungen, zeigen „System“.

Klicke bei einem Ereignis auf „Änderungen anzeigen“, um zu sehen, was sich im Inhalt geändert hat:

- Ein **Schritt** (eine Transition, ein manuell gesetzter Status, das Anlegen) wird mit dem vorherigen Schritt verglichen. Entwurfsspeicherungen dazwischen werden übersprungen, du siehst also die Gesamtänderung des Schritts.
- Eine **Entwurfsspeicherung** wird mit der Speicherung davor verglichen, du siehst also genau, was diese eine Speicherung geändert hat.

Textänderungen werden Wort für Wort hervorgehoben. Verknüpfungen zeigen die Namen der verknüpften Elemente.

> [!NOTE]
> Den Verlauf sehen nur Super-Admins und die Gruppen aus `history_groups` (Standard: `admins`). Siehe [Konfiguration](configuration.md).

### Vorschau

Der Vorschau-Button öffnet die Seite in einem eigenen Fenster:

| Button | Wann | Zeigt |
| --- | --- | --- |
| Live-Seite | Die Fassung ist veröffentlicht und hat keine offenen Änderungen. | Die veröffentlichte Seite. |
| Live-Vorschau | Die Fassung ist unveröffentlicht oder hat eine Arbeitskopie. | Eine Vorschau des Entwurfs oder der Arbeitskopie, auch vor der Veröffentlichung. |

Dieselben Buttons stehen in den Listen neben jedem Status-Badge.

### Revisionen und Arbeitskopien

Hat eine verwaltete Collection Statamic-Revisionen aktiviert (Statamic Pro), legt das Bearbeiten eines veröffentlichten Eintrags eine Arbeitskopie an, statt den Live-Eintrag zu ändern. Der Workflow zeigt dann den Status „Unveröffentlichte Änderungen“:

- Die veröffentlichte Fassung bleibt online.
- „Live-Vorschau“ zeigt die Arbeitskopie.
- Sobald die Arbeitskopie veröffentlicht ist, kehrt der Eintrag von selbst in seinen Veröffentlichungs-Status zurück. Das Veröffentlichen einer Arbeitskopie zählt außerdem als Freigabe für die [Inhaltsalterung](sla.md).

Wer eine Arbeitskopie veröffentlichen darf, lässt sich je Workflow einschränken (siehe [Berechtigungen](permissions.md)).

### Admin-Werkzeuge

Nutzer mit privilegiertem Zugang (Super-Admins und `privileged_groups`) sehen unten auf der Seite „Status manuell setzen (Admin)“. Damit setzt du den Status direkt, ohne eine Transition auszuführen. Dabei gehen keine Mails raus und keine Transition-Actions laufen; der Verlauf vermerkt es als „Status manuell gesetzt.“

Hat ein Place ein Aktualisierungsintervall, prüft die Seite im Hintergrund auf Änderungen. Hat sich etwas geändert, fragt sie „Neuer Inhalt verfügbar“ und bietet „Jetzt aktualisieren“ oder „Später“ an.

### Übersichten für Übersetzung und Einfache Sprache

Lokalisierungs-Workflows nutzen eine zweispaltige Übersicht: links die Quelle, rechts die Übersetzung.

- „Quelle einblenden“ / „Quelle ausblenden“ schaltet die Quellspalte um. Ein Workflow kann die Quelle für bestimmte Personen eingeklappt zeigen oder ausblenden.
- „Die Quelle wurde überarbeitet“ erscheint, wenn die Quelle nach dieser Fassung freigegeben wurde.
- „In andere Site überführen“ legt weitere Sprachfassungen an. Verfügbar für privilegierte Nutzer und die Standard-Zuweisungsgruppe des Workflows.
- Solange eine Umschreibung in Einfache Sprache läuft, zeigt der Inhalt „Wird gerade in Einfache Sprache umgeschrieben …“.

In Workflows für Einfache Sprache öffnet der Button „Hinweise zur KI-Überarbeitung“ den Prüfbericht des Dienstes für Einfache Sprache: was vereinfacht, ergänzt und weggelassen wurde. Solange die Fassung in dem Schritt steht, in dem fertige Umschreibungen landen, ist der Button mit einem Punkt hervorgehoben („Neuer Prüfbericht — bitte vor der Freigabe lesen.“). Wer den Button sieht, legst du je Workflow fest.

Zur Einrichtung siehe [Übersetzung & Einfache Sprache](localization.md).

## Beiträge anlegen

Darfst du in einem Workflow Inhalte anlegen, zeigt seine Manager-Seite „Neuer Beitrag“. Das Widget „Alle Beiträge“ zeigt je Workflow einen Button „<Workflow>: neuer Beitrag“.

1. Klicke auf „Neuer Beitrag“.
2. Gib einen Titel ein.
3. Verwaltet der Workflow mehrere Collections, wähle eine aus.
4. Koppelt der Workflow eine erste Transition an das Anlegen und fragt diese nach einer Person, wählst du die Zugewiesenen im selben Dialog. „Benachrichtigung per E-Mail senden“ funktioniert wie in der Personenauswahl.
5. Bestätige. Der Button trägt die Beschriftung der gekoppelten Transition, zum Beispiel „Entwurf erstellen“, oder ohne sie „Anlegen“.

Der Beitrag entsteht als Entwurf in der ersten Site der Collection, auf die du Zugriff hast. Er bekommt den Start-Status des Workflows und wird der Standard-Zuweisungsgruppe des Workflows zugewiesen, dazu dir, wenn der Workflow das so vorsieht. Ist eine gekoppelte Transition gesetzt, läuft sie sofort. Danach lädt die Seite neu, damit du siehst, wohin der Beitrag gegangen ist.

> [!NOTE]
> Beiträge anlegen ist standardmäßig aus. Nur Super-Admins und die Gruppen aus `post_creator_groups` (oder der eigenen Einstellung des Workflows) dürfen anlegen. Siehe [Berechtigungen](permissions.md).

## Im Eintrags-Editor

Jedes Blueprint einer verwalteten Collection bekommt das Workflow-Panel automatisch. Du musst das Blueprint nicht anpassen.

### Kopfzeile

Die Kopfzeile des Eintrags-Editors zeigt den aktuellen Status der Fassung als Badge, gefolgt von einem Button je verfügbarer Transition, neben Statamics eigenem „Speichern“-Button. Ohne verfügbare Transition steht dort „Keine Aktionen verfügbar.“

Wenn du im Editor auf einen Transition-Button klickst:

1. Der Eintrag wird zuerst gespeichert. Schlägt das Speichern fehl, etwa wegen eines Validierungsfehlers, bricht die Transition ab („Beitrag konnte nicht gespeichert werden. Transition abgebrochen.“).
2. Hat die Collection Revisionen aktiviert, wird eine Statamic-Revision mit der Beschriftung der Transition angelegt.
3. Die Transition läuft, bei Bedarf mit Personen- oder Site-Auswahl.
4. Du landest auf der Workflow-Übersicht.

### Veröffentlichen

Statamics „Veröffentlichen“-Button ist ausgeblendet, wenn alles davon zutrifft:

- der Workflow steuert das Veröffentlichen (er hat mindestens einen Place mit `allow_publish`),
- der aktuelle Place erlaubt das Veröffentlichen nicht,
- du bist weder Super-Admin noch in `privileged_groups`.

Der Eintrag geht dann über die Transitions des Workflows online. Solange eine Arbeitskopie existiert und der Workflow einschränkt, wer sie veröffentlichen darf, sehen nur diese Personen den Button.

In einer Collection mit Lokalisierungs-Workflow ist die Karte „Websites“ für nicht privilegierte Nutzer ausgeblendet. Neue Sprachfassungen entstehen dann über den Workflow.

### Schreibgeschützte Einträge

Ist in einem Workflow „Nur Zuständige dürfen bearbeiten“ eingeschaltet, öffnet sich der Eintrags-Editor schreibgeschützt für alle, die weder aktuell zugewiesen noch in der Ausnahmeliste des Workflows noch Super-Admin sind. Das Workflow-Panel bleibt nutzbar, Transitions funktionieren also weiter. Statamics eigene Berechtigungen gelten zusätzlich.

## Fieldtypes

| Fieldtype | Handle | Zweck |
| --- | --- | --- |
| Workflow-Aktionen | `workflow_actions` | Das oben beschriebene Workflow-Panel im Eintrags-Editor. Wird automatisch in den ersten Tab jedes Blueprints einer verwalteten Collection eingefügt. Speichert nichts. |
| Revisionsstatus | `revision_status` | Zeigt, ob ein Eintrag eine unveröffentlichte Arbeitskopie hat. Optional, du fügst ihn selbst hinzu. |

### Revisionsstatus

`revision_status` ist ein reines Anzeigefeld für Collections mit Revisionen. In der Eintragsliste zeigt es ein orangefarbenes Badge „Unveröffentlichte Änderungen“, im Eintrags-Editor dasselbe Badge oder „Keine offenen Änderungen“. Es speichert nichts.

Im Blueprint-Builder lässt sich der Fieldtype nicht auswählen. Trage ihn in die Blueprint-YAML ein:

```yaml
-
  handle: revision_status
  field:
    type: revision_status
    display: 'Revisionsstatus'
```

In den Listen von Collections mit Revisionen ergänzt das Addon außerdem den Filter „Revisionsstatus“ mit den Optionen „Unveröffentlichte Änderungen“ und „Keine offenen Änderungen“.

## Nächste Schritte

- [Benachrichtigungen](notifications.md): welche Mails der Workflow verschickt und an wen.
- [Berechtigungen](permissions.md): wer ausführen, zuweisen, bearbeiten, veröffentlichen und kommentieren darf.
- [Inhaltsalterung (SLA)](sla.md): wie veröffentlichte Inhalte altern und zurückkommen.
- [Übersetzung & Einfache Sprache](localization.md): Lokalisierungs-Workflows und ihre Übersichtsseite.

---

← [Workflow-Editor](editor.md) · [Doku-Start](README.md) · [Benachrichtigungen](notifications.md) →
