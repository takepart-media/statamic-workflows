# Übersetzung & Einfache Sprache

🇬🇧 [English](../en/translation.md)

Verbinde Lokalisierungs-Workflows mit DeepL für Übersetzungen und mit einem Dienst für Einfache Sprache.

> **Auf einen Blick**
> - Config-Schlüssel: `workflows.translation`
> - Standard: beide Dienste aus, solange ihre Env-Variablen leer sind
> - Eigener Code: nein

## Überblick

Ein Lokalisierungs-Workflow (`detail: translation`) legt eine Fassung eines Eintrags in einer anderen Site an. Dafür nutzt er zwei Maschinendienste:

- **DeepL** übersetzt synchron, bevor die Lokalisierung entsteht.
- Ein projektspezifischer **Einfache-Sprache-Dienst** schreibt asynchron um. Das Ergebnis holt ein Poll-Kommando jede Minute ab.

Welcher Dienst greift, entscheidet der Workflow. Ist die Ziel-Site gleich `metadata.plain_language_site`, nutzt er den Einfache-Sprache-Dienst, sonst DeepL.

Zugangsdaten stehen nur in der Config (per Env), nie in der committeten Workflow-YAML. Gelesen wird über `config()`, das funktioniert also auch mit `php artisan config:cache`.

## Schnellstart

1. Zugangsdaten in die `.env` eintragen:

   ```env
   # DeepL – Free-Keys enden auf ":fx" und gehen an api-free.deepl.com
   DEEPL_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx

   # Einfache-Sprache-Dienst – nur die Basis-URL (Schema + Host)
   PLAIN_LANGUAGE_URL=https://rewrite.example.com
   PLAIN_LANGUAGE_TOKEN=secret
   PLAIN_LANGUAGE_GUIDELINE=mein-projekt
   PLAIN_LANGUAGE_TYPE=easy
   ```

2. Den Laravel-Scheduler im Cron eintragen. Ohne ihn werden Einfache-Sprache-Ergebnisse nie zurückgeschrieben:

   ```bash
   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
   ```

3. Im Workflow die Site für Einfache Sprache wählen (`metadata.plain_language_site`). Die Lokalisierungs-Sektion des Einstellungs-Editors zeigt pro Env-Variable, ob sie gesetzt ist.

4. Die Start-Transition auslösen. Die Lokalisierung entsteht übersetzt (DeepL) oder als Kopie, die nach dem Umschreiben überschrieben wird (Einfache Sprache).

## Konfiguration

### Aktivierung

| Dienst | Env-Variablen | An, wenn |
| --- | --- | --- |
| DeepL | `DEEPL_API_KEY` | Key nicht leer |
| Einfache Sprache | `PLAIN_LANGUAGE_URL`, `PLAIN_LANGUAGE_TOKEN` | **beide** nicht leer |

Andere Variablennamen? Config publizieren (`php artisan vendor:publish --tag=workflows-config`) und die `env()`-Aufrufe ändern.

### `translation.deepl`

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `key` | `string\|null` | für DeepL | `env('DEEPL_API_KEY')` | API-Key. Der Endpunkt folgt aus dem Key: Endung `:fx` → `https://api-free.deepl.com`, sonst `https://api.deepl.com`, jeweils mit `/v2/translate`. Eine eigene Endpunkt-Einstellung gibt es nicht. |

### `translation.plain_language`

| Schlüssel | Typ | Pflicht | Standard | Beschreibung |
| --- | --- | --- | --- | --- |
| `url` | `string\|null` | ja | `env('PLAIN_LANGUAGE_URL')` | Basis-URL des Dienstes, ein abschließendes `/` wird entfernt. Das Addon hängt `/api/plain-language/v1/generate` (Einreichen) bzw. `…/generate/status` (Abfrage) an. Eine URL, die schon auf den Generate-Pfad endet, wird unverändert übernommen. |
| `token` | `string\|null` | ja | `env('PLAIN_LANGUAGE_TOKEN')` | Bearer-Token. Wird nie geloggt. |
| `body_extras.guideline` | `string\|null` | nein | `env('PLAIN_LANGUAGE_GUIDELINE')` | Umschreib-Profil deines Projekts auf Dienstseite. |
| `body_extras.type` | `string\|null` | nein | `env('PLAIN_LANGUAGE_TYPE')` | Art des Umschreibens. |
| `body_extras.*` | `mixed` | nein | – | Weitere Schlüssel gehen ebenfalls mit. `null` und `''` werden nicht gesendet. Bei gleichem Namen gewinnen die Extras gegen die Protokollfelder. |
| `poll_scheduler` | `bool` | nein | `env('PLAIN_LANGUAGE_POLL_SCHEDULER', true)` | Registriert `workflows:plain-language-poll` jede Minute (`onOneServer`, `withoutOverlapping`). `false` = du führst das Kommando selbst aus. |
| `log_calls` | `bool` | nein | `env('PLAIN_LANGUAGE_LOG_CALLS', false)` | Payload und Antwort **erfolgreicher** Aufrufe loggen. Fehler werden immer geloggt. |
| `max_polls` | `int` | nein | `env('PLAIN_LANGUAGE_MAX_POLLS', 5)` | Abfragen (≈ Minuten), die eine Einreichung abgewartet wird, bevor sie als gescheitert gilt. |
| `max_attempts` | `int` | nein | `env('PLAIN_LANGUAGE_MAX_ATTEMPTS', 3)` | Einreichungen insgesamt, die erste eingerechnet. `1` = keine Wiederholung. |
| `heading_set` | `string\|null` | nein | `'heading'` | In einem **Top-Level**-Bard-Feld wird ein zurückgegebenes `<h2>`/`<h3>` zu diesem Set, sofern das Feld es deklariert. `null` = aus. |
| `heading_index_field` | `string\|null` | nein | `'heading_index'` | Feld, in dem Heading-Sets 1, 2, 3 … durchnummeriert werden. `null` = aus. |
| `include_disabled` | `bool` | nein | `true` | Auch deaktivierte Replicator-Zeilen und Bard-Sets umschreiben. |

Bei `max_polls` und `max_attempts` werden Werte unter 1 zu 1. Nicht-numerische Werte, etwa ein leeres `PLAIN_LANGUAGE_MAX_POLLS=`, fallen auf den Standardwert zurück.

## Ablauf: DeepL

DeepL arbeitet synchron in der Start-Transition:

1. Das Addon extrahiert die Texte **bevor** etwas angelegt wird.
2. Es sendet sie in Chunks mit `tag_handling: html`. Jeder Aufruf hat 30 s Timeout, der ganze Lauf 45 s.
3. Die Übersetzungen werden per Index zurückgeschrieben. Erst dann entsteht die Lokalisierung.

Schlägt die Übersetzung fehl, antwortet das Addon mit 422 und einer Meldung. Es bleibt kein Eintrag zurück. Ohne Key entsteht die Lokalisierung als 1:1-Kopie der Quelle.

## Ablauf: Einfache Sprache

Einfache Sprache arbeitet asynchron:

1. **Einreichen.** Die Start-Transition legt die Lokalisierung zuerst als Kopie an. Dann geht pro Eintrag ein Request an den Dienst: `text[]` (der Titel als `<h1>`, dann je Textblock ein `<section data-wf-leaf="N">`), `source_lang`, `target_lang`, `tag_handling: html`, `modules` (aus `content_fields.entity`), `input_format: json-modules`, `generate_intro` sowie `body_extras`.
2. **Warten.** Der Dienst antwortet `202` mit einer `text_id`, die in `workflow_plain_language_jobs` landet. Die Redaktion sieht auf der Workflow-Detailseite einen Spinner und „Versuch n von m“. Lehnt der Dienst die Einreichung ab, wird die Kopie wieder gelöscht und ein Fehler-Toast erscheint.
3. **Abholen.** `workflows:plain-language-poll` fragt jede Minute `…/status?id=a,b,c` ab. Bei `completed` schreibt es pro Textblock zurück, leitet `seo.title` und `seo.description` ab und hält die QS-Hinweise als Kommentar und Event fest. `failed`, eine unbekannte ID oder ein nach `max_polls` noch offener Job gilt als gescheitert.
4. **Wiederholen.** Eine gescheiterte Einreichung wird mit neuem Job erneut eingereicht, bis `max_attempts` erreicht ist.
5. **Abschließen.** Nach dem letzten Fehlversuch wandert die Fassung nach `metadata.plain_language_failed_place`, falls gesetzt. Sonst wird eine unberührte Kopie gelöscht. Eine Fassung, die schon einen Rewrite trug, bleibt, wo sie ist. Nach Erfolg verschiebt `metadata.plain_language_completed_place` die Fassung optional weiter.

Die Transition-Action `plain_language_resubmit` schreibt eine bestehende Fassung erneut um.

Ist der Dienst nicht konfiguriert, entsteht die Lokalisierung als Kopie ohne Job. Das Poll-Kommando lässt offene Jobs dann unangetastet.

### Das Poll-Kommando

```bash
php artisan workflows:plain-language-poll
```

| Option | Beschreibung |
| --- | --- |
| `--dry-run` | Zeigt, was der Dienst meldet und was geschrieben würde, schreibt aber nichts. |
| `--diff` | Gibt pro Rückschreiben den Feld-Diff aus. |

## Beispiele

### Nur DeepL

Du brauchst keine Einfache Sprache. Ein Key genügt, der Einfache-Sprache-Dienst bleibt aus:

```env
DEEPL_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

Ohne `:fx` geht der Key an `https://api.deepl.com` (Pro).

### Poll-Kommando selbst ausführen

Du betreibst einen eigenen, überwachten Worker statt des Schedulers:

```env
PLAIN_LANGUAGE_POLL_SCHEDULER=false
```

Der Worker muss dann `php artisan workflows:plain-language-poll` etwa jede Minute ausführen.

### Langsamen Dienst abwarten

Der Dienst braucht üblicherweise 6 Minuten. Lege `max_polls` darüber:

```env
PLAIN_LANGUAGE_MAX_POLLS=10
PLAIN_LANGUAGE_MAX_ATTEMPTS=2
```

Schlimmstenfalls vergehen bis zum Parken etwa `max_polls × max_attempts` = 20 Minuten.

## Hinweise & Grenzen

> [!WARNING]
> Ohne Scheduler oder eigenen Runner wird ein eingereichter Rewrite nie zurückgeschrieben.

> [!WARNING]
> Lege `max_polls` **über** die übliche Laufzeit des Dienstes. Ein Wert genau darauf reicht gesunde Jobs erneut ein.

> [!WARNING]
> Das Rückschreiben ist strikt. Fehlende, unbekannte oder leere Abschnitte oder eine geänderte Struktur führen dazu, dass nichts geschrieben wird. Der Dienst muss jedes `section[data-wf-leaf]` unverändert zurückgeben.

> [!NOTE]
> Verbindungsfehler (DNS, TLS, Timeout) beim Abfragen zählen keinen Versuch, der nächste Durchlauf probiert erneut. Bei einem beantworteten Fehlercode fragt das Kommando jeden Job einzeln ab, um den fehlerhaften zu finden.

> [!NOTE]
> `seo` geht nie an den Einfache-Sprache-Dienst. DeepL kürzt SEO-Texte nicht (SEO Pro: 60/160 Zeichen). Der Einfache-Sprache-Pfad kürzt die abgeleitete Description auf höchstens 160 Zeichen, den Titel nicht.

> [!NOTE]
> `heading_set` und `heading_index_field` wirken nur in Top-Level-Bard-Feldern, die das Set deklarieren. Welche Felder gesendet werden, steht unter [Inhaltsfelder](content-fields.md).

> [!TIP]
> `log_calls` loggt ganze Einträge (dutzende KB) und jede „läuft noch“-Abfrage. Schalte es in Produktion nur zur Diagnose ein.

Log-Schlüssel: `workflows.translation.plain_language_request`, `…_response`, `…_unreachable`, `…_unreadable`, `…_unusable_output`, `…_mixed_formats`, `…_leaf_rejected`, `…_leaf_diff`.

## Fehlerbehebung

| Symptom | Ursache | Lösung |
| --- | --- | --- |
| Der Spinner dreht sich, aber es kommt nie ein Ergebnis. | Der Scheduler läuft nicht, oder `poll_scheduler` ist `false` ohne eigenen Runner. | Cron für `schedule:run` einrichten oder das Poll-Kommando selbst ausführen. |
| Gesunde Jobs werden mehrfach eingereicht. | `max_polls` liegt auf oder unter der Laufzeit des Dienstes. | `max_polls` erhöhen. |
| Der Dienst meldet `completed`, aber nichts wird geschrieben. | Die Antwort passt nicht zur gesendeten Abschnittsstruktur (Log `workflows.translation.plain_language_leaf_rejected`). | Antwort mit `--dry-run --diff` prüfen. Der Dienst muss jedes `section[data-wf-leaf]` zurückgeben. |
| Die Lokalisierung ist eine 1:1-Kopie der Quelle. | `DEEPL_API_KEY` ist leer bzw. der Einfache-Sprache-Dienst ist nicht konfiguriert. | Env-Variablen setzen. Der Einstellungs-Editor zeigt, welche fehlen. |

## Siehe auch

- [Inhaltsfelder](content-fields.md)
- [DOCS.md](../../../DOCS.md): „Lokalisierungs-Workflows (`detail: translation`)“, „Rückschreiben pro Textblatt (Format `sections`)“, „SEO-Texte gehen nur an DeepL“, „Einfache Sprache leitet title/description ab“, „Bestandsdaten: `workflows:backfill-plain-language`“, §15 (Scheduler-Cron)
- [config/workflows.php](../../../config/workflows.php)
- [src/Translation/DeeplConfig.php](../../../src/Translation/DeeplConfig.php)
- [src/Translation/DeeplTranslator.php](../../../src/Translation/DeeplTranslator.php)
- [src/Translation/TranslationRunner.php](../../../src/Translation/TranslationRunner.php)
- [src/Translation/PlainLanguageConfig.php](../../../src/Translation/PlainLanguageConfig.php)
- [src/Translation/PlainLanguageTranslator.php](../../../src/Translation/PlainLanguageTranslator.php)
- [src/Translation/PlainLanguageLeafDocument.php](../../../src/Translation/PlainLanguageLeafDocument.php)
- [src/Translation/PlainLanguageLeafWriter.php](../../../src/Translation/PlainLanguageLeafWriter.php)
- [src/Content/TextLeafWalker.php](../../../src/Content/TextLeafWalker.php)
- [src/Console/PollPlainLanguageCommand.php](../../../src/Console/PollPlainLanguageCommand.php)
- [src/ServiceProvider.php](../../../src/ServiceProvider.php) (`schedule()`)

---

← [Inhaltsfelder](content-fields.md) · [Übersicht](README.md)
