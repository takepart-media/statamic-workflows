<?php

namespace TakepartMedia\Workflows\Content;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;
use Statamic\Facades\User;

/**
 * Turns two EntrySnapshots into the field-level change list the History diff
 * renders. Lives outside the controller because it has a second caller with a
 * very different question: DraftSaveLogger only wants to know *whether* a save
 * changed anything worth logging.
 *
 * Both questions MUST be answered by the same code. Raw snapshot equality is
 * useless as a change signal — Bard/Replicator write fresh set UIDs on every
 * save, so the stored JSON always differs even when the text is identical. The
 * only reliable test is the one this class already performs for display: render
 * both sides to the string the editor would see and compare those. Duplicating
 * that logic would produce History rows whose diff panel reports "no changes".
 */
class SnapshotDiffer
{
    /**
     * Entry attributes that live outside the blueprint's data — Statamic derives
     * them from the filename. EntrySnapshot adds them so the diff can see them,
     * and they always bypass the `history_fields` whitelist.
     */
    private const META_HANDLES = ['slug', 'date', 'published'];

    /** Snapshot keys that change on every save and are never worth diffing. */
    private const SNAPSHOT_NOISE = ['updated_by', 'updated_at'];

    /**
     * The blueprint tab Statamic puts meta fields into. Not a naming convention of
     * this project: Collection::ensureEntryBlueprintFields() places `slug`, `date`
     * and every taxonomy field in a tab called `sidebar`.
     */
    private const SIDEBAR_TAB = 'sidebar';

    /**
     * Every displayable difference between two snapshots.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  object|null  $entry  The entry the snapshots belong to — supplies the blueprint for labels and fieldtypes.
     * @param  array<int, string>  $whitelist  `metadata.history_fields`; empty means "show every changed field".
     * @return array<int, array{field: string, label: string, kind: string, summary: ?string, from: ?string, to: ?string, showValues: bool}>
     */
    public function changes(array $before, array $after, $entry = null, array $whitelist = []): array
    {
        return $this->collect($before, $after, $entry, $whitelist, false);
    }

    /**
     * Whether a single displayable field changed. Short-circuits on the first
     * one, so the common "nothing changed" save still walks every handle but the
     * expensive summary rendering stops as soon as there is an answer.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int, string>  $whitelist
     */
    public function hasChanges(array $before, array $after, $entry = null, array $whitelist = []): bool
    {
        // Identical arrays prove "no change". The reverse does NOT hold (see the
        // class docblock), so this shortcut only ever runs in this direction.
        if ($before === $after) {
            return false;
        }

        return $this->collect($before, $after, $entry, $whitelist, true) !== [];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int, string>  $whitelist
     * @return array<int, array<string, mixed>>
     */
    private function collect(array $before, array $after, $entry, array $whitelist, bool $stopAtFirst): array
    {
        $handles = array_unique(array_merge(array_keys($after), array_keys($before)));
        sort($handles);

        // These change on every save. Newer snapshots already exclude them
        // (EntrySnapshot), but older rows still carry them and would otherwise be
        // the only "change" a diff reports.
        $handles = array_values(array_diff($handles, self::SNAPSHOT_NOISE));

        $blueprint = $entry && method_exists($entry, 'blueprint') ? $entry->blueprint() : null;

        // The workflow YAML can whitelist which field handles the History diff
        // should surface (metadata.history_fields) — but that whitelist governs
        // CONTENT fields only. Sidebar and meta fields are always surfaced: a slug
        // rename, a date shift or a changed taxonomy is exactly the kind of edit an
        // editor needs to see, and expecting every project to list them by hand is
        // how they went missing in the first place. Omit the key entirely to show
        // every changed field.
        if ($whitelist !== []) {
            $handles = array_values(array_filter(
                $handles,
                fn (string $handle) => in_array($handle, $whitelist, true)
                    || $this->isMetaHandle($handle, $blueprint),
            ));
        }

        $changes = [];

        foreach ($handles as $handle) {
            $previous = $before[$handle] ?? null;
            $current = $after[$handle] ?? null;

            // Cheap identity check — cuts most no-op fields immediately.
            if ($previous === $current) {
                continue;
            }

            $kind = match (true) {
                $previous === null || $previous === '' || $previous === [] => 'added',
                $current === null || $current === '' || $current === [] => 'removed',
                default => 'modified',
            };

            $field = $blueprint?->field($handle);
            $type = $field?->type();
            $label = $this->fieldLabel($blueprint, $handle);

            $summary = $this->changeSummary($type, $field, $label, $previous, $current, $kind, $handle);

            // Content-level equality: Bard/Replicator writes new set-UIDs on
            // every save, so the raw JSON always differs even when nothing
            // meaningful changed. If the summary's from/to (the actual text
            // the user would see) is identical, skip this field.
            if ($summary['showValues']
                && (string) ($summary['from'] ?? '') === (string) ($summary['to'] ?? '')) {
                continue;
            }

            $changes[] = [
                'field' => $handle,
                'label' => $label,
                'kind' => $kind,
                'summary' => $summary['summary'],
                'from' => $summary['from'],
                'to' => $summary['to'],
                'showValues' => $summary['showValues'],
            ];

            if ($stopAtFirst) {
                return $changes;
            }
        }

        return $changes;
    }

    /**
     * Bucket a single field change into a compact display shape based on the
     * blueprint fieldtype. Scalars/choice/date get before/after strings
     * (`showValues: true`); assets, rich content and relationships collapse
     * to a single human-readable line via `summary`.
     *
     * @return array{summary: ?string, from: ?string, to: ?string, showValues: bool}
     */
    private function changeSummary(?string $type, $field, string $label, $before, $after, string $kind, string $handle = ''): array
    {
        $scalarTypes = ['text', 'textarea', 'slug', 'email', 'code', 'markdown', 'integer', 'float'];
        $choiceTypes = ['select', 'radio', 'button_group', 'checkboxes'];
        $assetTypes = ['assets', 'video'];
        $richTypes = ['bard', 'replicator', 'grid', 'array', 'yaml', 'template'];
        $relationshipTypes = ['entries', 'terms', 'users', 'taxonomies', 'user_roles', 'user_groups', 'sites'];

        // Bild / Video / Datei — show the file paths (or asset IDs) so the
        // redakteur sees which asset was swapped, not just "geändert".
        if (in_array($type, $assetTypes, true)) {
            return [
                'summary' => null,
                'from' => $this->extractAssetPaths($before),
                'to' => $this->extractAssetPaths($after),
                'showValues' => true,
            ];
        }

        // Bard / Replicator / Grid — flatten the nested tree to plain text
        // and show the before/after side-by-side. That's what the editor is
        // actually curious about ("wie sah der Text davor aus?"), not the
        // structural JSON.
        if (in_array($type, $richTypes, true)) {
            return [
                'summary' => null,
                'from' => $this->extractRichText($before),
                'to' => $this->extractRichText($after),
                'showValues' => true,
            ];
        }

        // entries / terms / users / taxonomies … — resolve the stored IDs to the
        // names an editor recognises, so a taxonomy or author change reads as
        // "Fabry, MCD → Fabry" instead of "2 → 1 linked items". Falls back to the
        // count line when nothing resolves (deleted term, foreign ID format).
        if (in_array($type, $relationshipTypes, true)) {
            $fromLabels = $this->resolveRelationshipLabels($type, $field, $before);
            $toLabels = $this->resolveRelationshipLabels($type, $field, $after);

            if ($fromLabels !== null || $toLabels !== null) {
                return [
                    'summary' => null,
                    'from' => $fromLabels ?? '—',
                    'to' => $toLabels ?? '—',
                    'showValues' => true,
                ];
            }

            $fromCount = is_array($before) ? count($before) : ($before ? 1 : 0);
            $toCount = is_array($after) ? count($after) : ($after ? 1 : 0);
            $summary = $fromCount !== $toCount
                ? (string) __('workflows::messages.change_relationship_count', ['from' => $fromCount, 'to' => $toCount])
                : (string) __('workflows::messages.change_relationship_modified');

            return [
                'summary' => $summary,
                'from' => null,
                'to' => null,
                'showValues' => false,
            ];
        }

        // Toggle — before/after as human strings.
        if ($type === 'toggle') {
            return [
                'summary' => null,
                'from' => (string) __('workflows::messages.change_bool_'.($before ? 'true' : 'false')),
                'to' => (string) __('workflows::messages.change_bool_'.($after ? 'true' : 'false')),
                'showValues' => true,
            ];
        }

        // Select / Radio / Button Group / Checkboxes — resolve option labels.
        if (in_array($type, $choiceTypes, true)) {
            return [
                'summary' => null,
                'from' => $this->resolveChoiceLabels($field, $before),
                'to' => $this->resolveChoiceLabels($field, $after),
                'showValues' => true,
            ];
        }

        // date/time — format both sides the same way, otherwise the word diff
        // reports a change purely because one side is an ISO string with an offset
        // and the other a bare date.
        if (in_array($type, ['date', 'time'], true)) {
            return [
                'summary' => null,
                'from' => $this->formatDateValue($before),
                'to' => $this->formatDateValue($after),
                'showValues' => true,
            ];
        }

        // Booleans that have no blueprint field — `published` is the one that
        // matters. Without this it falls into the scalar bucket below and renders
        // as "1" versus an empty string. Publishing gets its own wording; a
        // generic toggle reads as enabled/disabled.
        if ($type === null && (is_bool($before) || is_bool($after))) {
            $key = $handle === 'published' ? 'change_published_' : 'change_bool_';

            return [
                'summary' => null,
                'from' => (string) __('workflows::messages.'.$key.($before ? 'true' : 'false')),
                'to' => (string) __('workflows::messages.'.$key.($after ? 'true' : 'false')),
                'showValues' => true,
            ];
        }

        // Explicit scalar bucket.
        if (in_array($type, $scalarTypes, true) || $type === null && (is_scalar($before) || is_scalar($after))) {
            $fromStr = $before === null ? '—' : mb_substr((string) $before, 0, 500);
            $toStr = $after === null ? '—' : mb_substr((string) $after, 0, 500);

            return [
                'summary' => null,
                'from' => $fromStr,
                'to' => $toStr,
                'showValues' => true,
            ];
        }

        // Unknown or generic array — treat as opaque.
        return [
            'summary' => (string) __('workflows::messages.change_value_modified'),
            'from' => null,
            'to' => null,
            'showValues' => false,
        ];
    }

    /**
     * Flatten a Bard/Replicator/Grid value into a plain-text representation
     * for the side-by-side History diff. Walks the ProseMirror-shaped tree
     * (`type`/`content`/`text`/`attrs`) and concatenates text nodes, with
     * paragraph breaks between top-level blocks. For image/asset nodes in
     * Bard we emit a marker `[Bild: caption]` so the redakteur sees the
     * placeholder rather than a gap.
     */
    private function extractRichText($value): string
    {
        if (is_string($value)) {
            return $value === '' ? '—' : $value;
        }

        if (! is_array($value)) {
            return '—';
        }

        $parts = [];
        foreach ($value as $node) {
            $text = $this->flattenRichNode($node);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $out = trim(implode("\n\n", $parts));

        return $out === '' ? '—' : mb_substr($out, 0, 2000);
    }

    private function flattenRichNode($node): string
    {
        if (! is_array($node)) {
            return (string) $node;
        }

        // Bard text leaf: {type: 'text', text: '...'}.
        if (isset($node['text']) && is_string($node['text'])) {
            return $node['text'];
        }

        $type = $node['type'] ?? null;

        // Bard image node: {type: 'image', attrs: {src, alt, ...}} — surface
        // the alt/caption so the redakteur sees "there was an image here".
        if ($type === 'image') {
            $alt = $node['attrs']['alt'] ?? $node['attrs']['caption'] ?? '';
            $src = $node['attrs']['src'] ?? $node['attrs']['image'] ?? '';
            $label = trim((string) ($alt !== '' ? $alt : basename((string) $src)));

            return '[Bild'.($label !== '' ? ": {$label}" : '').']';
        }

        // Bard set-block: {type: 'set', attrs: {id: 'bt_...', values: {type:
        // '<handle>', ...}}}. Emit the SET HANDLE from `attrs.values.type`
        // as a bracketed marker; never emit the internal Bard UID.
        if ($type === 'set' && isset($node['attrs']['values']) && is_array($node['attrs']['values'])) {
            $values = $node['attrs']['values'];
            $handle = (string) ($values['type'] ?? 'set');
            $inner = [];
            foreach ($values as $k => $v) {
                if (in_array($k, ['type', '_id', 'enabled', 'id'], true)) {
                    continue;
                }
                $flat = $this->flattenRichNode($v);
                if ($flat !== '') {
                    $inner[] = $flat;
                }
            }
            $body = trim(implode(' ', $inner));

            return "[{$handle}]".($body !== '' ? " {$body}" : '');
        }

        // Replicator set-block (flat form): {type: '<handle>', field: '...'}.
        // Emit the type as a bracketed marker + the block's non-meta fields.
        if ($type !== null && ! isset($node['content']) && ! isset($node['text'])) {
            $handle = (string) $type;
            $inner = [];
            foreach ($node as $k => $v) {
                if (in_array($k, ['type', '_id', 'enabled', 'attrs', 'id'], true)) {
                    continue;
                }
                $flat = $this->flattenRichNode($v);
                if ($flat !== '') {
                    $inner[] = $flat;
                }
            }
            $body = trim(implode(' ', $inner));

            return "[{$handle}]".($body !== '' ? " {$body}" : '');
        }

        // Container node with children — recurse.
        if (isset($node['content']) && is_array($node['content'])) {
            $inner = [];
            foreach ($node['content'] as $child) {
                $flat = $this->flattenRichNode($child);
                if ($flat !== '') {
                    $inner[] = $flat;
                }
            }

            return trim(implode(' ', $inner));
        }

        // Fallback — array of stuff. Recurse but never emit set UIDs.
        $inner = [];
        foreach ($node as $k => $v) {
            if (in_array($k, ['_id', 'id'], true)) {
                continue;
            }
            if (is_array($v) || is_string($v)) {
                $flat = $this->flattenRichNode($v);
                if ($flat !== '') {
                    $inner[] = $flat;
                }
            }
        }

        return trim(implode(' ', $inner));
    }

    /**
     * Flatten an Assets/Video snapshot value into a comma-separated list of
     * file paths (or IDs) for the History diff. Handles both single-item
     * (max_items=1) scalars and array values.
     */
    private function extractAssetPaths($value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        $items = is_array($value) ? $value : [$value];

        return collect($items)
            ->map(function ($item) {
                if (is_array($item)) {
                    return (string) ($item['path'] ?? $item['url'] ?? $item['id'] ?? json_encode($item));
                }

                return (string) $item;
            })
            ->filter()
            ->implode(', ');
    }

    /**
     * Map a select/radio/checkboxes value to its human option label(s) via
     * the blueprint field's `config.options`. Falls back to the raw value.
     */
    private function resolveChoiceLabels($field, $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        $options = $field?->config()['options'] ?? [];
        // Statamic supports both list-of-strings and key-value maps.
        $lookup = [];
        foreach ($options as $k => $v) {
            if (is_int($k)) {
                if (is_array($v) && isset($v['value'])) {
                    $lookup[(string) $v['value']] = (string) ($v['label'] ?? $v['value']);
                } else {
                    $lookup[(string) $v] = (string) $v;
                }
            } else {
                $lookup[(string) $k] = is_array($v) ? (string) ($v['label'] ?? $k) : (string) $v;
            }
        }

        $values = is_array($value) ? $value : [$value];

        return collect($values)
            ->map(fn ($v) => $lookup[(string) $v] ?? (string) $v)
            ->implode(', ');
    }

    /**
     * Display label for a blueprint field handle. Falls back to a humanised
     * version of the handle when the blueprint doesn't declare the field
     * (e.g. legacy data still in the entry after a blueprint change).
     */
    private function fieldLabel($blueprint, string $handle): string
    {
        $display = $blueprint?->field($handle)?->display();

        if (is_string($display) && $display !== '') {
            return $display;
        }

        // Same escape hatch as state/action labels: a project can localise any
        // handle via `field_<handle>`. Needed for the meta attributes that have no
        // blueprint field at all — `published` would otherwise read "Published"
        // in a German CP.
        $key = "workflows::messages.field_{$handle}";
        $translated = __($key);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return $handle === '' ? '' : (string) Str::of($handle)->replace('_', ' ')->title();
    }

    /**
     * Resolve a relationship field's stored IDs to display names, comma-separated.
     * Returns null when nothing could be resolved, so the caller can fall back to
     * the count-only summary rather than printing raw IDs.
     *
     * Term IDs are the fiddly part: with a single-taxonomy `terms` field Statamic
     * stores the bare slug and prefixes it at read time. Mirrors the normalisation
     * in Statamic's own Fieldtypes\Terms::toItemArray().
     */
    private function resolveRelationshipLabels(?string $type, $field, $value): ?string
    {
        $ids = collect(is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]))
            ->filter(fn ($id) => is_string($id) || is_numeric($id))
            ->map(fn ($id) => (string) $id);

        if ($ids->isEmpty()) {
            return null;
        }

        $taxonomies = collect($field && method_exists($field, 'get') ? ($field->get('taxonomies') ?? []) : [])
            ->filter()
            ->values();

        $labels = $ids->map(function (string $id) use ($type, $taxonomies) {
            $resolved = match ($type) {
                'terms', 'taxonomies' => $this->termTitle($id, $taxonomies),
                'users' => User::find($id)?->name() ?? User::find($id)?->email(),
                'entries' => Entry::find($id)?->value('title'),
                default => null,
            };

            // Keep the raw ID rather than dropping the item — an editor still sees
            // that something was added or removed.
            return is_string($resolved) && $resolved !== '' ? $resolved : $id;
        });

        // Nothing actually resolved → the caller's count summary is more honest
        // than a list of opaque IDs.
        if ($labels->values()->all() === $ids->values()->all()) {
            return null;
        }

        return $labels->implode(', ');
    }

    /**
     * Title of a term, accepting both the `taxonomy::slug` form and the bare slug
     * a single-taxonomy field stores.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $taxonomies
     */
    private function termTitle(string $id, $taxonomies): ?string
    {
        if (! str_contains($id, '::')) {
            $handle = $taxonomies->first();

            if (! is_string($handle) || $handle === '') {
                return null;
            }

            $id = "{$handle}::{$id}";
        }

        return Term::find($id)?->title();
    }

    /**
     * Render a date/time value for the diff. Both sides go through this so the
     * word diff never fires on formatting alone.
     */
    private function formatDateValue($value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (! is_scalar($value)) {
            return (string) __('workflows::messages.change_value_modified');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    /**
     * Whether a handle is sidebar/meta and therefore exempt from the
     * `history_fields` whitelist: either a system attribute (slug/date/published)
     * or a blueprint field sitting in the `sidebar` tab.
     */
    private function isMetaHandle(string $handle, $blueprint = null): bool
    {
        if (in_array($handle, self::META_HANDLES, true)) {
            return true;
        }

        return $blueprint !== null
            && method_exists($blueprint, 'hasFieldInTab')
            && $blueprint->hasFieldInTab($handle, self::SIDEBAR_TAB);
    }
}
