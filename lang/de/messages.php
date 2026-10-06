<?php

return [
    // Chrome
    'back' => 'Zurück',
    'edit' => 'Bearbeiten',
    // „Live-Vorschau“-Button der Workflow-Ansichten; die beiden Hinweise sind
    // Tooltips, je nachdem ob eine Arbeitskopie gezeigt wird.
    'live_preview' => 'Live-Vorschau',
    'live_preview_hint' => 'Öffnet die Seite in einem eigenen Fenster – auch wenn sie noch unveröffentlicht ist.',
    'live_preview_pending_hint' => 'Zeigt die noch unveröffentlichten Änderungen (Arbeitskopie) in einem eigenen Fenster.',
    'live_page' => 'Live-Seite',
    'live_page_hint' => 'Öffnet die veröffentlichte Seite in einem eigenen Fenster.',
    'versions' => 'Übersetzungen',
    'content' => 'Inhalt',
    'no_actions' => 'Keine Aktionen verfügbar.',
    'history' => 'Verlauf',
    'history_open' => 'Verlauf anzeigen',
    'no_history' => 'Noch kein Verlauf.',
    'comments' => 'Kommentare',
    'new' => 'neu',
    'delete' => 'Löschen',
    'no_comments' => 'Noch keine Kommentare.',
    // Tooltip des `+N`-Markers im Index (pro Site-Spalte bzw. am Titel).
    'new_comments_hint' => 'Neue Kommentare, die du noch nicht gelesen hast',
    'write_comment' => 'Kommentar schreiben…',
    'save' => 'Speichern',
    'delete_comment_confirm' => 'Diesen Kommentar löschen?',
    'comment_saved' => 'Kommentar gespeichert',
    'comment_deleted' => 'Kommentar gelöscht',
    'comment_save_error' => 'Kommentar konnte nicht gespeichert werden.',
    'comment_delete_error' => 'Kommentar konnte nicht gelöscht werden.',
    'transition_error' => 'Übergang konnte nicht angewendet werden.',
    'transition_not_allowed' => 'Dieser Schritt ist aus dem aktuellen Status nicht erlaubt.',
    'actions_load_error' => 'Workflow-Aktionen konnten nicht geladen werden.',
    'save_first_failed' => 'Beitrag konnte nicht gespeichert werden. Transition abgebrochen.',
    'event_note_created' => 'Beitrag angelegt.',
    'event_note_localized' => 'Übersetzung erstellt (:site).',
    'event_note_set_state' => 'Status manuell gesetzt.',
    // Wird pro Speicherung geloggt, die wirklich etwas geändert hat.
    // `draft_saved` deckt Entwürfe und Arbeitskopien ab; `entry_saved` tritt nur in
    // Collections ohne Revisionen auf, wo ein Save direkt den Live-Beitrag schreibt.
    'event_note_draft_saved' => 'Entwurf gespeichert.',
    'event_note_entry_saved' => 'Beitrag gespeichert.',
    'event_note_revision_created' => 'Arbeitskopie angelegt.',
    'event_note_revision_published' => 'Arbeitskopie veröffentlicht.',
    'event_note_source_draft' => 'Der Beitrag liegt im Entwurf — diese Fassung ist mitgegangen.',
    'event_note_sla_aged' => 'Seit :days Tagen nicht aktualisiert.',
    'event_note_reminder_sent' => 'Erinnerung verschickt — seit :days Tagen in diesem Status.',
    'history_event_reminder_sent' => 'Erinnerung verschickt',
    'state_since' => 'Im Status „:state“ seit :date',
    'event_note_auto_transition' => '{1} Automatisch ausgeführt — Frist von einem Tag abgelaufen.|[2,*] Automatisch ausgeführt — Frist von :days Tagen abgelaufen.',
    'event_note_auto_transition_undone' => 'Automatische Transition [:transition] rückgängig gemacht.',
    'event_actor_system' => 'System',
    'event_actor_current_user' => 'Aktueller Benutzer',
    'history_show_diff' => 'Änderungen anzeigen',
    'history_hide_diff' => 'Änderungen ausblenden',
    'history_event_comment_created' => 'hat kommentiert',
    'history_comment_deleted' => 'Kommentar entfernt',
    'comment_content_stale' => 'Bezieht sich auf eine ältere Version dieser Passage',
    'comment_container_changed' => 'In diesem Bereich wurde seit dem Kommentar etwas geändert',
    'history_vs_previous' => 'vs. vorheriger Schritt',
    'history_vs_original' => 'vs. Original',
    'history_no_diff_data' => 'Keine Diff-Daten verfügbar (Snapshot fehlt).',
    'history_no_comparison' => 'Kein früherer Schritt zum Vergleich.',
    'history_no_changes' => 'Keine Änderungen an Feldern.',
    'loading' => 'Lädt …',
    'change_relationship_modified' => 'Verknüpfungen geändert.',
    'change_relationship_count' => ':from → :to verknüpfte Elemente.',
    'change_bool_true' => 'Aktiviert',
    'change_bool_false' => 'Deaktiviert',
    'change_published_true' => 'Veröffentlicht',
    'change_published_false' => 'Nicht veröffentlicht',
    'change_value_modified' => 'Wert geändert.',

    // Labels für Felder ohne Blueprint-Eintrag. Statamic hält Slug, Datum und
    // Veröffentlichungsstatus außerhalb der Blueprint-Daten, sie haben also kein
    // `display`, aus dem der Diff ein Label ziehen könnte. Der Schlüssel
    // `field_<handle>` funktioniert für jedes Feld.
    'field_slug' => 'Slug',
    'field_date' => 'Datum',
    'field_published' => 'Veröffentlichung',
    'requires_group' => 'Erfordert Gruppe: :group',
    'requires_assignee' => 'Nur zugewiesene Personen dürfen diese Aktion ausführen.',
    'requires_site_access' => 'Kein Zugriff auf die Website „:site" — dafür fehlt die Berechtigung „access :handle site".',
    'assignee_required' => 'Bitte eine Person auswählen.',
    'assignee_invalid' => 'Diese Person erfüllt die Zuweisungs-Kriterien nicht.',
    'assignment_no_candidates' => 'Niemand erfüllt die Zuweisungs-Kriterien dieser Transition.',
    'pick_assignee_title' => 'Person zuweisen',
    'pick_assignee_prompt' => 'Wähle die Personen, an die der Beitrag jetzt übergeben werden soll. Mehrfachauswahl möglich.',
    'pick_assignee_count' => ':count ausgewählt',
    'confirm' => 'Bestätigen',
    'open_where_prompt' => 'Wohin möchtest du?',
    'open_workflow_summary' => 'Zur Zusammenfassung',
    'open_entry_editor' => 'Beitrag bearbeiten',

    // Translation manager
    'source' => 'Quelle',
    'translation_target' => 'Übersetzung',
    // Der Ein-/Ausblende-Button der Quellspalte, wenn eine Regel sie eingeklappt
    // startet (metadata.translation_source_view, Modus `collapsed`).
    'source_show' => 'Quelle einblenden',
    'source_hide' => 'Quelle ausblenden',
    // Ersetzt den Content-Block, solange TransitionActions::PENDING_REWRITE_SPINNER
    // aktiv ist und ein Auftrag für diese Fassung offen ist.
    'pending_rewrite_spinner' => 'Wird gerade in Einfache Sprache umgeschrieben …',
    'pending_rewrite_attempt' => 'Versuch :attempt von :max',
    'pending_rewrite_retry_started' => 'Der Versuch ist fehlgeschlagen — Versuch :attempt von :max wurde gestartet.',
    'transfer' => 'In andere Site überführen',
    'transfer_title' => 'In andere Site überführen',
    'transferred' => 'In Site(s) überführt',
    'transfer_error' => 'Überführen nicht möglich.',
    'no_target_sites' => 'Keine gültige Ziel-Site gewählt.',
    'target_site_required' => 'Bitte wähle eine Ziel-Sprache.',
    'target_site_invalid' => 'Die gewählte Ziel-Sprache ist nicht verfügbar.',
    // Der feste Ziel-Pfad (Einfache-Sprache-Manager) hat keinen Dialog davor, der
    // belegte Sites ausblenden könnte — deshalb sagt die Begründung am Button, was
    // sonst die Auswahlliste gezeigt hätte.
    'target_site_taken' => 'Es gibt bereits eine Version in :site.',
    // Andere Ursache als target_site_taken: die Site existiert zwar (validiert),
    // ist für DIESEN Beitrag aber gerade kein erreichbares Ziel — z. B. nicht Teil
    // der Collection dieses Beitrags, von einem anderen Manager beansprucht, oder
    // die eigene Quelle. Keine Version vorhanden, also keine falsche Behauptung.
    'target_site_unavailable' => ':site ist für diesen Beitrag kein erreichbares Ziel (nicht Teil der Collection, anderweitig beansprucht, oder die eigene Quelle).',
    // Genau eine Fassung pro Beitrag startet eine Übersetzung: die, aus der dieser
    // Manager liest. Steht die Quelle auf Einfacher Sprache, ist die deutsche
    // Fassung hier nicht mehr zuständig.
    'transition_start_at_source' => 'Übersetzungen beginnen an der Quell-Fassung (:site).',
    // Übersetzt wird nur aus fertiger Arbeit: die Quelle muss im letzten Place ihres
    // eigenen Workflows stehen. Zwei Formulierungen, weil es zwei Fälle gibt — ein
    // Workflow, der die Fassung verwaltet, oder nur der Veröffentlicht-Status.
    'translation_source_not_ready' => 'Die Quelle in :site ist noch nicht fertig — sie steht auf „:state", nötig ist „:required".',
    'translation_source_unpublished' => 'Die Quelle in :site ist nicht veröffentlicht.',
    'translation_source_none_ready' => 'Keine Fassung dieses Beitrags ist fertig — es gibt nichts, woraus übersetzt werden könnte.',
    // Kein Status in diesem Workflow: ein unveröffentlichter Beitrag ist noch nicht
    // Teil eines Lokalisierungs-Managers (siehe WorkflowResolver::derivedState).
    'workflow_not_started' => 'Dieser Beitrag ist noch nicht veröffentlicht und damit noch nicht Teil dieses Workflows.',
    // „Neu erzeugen" wird an der Quell-Fassung und an der Einfache-Sprache-Fassung
    // angeboten und wirkt immer auf letztere. Überall sonst wäre es sinnlos oder
    // würde die falsche Version überschreiben.
    'transition_resubmit_wrong_version' => 'Neu erzeugen geht an der Fassung in :source oder in :target.',
    'transition_resubmit_no_target' => 'Es gibt noch keine Fassung in :site, die neu erzeugt werden könnte.',
    'transition_resubmit_state' => 'Die Fassung in :site steht auf „:state" — von dort führt dieser Schritt nicht weiter.',
    'translation_plain_no_site' => 'Dieser Workflow hat keine Ziel-Site für Einfache Sprache.',
    'translation_source_missing' => 'Für diesen Beitrag existiert keine Version in :site — daraus kann nicht übersetzt werden.',
    'translation_plain_job_running' => 'Für diesen Beitrag läuft noch eine Umschreibung in Einfache Sprache.',
    'transition_site_pick_title' => 'In welche Sprache übersetzen?',
    'transition_site_pick_source_label' => 'Ursprungs-Site',
    'transition_site_pick_target_label' => 'Ziel-Sprache',
    'transition_site_pick_confirm' => 'Übersetzung starten',
    'transition_site_pick_no_targets' => 'Alle verfügbaren Sites haben bereits eine Übersetzung.',

    // Maschinelle Übersetzung. Sie läuft vollständig durch, BEVOR die Localization
    // angelegt wird — jede dieser Fehlermeldungen bedeutet deshalb zugleich, dass
    // kein Beitrag entstanden ist.
    'translation_running' => 'Übersetzung läuft — das kann einen Moment dauern.',
    'translation_not_configured' => 'Kein DeepL-API-Schlüssel hinterlegt (DEEPL_API_KEY in der .env).',
    'translation_unreachable' => 'Der Übersetzungsdienst ist nicht erreichbar.',
    'translation_error_timeout' => 'Die Übersetzung hat zu lange gedauert und wurde abgebrochen — es wurde kein Beitrag angelegt.',
    'translation_bad_response' => 'Der Übersetzungsdienst hat eine unerwartete Antwort geliefert — es wurde nichts geschrieben.',
    'translation_error_auth' => 'Der DeepL-API-Schlüssel wurde abgelehnt.',
    'translation_error_rate_limit' => 'Zu viele Anfragen an DeepL. Bitte kurz warten und erneut versuchen.',
    'translation_error_quota' => 'Das DeepL-Kontingent ist aufgebraucht.',
    'translation_error_request' => 'DeepL hat die Anfrage abgelehnt.',
    'translation_error_generic' => 'Der Übersetzungsdienst antwortete mit Status :status.',
    // Einfache Sprache läuft nicht über DeepL, sondern über den eigenen Endpunkt —
    // eigene Meldungen, damit niemand den falschen Zugang prüft.
    'translation_plain_not_configured' => 'Kein Endpunkt für Einfache Sprache hinterlegt (PLAIN_LANGUAGE_URL und PLAIN_LANGUAGE_TOKEN in der .env).',
    'translation_plain_error_auth' => 'Der Token für Einfache Sprache wurde abgelehnt (PLAIN_LANGUAGE_TOKEN).',
    'translation_plain_error_rate_limit' => 'Zu viele Anfragen an den Dienst für Einfache Sprache. Bitte kurz warten und erneut versuchen.',
    'translation_plain_error_request' => 'Der Dienst für Einfache Sprache hat die Anfrage abgelehnt.',
    'translation_plain_job_written' => 'Einfache Sprache: :count Segment(e) übernommen.',
    'translation_plain_job_written_moved' => 'Einfache Sprache: :count Segment(e) übernommen, automatisch verschoben nach :place.',
    // Ursachen-Fragmente, absichtlich klein geschrieben und ohne Schlusspunkt: sie
    // werden in zwei Rahmen eingesetzt — in die Retry-Notiz („Versuch 1 von 3") und in
    // den terminalen Alert (translation_plain_failed_reason). Deshalb enthalten sie
    // weder „aufgegeben" noch eine Versuchszahl, die im ersten Fall falsch wäre. Die
    // Auftrags-ID steht bewusst nicht drin: sie sagt einem Redakteur nichts und liegt
    // ohnehin im Log, zusammen mit Payload und Antwort.
    'translation_plain_job_failed_unknown' => 'der Dienst meldet einen Fehlschlag ohne Begründung',
    'translation_plain_job_lost' => 'der Dienst kennt den Auftrag nicht mehr',
    'translation_plain_job_bad_output' => 'der Dienst lieferte ein unbrauchbares Ergebnis',
    'translation_plain_job_timed_out' => 'der Auftrag wurde nach :polls Abfragen nicht fertig',
    'translation_plain_job_endpoint_error' => 'der Dienst meldet einen Fehler (HTTP :status)',
    'translation_plain_job_no_segments' => 'die Quelle enthält keinen übersetzbaren Text',
    'translation_plain_job_source_missing' => 'die Quellfassung fehlt',
    'translation_plain_job_source_unpublished' => 'die Quelle ist nicht veröffentlicht',
    'translation_plain_job_unexpected' => 'ein unerwarteter Fehler ist aufgetreten',
    'translation_plain_job_resubmit_refused' => 'die erneute Einreichung wurde abgelehnt',

    // Die zwei Rahmen um ein Ursachen-Fragment.
    'translation_plain_job_retry' => 'Versuch :attempt von :max fehlgeschlagen: :reason. Erneut an den Dienst geschickt.',
    'translation_plain_failed_reason' => 'Übersetzung fehlgeschlagen: :reason. Nach einem Versuch aufgegeben.|Übersetzung fehlgeschlagen: :reason. Nach :count Versuchen aufgegeben.',
    'translation_plain_failed_toast' => 'Beitrag „:title“ konnte nicht in Einfache Sprache überführt werden.',
    'translation_plain_qs_notes' => 'Prüfbericht des Dienstes für Einfache Sprache:',
    // Button + Stack des QS-Berichts auf der Übersetzungsseite.
    'qs_notes' => 'QS-Hinweise',
    'qs_notes_intro' => 'Prüfbericht des Dienstes für Einfache Sprache: was vereinfacht, ergänzt und weggelassen wurde.',
    'qs_notes_highlight_title' => 'Neuer Prüfbericht — bitte vor der Freigabe lesen.',
    // Der Auftrag ist raus, die Texte kommen erst Minuten später — der Toast sagt
    // das, statt zu suggerieren, die Version sei schon fertig.
    'translation_plain_submitted' => 'Auftrag an den Dienst für Einfache Sprache geschickt — die Texte erscheinen in einigen Minuten.',
    'translation_warning_no_blueprint' => 'Kein Blueprint gefunden — es konnte kein Feld ausgewertet werden.',
    'translation_warning_not_localizable' => 'Feld „:field" ist im Blueprint nicht localizable — die Übersetzung wäre im Editor nicht sichtbar.',
    'translation_warning_render_failed' => ':path konnte nicht nach HTML gewandelt werden und wurde übersprungen.',
    'translation_warning_parse_failed' => ':path konnte nicht zurück nach ProseMirror gewandelt werden — Original behalten.',
    'translation_warning_empty_result' => ':path kam leer zurück — Original behalten.',
    'translation_warning_structure_changed' => ':path kam mit veränderter Block-Struktur zurück — die neuen Nodes wurden eingesetzt.',
    'set_state' => 'Status manuell setzen (Admin)',
    'set' => 'Setzen',
    'state_set' => 'Status gesetzt',
    'state_set_error' => 'Status konnte nicht gesetzt werden.',
    'invalid_state' => 'Ungültiger Status.',
    'applied' => 'Angewendet: :action',
    'demo_note' => '(Demo – nicht gespeichert)',
    'open_workflow_detail' => 'Workflow-Detail öffnen',
    'my_queue' => 'Meine Warteschlange',
    'my_created' => 'Von mir erstellt',
    'widget_assigned_title' => 'Meine Beiträge',
    'widget_open_manager' => 'Zum Beitragsmanager',
    // Eigener, kürzerer Text nur für den Fuß der WorkflowStatus-Stacks: dort
    // steht der Link neben dem Pager und muss in eine schmale Zeile passen.
    'widget_stack_open_manager' => 'Zum Manager',
    'widget_no_assigned' => 'Keine zugewiesenen Beiträge',
    'widget_no_created' => 'Keine von dir erstellten Beiträge',
    // Beobachten: persönliches Lesezeichen für die Dashboard-Karte „Beobachtet".
    'watch' => 'Beobachten',
    'watching' => 'Beobachtet',
    'unwatch' => 'Nicht mehr beobachten',
    'watch_error' => 'Beobachten konnte nicht geändert werden.',
    'widget_watched_title' => 'Beobachtet',
    'widget_watched_subline' => 'Zuletzt bearbeitet',
    'widget_no_watched' => 'Du beobachtest keine Beiträge. Auf der Detailseite eines Beitrags über „Beobachten" hinzufügen.',
    'widget_header_groups' => 'Funktionen',
    'widget_header_no_groups' => 'Keine Funktionen',
    'widget_header_affiliation' => 'Zugehörigkeit',
    'widget_header_responsible' => 'Verantwortlicher',
    'widget_tab_all' => 'Alle',
    'widget_created_subline' => 'Zuletzt bearbeitet',
    // Carbon liefert die vollständige, korrekt deklinierte Wendung („vor 6
    // Tagen") — siehe PersonalWorkItems::meta(). Der Key bleibt als Fuge:
    // ein Projekt kann die Zeitangabe hier einbetten.
    'widget_meta_since' => ':time',
    'widget_meta_today' => 'heute :time',
    // Kennzahlreihe „workflows_queue_stats" — vier Karten über den Listen.
    'widget_stats_queue' => 'In meiner Warteschlange',
    'widget_stats_review' => 'Wartet auf Freigabe',
    // Benannt statt bloß „Fehlgeschlagen": die Karte zählt genau eines —
    // Fassungen, die im `plain_language_failed_place` eines Workflows geparkt sind.
    'widget_stats_failed' => 'Fehlgeschlagene Übersetzungen',
    'widget_stats_published_week' => 'Diese Woche veröffentlicht',
    // trans_choice mit ganzen Tagen: „seit" verlangt den Dativ, und der steckt
    // nur in einer Pluralform, die wir selbst schreiben — ein zusammengesetztes
    // „seit " . Carbon-Diff ergab „seit 4 Tage". Siehe QueueStats::oldestSince().
    'widget_stats_oldest_since' => 'älteste seit 1 Tag|älteste seit :count Tagen',
    // Sowohl die Notiz unter der Fehler-Karte als auch, wo der Betrachter handeln
    // darf, die Beschriftung des Buttons, der sie ersetzt.
    'widget_stats_restart_hint' => 'erneut starten',
    // Wird nie gerendert: der zugängliche Name der Zahl, die das Popover öffnet.
    // Die Zahl selbst ist eine nackte Ziffernfolge und sagt einem Screenreader
    // nicht, dass sie ein Bedienelement ist.
    'widget_stats_show_posts' => 'Beiträge anzeigen',
    // Der Neustart ist eine Sammelaktion und überspringt, was er nicht anfassen
    // darf — er meldet deshalb, was er getan hat, statt nur zu gelingen.
    'widget_stats_retry_done' => ':count neu gestartet',
    'widget_stats_retry_error' => 'Konnte nicht neu starten.',
    // :diff kommt fertig mit Vorzeichen („+3", „−2", „±0").
    'widget_stats_vs_last_week' => ':diff zur Vorwoche',
    // Die Karten der Kennzahlreihe sind Links: nur für Screenreader angehängt,
    // damit die Karte nicht bloß „Wartet auf Freigabe 3" vorliest, sondern sagt,
    // wohin sie führt. Siehe QueueStatsWidget.vue.
    'widget_stats_jump_to_queue' => 'zu „Meine Warteschlange" springen',
    'widget_stats_filter_overview' => 'in „Alle Beiträge" anzeigen',
    'widget_stats_open_manager' => 'im Workflow öffnen',
    // Der entfernbare Chip über „Alle Beiträge", solange eine Karte der
    // Kennzahlreihe die Tabelle filtert. :label ist die Beschriftung der Karte.
    'overview_preset_active' => 'Gefiltert: :label',
    'overview_preset_clear' => 'Filter „:label" entfernen',
    // Statusreihe „workflows_status" — eine Karte je aktivem Workflow. Die
    // Zeile unter dem Workflow-Namen; steht auf jeder Karte, weil die Karten
    // umbrechen dürfen und eine Überschrift über der Reihe dann nur noch über
    // einem Teil von ihnen stünde.
    'widget_status_subline' => 'Inhalte nach Status',
    // Unter der Gesamtzahl in der Mitte des Rings. Kein trans_choice: die Zahl
    // steht darüber, das Wort benennt nur, was gezählt wird.
    'widget_status_entries' => 'Einträge',
    // Statt einer leeren Legende neben dem grauen Ring: die Karte eines
    // Workflows, in dem noch nichts liegt, wird nie ausgeblendet — sie soll
    // dann auch sagen, warum sie leer ist.
    'widget_status_empty' => 'Noch keine Inhalte',
    // Wenn das Nachladen der Beiträge hinter einem Ring-Segment scheitert.
    // Der Stack schließt sich dabei wieder — ein leeres Panel mit Titel wäre ein
    // Versprechen, das das Widget nicht halten kann.
    'widget_status_load_error' => 'Konnte Beiträge nicht laden.',
    'all_posts' => 'Alle Beiträge',
    // Überschrift der großen Liste in einem Lokalisierungs-Manager: dort stehen die
    // Beiträge, die eine Fassung in der Quell-Site haben — nur die lassen sich
    // übersetzen.
    'translatable_posts' => 'Übersetzbare Beiträge',
    'signed_in_as' => 'Angemeldet als :name',

    // Nav / workflow titles (keyed by the workflow's nav slug)
    'nav' => [
        'post-manager' => 'Beitragsmanager',
        'translation-manager' => 'Übersetzungsmanager',
        'plain-language-manager' => 'Einfache Sprache',
        'video-manager' => 'Videomanager',
    ],

    // Columns / filters
    'subject' => 'Titel',
    'state' => 'Status',
    'site' => 'Website',
    'assignees' => 'Zugewiesen',
    'assignee' => 'Zugewiesen an',
    'assigned_to' => 'Verantwortlich:',
    'edit_assignees' => 'Zuweisung bearbeiten',
    'no_assignees' => 'Niemand zugewiesen',
    'assignees_saved' => 'Zuweisung gespeichert',
    'assignees_save_error' => 'Zuweisung konnte nicht gespeichert werden.',
    'search_users' => 'Nutzer suchen…',
    'all_roles' => 'Alle Rollen',
    'all_groups' => 'Alle Gruppen',
    'no_users_found' => 'Keine Nutzer gefunden',
    'more_roles' => '+:count weitere',
    'more_groups' => '+:count weitere',

    // Creating a post
    'new_post' => 'Neuer Beitrag',
    // Eintrag im „Neuer Beitrag"-Menü des Dashboard-Widgets, eine Zeile pro Workflow.
    'new_post_in' => ':label: neuer Beitrag',
    'assign_to' => 'Zuweisen',
    'select_site' => 'Sites wählen',
    // Label der Collection-Auswahl im „Neuer Beitrag"-Modal bei mehreren eigenen Collections.
    // `:collection` kommt aus Statamics eigener Übersetzung (`__('Collection')`,
    // de.json: „Sammlung"), damit hier dasselbe Wort steht wie in der Sidenav.
    'select_collection' => ':collection wählen',
    'post_collection_invalid' => 'Dieser Workflow kann in dieser Sammlung keine Beiträge anlegen.',
    'cancel' => 'Abbrechen',
    'post_created' => 'Beitrag angelegt',
    'post_create_error' => 'Beitrag konnte nicht angelegt werden.',
    // Platzhalter-Titel eines frisch angelegten Beitrags — der Editor überschreibt ihn.
    'untitled_post' => 'Ohne Titel',
    // Das „Neuer Beitrag"-Modal: Titel ist Pflicht, der Bestätigen-Button heißt
    // „Anlegen" — oder trägt das Label der gekoppelten Transition
    // (metadata.create_transition), die direkt nach dem Anlegen läuft.
    'post_title' => 'Titel',
    'post_title_required' => 'Bitte einen Titel angeben.',
    'create' => 'Anlegen',
    // Toast, wenn der Beitrag zwar angelegt wurde, die gekoppelte Transition aber
    // nicht laufen konnte — der Beitrag steht dann im Start-Place.
    'create_transition_skipped' => 'Beitrag angelegt, aber „:label" wurde nicht ausgeführt: :reason',
    'all_sites' => 'Alle Websites',
    'all_states' => 'Alle Status',
    'all_workflows' => 'Alle Workflows',
    'all_assignees' => 'Alle Zugewiesenen',
    'embedded_podcast' => 'Eingebetteter Podcast',

    // Workflow states
    'state_idea' => 'Idee',
    'state_draft' => 'Entwurf',
    'state_in_review' => 'In Prüfung',
    'state_expert_review' => 'Fachliche Prüfung',
    'state_final_approve' => 'Finale Freigabe',
    'state_korrektorat' => 'Korrektorat',
    'state_published' => 'Veröffentlicht',
    'state_pending_revision' => 'Unveröffentlichte Änderungen',
    'revision_status' => 'Revisionsstatus',
    'revision_status_current' => 'Keine offenen Änderungen',
    'state_source_ready' => 'Quelle bereit',
    'state_in_translation' => 'In Übersetzung',
    'state_linguistic_review' => 'Sprachliche Prüfung',
    'state_approved' => 'Freigegeben',
    // Einfache Sprache. Eigene Handles, weil Place-Labels und -Farben global pro
    // Handle aufgelöst werden (first-wins): geteilte Handles würden die Labels der
    // beiden Manager vermischen.
    // Kein Status: der Beitrag ist noch nicht Teil dieses Workflows.
    'state_none' => 'Nicht gestartet',
    'state_plain_ready' => 'Quelle bereit',
    'state_in_plain_language' => 'Übersetzungsentwurf',
    'state_plain_review' => 'Fachliche Prüfung',
    'state_plain_approved' => 'Freigegeben',
    'state_queued' => 'In Warteschlange',
    'state_script_generation' => 'Skripterstellung',
    'state_voiceover' => 'Vertonung',
    'state_rendering' => 'Rendering',
    'state_review' => 'Prüfung',
    'state_done' => 'Fertig',
    'state_failed' => 'Fehlgeschlagen',

    // Workflow actions (transition labels). Handles match transition names
    // across all workflow YAMLs; unknown ones fall back to the YAML label.
    'action_start_draft' => 'Entwurf erstellen',
    'action_submit' => 'Zur Prüfung einreichen',
    'action_request_expert' => 'Zur fachlichen Prüfung',
    'action_approve_expert' => 'Fachlich freigeben',
    'action_final_approve' => 'Final freigeben',
    'action_publish' => 'Veröffentlichen',
    'action_reject' => 'Ablehnen',
    'action_start_translation' => 'Übersetzung starten',
    'action_submit_translation' => 'Übersetzung einreichen',
    'action_pass_linguistic' => 'Sprachlich freigeben',
    'action_approve' => 'Freigeben',
    'action_needs_changes' => 'Änderungen nötig',
    'action_start_plain_language' => 'Einfache Sprache erzeugen',
    'action_regenerate_plain_language' => 'Einfache Sprache neu erzeugen',
    'action_submit_plain_language' => 'Zur fachlichen Prüfung',
    'action_approve_plain_language' => 'Freigeben',
    'action_needs_changes_plain' => 'Änderungen nötig',
    'action_start_generation' => 'Generierung starten',
    'action_generate_voiceover' => 'Voiceover generieren',
    'action_render' => 'Rendern',
    'action_submit_for_review' => 'Zur menschlichen Prüfung',
    'action_fail' => 'Pipeline fehlgeschlagen',

    'source_changed' => 'Die Quelle wurde überarbeitet',
    'source_changed_hint' => 'Die Quelle ist vom :source_at, diese Fassung vom :version_at. Der Inhalt hier ist damit nicht mehr der aktuelle Stand.',
    'source_changed_hint_never' => 'Die Quelle ist vom :source_at. Diese Fassung wurde noch nie freigegeben, ihr Inhalt ist damit nicht der aktuelle Stand.',
    'column_content_updated_at' => 'Inhaltlich aktualisiert',
    // Spalte und Filter der workflowübergreifenden Liste im Dashboard-Widget.
    'column_workflow' => 'Workflow',
    'content_updated_at_instructions' => 'Ab hier zählt die Frist der Alterung. Wird automatisch gesetzt, sobald der Beitrag freigegeben wird — von Hand änderbar nur für Super-Admins.',
    'stale_marker' => 'veraltet',
    'stale_marker_hint' => 'Der Beitrag wurde seit dieser Fassung überarbeitet.',
    'filter_stale' => 'Aktualität',
    'filter_stale_any' => 'Alle',
    'filter_stale_only' => 'Nur mit veralteter Fassung',

    // SLA / Alterung.
    // Die zwei Stufen sind eigene Schritte des Workflows: Place, Transitions,
    // Farbe und Zuweisung pflegt der Autor. Hier steht nur, nach wie vielen Tagen
    // welcher seiner Places angesteuert wird.
    'sla' => 'SLA (Alterung veröffentlichter Beiträge)',
    'sla_intro' => 'Wandert ein Beitrag nach Ablauf der Frist automatisch in einen eigenen Schritt weiter. Läuft stündlich via Scheduler (php artisan schedule:run muss per Cron laufen).',
    'sla_needs_update_days' => 'Erste Stufe nach X Tagen',
    'sla_needs_update_place' => 'Erste Stufe: Schritt',
    'sla_outdated_days' => 'Zweite Stufe nach X Tagen',
    'sla_outdated_place' => 'Zweite Stufe: Schritt',
    'sla_needs_update_assign' => 'Erste Stufe: zuständig',
    'sla_outdated_assign' => 'Zweite Stufe: zuständig',
    'sla_assign_keep' => 'Unverändert',
    'sla_assign_handles_none' => 'Auswählen …',
    'sla_placeholder_disabled' => 'leer = deaktiviert',
    'sla_place_none' => 'Kein Schritt (Stufe aus)',
    'sla_hint' => '365 ≈ 12 Monate, 540 ≈ 18 Monate, 0 = beim nächsten Scheduler-Lauf. Tage und Schritt gehören zusammen: nur mit beidem ist eine Stufe aktiv.',
    'sla_place_hint' => 'Gibt es eine Transition dorthin, wird sie ausgeführt — mit ihrer Zuweisung, ihrer Mail und ihrem Recht. Fehlt sie, wird der Beitrag direkt verschoben und die Aktionen des Schritts laufen. Ohne eigene Zuständigkeit bleiben die bisherigen Zuständigen. Ein Schritt ohne „Veröffentlichen erlaubt" setzt den Beitrag auf Entwurf, und dann folgen die Übersetzungen (siehe unten).',
    'sla_clock_hint' => 'Die Frist läuft ab dem Zeitpunkt, an dem der Beitrag zuletzt freigegeben wurde — nicht ab dem letzten Speichern.',
    'sla_cron_hint' => 'Setzt voraus, dass „php artisan schedule:run" per Cron im Projekt läuft (siehe README → Scheduler).',

    // Reaktion einer Fassung darauf, dass ihre Quelle im Entwurf liegt.
    'source_draft' => 'Wenn die Quelle im Entwurf liegt',
    'source_draft_intro' => 'Geht der Beitrag auf Entwurf — durch die Alterung oder von Hand —, geht diese Fassung mit: auf Entwurf und zurück in den gewählten Schritt.',
    'source_draft_place' => 'Zurück in Schritt',
    'source_draft_place_none' => 'Nichts tun',
    'source_draft_hint' => 'Nur Schritte ohne „Veröffentlichen erlaubt" — die Fassung geht offline. Zurück kommt sie nicht automatisch: sie durchläuft ihren Weg neu.',

    // Einstellungen-Editor (nur Super-Admins)
    'settings' => 'Einstellungen',
    'queue' => 'Warteschlange',
    'collections' => 'Collections',
    'settings_index_title' => 'Workflows',
    'handle' => 'Handle',
    'settings_index_intro' => 'Alle in diesem Projekt definierten Workflows. Klick einen an, um seine Places, Transitions und Metadaten zu bearbeiten.',
    'settings_index_empty' => 'Noch keine Workflows angelegt. Lege eine YAML unter resources/workflows/ an oder starte mit php artisan workflows:install.',
    'install_workflow' => 'Workflow installieren',
    'install_intro' => 'Fügt einen der mitgelieferten Workflow-Typen dem Projekt hinzu. Danach gehört die YAML dem Projekt — Collections, Places und Transitions bearbeitest du in den Einstellungen des Workflows.',
    'install_type_label' => 'Workflow-Typ',
    
    // Langbeschreibung der mitgelieferten Beispiele im Install-Modal, je Type-Handle.
    // Fällt auf `metadata.label` aus der YAML zurück, wenn ein Projekt ein eigenes
    // Beispiel ergänzt, für das es hier keinen Schlüssel gibt.
    'install_description_postmanager' => 'Beitrags-Aktualisierung mit fachlicher Freigabe',
    'install_description_translation' => 'Übersetzung mit sprachlicher und fachlicher Prüfung',
    'install_description_plainlanguage' => 'Einfache Sprache mit fachlicher Prüfung',
    'install_collections_label' => 'Collections',
    'install_no_collections' => 'Keine Auswahl',
    'install_no_project_collections' => 'Dieses Projekt hat keine Statamic-Collections. Lege welche an und komm dann hierher zurück.',
    'install_backfill_label' => 'Bestehende Einträge einbeziehen',
    'install_backfill_hint' => 'Trägt alle vorhandenen Einträge der ausgewählten Collections in den Initial- bzw. Fertig-Zustand des Workflows ein. Optional — kann später über workflows:seed-states nachgeholt werden.',
    'install_run' => 'Installieren',
    'install_success' => 'Workflow installiert.',
    'install_error' => 'Workflow konnte nicht installiert werden.',
    'install_no_types' => 'Kein Workflow-Typ im Addon verfügbar.',
    'install_unknown_type' => 'Workflow-Typ [:type] ist nicht verfügbar.',
    'install_already_installed' => 'Workflow-Typ [:type] ist bereits installiert. Bearbeite ihn in seinen Einstellungen.',
    'install_all_installed' => 'Alle mitgelieferten Workflow-Typen sind schon installiert.',

    // Aktiv/Inaktiv und Löschen je Workflow (Settings-Übersicht)
    'status' => 'Status',
    'workflow_active' => 'Aktiv',
    'workflow_inactive' => 'Inaktiv',
    'workflow_activate' => 'Aktivieren',
    'workflow_deactivate' => 'Deaktivieren',
    'workflow_activated' => 'Workflow aktiviert.',
    'workflow_deactivated' => 'Workflow deaktiviert.',
    'workflow_active_error' => 'Status konnte nicht geändert werden.',
    'workflow_deactivate_hint' => 'Ein inaktiver Workflow verschwindet aus dem Menü, den Manager-Seiten und dem Beitrags-Editor. Definition und Verlauf bleiben erhalten.',
    // Status nachtragen je Workflow (CP-Gegenstück zu workflows:seed-states)
    'seed_states' => 'Status nachtragen',
    'states_seeded_none' => 'Alle Einträge haben schon einen Status.',
    'states_seed_error' => 'Status konnten nicht nachgetragen werden.',
    'states_seed_inactive' => 'Dieser Workflow ist deaktiviert — aktiviere ihn, bevor du Status nachträgst.',
    'states_seeded_no_done_place' => 'Dieser Workflow hat keinen Fertig-Place: alle Einträge sind im Initial-Status gelandet. Setze metadata.seed_published_place in den Einstellungen.',

    'workflow_delete' => 'Löschen',
    'workflow_delete_title' => 'Workflow löschen',
    'workflow_delete_confirm' => 'Die Definition von „:workflow" wird aus resources/workflows/ entfernt. Status, Zuweisungen, Verlauf und Kommentare bleiben in der Datenbank — bei einer erneuten Installation sind sie wieder da.',
    'workflow_deleted' => 'Workflow gelöscht.',
    'workflow_delete_error' => 'Workflow konnte nicht gelöscht werden.',
    'settings_intro' => 'Places, Transitions und Metadaten bearbeiten. Änderungen werden nach resources/workflows/.yaml geschrieben.',
    'settings_saved' => 'Workflow gespeichert.',
    'settings_dangling_handles' => 'Bitte gib für jede neue Place/Transition einen Handle oder ein Label an, bevor du speicherst.',
    'settings_save_error' => 'Workflow konnte nicht gespeichert werden.',
    'settings_version_conflict' => 'Der Workflow wurde inzwischen geändert – von jemand anderem oder direkt in der Datei. Neu laden, um die aktuelle Fassung zu sehen, oder bewusst überschreiben.',
    'settings_label_hint_intro' => 'Ein eigener Name pro Sprache hat Vorrang. Ein leeres Feld zeigt grau, wie der Place ohne ihn heißt: die Übersetzung des Addons, anpassbar in',
    'settings_label_hint_with_key' => 'unter dem Schlüssel',
    'back_to_queue' => 'Zurück zur Queue',
    'metadata' => 'Metadaten',
    'nav_label' => 'Nav-Titel',
    'nav_label_hint' => 'Gilt so, wie es hier steht — in jeder CP-Sprache. Leer lassen, damit der Titel aus den Übersetzungen kommt (Schlüssel: nav.<slug>) und der CP-Sprache folgt.',
    'nav_icon' => 'Nav-Icon',
    'nav_order' => 'Nav-Reihenfolge',
    
    // Feld-Labels im Definitions-Editor. Bilden die YAML-Struktur ab, tragen aber
    // einen sprechenden Titel statt des rohen Schlüsselnamens.
    'settings_field_nav_slug' => 'Nav-Slug',
    'settings_field_default_assignee_group' => 'Standard-Zuweisungsgruppe',
    'default_assignee_group_none' => 'Keine',
    'settings_field_assign_creator' => 'Ersteller automatisch zuweisen',
    'settings_field_initial_place' => 'Startzustand',
    // Ausrichtung der Fortschrittsleiste auf der Detailseite. Entscheidet beides:
    // Layout der Leiste UND ihre Position — waagerecht über dem Inhalt, senkrecht
    // in einer Spalte links davon. Siehe Workflow\ProgressSteps::ORIENTATION.
    'settings_field_progress_orientation' => 'Fortschrittsleiste',
    'settings_field_progress_orientation_hint' => 'Bestimmt Ausrichtung und Position: waagerecht steht die Leiste über dem Inhalt der Zusammenfassung, senkrecht in einer Spalte links davon. Auf schmalen Fenstern läuft sie immer senkrecht.',
    'progress_orientation_horizontal' => 'Waagerecht (über dem Inhalt)',
    'progress_orientation_vertical' => 'Senkrecht (links neben dem Inhalt)',
    'settings_field_color' => 'Farbe',
    'settings_field_allow_publish' => 'Veröffentlichen erlaubt',
    // Nur Darstellung: der Place wird in der Fortschrittsleiste der Summary nicht
    // als Stufe auf dem Weg gezeichnet, sondern versetzt an seinem Vorgänger —
    // und nur dann, wenn ein Beitrag tatsächlich darin steht. Siehe ProgressSteps.
    'settings_field_branch' => 'Abzweigung',
    'settings_field_branch_hint' => 'Kein Schritt auf dem Weg, sondern ein Abstecher davon (z. B. ein Fehlerfall). Erscheint in der Fortschrittsleiste versetzt am vorherigen Schritt und nur, solange ein Beitrag darin steht.',
    'settings_field_from' => 'Von',
    'settings_field_to' => 'Nach',
    'settings_field_variant' => 'Button-Variante',
    'places' => 'Places',
    'no_places' => 'Keine Places definiert.',
    'add_place' => 'Place hinzufügen',
    'move_up' => 'Nach oben',
    'move_down' => 'Nach unten',
    'color_default' => 'Standard',
    'required_group_none' => 'Keine Gruppe erforderlich',
    'required_by' => 'Ausführen darf',
    'required_by_none' => 'Alle Zuständigen',
    'required_by_hint' => 'Zusätzlich zur Zuständigkeit.',
    'handle_placeholder' => 'z. B. draft, review',
    'label_placeholder' => 'Anzeigename',
    'transitions' => 'Transitions',
    'no_transitions' => 'Keine Transitions definiert.',

    // Actions einer Transition: Schritte zwischen Klick und Anwenden. Zwei Typen
    // öffnen einen Dialog, und davon passt nur einer hinein — „target_missing" ist
    // kein Dialog, sondern eine stille Sperre und zählt nicht gegen dieses Limit.
    'transition_actions' => 'Aktionen',
    'action_add' => 'Action hinzufügen',
    'from_follows_initial_place' => 'Folgt dem Start-Place — die erste Transition führt aus ihm heraus.',
    'action_type_user_assignment' => 'Benutzerzuweisung',
    'action_type_target_missing' => 'Sperre: Site muss noch fehlen',
    'action_target_missing_site_none' => 'Site wählen',
    // Kein Dialog, kein Konfigurationsfeld — die bloße Anwesenheit ist die Aussage
    // „diese Transition erzeugt die Ziel-Version". Höchstens einmal je Workflow,
    // in jedem Lokalisierungs-Workflow wählbar. Im Übersetzungsmanager hängt die
    // Sprachauswahl daran: ohne die Action sitzt sie an der ersten Transition,
    // mit ihr an der gewählten.
    'action_type_start_localization' => 'Erzeugt die Ziel-Fassung (mit Sprachauswahl)',
    // Ebenfalls keine Konfiguration, aber kein Zähl-Limit — kann auf mehreren
    // Transitions gleichzeitig stehen (aus der Prüfung, aus der Freigabe). Nur im
    // Einfache-Sprache-Manager wählbar, siehe canResubmitPlainLanguage().
    'action_type_plain_language_resubmit' => 'Einfache Sprache neu erzeugen',
    // EINE Action, beide Scopes, ein Label — deshalb liest die Place-Karte
    // denselben Key (siehe SHARED_ACTION_TYPES in WorkflowSettings.vue). Anders
    // als die drei Markierungen darüber TUT sie etwas: sie verschickt eine Mail.
    // Höchstens einmal je Transition und je Place (der Writer lehnt eine zweite
    // ab). Siehe Workflow\MailAction.
    'action_type_mail' => 'Mail verschicken',
    'action_mail_hint' => 'Nach der Transition bekommt jeder, der dem Beitrag dann zugewiesen ist, eine Mail mit einem Button zur Workflow-Übersicht des Beitrags — in seiner eigenen CP-Sprache.',
    // Derselbe Text wäre hier falsch: an einem Place ist der Auslöser nicht
    // „dieser Schritt lief", sondern „eine Fassung ist hier angekommen" — auch
    // dann, wenn niemand geklickt hat. Genau das ist der Fall bei der Einfachen
    // Sprache: der Poll-Befehl setzt den Status selbst, ohne Transition.
    'action_mail_place_hint' => 'Sobald eine Fassung in diesem Status ankommt, bekommt jeder, der ihr dann zugewiesen ist, eine Mail — auch wenn niemand geklickt hat (etwa wenn die Einfache Sprache fertig geworden ist). Brachte die Transition selbst schon eine Mail mit, entfällt diese hier, damit nicht zwei für einen Klick rausgehen.',
    // Welche Mail verschickt wird — die einzige Einstellung dieser Action. Der
    // Text steckt nicht in der Action, sondern in den Sprachdateien; siehe
    // Mail\MailTypes.
    'action_mail_type_label' => 'Mail-Typ',
    'action_only_one_modal' => 'Es lässt sich nur ein Dialog vor der Transition zeigen.',

    // Benutzerzuweisung: wer den Beitrag als Nächstes übernimmt. Der Modus sagt,
    // WER entscheidet — im manuellen öffnet sich der Auswahldialog, im
    // automatischen werden alle Treffer zugewiesen. Wer überhaupt infrage kommt,
    // sagen in beiden Fällen dieselben Kriterien.
    'assignment_mode' => 'Zuweisung',
    'assignment_mode_manual' => 'Manuell auswählen …',
    'assignment_mode_automatic' => 'Automatisch zuweisen …',
    'assignment_match_all' => 'wenn alle folgenden Bedingungen erfüllt sind',
    'assignment_match_any' => 'wenn eine der folgenden Bedingungen erfüllt ist',
    'assignment_criteria_hint' => 'Du kannst jeden „Handle“ eingeben und bist nicht auf die Optionen im Dropdown beschränkt.',
    'assignment_add_group' => 'Gruppe hinzufügen',
    'assignment_criterion_add' => 'Kriterium hinzufügen',
    'assignment_group_hint' => 'Eine Gruppe verknüpft ihre Kriterien untereinander — so wird „in der Gruppe UND (Kategorie ODER Entität)" ausdrückbar. Eine Ebene tief.',
    'assignment_add_criterion' => 'Bedingung hinzufügen',
    'assignment_criterion_placeholder' => 'Kriterium',
    'assignment_value_placeholder' => 'Wert',
    'assignment_criteria_none_manual' => 'Keine Bedingung — alle Nutzer stehen zur Auswahl.',
    'assignment_criteria_none_automatic' => 'Ohne Bedingung lässt sich nicht automatisch zuweisen.',
    'assignment_criterion_group' => 'Gruppe',
    'assignment_criterion_role' => 'Rolle',
    // Labels der in config/workflows.php ausgelieferten Zuweisungskriterien. Ein
    // Projekt kann dort auch einen eigenen Schlüssel oder einen festen Text setzen.
    'assignment_criterion_category' => 'Kategorie',
    'assignment_criterion_responsibility' => 'Zuständigkeit',
    'assignment_criterion_post_category' => 'Kategorie des Beitrags',
    'assignment_operator_is' => 'ist gleich',
    'assignment_operator_is_not' => 'ist nicht gleich',
    'assignment_operator_matches_entry' => 'stimmt mit dem Beitrag überein',
    'add_transition' => 'Transition hinzufügen',
    'actions' => 'Aktionen',
    'confirm_delete_place' => 'Place [:handle] löschen?',
    'confirm_delete_transition' => 'Transition [:handle] löschen?',
    'migrate_place_title' => 'Place löschen — Beiträge umziehen',
    'migrate_place_prompt' => ':count Beiträge sind aktuell im Place :place. Wähle einen Ziel-Place, in den sie vor dem Löschen migriert werden.',
    'migrate_target' => 'Verschieben nach',
    'select_place' => 'Place wählen…',
    'confirm_delete' => 'Löschen',
    'place_deleted' => 'Place [:place] gelöscht.',
    'place_delete_error' => 'Place konnte nicht gelöscht werden.',
    'place_not_found' => 'Place nicht gefunden.',
    'place_delete_initial' => 'Das initial_place kann nicht gelöscht werden. Ändere zuerst initial_place.',
    'migrate_target_invalid' => 'Ungültiges Migrationsziel.',
    'place_delete_needs_migration' => 'Es gibt noch Beiträge in diesem Place. Bitte migrate_to setzen.',
    'event_note_definition_saved' => 'Workflow-Definition über die Einstellungen bearbeitet.',
    'event_note_place_deleted' => 'Place [:place] über die Einstellungen entfernt.',

    // Collections-Auswahl im Einstellungen-Editor
    'collections_intro' => 'Collections, deren Einträge dieser Workflow verwaltet. Einträge dieser Collections erhalten das Workflow-Feld, ihre Veröffentlichung wird über den Workflow-Status gesteuert und sie erscheinen auf der Manager-Seite.',
    'select_collections' => 'Collections wählen',
    'collections_owned_by' => ':collection wird vom Workflow „:workflow" verwaltet. Dieser Workflow greift dort nur auf Einträge zu, die ihm ausdrücklich zugeordnet wurden.',
    'collections_hint' => 'Verwaltet ein Workflow mehrere Collections, wird „Alle Beiträge" pro Collection in Tabs aufgeteilt. Neu hinzugefügte Collections werden beim Speichern automatisch mit Status befüllt; nachholen lässt sich das jederzeit über „Status nachtragen" in der Workflow-Übersicht oder „php artisan workflows:seed-states".',
    'seed_published_place' => 'Fertig-Place (für Backfill)',
    'seed_published_place_hint' => 'Status, den beim Nachtragen die Einträge erhalten, deren Arbeit schon getan ist: bei einem Lokalisierungs-Workflow alle, für die die Sites dieses Managers bereits existieren (Einfache Sprache: die Ziel-Site; Übersetzung: irgendeine Site außer der Quelle und den von anderen Managern verwalteten) — bei allen anderen die veröffentlichten. Nötig, wenn der Workflow keinen Place mit allow_publish hat (der Übersetzungs-Workflow endet z. B. in „Freigegeben"). Leer = alle Einträge starten im initial_place.',
    'seed_published_place_none' => 'kein Fertig-Place',
    'post_creator_groups' => 'Darf Beiträge anlegen',
    'post_creator_groups_none' => 'nur Super-Admins',
    'post_creator_groups_hint' => 'Nutzergruppen, die auf der Manager-Seite dieses Workflows den „Neuer Beitrag"-Button sehen. Super-Admins dürfen immer. Leer = ausschließlich Super-Admins.',
    'post_creator_groups_hint_fallback' => 'Nutzergruppen, die auf der Manager-Seite dieses Workflows den „Neuer Beitrag"-Button sehen. Super-Admins dürfen immer. Leer = es greift die Projekt-Konfiguration (:groups).',
    // Die Transition, die der Bestätigen-Button des „Neuer Beitrag"-Modals nach
    // dem Anlegen ausführt — der „Workflow-CTA". Siehe WorkflowController::storePost().
    'create_transition' => 'Workflow-Aktion beim Anlegen',
    'create_transition_none' => 'keine — nur anlegen',
    'create_transition_hint' => 'Der Bestätigen-Button des „Neuer Beitrag"-Dialogs trägt dann das Label dieser Transition und führt sie direkt nach dem Anlegen aus — z. B. „Entwurf erstellen", das den Beitrag den Prüfern zuweist und sie benachrichtigt. Nur Transitionen, die vom Start-Place ausgehen. Verlangt die Transition eine Zuweisung von Hand, fragt der Dialog die Personen gleich mit ab. Kann die Transition nicht laufen (keine Kandidaten, fehlende Berechtigung), wird der Beitrag trotzdem angelegt und bleibt im Start-Place; der Hinweis nennt den Grund. Leer = der Button heißt „Anlegen" und tut nur das.',
    'assigner_groups' => 'Darf Verantwortliche zuweisen',
    'assigner_groups_none' => 'nur Super-Admins',
    'assigner_groups_hint' => 'Nutzergruppen, die festlegen dürfen, wer für einen Beitrag verantwortlich ist. Super-Admins dürfen immer. Leer = ausschließlich Super-Admins. Nicht zu verwechseln mit „default_assignee_group": das sagt, wem ein Beitrag zugewiesen wird, dies hier, wer zuweisen darf.',
    'assigner_groups_hint_fallback' => 'Nutzergruppen, die festlegen dürfen, wer für einen Beitrag verantwortlich ist. Super-Admins dürfen immer. Leer = es greift die Projekt-Konfiguration (:groups).',
    // Übersetzungs-APIs — nur im Settings-Editor eines Lokalisierungs-Workflows
    // sichtbar, und rein anzeigend: Schlüssel und URLs stehen in der .env, nicht
    // in den Settings. Die Variablennamen sagen selbst, zu welchem Dienst eine
    // Zeile gehört, deshalb braucht die Sektion keinen Erklärtext.
    'translation_apis' => 'Übersetzungs-APIs',
    'deepl_plan_free' => 'Free-Endpoint (api-free.deepl.com)',
    'deepl_plan_pro' => 'Pro-Endpoint (api.deepl.com)',
    'env_set' => 'ist gesetzt',
    'env_missing' => 'fehlt in der .env',
    'copy_env_name' => ':name kopieren',
    'copied' => 'Kopiert',
    'copy_failed' => 'Kopieren nicht möglich — bitte von Hand markieren.',

    // Mailer-Hinweis an der `mail`-Action. Zeigt bewusst KEINE
    // Zugangsdaten (siehe WorkflowController::mailStatus()) — nur, ob überhaupt
    // etwas rausgeht. `log` ist Laravels Standard und stellt nichts zu; genau das
    // ist der Fall, den man hier sehen soll.
    'mailer_current' => 'Mail-Versand über',
    'mailer_delivering' => 'wird zugestellt',
    'mailer_not_delivering' => 'stellt nichts zu — die Mail wird nur protokolliert bzw. verworfen',
    'mailer_from' => 'Absender: :address',
    'mailer_from_missing' => 'Kein Absender gesetzt (MAIL_FROM_ADDRESS)',
    // Überschriften der workflow-spezifischen Sektion. Welche erscheint, entscheidet
    // `plain_language_site` — siehe plain_language_site_hint.
    'plain_language_settings' => 'Einfache Sprache',
    'translation_settings' => 'Übersetzung',
    'plain_language_site' => 'Ziel-Site',
    'plain_language_site_none' => 'keine — kein Einfache-Sprache-Manager mehr',
    'plain_language_site_hint' => 'Die Site, in die dieser Manager schreibt. Weil sie feststeht, fragt „Einfache Sprache erzeugen" nach keiner Sprache, und die Texte gehen an den Dienst für Einfache Sprache statt an DeepL; für jeden anderen Manager ist diese Site kein Ziel mehr. Leeren macht aus diesem Workflow einen gewöhnlichen Übersetzungsmanager mit Sprachauswahl — nur nötig, wenn eine Definition den Wert versehentlich trägt.',
    'plain_language_completed_place' => 'Ziel-Place nach Job-Abschluss',
    'plain_language_completed_place_none' => 'keiner — bleibt liegen bis zum manuellen Klick',
    'plain_language_completed_place_hint' => 'Sobald ein Umschreib-Auftrag erfolgreich abgeschlossen ist (Text übernommen, keine Fehler), setzt der Scheduler den Beitrag direkt in diesen Place — ohne über eine Transition zu laufen, also ohne Gruppen-Prüfung, Assignee-Reset oder Publish-Gate. Leer lässt den Beitrag wie bisher liegen, bis jemand „Zur fachlichen Prüfung" manuell klickt. Bei einem fehlgeschlagenen Auftrag greift das nie — dafür gibt es den Fehler-Place darunter.',
    'plain_language_failed_place' => 'Ziel-Place bei fehlgeschlagener Übersetzung',
    'plain_language_failed_place_none' => 'keiner — Kopie wird gelöscht',
    'plain_language_failed_place_hint' => 'Wohin der Beitrag wandert, wenn die Übersetzung in Einfache Sprache endgültig scheitert — sei es, weil der Dienst den Auftrag ablehnt, ihn als fehlgeschlagen meldet, ein unbrauchbares Ergebnis liefert oder nach der Abfrage-Grenze nicht fertig geworden ist. Wie beim Abschluss-Place ohne Transition, also ohne Gruppen-Prüfung. Gib diesem Place eine Transition mit der Aktion „Einfache Sprache neu erzeugen", dann ist ein zweiter Versuch ein Klick. Leer löscht stattdessen die unübersetzte Kopie — dann bleibt vom Fehlschlag nichts sichtbar außer einem flüchtigen Hinweis.',
    'translation_source_site' => 'Quell-Site',
    'translation_source_site_none' => 'Main-Site (Standard)',
    'translation_source_site_hint' => 'Die Version, aus der übersetzt wird. Leer = die Main-Site, also die Fassung, in der redaktionell gearbeitet wird (bei Beiträgen ohne Main-Site-Version deren Ursprungsversion). Wählt man hier z. B. Einfache Sprache, entstehen ab dann alle Übersetzungen aus der Einfache-Sprache-Fassung und nicht mehr aus der deutschen — es gibt genau eine Quelle pro Manager. Fehlt einem Beitrag die gewählte Version, bleibt der Start gesperrt und nennt den Grund; stillschweigend auf eine andere Quelle ausweichen tut der Manager nie.',
    'translation_source_pick' => 'Quelle beim Übersetzen wählbar',
    'translation_source_pick_hint' => 'Standardmäßig aus: die eingestellte Quell-Site gilt verbindlich — sonst wäre sie ein Vorschlag und keine Einstellung. Eingeschaltet bietet der Sprach-Dialog neben der Ziel-Sprache auch die Quelle an, vorbelegt mit der Einstellung und begrenzt auf die Fassungen, die der Beitrag tatsächlich hat. Für Projekte, die pro Übersetzung entscheiden wollen; im Einfache-Sprache-Manager nicht möglich, weil der nichts fragt.',
    // Regeln, wer die Quellspalte der Detailansicht wie sieht — siehe
    // Workflow\SourceViewRules. Darstellungsregel, keine Berechtigung: Super-Admins
    // sind nicht ausgenommen.
    'translation_source_view' => 'Quellansicht je Zielgruppe',
    'translation_source_view_hint' => 'Wem die Detailansicht die Quelle anders zeigt als in der Gegenüberstellung. „Nur Übersetzung": die Quelle wird gar nicht ausgeliefert. „Quelle eingeklappt": die Seite startet einspaltig, ein Button blendet die Quelle ein. Trifft mehr als eine Regel, gilt die strengere. Wen keine Regel trifft, sieht beides nebeneinander. Gilt auch für Super-Admins — es ist eine Darstellung, kein Recht.',
    'translation_source_view_add' => 'Regel hinzufügen',
    'translation_source_view_empty' => 'Keine Regeln — alle sehen Quelle und Übersetzung nebeneinander.',
    'translation_source_view_mode_hidden' => 'Nur Übersetzung',
    'translation_source_view_mode_collapsed' => 'Quelle eingeklappt',
    'translation_source_view_by_user' => 'Benutzer',
    'translation_source_view_handles_none' => 'niemand — Regel wird nicht gespeichert',
    'translation_source_view_remove' => 'Regel entfernen',
    // Actions eines Places: was gilt, solange eine Fassung hier steht — und mit
    // `mail` eines, das beim ANKOMMEN passiert. `action_type_mail` steht oben bei
    // den Transition-Typen, weil es dieselbe Action ist.
    // — kein Klick-Moment wie bei Transitions, deshalb eine eigene, kleinere
    // Typ-Menge (siehe Workflow\PlaceActions).
    'place_actions' => 'Aktionen',
    'place_action_type_pending_rewrite_spinner' => 'Ladeanzeige während Umschreibung',
    'place_action_type_refresh_interval' => 'Aktualisieren nach X Minuten (mit Modal)',
    'action_refresh_interval_minutes_placeholder' => 'Minuten',
    'place_action_type_reminder' => 'Erinnerung alle X Tage (Mail)',
    'place_action_type_auto_transition' => 'Automatisch weiter nach X Tagen',
    'action_auto_transition_days_label' => 'Nach X Tagen',
    'action_auto_transition_transition_label' => 'Transition',
    'action_auto_transition_hint' => 'Steht eine Fassung am Stichtag (Ankunftstag + X Tage) noch in diesem Status, wird die gewählte Transition automatisch ausgeführt — mit ihrer Zuweisung und Mail, ohne Auslöser. Geprüft wird einmal täglich (:time Uhr). Die Mails in diesem Status nennen den Stichtag.',
    'action_reminder_every_days_label' => 'Alle X Tage',
    'action_reminder_every_days_placeholder' => 'Tage',
    'action_reminder_hint' => 'Solange eine Fassung in diesem Status steht, bekommt jeder, der ihr zugewiesen ist, alle X Tage eine Erinnerung — die erste X Tage nach dem Ankommen. Geprüft wird einmal täglich (:time Uhr); sobald die Fassung weiterzieht, hört es auf.',
    'refresh_modal_title' => 'Neuer Inhalt verfügbar',
    'refresh_modal_body' => 'Seit dem Laden dieser Seite hat sich etwas geändert — jetzt aktualisieren, um die neue Fassung zu sehen?',
    'refresh_modal_later' => 'Später',
    'refresh_modal_confirm' => 'Jetzt aktualisieren',
    'states_seeded' => 'Status für :count vorhandene Einträge nachgetragen.',
    'collection_unknown' => 'Unbekannte Collection [:collection].',
    'collection_removal_needs_confirmation' => 'Beim Entfernen einer Collection gehen Workflow-Daten verloren. Bitte bestätigen.',
    'collection_removal_title' => 'Collection entfernen — Workflow-Daten löschen',
    'collection_removal_prompt' => 'Du entfernst :collections aus diesem Workflow. :count Einträge fallen damit aus dem Workflow.',
    'collection_removal_warning' => 'Status, Zuweisungen, Historie und Kommentare dieser Einträge werden gelöscht und lassen sich nicht wiederherstellen. Die Einträge selbst bleiben unverändert.',
    'confirm_collection_removal' => 'Entfernen und Daten löschen',
    'event_note_collections_removed' => 'Collections [:collections] über die Einstellungen entfernt — Workflow-Daten von :count Einträgen gelöscht.',

    // Die Mail-Typen, die die `mail`-Action schicken kann — die Beschriftungen im
    // Auswahlfeld des Editors. Ein eigener Typ aus `config/workflows.php →
    // mail_types` bringt sein Label als `mail_type_<handle>` mit.
    'mail_type_assigned' => 'Beitrag zugewiesen',
    'mail_type_plain_language_ready' => 'Einfache Sprache ist fertig',
    'mail_type_sla' => 'Aktualisierung fällig',
    'mail_type_reminder' => 'Erinnerung: wartet noch',

    // Texte der Mails. Ein Typ = ein Block `mail_<typ>_*`; gerendert in der
    // Sprache des EMPFÄNGERS, nicht des Auslösers — siehe Mail\WorkflowNotification.
    // Jeder Key, den ein Typ nicht selbst setzt, wird von `assigned` geerbt
    // (Mail\MailTypes::text()), deshalb ist `assigned` der einzige vollständige
    // Block hier.
    //
    // DREI REGELN FÜR JEDEN NEUEN TEXT HIER, und alle drei aus demselben Grund —
    // eine Mail geht an wen auch immer gerade zugewiesen ist, und das kann jede
    // Rolle im Haus sein:
    //
    //   1. Keine Anrede und kein Name. Es gibt deshalb auch keinen
    //      `greeting`-Key mehr; `recipientName` steht einem eigenen Template
    //      weiterhin zur Verfügung (siehe Mail\WorkflowNotification::defaults()).
    //   2. Kein Du und kein Sie. Passiv oder Infinitiv statt Anrede —
    //      „ist eingetragen", „kann gegengelesen werden", „Beitrag öffnen".
    //      EINE AUSNAHME, gewollt: `mail_sla_intro` sagt „Sie" — die Bitte um
    //      eine Jahresfreigabe geht an Autor:innen, und die Redaktion wollte sie
    //      als Ansprache. Keine Vorlage für die anderen Blöcke.
    //   3. Keine Fachbegriffe der Mechanik. „Workflow", „Transition", „Place"
    //      sagen dem Leser nichts; `:workflow` ist ohnehin das Nav-Label des
    //      Managers („Einfache Sprache“) und trägt sich allein.
    'mail_assigned_subject' => 'Zur Bearbeitung: :title',
    'mail_assigned_heading' => 'Ein Beitrag wartet auf Bearbeitung',
    'mail_assigned_intro' => 'Der Beitrag „:title“ ist in „:workflow“ zur Bearbeitung eingetragen.',
    'mail_assigned_field_state' => 'Status',
    'mail_assigned_field_actor' => 'Ausgelöst von',
    'mail_assigned_field_reminder' => 'Erinnerung',
    'mail_assigned_field_entered' => 'Eingegangen am',
    'mail_assigned_button' => 'Beitrag öffnen',
    'mail_assigned_outro' => 'Diese Nachricht wurde automatisch verschickt.',
    'mail_assigned_progress' => 'Schritt :position von :total',

    // „Die Einfache Sprache ist fertig." Kein `field_actor` eigens: den Anlass hat
    // niemand ausgelöst (der Scheduler schreibt den Text ein), die Zeile fällt
    // dann von selbst weg. `:workflow` steht hier bewusst nicht im Satz — wer die
    // Mail liest, weiß, worum es geht.
    'mail_plain_language_ready_subject' => 'Einfache Sprache ist fertig: :title',
    'mail_plain_language_ready_heading' => 'Die Einfache Sprache ist fertig',
    'mail_plain_language_ready_intro' => 'Für „:title“ liegt die Fassung in Einfacher Sprache jetzt vor und kann gegengelesen werden.',
    'mail_plain_language_ready_button' => 'Einfache Sprache ansehen',

    // „Dieser Beitrag ist alt geworden." EIN Block für BEIDE Stufen des
    // `sla`-Blocks, `needs_update` und `outdated` — welche der beiden es ist, sagt
    // die Status-Zeile der Mail (`field_state`, gefüllt aus dem Label des Places),
    // genau wie `assigned` mit einem Block jeden Place jedes Workflows bedient.
    //
    // Deshalb nennt der Satz WEDER eine Stufe NOCH eine Tageszahl: er muss am Tag
    // 320 genauso stimmen wie am Tag 900, und eine Mail, die „seit 320 Tagen“
    // behauptet, wird falsch, sobald jemand die Frist ändert. `:updated` darf
    // stehen — das ist der STAND des Inhalts (ContentTimestamp), nicht die Frist,
    // und der stimmt unabhängig davon, wann jemand die Mail liest. Fehlt er
    // (Beitrag ohne jedes Datum), greift `intro_undated`.
    //
    // Kein `field_actor` eigens: die Frist läuft ab, ohne dass jemand etwas
    // anklickt (TransitionAgedPostsCommand setzt den Beitrag um), die Zeile fällt
    // dann von selbst weg.
    'mail_sla_subject' => 'Zur Aktualisierung: :title',
    'mail_sla_heading' => 'Ein Beitrag ist zu aktualisieren',
    'mail_sla_intro' => 'Der Beitrag „:title“ wurde seit dem :updated nicht mehr aktualisiert. Vielen Dank, dass Sie den Beitrag inhaltlich prüfen und ihn zur Überarbeitung kommentieren oder für die Veröffentlichung für ein weiteres Jahr freigeben.',
    'mail_sla_intro_undated' => 'Der Beitrag „:title“ wurde seit längerer Zeit nicht mehr aktualisiert. Vielen Dank, dass Sie den Beitrag inhaltlich prüfen und ihn zur Überarbeitung kommentieren oder für die Veröffentlichung für ein weiteres Jahr freigeben.',
    'mail_sla_button' => 'Beitrag prüfen',

    // „Dieser Beitrag wartet noch." Die Mail der `reminder`-Action eines Places,
    // alle X Tage erneut, solange der Beitrag dort steht. Anders als bei `sla`
    // darf hier eine Tageszahl stehen: `:days` zählt der Scheduler ab dem
    // Ankommen in genau dem Status, den die Status-Zeile nennt — sie stimmt also.
    'mail_reminder_subject' => 'Erinnerung: :title',
    'mail_reminder_heading' => 'Ein Beitrag wartet noch auf Bearbeitung',
    'mail_reminder_intro' => 'Der Beitrag „:title“ steht in „:workflow“ seit :days Tagen im selben Status und wartet auf Bearbeitung.',
    // Die Zeile „Erinnerung" in jeder Mail, deren Status eine `reminder`-Action
    // trägt (Mail\WorkflowNotification::reminder()). Kein Du/Sie, siehe oben.
    'mail_reminder_notice' => '{1} täglich, solange der Beitrag in diesem Status steht|[2,*] alle :days Tage, solange der Beitrag in diesem Status steht',
    // Der Hinweiskasten jeder Mail, deren Status eine `auto_transition` trägt
    // (Mail\WorkflowNotification::autoTransition()). `:transition` ist das Label
    // der automatischen Transition, `:options` die Labels aller Wege hinaus.
    'mail_auto_transition_heading' => 'Automatisch :transition am :date',
    'mail_auto_transition_body' => 'Bis dahin ist :options möglich. Ohne Rückmeldung wird :transition am :date automatisch ausgeführt.',
    'mail_auto_transition_or' => 'oder',
    'mail_auto_transition_body_single' => 'Ohne Rückmeldung wird :transition am :date automatisch ausgeführt.',

    // Validierungsfehler aus dem Definitions-Editor (Workflow\WorkflowDefinitionWriter).
    // Landen als Toast im CP, sind also übersetzt — die genannten YAML-Schlüssel,
    // Handles und Action-Typen bleiben aber wortgleich stehen: genau die muss der
    // Autor im Editor korrigieren.
    'writer_scope_transition' => 'Transition [:handle]',
    'writer_scope_place' => 'Place [:handle]',
    'writer_declared_none' => 'keine',
    'writer_no_example' => 'Für den Workflow [:workflow] ist kein Beispiel mitgeliefert.',
    'writer_workflow_file_missing' => 'Workflow [:workflow] liegt nicht unter :path.',
    'writer_no_places' => 'Ein Workflow muss mindestens ein Place definieren.',
    'writer_places_mismatch' => 'Im Speichern fehlen bestehende Places (:places). Places werden nicht durch Weglassen entfernt.',
    'writer_initial_place_unknown' => 'initial_place [:place] ist keines der definierten Places.',
    'writer_transition_from_unknown' => 'Transition [:handle] verweist auf das unbekannte from-Place [:place].',
    'writer_transition_to_unknown' => 'Transition [:handle] verweist auf das unbekannte to-Place [:place].',
    'writer_place_handle_reserved' => 'Das Place-Handle [:handle] ist reserviert und wird automatisch ergänzt.',
    'writer_sla_not_authoring' => 'Die Alterung gehört in den Workflow, der die Beiträge selbst führt — ein Lokalisierungs-Workflow reagiert auf dessen Frist, er hat keine eigene.',
    'writer_sla_days_invalid' => 'SLA [:key]: [:value] ist keine Anzahl von Tagen (leer oder eine ganze Zahl ab 0).',
    'writer_sla_stage_incomplete' => 'SLA-Stufe [:stage]: Tage und Schritt gehören zusammen — mit nur einem der beiden passiert nichts.',
    'writer_sla_place_unknown' => 'SLA-Stufe [:stage] zeigt auf den unbekannten Schritt [:place].',
    'writer_sla_assign_invalid' => 'SLA-Stufe [:stage]: Die Zuständigkeit braucht eine Art (Gruppe, Rolle oder Person) und mindestens einen Eintrag.',
    'writer_sla_days_order' => 'Die zweite Stufe (:outdated Tage) darf nicht vor der ersten liegen (:needs_update Tage) — beide Fristen zählen ab derselben Freigabe.',
    'writer_source_draft_not_localization' => 'Ein Rückfall-Schritt für den Entwurf der Quelle gehört in einen Lokalisierungs-Workflow — nur dessen Fassungen haben eine Quelle über sich.',
    'writer_source_draft_place_unknown' => 'Der Rückfall-Schritt [:place] existiert in diesem Workflow nicht.',
    'writer_source_draft_place_publishes' => 'Der Rückfall-Schritt [:place] erlaubt das Veröffentlichen — die Fassung soll dort aber offline sein.',
    'writer_place_color_unsupported' => 'Place [:handle] nutzt die nicht unterstützte Farbe [:color].',
    'writer_place_spinner_not_boolean' => 'Place [:handle] hat ein metadata.pending_rewrite_spinner, das kein Boolean ist.',
    'writer_place_spinner_needs_site' => 'Place [:handle] setzt metadata.pending_rewrite_spinner, das metadata.plain_language_site voraussetzt.',
    'writer_place_branch_not_boolean' => 'Place [:handle] hat ein metadata.branch, das kein Boolean ist.',
    'writer_place_branch_initial' => 'Place [:handle] ist der Startzustand und kann keine Abzweigung sein — jeder neue Beitrag stünde sofort neben dem Weg.',
    'writer_place_assign_group_retired' => 'Place [:handle] setzt metadata.assign_group. Diesen Schlüssel gibt es nicht mehr: eine Transition weist nur noch über ihre [:action]-Action zu. Nutze required_group für die Berechtigungsprüfung und hänge die Übergabe an die Transition, die sie ausführt.',
    'writer_transition_variant_unsupported' => 'Transition [:handle] nutzt die nicht unterstützte Button-Variante [:variant].',
    'writer_transition_key_not_handle_list' => 'Transition [:handle]: metadata.:key muss eine Liste von Handles sein.',
    'writer_transition_resubmit_not_boolean' => 'Transition [:handle] hat ein metadata.plain_language_resubmit, das kein Boolean ist.',
    'writer_transition_resubmit_needs_site' => 'Transition [:handle] setzt metadata.plain_language_resubmit, das metadata.plain_language_site voraussetzt.',
    'writer_transition_start_localization_needs_detail' => 'Transition [:handle] deklariert eine [:action]-Action, die metadata.detail: translation voraussetzt — nur ein Lokalisierungs-Workflow erzeugt eine Zielversion.',
    'writer_transition_resubmit_action_needs_site' => 'Transition [:handle] deklariert eine [:action]-Action, die metadata.plain_language_site voraussetzt.',
    'writer_start_localization_once' => 'Nur eine Transition darf eine [:action]-Action deklarieren.',
    'writer_start_localization_required' => 'Ein Lokalisierungs-Workflow (metadata.detail: translation) braucht genau eine Transition mit einer [:action]-Action — den Schritt, der die Zielversion erzeugt.',
    'writer_seed_published_place_unknown' => 'metadata.seed_published_place [:place] ist keines der definierten Places.',
    'writer_completed_place_unknown' => 'metadata.plain_language_completed_place [:place] ist keines der definierten Places.',
    'writer_completed_place_needs_site' => 'metadata.plain_language_completed_place setzt metadata.plain_language_site voraus.',
    'writer_failed_place_unknown' => 'metadata.plain_language_failed_place [:place] ist keines der definierten Places.',
    'writer_failed_place_needs_site' => 'metadata.plain_language_failed_place setzt metadata.plain_language_site voraus.',
    'writer_metadata_not_group_list' => 'metadata.:key muss eine Liste von Benutzergruppen-Handles sein.',
    'writer_site_fields_not_list' => 'metadata.site_fields muss eine Liste von Feld-Handles sein.',
    'writer_clear_fields_not_list' => 'metadata.plain_language_clear_fields muss eine Liste von Feld-Handles sein.',
    'writer_clear_fields_needs_site' => 'metadata.plain_language_clear_fields setzt metadata.plain_language_site voraus.',
    'writer_metadata_site_unknown' => 'metadata.:key [:site] ist keine konfigurierte Site.',
    'writer_translation_source_same_as_plain' => 'metadata.translation_source_site muss sich von metadata.plain_language_site unterscheiden.',
    'writer_source_pick_not_boolean' => 'metadata.translation_source_pick muss ein Boolean sein.',
    'writer_progress_orientation_unknown' => 'metadata.progress_orientation nutzt den unbekannten Wert [:value]. Bekannt: :known.',
    'writer_source_pick_needs_detail' => 'metadata.translation_source_pick setzt einen Lokalisierungs-Workflow voraus (metadata.detail: translation).',
    'writer_source_pick_conflicts_plain' => 'metadata.translation_source_pick lässt sich nicht mit metadata.plain_language_site kombinieren — dieser Manager fragt nichts ab.',
    'writer_source_view_not_list' => 'metadata.translation_source_view muss eine Liste von Regeln sein (je mode, by, handles).',
    'writer_source_view_mode_unknown' => 'metadata.translation_source_view: unbekannter mode „:value" — erlaubt: :known.',
    'writer_source_view_by_unknown' => 'metadata.translation_source_view: unbekannter Zielgruppen-Typ „:value" — erlaubt: :known.',
    'writer_source_view_handles_not_list' => 'metadata.translation_source_view: handles muss eine nicht leere Liste von Handles bzw. Benutzer-IDs sein.',
    'writer_source_view_needs_detail' => 'metadata.translation_source_view setzt einen Lokalisierungs-Workflow voraus (metadata.detail: translation) — nur dessen Detailansicht hat eine Quellspalte.',
    'writer_create_transition_not_string' => 'metadata.create_transition muss ein Transition-Handle (String) sein.',
    'writer_create_transition_unknown' => 'metadata.create_transition verweist auf die unbekannte Transition „:transition".',
    'writer_create_transition_system' => 'metadata.create_transition darf keine System-Transition sein („:transition" wird automatisch ausgelöst, nicht per Button).',
    'writer_create_transition_not_from_initial' => 'metadata.create_transition: „:transition" geht nicht vom Start-Place „:place" aus — ein neuer Beitrag steht genau dort, die Transition könnte nie laufen.',
    'writer_create_transition_needs_entry_workflow' => 'metadata.create_transition gibt es nur in Beitrags-Workflows — ein Lokalisierungs-Workflow legt keine Beiträge an.',
    'writer_collection_unknown' => 'Unbekannte Collection [:collection].',
    'writer_action_values_not_map' => ':where, Action [:action]: values muss eine Map sein.',
    'writer_action_value_undeclared' => ':where, Action [:action]: nicht deklarierter Wert [:value]. Deklariert: :declared.',
    'writer_action_value_invalid' => ':where, Action [:action]: ungültiger Wert für [:key] — erwartet wird :expected.',
    'writer_transition_actions_not_list' => 'Transition [:handle]: metadata.actions muss eine Liste sein.',
    'writer_transition_action_not_map' => 'Transition [:handle] hat eine Action, die keine Map ist.',
    'writer_transition_site_modal_not_configurable' => 'Transition [:handle] deklariert eine [:action]-Action. Dieser Dialog ist nicht konfigurierbar: ein Lokalisierungs-Workflow öffnet ihn immer bei der Transition aus seinem initial_place heraus.',
    'writer_transition_user_modal_retired' => 'Transition [:handle] deklariert eine [:action]-Action. Diese Schreibweise wird für bestehende Definitionen noch gelesen, aber nicht mehr gespeichert — nutze stattdessen [:replacement] mit criteria.',
    'writer_transition_action_type_unknown' => 'Transition [:handle] nutzt den unbekannten Action-Typ [:type]. Bekannte Typen: :known.',
    'writer_transition_target_missing_site_unknown' => 'Transition [:handle], Action [target_missing]: [:site] ist keine konfigurierte Site.',
    'writer_transition_multiple_dialogs' => 'Transition [:handle] deklariert mehr als eine Dialog-Action.',
    'writer_assignment_group_empty' => 'Transition [:handle]: eine Kriterien-Gruppe ohne Kriterien trifft auf niemanden zu.',
    'writer_assignment_group_nested' => 'Transition [:handle]: Kriterien-Gruppen lassen sich nicht weiter verschachteln — eine Ebene ist das Maximum.',
    'writer_transition_multiple_assignments' => 'Transition [:handle] deklariert mehr als eine [:action]-Action.',
    'writer_transition_multiple_mails' => 'Transition [:handle] deklariert mehr als eine [:action]-Action.',
    'writer_transition_action_mail_unknown' => 'Transition [:handle], Mail-Action: unbekannter Mail-Typ [:mail]. Bekannte Typen: :known.',
    'writer_assignment_mode_unknown' => 'Transition [:handle], Action [:action]: unbekannter mode [:mode]. Bekannte Modi: :known.',
    'writer_assignment_match_unknown' => 'Transition [:handle], Action [:action]: unbekanntes match [:match]. Erlaubte Werte: :known.',
    'writer_assignment_criteria_not_list' => 'Transition [:handle], Action [:action]: criteria muss eine Liste sein.',
    'writer_assignment_automatic_without_criteria' => 'Transition [:handle], Action [:action]: automatisch, aber ohne criteria. Damit würde der Beitrag jedem Benutzer zugewiesen.',
    'writer_assignment_criterion_not_map' => 'Transition [:handle], Action [:action]: ein Kriterium ist keine Map.',
    'writer_assignment_criterion_unknown' => 'Transition [:handle] nutzt das unbekannte Zuweisungskriterium [:criterion]. Bekannte Kriterien: :known.',
    'writer_assignment_operator_unsupported' => 'Transition [:handle], Kriterium [:criterion]: der Operator [:operator] wird nicht unterstützt. Unterstützt werden: :supported.',
    'writer_assignment_criterion_no_value' => 'Transition [:handle], Kriterium [:criterion]: kein Wert angegeben.',
    'writer_assignment_subject_only_any' => 'Transition [:handle], Action [:action]: automatisch, aber die criteria sind mit [any] verknüpft und mindestens eines prüft nur den Beitrag. Eine zutreffende Beitragsbedingung würde jeden Benutzer zulassen.',
    'writer_assignment_subject_only_all' => 'Transition [:handle], Action [:action]: automatisch, aber die criteria prüfen nur den Beitrag und schränken die Benutzer nie ein. Damit würde der Beitrag jedem Benutzer zugewiesen.',
    'writer_place_actions_not_list' => 'Place [:handle]: metadata.actions muss eine Liste sein.',
    'writer_place_action_not_map' => 'Place [:handle] hat eine Action, die keine Map ist.',
    'writer_place_action_type_unknown' => 'Place [:handle] nutzt den unbekannten Action-Typ [:type]. Bekannte Typen: :known.',
    'writer_place_spinner_action_needs_site' => 'Place [:handle] deklariert eine [:action]-Action, die metadata.plain_language_site voraussetzt.',
    'writer_place_refresh_interval_invalid' => 'Place [:handle], Action [refresh_interval]: ungültiger Minutenwert [:value] — erlaubt ist eine ganze Zahl zwischen 1 und 1440.',
    'writer_place_multiple_mails' => 'Place [:handle] deklariert mehr als eine [:action]-Action.',
    'writer_place_action_mail_unknown' => 'Place [:handle], Mail-Action: unbekannter Mail-Typ [:mail]. Bekannte Typen: :known.',
    'writer_place_reminder_invalid' => 'Place [:handle], Action [reminder]: ungültiger Tageswert [:value] — erlaubt ist eine ganze Zahl zwischen 1 und 365.',
    'writer_place_multiple_reminders' => 'Place [:handle] deklariert mehr als eine [reminder]-Action.',
    'writer_place_auto_transition_days_invalid' => 'Place [:handle], Action [auto_transition]: ungültiger Tageswert [:value] — erlaubt ist eine ganze Zahl zwischen 1 und 365.',
    'writer_place_auto_transition_invalid' => 'Place [:handle], Action [auto_transition]: Transition [:transition] existiert nicht, ist eine System-Transition oder startet nicht in diesem Place.',
    'writer_place_multiple_auto_transitions' => 'Place [:handle] deklariert mehr als eine [auto_transition]-Action.',
    'writer_place_action_reminder_unknown' => 'Place [:handle], Reminder-Action: unbekannter Mail-Typ [:mail]. Bekannte Typen: :known.',
    // Fortschrittsleiste im Summary-Header (WorkflowProgress.vue).
    'progress_label' => 'Fortschritt im Workflow',
    'progress_off_track' => 'Abzweigung',
    'progress_unknown_place' => 'Dieser Zustand steht in keinem Place des Workflows.',
];
