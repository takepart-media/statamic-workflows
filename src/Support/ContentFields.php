<?php

namespace TakepartMedia\Workflows\Support;

/**
 * The blueprint field handles the addon reads and writes, resolved from
 * `workflows.content_fields`.
 *
 * The translation, plain-language and preview code all have to know which entry field
 * is "the body", which one is "the intro" and which Bard set types carry text. Those
 * names belong to the consuming project's blueprints, not to the addon, so they are
 * configuration. The defaults are neutral: a field the project does not name is `null`
 * and the code that would read it simply skips it.
 *
 * Merged key by key here rather than left to `mergeConfigFrom`, which is shallow — a
 * project that only sets `body` must not lose the other defaults.
 */
class ContentFields
{
    public const DEFAULTS = [
        'title' => 'title',
        'intro' => null,
        'body' => 'content',
        'seo' => 'seo',
        'entity' => null,
        'scope' => null,
        'sources_title' => null,
        'sources_text' => null,
        'podcast_link' => null,
        'podcast_code' => null,
        'sets' => [],
    ];

    public static function get(string $key)
    {
        $configured = config('workflows.content_fields');
        $configured = is_array($configured) ? $configured : [];

        return array_key_exists($key, $configured) ? $configured[$key] : (self::DEFAULTS[$key] ?? null);
    }

    public static function title(): string
    {
        return (string) self::get('title');
    }

    /** The Bard field the intro lives in, or null when the project has none. */
    public static function intro(): ?string
    {
        return self::handle('intro');
    }

    /** The main content field the rewritten document replaces. */
    public static function body(): string
    {
        return (string) self::get('body');
    }

    public static function seo(): string
    {
        return (string) self::get('seo');
    }

    /** The field naming the entity (module) an entry belongs to, sent as request context. */
    public static function entity(): ?string
    {
        return self::handle('entity');
    }

    /** The set field limiting a block to certain entities. */
    public static function scope(): ?string
    {
        return self::handle('scope');
    }

    public static function sourcesTitle(): ?string
    {
        return self::handle('sources_title');
    }

    public static function sourcesText(): ?string
    {
        return self::handle('sources_text');
    }

    public static function podcastLink(): ?string
    {
        return self::handle('podcast_link');
    }

    public static function podcastCode(): ?string
    {
        return self::handle('podcast_code');
    }

    /**
     * How the preview reads each Bard set type: `type => [kind, field, extra]`, where
     * kind is `html`, `heading` (extra = subheadline field), `video`, `asset` or `code`
     * (extra = label). Types not listed are left out of the preview.
     *
     * @return array<string, array<int, string>>
     */
    public static function sets(): array
    {
        $sets = self::get('sets');

        return is_array($sets) ? $sets : [];
    }

    private static function handle(string $key): ?string
    {
        $value = self::get($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
