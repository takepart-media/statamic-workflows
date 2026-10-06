<?php

namespace TakepartMedia\Workflows\Translation;

/**
 * The plain-language ("Einfache Sprache") connection, resolved from
 * `config/workflows.php`.
 *
 * Same reasoning as DeeplConfig: URL and token live in the environment and nowhere
 * else, because this addon's settings editor writes a COMMITTED YAML — a token
 * stored there would land in every clone, backup and CI log, every environment
 * would share it, and rotating it would mean a commit. Reading through `config()`
 * rather than `env()` keeps both working under `php artisan config:cache`.
 *
 * `PLAIN_LANGUAGE_URL` is the service's BASE — scheme and host, no path. The path is
 * the API's own business and lives here as a constant, exactly as DeeplConfig appends
 * `/v2/translate` to its base: a path in the environment is a second place for the
 * contract to be wrong, and it would let a deployment point at an endpoint that
 * speaks a different shape than this adapter sends.
 *
 * WHICH site carries the plain-language version stays a per-workflow decision
 * (`metadata.plain_language_site`) — only the connection is global.
 */
class PlainLanguageConfig
{
    /**
     * The environment variables the shipped config reads. Shown in the CP so an
     * admin knows which variable is missing — a project that publishes the config
     * and points it elsewhere makes these labels a white lie, which is the right
     * trade for not having to plumb the names through.
     */
    public const ENV_URL = 'PLAIN_LANGUAGE_URL';

    public const ENV_TOKEN = 'PLAIN_LANGUAGE_TOKEN';

    /** The generation endpoint's path, appended to the configured base. */
    public const GENERATE_PATH = '/api/plain-language/v1/generate';

    /**
     * Where submitted jobs are asked after. A suffix of the generation path rather
     * than a path of its own, which is how the service itself reports it in the
     * `status_url` of its `202` response.
     */
    public const STATUS_PATH = self::GENERATE_PATH.'/status';

    /**
     * The configured base URL, or null when unset. A blank line in `.env` is not a URL.
     * Trailing slashes are trimmed so joining the path cannot produce a double one.
     */
    public function baseUrl(): ?string
    {
        $url = $this->stringOrNull(config('workflows.translation.plain_language.url'));

        return $url === null ? null : rtrim($url, '/');
    }

    /**
     * The URL a generation request goes to: base + GENERATE_PATH.
     *
     * A base that ALREADY ends in the path is taken as-is. `PLAIN_LANGUAGE_URL` used to
     * mean "the full endpoint", so an existing `.env` may well carry it — and silently
     * turning that into `…/generate/api/plain-language/v1/generate` would be a 404 that
     * looks like the service being down.
     */
    public function generateUrl(): ?string
    {
        $base = $this->baseUrl();

        if ($base === null) {
            return null;
        }

        return str_ends_with($base, self::GENERATE_PATH) ? $base : $base.self::GENERATE_PATH;
    }

    /**
     * The URL submitted jobs are polled at. Derived from generateUrl() so a base that
     * already carried the generation path stays correct here too.
     */
    public function statusUrl(): ?string
    {
        $generate = $this->generateUrl();

        return $generate === null ? null : $generate.'/status';
    }

    /**
     * Polls one submission is waited out before it counts as dead, and how many
     * submissions a rewrite gets in total. See config/workflows.php for what to pick.
     *
     * Here rather than in PollPlainLanguageCommand because they are no longer only the
     * command's business: the instance page shows "attempt 2 of 3" beside the spinner and
     * needs the same maximum. Two readers of one key drift, and the clamping below is
     * exactly the part that must not be re-implemented per caller.
     */
    public function maxPolls(): int
    {
        return $this->budget('max_polls', 5);
    }

    public function maxAttempts(): int
    {
        return $this->budget('max_attempts', 3);
    }

    /**
     * Whether SUCCESSFUL calls to the service are logged in full. Failures are logged
     * regardless — see the `log_calls` comment in config/workflows.php for why the two
     * are not the same question.
     *
     * Read through config() rather than env() so the switch survives `config:cache`,
     * which is the state a production box is usually in — `env()` returns null there.
     *
     * Anything Laravel's own truthiness accepts works ("true", "1", true); a missing
     * key is false, which is the safe half: an unset switch must not fill the log.
     */
    public function logsCalls(): bool
    {
        return filter_var(
            config('workflows.translation.plain_language.log_calls', false),
            FILTER_VALIDATE_BOOL
        );
    }

    /**
     * Anything non-numeric falls back to the default rather than to zero, and that is the
     * important half: `config()` only returns its own default when the key is MISSING, so
     * a present-but-empty value comes straight through. `PLAIN_LANGUAGE_MAX_POLLS=` in a
     * .env yields `''`, which as an int is 0 — a budget of nothing, failing every job on
     * its first poll. Floored at 1 as well, for a deliberate but useless 0 or a negative.
     */
    private function budget(string $key, int $default): int
    {
        $configured = config('workflows.translation.plain_language.'.$key, $default);

        return max(1, (int) (is_numeric($configured) ? $configured : $default));
    }

    /** The bearer token, or null when unset. */
    public function token(): ?string
    {
        return $this->stringOrNull(config('workflows.translation.plain_language.token'));
    }

    /**
     * Both halves have to be present. A URL without a token would fail on the
     * first request with a 401, and the caller treats "not configured" as
     * "copy the source 1:1" — which is the better outcome than a failed run.
     */
    /**
     * The project's own fixed request fields (`guideline`, `type`, …): whatever the
     * service's profile for this project calls for. Null and blank values are dropped
     * so an unset env variable never travels as an empty field.
     */
    public function bodyExtras(): array
    {
        $extras = config('workflows.translation.plain_language.body_extras');

        if (! is_array($extras)) {
            return [];
        }

        return array_filter($extras, fn ($value) => $value !== null && $value !== '');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null && $this->token() !== null;
    }

    private function stringOrNull($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
