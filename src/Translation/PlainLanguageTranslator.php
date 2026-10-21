<?php

namespace TakepartMedia\Workflows\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The plain-language ("Einfache Sprache") HTTP adapter — the only class that talks
 * to the project's own rewriting endpoint.
 *
 * ASYNCHRONOUS, and that is the whole reason it is not a `Translator`. Submitting a
 * batch answers `202 Accepted` with a job id; the rewritten texts arrive minutes
 * later from the status endpoint. There is no version of "texts in, texts out, same
 * call" that fits, so this class offers two methods instead — `submit()` and
 * `fetch()` — and the flow around them lives in the controller (which creates the
 * localization straight away) and in PollPlainLanguageCommand (which writes the
 * results back).
 *
 * The request body still mirrors DeepL's field names, because the segments,
 * chunking and write-back are shared with that path: every text is HTML, and a block
 * limited to certain modules arrives wrapped in `data-modules` — the whole reason
 * this endpoint gets told about modules at all, so it must be read, not stripped.
 *
 * Source and target language are both `DE` for a German plain-language site. That is
 * not a degenerate case: the endpoint's job is the rewrite, not a language change.
 */
class PlainLanguageTranslator implements TranslationService
{
    /**
     * Per-request timeout. Generous compared to a synchronous provider's because it
     * only covers handing the job over and asking after it, never the rewrite itself.
     */
    private const TIMEOUT = 30;

    /**
     * Fields this endpoint expects on top of the DeepL-shaped ones. The wire protocol's
     * own part is fixed here; the project's part (`guideline`, `type`, …) comes from
     * `translation.plain_language.body_extras` and is merged over it.
     *
     * Public because the payload preview has to show the same body a run would send;
     * two places building it would be two answers to "what goes out".
     *
     * `input_format: json-modules` is what puts the service in envelope mode, which
     * is why `output_text` comes back as a LIST aligned with the texts we sent rather
     * than one concatenated string.
     *
     * `generate_intro` asks the service to write the opening paragraph itself instead of
     * rewriting the one it was handed. The intro travels marked (`<div class="intro">`
     * inside its section), so the service can tell which text that is — without the
     * marker the flag would have nothing to act on, which is why the leaf path sends
     * it as false when no intro section goes out (PlainLanguageLeafDocument::requestExtras()).
     *
     * It travels as a JSON boolean: the body carries `"generate_intro": true`.
     */
    public const PROTOCOL_EXTRAS = [
        'input_format' => 'json-modules',
        'generate_intro' => true,
    ];

    /**
     * The protocol extras, then the per-request ones ($request — today
     * `generate_intro`, which depends on whether an intro section goes out), then
     * the project's `body_extras`, which win on a clash.
     *
     * @param  array<string, mixed>  $request
     */
    public function bodyExtras(array $request = []): array
    {
        return array_merge(self::PROTOCOL_EXTRAS, $request, $this->config->bodyExtras());
    }

    public function __construct(private PlainLanguageConfig $config) {}

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Hand a batch over and return the job id the service will finish under.
     *
     * $context carries document-level facts that apply to every text — today the
     * entity the entry belongs to, sent as `modules`. A block-level `in_entities`
     * travels per text instead, inside the `data-modules` wrapper, because it does
     * not apply to the whole batch. The fixed keys win over $context, so a caller
     * cannot accidentally overwrite `text` or the languages.
     *
     * @param  array<int, string>  $texts
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extras  per-request protocol fields, see bodyExtras()
     *
     * @throws TranslationFailed
     */
    public function submit(array $texts, string $sourceLang, string $targetLang, array $context = [], array $extras = []): string
    {
        $texts = array_values($texts);

        if ($texts === []) {
            throw new TranslationFailed(__('workflows::messages.translation_bad_response'));
        }

        $url = $this->config->generateUrl();
        $token = $this->config->token();

        if ($url === null || $token === null) {
            throw new TranslationFailed(__('workflows::messages.translation_plain_not_configured'));
        }

        $body = array_merge($context, [
            'text' => $texts,
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
            'tag_handling' => 'html',
        ], $this->bodyExtras($extras));

        $response = $this->send(fn () => Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->asJson()
            ->post($url, $body), $url, $body);

        $textId = $response->json('text_id');

        if (! is_string($textId) || $textId === '') {
            // Without an id there is nothing to poll, so the run has no future — better
            // to say so now than to leave a localization nobody will ever finish.
            $this->logUnreadable($url, $response, ['expected' => 'text_id']);

            throw new TranslationFailed(__('workflows::messages.translation_bad_response'));
        }

        return $textId;
    }

    /**
     * Ask after submitted jobs. Keyed by job id, so a caller can look up exactly the
     * ones it asked about; ids the service does not know are simply absent.
     *
     * The endpoint answers with a LIST of job objects (`id`, `status`, `output_text`,
     * `error_message`, …). Anything it reports is passed through untouched — mapping
     * its vocabulary onto our own would be a second place for the contract to drift.
     *
     * @param  array<int, string>  $textIds
     * @return array<string, array<string, mixed>>
     *
     * @throws TranslationFailed
     */
    public function fetch(array $textIds): array
    {
        $textIds = array_values(array_filter($textIds));

        if ($textIds === []) {
            return [];
        }

        $url = $this->config->statusUrl();
        $token = $this->config->token();

        if ($url === null || $token === null) {
            throw new TranslationFailed(__('workflows::messages.translation_plain_not_configured'));
        }

        $query = ['id' => implode(',', $textIds)];

        $response = $this->send(fn () => Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->get($url, $query), $url, $query);

        $jobs = $response->json();

        if (! is_array($jobs)) {
            $this->logUnreadable($url, $response, ['expected' => 'array of jobs']);

            throw new TranslationFailed(__('workflows::messages.translation_bad_response'));
        }

        $byId = [];

        foreach ($jobs as $job) {
            if (is_array($job) && is_string($job['id'] ?? null)) {
                $byId[$job['id']] = $job;
            }
        }

        return $byId;
    }

    /**
     * Perform a call and turn everything that can go wrong into TranslationFailed.
     *
     * Shared by both methods because the failure handling is the interesting part and
     * has to be identical: a connection-level problem and a rejection read the same to
     * the caller, and both log the detail that the message cannot carry.
     *
     * A call that GOES WRONG always logs, and logs everything: payload, and either the
     * exception or the response body. That half is not switchable, because a rejection
     * has to be diagnosable from the log of the run that hit it — asking somebody to
     * enable logging and reproduce it turns a five-minute answer into a next-day one.
     *
     * A call that SUCCEEDS logs only when `log_calls` is on (see config/workflows.php).
     * It then leaves TWO entries, one when the request goes out and one when the answer
     * comes back, because they answer different questions: the first is the only record
     * that survives a request that never returns at all (hang, killed worker), where
     * even the failure path never runs. They share `url` and the payload so a pair can
     * be matched up in a busy log.
     *
     * The request entry is written BEFORE the call and cannot know the outcome, so with
     * the switch off it is skipped and a failure logs the payload on the failing entry
     * instead — which is why the payload is part of $context rather than of the answer.
     *
     * The bearer token never appears: it is set as a header on the pending request and
     * is not part of $payload, so there is nothing to redact.
     *
     * @param  callable(): \Illuminate\Http\Client\Response  $call
     * @param  array<string, mixed>  $payload  the request body (POST) or query (GET)
     *
     * @throws TranslationFailed
     */
    private function send(callable $call, string $url, array $payload)
    {
        $context = [
            'url' => $url,
            'payload' => $payload,
            'payload_bytes' => strlen(json_encode($payload)),
        ];

        $logCalls = $this->config->logsCalls();

        if ($logCalls) {
            Log::info('workflows.translation.plain_language_request', $context);
        }

        $startedAt = microtime(true);

        try {
            $response = $call();
        } catch (\Throwable $e) {
            // Connection level -- DNS, TLS, timeout. Report it for the log, but hand the
            // caller a sentence rather than a stack trace.
            Log::warning('workflows.translation.plain_language_unreachable', $context + [
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'exception' => $e::class.': '.$e->getMessage(),
            ]);

            report($e);

            throw new TranslationFailed(__('workflows::messages.translation_unreachable'));
        }

        $body = $response->body();

        // Built for both outcomes, because the same questions get asked of both — only
        // WHETHER it is written differs. A rejection goes out as WARNING so it surfaces
        // where only warnings are watched; a success is INFO and switchable.
        $answer = $context + [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body_bytes' => strlen($body),
            'body' => $body,
        ];

        if (! $response->successful()) {
            // The body is the only thing that says WHY. A 422 from a validating endpoint
            // names the offending field; swallowing it leaves the editor with "the
            // service rejected the request" and nothing to act on.
            Log::warning('workflows.translation.plain_language_response', $answer);

            throw new TranslationFailed($this->messageFor($response->status(), $body), $response->status());
        }

        if ($logCalls) {
            Log::info('workflows.translation.plain_language_response', $answer);
        }

        return $response;
    }

    /**
     * A 2xx whose shape we cannot read is the hardest failure to diagnose from the
     * outside, and the body is the only place that says what the service answered.
     *
     * @param  array<string, mixed>  $extra
     */
    private function logUnreadable(string $url, $response, array $extra = []): void
    {
        Log::warning('workflows.translation.plain_language_unreadable', array_merge([
            'status' => $response->status(),
            'url' => $url,
            'top_level_keys' => is_array($response->json()) ? array_keys($response->json()) : null,
            'body' => $response->body(),
        ], $extra));
    }

    /**
     * Only the codes this endpoint's own contract defines. Everything else falls
     * through to the generic message — inventing an explanation for a status we have
     * not agreed on would be worse than naming the number.
     *
     * Whatever the service said is appended, so the editor sees the actual reason
     * rather than just our sentence about it.
     */
    private function messageFor(int $status, string $body = ''): string
    {
        $message = match ($status) {
            401, 403 => __('workflows::messages.translation_plain_error_auth'),
            429 => __('workflows::messages.translation_plain_error_rate_limit'),
            400, 422 => __('workflows::messages.translation_plain_error_request'),
            default => __('workflows::messages.translation_error_generic', ['status' => $status]),
        };

        $detail = $this->detailFrom($body);

        return $detail === null ? $message : $message.' — '.$detail;
    }

    /**
     * The human-readable part of an error body, short enough for a toast.
     *
     * Tries the keys validation errors habitually use, in order of specificity, and
     * falls back to the raw body. No shape is assumed: this endpoint's error format is
     * not something the addon gets to declare, so anything unrecognised is passed
     * through verbatim rather than dropped. The full body is in the log either way.
     */
    private function detailFrom(string $body): ?string
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            // Laravel-style `errors: {field: [messages]}` first — it names the field,
            // which is the one thing a 422 is actually about.
            if (is_array($decoded['errors'] ?? null)) {
                $body = collect($decoded['errors'])
                    ->map(fn ($messages, $field) => $field.': '.implode(' ', (array) $messages))
                    ->implode(' | ');
            } else {
                foreach (['message', 'detail', 'error', 'error_message', 'title'] as $key) {
                    if (is_string($decoded[$key] ?? null) && trim($decoded[$key]) !== '') {
                        $body = $decoded[$key];
                        break;
                    }
                }
            }
        }

        return mb_strimwidth(trim($body), 0, 300, '…');
    }
}
