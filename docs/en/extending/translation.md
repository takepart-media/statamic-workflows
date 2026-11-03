# Translation & plain language

🇩🇪 [Deutsch](../../de/extending/translation.md)

Connect localization workflows to DeepL for translations and to a plain-language service ("Einfache Sprache").

> **At a glance**
> - Config key: `workflows.translation`
> - Default: both services off while their env variables are empty
> - Own code: no

## Overview

A localization workflow (`detail: translation`) creates a version of an entry in another site. It uses two machine services for this:

- **DeepL** translates synchronously, before the localization is created.
- A project-specific **plain-language service** rewrites asynchronously. A poll command collects the result every minute.

The workflow decides which service runs. If the target site equals `metadata.plain_language_site`, it uses the plain-language service, otherwise DeepL.

Credentials live only in the config (via env), never in the committed workflow YAML. Values are read via `config()`, so they also work with `php artisan config:cache`.

## Quick start

1. Add the credentials to your `.env`:

   ```env
   # DeepL – free keys end in ":fx" and go to api-free.deepl.com
   DEEPL_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx

   # Plain-language service – base URL only (scheme + host)
   PLAIN_LANGUAGE_URL=https://rewrite.example.com
   PLAIN_LANGUAGE_TOKEN=secret
   PLAIN_LANGUAGE_GUIDELINE=my-project
   PLAIN_LANGUAGE_TYPE=easy
   ```

2. Add the Laravel scheduler to cron. Without it, plain-language results are never written back:

   ```bash
   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
   ```

3. Pick the plain-language site in the workflow (`metadata.plain_language_site`). The localization section of the settings editor shows whether each env variable is set.

4. Fire the start transition. The localization is created translated (DeepL) or as a copy that the rewrite overwrites later (plain language).

## Configuration

### Activation

| Service | Env variables | On when |
| --- | --- | --- |
| DeepL | `DEEPL_API_KEY` | key is non-blank |
| Plain language | `PLAIN_LANGUAGE_URL`, `PLAIN_LANGUAGE_TOKEN` | **both** non-blank |

Different variable names? Publish the config (`php artisan vendor:publish --tag=workflows-config`) and change the `env()` calls.

### `translation.deepl`

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `key` | `string\|null` | for DeepL | `env('DEEPL_API_KEY')` | API key. The endpoint follows from the key: suffix `:fx` → `https://api-free.deepl.com`, else `https://api.deepl.com`, each with `/v2/translate`. There is no separate endpoint setting. |

### `translation.plain_language`

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `url` | `string\|null` | yes | `env('PLAIN_LANGUAGE_URL')` | Service base URL; a trailing `/` is trimmed. The addon appends `/api/plain-language/v1/generate` (submit) and `…/generate/status` (poll). A URL that already ends in the generate path is accepted as is. |
| `token` | `string\|null` | yes | `env('PLAIN_LANGUAGE_TOKEN')` | Bearer token. Never logged. |
| `body_extras.guideline` | `string\|null` | no | `env('PLAIN_LANGUAGE_GUIDELINE')` | Your project's rewriting profile on the service side. |
| `body_extras.type` | `string\|null` | no | `env('PLAIN_LANGUAGE_TYPE')` | Kind of rewrite. |
| `body_extras.*` | `mixed` | no | – | Any further key is sent too. `null` and `''` are dropped. On a name clash, extras win over the protocol fields. |
| `poll_scheduler` | `bool` | no | `env('PLAIN_LANGUAGE_POLL_SCHEDULER', true)` | Registers `workflows:plain-language-poll` every minute (`onOneServer`, `withoutOverlapping`). `false` = you run the command yourself. |
| `log_calls` | `bool` | no | `env('PLAIN_LANGUAGE_LOG_CALLS', false)` | Log payload and response of **successful** calls. Failures are always logged. |
| `max_polls` | `int` | no | `env('PLAIN_LANGUAGE_MAX_POLLS', 5)` | Polls (≈ minutes) one submission is waited out before it counts as failed. |
| `max_attempts` | `int` | no | `env('PLAIN_LANGUAGE_MAX_ATTEMPTS', 3)` | Submissions in total, the first included. `1` = no retries. |
| `heading_set` | `string\|null` | no | `'heading'` | In a **top-level** Bard field, a returned `<h2>`/`<h3>` becomes this set, if the field declares it. `null` = off. |
| `heading_index_field` | `string\|null` | no | `'heading_index'` | Field in which heading sets are numbered 1, 2, 3 … `null` = off. |
| `include_disabled` | `bool` | no | `true` | Also rewrite disabled Replicator rows and Bard sets. |

For `max_polls` and `max_attempts`, values below 1 become 1. Non-numeric values, such as an empty `PLAIN_LANGUAGE_MAX_POLLS=`, fall back to the default.

## Lifecycle: DeepL

DeepL works synchronously inside the start transition:

1. The addon extracts the texts **before** anything is created.
2. It sends them in chunks with `tag_handling: html`. Each call has a 30 s timeout; the whole run has 45 s.
3. The translations are written back by index. Only then is the localization created.

If the translation fails, the addon responds with 422 and a message. No entry is left behind. Without a key, the localization is created as a 1:1 copy of the source.

## Lifecycle: plain language

Plain language works asynchronously:

1. **Submit.** The start transition first creates the localization as a copy. Then one request per entry goes to the service: `text[]` (the title as `<h1>`, then one `<section data-wf-leaf="N">` per text block), `source_lang`, `target_lang`, `tag_handling: html`, `modules` (from `content_fields.entity`), `input_format: json-modules`, `generate_intro`, plus `body_extras`.
2. **Wait.** The service answers `202` with a `text_id`, stored in `workflow_plain_language_jobs`. Editors see a spinner and "attempt n of m" on the workflow detail page. If the service refuses the submission, the copy is deleted again and an error toast appears.
3. **Collect.** `workflows:plain-language-poll` queries `…/status?id=a,b,c` every minute. On `completed` it writes back per text block, derives `seo.title` and `seo.description`, and records the QS notes as a comment and an event. `failed`, an unknown id, or a job still open after `max_polls` counts as failed.
4. **Retry.** A failed submission is re-submitted with a fresh job until `max_attempts` is reached.
5. **Finish.** After the last failed attempt, the version moves to `metadata.plain_language_failed_place` if set. Otherwise an untouched copy is deleted. A version that already held a rewrite stays where it is. On success, `metadata.plain_language_completed_place` optionally moves the version on.

The transition action `plain_language_resubmit` rewrites an existing version again.

If the service is not configured, the localization is created as a copy without a job. The poll command then leaves open jobs untouched.

### The poll command

```bash
php artisan workflows:plain-language-poll
```

| Option | Description |
| --- | --- |
| `--dry-run` | Reports what the service says and what would be written, without writing anything. |
| `--diff` | Prints the per-field diff of every write-back. |

## Examples

### DeepL only

You don't need plain language. One key is enough; the plain-language service stays off:

```env
DEEPL_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

Without `:fx`, the key goes to `https://api.deepl.com` (Pro).

### Run the poll command yourself

You run your own supervised worker instead of the scheduler:

```env
PLAIN_LANGUAGE_POLL_SCHEDULER=false
```

Your worker must then run `php artisan workflows:plain-language-poll` about every minute.

### Wait out a slow service

The service usually takes 6 minutes. Set `max_polls` above that:

```env
PLAIN_LANGUAGE_MAX_POLLS=10
PLAIN_LANGUAGE_MAX_ATTEMPTS=2
```

In the worst case, parking takes about `max_polls × max_attempts` = 20 minutes.

## Notes & limitations

> [!WARNING]
> Without the scheduler or your own runner, a submitted rewrite is never written back.

> [!WARNING]
> Set `max_polls` **above** the service's usual turnaround. A value equal to it re-submits healthy jobs.

> [!WARNING]
> Write-back is strict. Missing, unknown or empty sections, or a changed structure, mean nothing is written. The service must return every `section[data-wf-leaf]` unchanged.

> [!NOTE]
> Connection errors (DNS, TLS, timeout) while polling don't count as an attempt; the next run retries. On an answered error code, the command queries each job separately to isolate the bad one.

> [!NOTE]
> `seo` is never sent to the plain-language service. DeepL does not shorten SEO texts (SEO Pro: 60/160 characters). The plain-language path cuts the derived description to at most 160 characters, but not the title.

> [!NOTE]
> `heading_set` and `heading_index_field` only act on top-level Bard fields that declare the set. Which fields are sent is described in [Content fields](content-fields.md).

> [!TIP]
> `log_calls` logs whole entries (dozens of KB) and every "still working" poll. Turn it on in production only for diagnosis.

Log keys: `workflows.translation.plain_language_request`, `…_response`, `…_unreachable`, `…_unreadable`, `…_unusable_output`, `…_mixed_formats`, `…_leaf_rejected`, `…_leaf_diff`.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| The spinner keeps turning, but no result ever arrives. | The scheduler is not running, or `poll_scheduler` is `false` with no runner of your own. | Set up cron for `schedule:run`, or run the poll command yourself. |
| Healthy jobs are submitted several times. | `max_polls` is at or below the service's turnaround. | Raise `max_polls`. |
| The service reports `completed`, but nothing is written. | The answer does not match the sent section structure (log `workflows.translation.plain_language_leaf_rejected`). | Inspect the answer with `--dry-run --diff`. The service must return every `section[data-wf-leaf]`. |
| The localization is a 1:1 copy of the source. | `DEEPL_API_KEY` is empty, or the plain-language service is not configured. | Set the env variables. The settings editor shows which ones are missing. |

## See also

- [Content fields](content-fields.md)
- [Translation & plain language](../localization.md) – localization workflows, SEO handling and the backfill command
- [Commands & scheduler](../commands.md) – scheduler cron and `workflows:backfill-plain-language`
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

← [Content fields](content-fields.md) · [Overview](README.md)
