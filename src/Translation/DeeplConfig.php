<?php

namespace TakepartMedia\Workflows\Translation;

/**
 * The DeepL connection, resolved from `config/workflows.php`.
 *
 * The key lives in the environment and nowhere else — deliberately not in the
 * workflow YAML the CP settings editor writes: `resources/workflows/*.yaml` is
 * committed, so a key stored there would land in every clone, backup and CI log,
 * every environment would share it, and rotating it would mean a commit. Going
 * through `config()` rather than `env()` also keeps it working under
 * `php artisan config:cache`, which stops Laravel from reading `.env` at all.
 *
 * Which endpoint to talk to is derived from the key, not configured separately:
 * free keys end in `:fx`, which is exactly how the official DeepL SDKs decide.
 * A second setting could only ever contradict the first one.
 *
 * This class is the one place that knows both rules. The settings editor uses it
 * today for its status line; the HTTP client will use it later for the request.
 */
class DeeplConfig
{
    /**
     * The environment variable the shipped config reads. Shown in the CP so an
     * admin knows which variable to set — a project that publishes the config
     * and points it elsewhere makes this label a white lie, which is the right
     * trade for not having to plumb the name through.
     */
    public const ENV_KEY = 'DEEPL_API_KEY';

    private const FREE_SUFFIX = ':fx';

    private const BASE_FREE = 'https://api-free.deepl.com';

    private const BASE_PRO = 'https://api.deepl.com';

    /**
     * The API key, or null when unset. Empty strings count as unset — a blank
     * line in `.env` is not a configured key.
     */
    public function key(): ?string
    {
        $key = config('workflows.translation.deepl.key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    public function isConfigured(): bool
    {
        return $this->key() !== null;
    }

    /** @return 'free'|'pro'|null */
    public function plan(): ?string
    {
        return $this->planFor($this->key());
    }

    /** The API base URL, or null when there is no key to judge. */
    public function baseUrl(): ?string
    {
        $key = $this->key();

        return $key === null ? null : $this->baseUrlFor($key);
    }

    public function translateUrl(): ?string
    {
        $base = $this->baseUrl();

        return $base === null ? null : $base.'/v2/translate';
    }

    /** @return 'free'|'pro'|null */
    public function planFor(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return str_ends_with($key, self::FREE_SUFFIX) ? 'free' : 'pro';
    }

    public function baseUrlFor(string $key): string
    {
        return $this->planFor($key) === 'free' ? self::BASE_FREE : self::BASE_PRO;
    }
}
