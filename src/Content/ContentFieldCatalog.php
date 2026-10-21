<?php

namespace TakepartMedia\Workflows\Content;

use Statamic\Facades\Collection;
use Statamic\Fields\Blueprint;
use TakepartMedia\Workflows\Support\ContentFields;

/**
 * Which top-level blueprint fields of a collection can carry a workflow's
 * content, and which one is picked when a workflow names none.
 *
 * A workflow says what its content is in `metadata.content_fields` — a sorted
 * list of top-level field handles. Everything below such a field (Replicator
 * rows, Grid rows, Groups, Bard sets) is walked generically, so the catalog
 * only has to know the top level: the handle, its label, its type, and whether
 * the addon can read that type at all.
 *
 * Fields are listed across all entry blueprints of a collection, hidden ones
 * included — a hidden blueprint only stays out of the "create" menu, its
 * entries still exist and still go through the workflow. Deduplicated by
 * handle in blueprint order: the first blueprint declaring a handle decides
 * its label and type, `blueprints` lists every blueprint that has it.
 */
class ContentFieldCatalog
{
    /** Field types the content walker can read (structure or text). */
    public const SUPPORTED = ['bard', 'replicator', 'grid', 'group', 'markdown', 'text', 'textarea', 'code', 'video', 'assets'];

    /**
     * Types auto-detection picks from, in no particular order — the blueprint
     * order decides. Deliberately narrower than SUPPORTED: a lone `text` field
     * is usually a subtitle or an anchor, not "the content".
     */
    public const AUTO_TYPES = ['bard', 'markdown', 'replicator'];

    /**
     * Per collection handle, cached for the process like
     * AssignmentCriteria::entryFields() — blueprints do not change during a
     * request, and the editor asks for every collection at once.
     *
     * @var array<string, array<int, array{handle: string, label: string, type: string, supported: bool, blueprints: array<int, string>}>>
     */
    private static array $fields = [];

    /**
     * Top-level fields per collection. Unknown collections map to an empty list.
     *
     * @param  array<int, string>  $collections
     * @return array<string, array<int, array{handle: string, label: string, type: string, supported: bool, blueprints: array<int, string>}>>
     */
    public static function fieldsFor(array $collections): array
    {
        $result = [];

        foreach ($collections as $handle) {
            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $result[$handle] = self::$fields[$handle] ??= self::collect($handle);
        }

        return $result;
    }

    /**
     * The field a workflow without `metadata.content_fields` reads from this
     * blueprint, or null when nothing qualifies.
     *
     * First the globally configured `content_fields.intro` / `body`, if the
     * blueprint has them — so a project that configured them keeps exactly
     * what it had (back-compat). Otherwise the first field whose type is in
     * AUTO_TYPES, in blueprint order.
     */
    public static function autoDetect(Blueprint $blueprint): ?string
    {
        return self::autoDetectAll($blueprint)[0] ?? null;
    }

    /**
     * Like autoDetect(), but keeps BOTH configured fields when the blueprint
     * has intro and body — the resolver reads the two of them, the editor's
     * "automatic: <field>" hint only names the first.
     *
     * @return array<int, string>
     */
    public static function autoDetectAll(Blueprint $blueprint): array
    {
        $fields = $blueprint->fields()->all();

        $configured = array_values(array_filter(
            [ContentFields::intro(), ContentFields::body()],
            fn ($handle) => is_string($handle) && $handle !== '' && $fields->has($handle),
        ));

        if ($configured !== []) {
            return array_values(array_unique($configured));
        }

        foreach ($fields as $handle => $field) {
            if (in_array($field->type(), self::AUTO_TYPES, true)) {
                return [(string) $handle];
            }
        }

        return [];
    }

    /**
     * The auto-detected field per collection: what the first entry blueprint
     * that yields anything detects. Null when none of them does — the linter
     * warns about that.
     *
     * @param  array<int, string>  $collections
     * @return array<string, string|null>
     */
    public static function autoDetectFor(array $collections): array
    {
        $result = [];

        foreach ($collections as $handle) {
            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $result[$handle] = null;

            foreach (Collection::find($handle)?->entryBlueprints() ?? [] as $blueprint) {
                if (($detected = self::autoDetect($blueprint)) !== null) {
                    $result[$handle] = $detected;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{handle: string, label: string, type: string, supported: bool, blueprints: array<int, string>}>
     */
    private static function collect(string $collection): array
    {
        $fields = [];

        foreach (Collection::find($collection)?->entryBlueprints() ?? [] as $blueprint) {
            foreach ($blueprint->fields()->all() as $handle => $field) {
                $handle = (string) $handle;

                if (isset($fields[$handle])) {
                    $fields[$handle]['blueprints'][] = $blueprint->handle();

                    continue;
                }

                $type = (string) $field->type();

                $fields[$handle] = [
                    'handle' => $handle,
                    'label' => (string) ($field->display() ?: $handle),
                    'type' => $type,
                    'supported' => in_array($type, self::SUPPORTED, true),
                    'blueprints' => [$blueprint->handle()],
                ];
            }
        }

        return array_values($fields);
    }
}
