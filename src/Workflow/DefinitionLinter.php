<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Facades\Collection;
use TakepartMedia\Workflows\Content\ContentFieldCatalog;

/**
 * Warnings about a workflow definition — things that are allowed but probably
 * not meant. Unlike WorkflowDefinitionWriter::collectErrors() nothing here ever
 * blocks a save: every rule has a legitimate exception, and the editor shows
 * the warnings next to the graph instead of refusing it.
 *
 * Built to run on definitions the writer would reject, too (the validate
 * endpoint answers both at once), so it reads every key defensively.
 *
 * Each warning has the shape of a writer error:
 * `{path, code, message, node: {kind, handle}}`.
 */
class DefinitionLinter
{
    public function __construct(
        private DefinitionLoader $loader,
        private WorkflowResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $definition  a prepared definition (see WorkflowDefinitionWriter::prepare())
     * @return array<int, array{path: string, code: string, message: string, node: array{kind: string, handle: string|null}}>
     */
    public function lint(string $name, array $definition): array
    {
        $places = is_array($definition['places'] ?? null) ? array_map('strval', array_keys($definition['places'])) : [];
        $transitions = is_array($definition['transitions'] ?? null) ? $definition['transitions'] : [];
        $edges = $this->edges($transitions);

        return array_merge(
            $this->unreachable($definition, $places, $edges),
            $this->deadEnds($definition, $places, $edges),
            $this->duplicateEdges($edges),
            $this->foreignCollections($name, $definition),
            $this->contentFields($definition),
            $this->revisionsOff($definition),
        );
    }

    /**
     * Every transition as `{handle, from: [...], to}` — only the well-formed
     * parts; a broken transition is the writer's business, not the linter's.
     *
     * @param  array<mixed, mixed>  $transitions
     * @return array<int, array{handle: string, from: array<int, string>, to: string|null}>
     */
    private function edges(array $transitions): array
    {
        $edges = [];

        foreach ($transitions as $handle => $transition) {
            if (! is_array($transition)) {
                continue;
            }

            $to = $transition['to'] ?? null;

            $edges[] = [
                'handle' => (string) $handle,
                'from' => array_values(array_filter((array) ($transition['from'] ?? []), 'is_string')),
                'to' => is_string($to) ? $to : null,
            ];
        }

        return $edges;
    }

    /**
     * Places no chain of transitions leads to from the initial place.
     *
     * The places the addon itself moves subjects into count as reachable on
     * their own: the ageing places (scheduler), the plain-language completed and
     * failed places (poll command), the source-draft place (cascade) and the
     * seed place (backfill). An author rightly draws no transition into those.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $places
     * @param  array<int, array{handle: string, from: array<int, string>, to: string|null}>  $edges
     * @return array<int, array<string, mixed>>
     */
    private function unreachable(array $definition, array $places, array $edges): array
    {
        $initial = $definition['initial_place'] ?? null;

        if (! is_string($initial) || ! in_array($initial, $places, true)) {
            return [];
        }

        $queue = array_values(array_unique(array_merge([$initial], array_intersect($this->systemTargets($definition), $places))));
        $seen = array_fill_keys($queue, true);

        while ($queue !== []) {
            $place = array_shift($queue);

            foreach ($edges as $edge) {
                if ($edge['to'] !== null && ! isset($seen[$edge['to']]) && in_array($place, $edge['from'], true)) {
                    $seen[$edge['to']] = true;
                    $queue[] = $edge['to'];
                }
            }
        }

        $warnings = [];

        foreach ($places as $place) {
            if (! isset($seen[$place])) {
                $warnings[] = $this->warning("places.{$place}", 'place_unreachable', ['place' => $place]);
            }
        }

        return $warnings;
    }

    /**
     * Places with no way out that are not meant to be an end: anything but a
     * publish place, the flow's final place (WorkflowResolver::finalPlace()),
     * the seed place, the two plain-language result places, and a place that
     * waits for a rewrite (pending_rewrite_spinner) — the poll command moves
     * subjects out of that one.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $places
     * @param  array<int, array{handle: string, from: array<int, string>, to: string|null}>  $edges
     * @return array<int, array<string, mixed>>
     */
    private function deadEnds(array $definition, array $places, array $edges): array
    {
        try {
            $final = $this->resolver->finalPlace($definition);
        } catch (\Throwable) {
            $final = null;
        }

        $exempt = array_filter([
            $final,
            $definition['metadata']['seed_published_place'] ?? null,
            $definition['metadata']['plain_language_completed_place'] ?? null,
            $definition['metadata']['plain_language_failed_place'] ?? null,
        ], 'is_string');

        $warnings = [];

        foreach ($places as $place) {
            $config = $definition['places'][$place] ?? null;

            if (in_array($place, $exempt, true)
                || (is_array($config) && ($config['metadata']['allow_publish'] ?? false) === true)
                || (is_array($config) && $this->waitsForRewrite($config))) {
                continue;
            }

            $leaves = false;

            foreach ($edges as $edge) {
                if (in_array($place, $edge['from'], true)) {
                    $leaves = true;
                    break;
                }
            }

            if (! $leaves) {
                $warnings[] = $this->warning("places.{$place}", 'place_dead_end', ['place' => $place]);
            }
        }

        return $warnings;
    }

    /**
     * A second transition for a from→to pair an earlier one already covers.
     * Sometimes deliberate (two buttons with different gates), usually a copy
     * left behind — hence a warning on the later transition only.
     *
     * @param  array<int, array{handle: string, from: array<int, string>, to: string|null}>  $edges
     * @return array<int, array<string, mixed>>
     */
    private function duplicateEdges(array $edges): array
    {
        $firstFor = [];
        $warnings = [];

        foreach ($edges as $edge) {
            if ($edge['to'] === null) {
                continue;
            }

            foreach (array_unique($edge['from']) as $from) {
                $pair = $from."\0".$edge['to'];

                if (! isset($firstFor[$pair])) {
                    $firstFor[$pair] = $edge['handle'];

                    continue;
                }

                $warnings[] = $this->warning("transitions.{$edge['handle']}", 'duplicate_edge', [
                    'handle' => $edge['handle'],
                    'other' => $firstFor[$pair],
                    'from' => $from,
                    'to' => $edge['to'],
                ]);
            }
        }

        return $warnings;
    }

    /**
     * Collections another workflow owns (WorkflowResolver::forCollection()).
     * Sharing one with a localization workflow is the normal setup and not
     * reported; two AUTHORING workflows on one collection is, because only the
     * alphabetically first gets to create in it.
     *
     * Ownership is computed the way forCollection() does, over the active
     * workflows plus this definition as if it were active and saved — an
     * inactive workflow being edited is exactly when this is worth knowing.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, array<string, mixed>>
     */
    private function foreignCollections(string $name, array $definition): array
    {
        if ($this->resolver->isLocalizationWorkflow($definition)) {
            return [];
        }

        $names = array_values(array_unique(array_merge($this->loader->all(), [$name])));
        sort($names);

        $warnings = [];

        foreach (array_values((array) ($definition['collections'] ?? [])) as $index => $collection) {
            if (! is_string($collection)) {
                continue;
            }

            foreach ($names as $candidate) {
                $raw = $candidate === $name ? $definition : $this->rawOrNull($candidate);

                if ($raw === null
                    || ! in_array($collection, (array) ($raw['collections'] ?? []), true)
                    || $this->resolver->isLocalizationWorkflow($raw)) {
                    continue;
                }

                if ($candidate !== $name) {
                    $warnings[] = $this->warning("collections.{$index}", 'collection_owned_elsewhere', [
                        'collection' => $collection,
                        'owner' => $candidate,
                    ]);
                }

                break;
            }
        }

        return $warnings;
    }

    /**
     * Content fields that only some governed collections have, and collections
     * where an unconfigured workflow would find no content at all.
     *
     * Both are legal: ContentFieldResolver filters per entry and falls back to
     * auto-detection, so a field missing from a collection's blueprints just
     * means that collection reads something else (or nothing). Worth a warning
     * because the summary of those entries then looks different from what the
     * author picked. A field no blueprint has at all is the writer's
     * `content_field_unknown`, not repeated here.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, array<string, mixed>>
     */
    private function contentFields(array $definition): array
    {
        $collections = array_values(array_filter(
            (array) ($definition['collections'] ?? []),
            fn ($handle) => is_string($handle) && $handle !== '',
        ));

        if ($collections === []) {
            return [];
        }

        $configured = $definition['metadata']['content_fields'] ?? null;
        $configured = is_array($configured)
            // Keys kept, so a warning points at the entry's real position in the list.
            ? array_unique(array_filter($configured, fn ($handle) => is_string($handle) && $handle !== ''))
            : [];

        try {
            if ($configured === []) {
                $warnings = [];

                foreach (ContentFieldCatalog::autoDetectFor($collections) as $collection => $detected) {
                    if ($detected === null) {
                        $warnings[] = $this->warning('metadata.content_fields', 'content_field_auto_none', ['collection' => $collection]);
                    }
                }

                return $warnings;
            }

            $handles = array_map(
                fn (array $fields) => array_column($fields, 'handle'),
                ContentFieldCatalog::fieldsFor($collections),
            );
        } catch (\Throwable) {
            // A collection that does not exist is reported by the writer.
            return [];
        }

        $warnings = [];

        foreach ($configured as $index => $field) {
            $missing = array_keys(array_filter($handles, fn (array $present) => ! in_array($field, $present, true)));

            // Missing everywhere → the writer refuses it; missing nowhere → fine.
            if ($missing === [] || count($missing) === count($handles)) {
                continue;
            }

            $warnings[] = $this->warning("metadata.content_fields.{$index}", 'content_field_partial', [
                'field' => $field,
                'collections' => implode(', ', $missing),
            ]);
        }

        return $warnings;
    }

    /**
     * Whether a place shows the rewrite spinner, as the boolean flag or as the
     * place action.
     *
     * @param  array<string, mixed>  $place
     */
    private function waitsForRewrite(array $place): bool
    {
        if (($place['metadata']['pending_rewrite_spinner'] ?? null) === true) {
            return true;
        }

        return collect(is_array($place['metadata']['actions'] ?? null) ? $place['metadata']['actions'] : [])
            ->contains(fn ($action) => is_array($action) && ($action['type'] ?? null) === PlaceActions::PENDING_REWRITE_SPINNER);
    }

    /**
     * Places the addon moves subjects into without a transition.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function systemTargets(array $definition): array
    {
        $metadata = is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [];

        $targets = [
            $metadata['seed_published_place'] ?? null,
            $metadata['plain_language_completed_place'] ?? null,
            $metadata['plain_language_failed_place'] ?? null,
            $metadata[StaleLocalizations::PLACE_KEY] ?? null,
        ];

        try {
            $targets = array_merge($targets, SlaSettings::places($definition));
        } catch (\Throwable) {
            // A malformed ageing block is reported by the writer.
        }

        return array_values(array_filter($targets, fn ($place) => is_string($place) && $place !== ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawOrNull(string $name): ?array
    {
        try {
            return $this->loader->raw($name);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Governed collections without revisions. Then saving a published post puts
     * the change live at once: no working copy, no "Unpublished changes" step,
     * and the editor offers "Save & Publish".
     *
     * - `metadata.revisions: true` (CollectionRevisions) switches them on at
     *   save — only a missing Statamic Pro / global switch is worth a warning.
     * - `metadata.revisions: false` is a decision, not an oversight: quiet.
     * - Absent: each collection without revisions is named.
     *
     * Only for workflows that publish (an `allow_publish` place) and only while
     * `workflows.revisions` is on; a collection that does not exist is the
     * writer's business.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, array<string, mixed>>
     */
    private function revisionsOff(array $definition): array
    {
        if (! config('workflows.revisions', true)) {
            return [];
        }

        $publishes = collect(is_array($definition['places'] ?? null) ? $definition['places'] : [])
            ->contains(fn ($place) => is_array($place) && ($place['metadata']['allow_publish'] ?? false) === true);

        if (! $publishes) {
            return [];
        }

        $wanted = CollectionRevisions::wanted($definition);

        if ($wanted === false) {
            return [];
        }

        if ($wanted === true) {
            return CollectionRevisions::available()
                ? []
                : [$this->warning('metadata.'.CollectionRevisions::KEY, 'revisions_unavailable', [])];
        }

        $warnings = [];

        foreach (array_values((array) ($definition['collections'] ?? [])) as $index => $handle) {
            $collection = is_string($handle) ? Collection::find($handle) : null;

            if ($collection !== null && ! $collection->revisionsEnabled()) {
                $warnings[] = $this->warning("collections.{$index}", 'collection_revisions_off', ['collection' => $handle]);
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $replace
     * @return array{path: string, code: string, message: string, node: array{kind: string, handle: string|null}}
     */
    private function warning(string $path, string $code, array $replace): array
    {
        return [
            'path' => $path,
            'code' => $code,
            'message' => (string) __("workflows::messages.lint_{$code}", $replace),
            'node' => DefinitionValidationException::nodeFor($path),
        ];
    }
}
