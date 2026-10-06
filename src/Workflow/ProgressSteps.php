<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * The workflow's places as a progress bar: one main line of steps, plus at most
 * one side branch. The single place that knows what belongs on the line and what
 * hangs off it — same role TransitionActions plays for action spellings.
 *
 * Resolved on the SERVER rather than in the component, for one reason worth
 * naming: "which places are steps" is three rules deep (declaration order, minus
 * the auto-injected places, minus the ones marked as a branch), and two of those
 * rules are already written down in PHP. A client-side copy would be a second
 * definition of the same thing, kept in step by hand.
 *
 * What it deliberately does NOT ship: colours and their fallbacks. `stateColor()`
 * on the client already resolves a place's `metadata.color` through the
 * `stateColors` prop and falls back to a per-handle default map — sending a colour
 * from here would override that chain with a worse-informed answer.
 *
 * THE LINE IS AN APPROXIMATION, and knowingly so. Order comes from the YAML, not
 * from walking the transitions: the graph is cyclic (every `reject` transition
 * points backwards), so a topological order would need arbitrary tie-breaking and
 * would still not be "the happy path". The author already controls the YAML order
 * with the reorder buttons in the settings editor, which makes it the one order
 * somebody actually decided.
 */
class ProgressSteps
{
    /**
     * `metadata.branch` — a place that is not a step on the way but a turn-off
     * from it: a failure state, a parking place, anything a subject can end up in
     * without it being progress.
     *
     * Read here and nowhere else in PHP. The flag has no behaviour attached; it
     * says how a place is DRAWN, which is why it lives with the drawing and not
     * with the engine.
     */
    public const BRANCH = 'branch';

    /**
     * `metadata.progress_orientation` — how this workflow's bar is laid out, and
     * therefore where it sits on the instance page: `horizontal` above the summary
     * content, `vertical` in a column to its left.
     *
     * Per workflow rather than per user or per page, because it follows the SHAPE
     * of the definition: a seven-step post manager reads well as a wide band above
     * the content, a four-step localization workflow next to it. That is a property
     * of the workflow, and the person who arranges the places is the one who can
     * judge it — hence a setting in the same editor, next to the places themselves.
     *
     * One key decides both the layout and the position on purpose. Two settings
     * could disagree ("vertical, above"), and neither answer would be wrong enough
     * to reject, so the CP would just render something nobody chose.
     */
    public const ORIENTATION = 'progress_orientation';

    public const ORIENTATION_HORIZONTAL = 'horizontal';

    public const ORIENTATION_VERTICAL = 'vertical';

    public const ORIENTATIONS = [self::ORIENTATION_HORIZONTAL, self::ORIENTATION_VERTICAL];

    /**
     * @param  array<string, mixed>  $raw  the whole workflow definition
     * @param  string|null  $current  the place the subject sits in, or null when it
     *                                is not part of this workflow yet
     * @return array{main: array<int, array{handle: string, label: string}>, branch: array{handle: string, label: string, after: string|null}|null, current: string|null}
     */
    public static function for(array $raw, ?string $current): array
    {
        $places = $raw['places'] ?? [];
        $main = self::mainLine($places, self::offLine($raw));

        return [
            'main' => collect($main)
                ->map(fn ($place, $handle) => [
                    'handle' => (string) $handle,
                    'label' => self::label((string) $handle),
                ])
                ->values()
                ->all(),
            // Only ever set when the subject is standing somewhere that is not a
            // step. That is the whole "a branch shows up when it is taken" rule,
            // decided here so the component has nothing to filter: what it is
            // handed is what it draws.
            'branch' => $current === null || array_key_exists($current, $main)
                ? null
                : [
                    'handle' => $current,
                    'label' => self::label($current),
                    'after' => self::anchor($raw, $places, $main, $current),
                ],
            'current' => $current,
        ];
    }

    /**
     * How this workflow's bar is laid out. Horizontal unless the definition says
     * otherwise — an unreadable value falls back rather than propagating, because
     * the writer already refuses to store one and a hand-edited YAML should not
     * take the page down over a presentation detail.
     *
     * @param  array<string, mixed>  $raw  the whole workflow definition
     */
    public static function orientation(array $raw): string
    {
        $value = $raw['metadata'][self::ORIENTATION] ?? null;

        return in_array($value, self::ORIENTATIONS, true) ? $value : self::ORIENTATION_HORIZONTAL;
    }

    /**
     * How far along the main line the subject stands — the index of the step it is
     * on, or of the step a branch was taken from. Null when it is nowhere on the
     * line at all (no state, or a branch with no anchor).
     *
     * Everything BEFORE this index is finished. Split out because two renderers now
     * need that rule — the CP bar and the assignment mail — and a second copy of
     * "the anchor counts as reached" is exactly the kind of thing that drifts.
     * WorkflowProgress.vue holds the unavoidable JS twin (`fillIndex`); if this
     * changes, that changes.
     *
     * @param  array{main: array<int, array{handle: string, label: string}>, branch: array{after: string|null}|null, current: string|null}  $steps  the output of for()
     */
    public static function reachedIndex(array $steps): ?int
    {
        if ($steps['current'] === null) {
            return null;
        }

        foreach ($steps['main'] as $i => $step) {
            if ($step['handle'] === $steps['current']) {
                return $i;
            }
        }

        $after = $steps['branch']['after'] ?? null;

        if ($after === null) {
            return null;
        }

        foreach ($steps['main'] as $i => $step) {
            if ($step['handle'] === $after) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The index of the last FINISHED step, or null when none is.
     *
     * One short of reachedIndex() while the subject stands on the line — the step you
     * are on is not behind you. On a branch it IS reachedIndex(): the subject really
     * did pass through the anchor and then turned off.
     *
     * That asymmetry is the whole reason this is a method. It was written out twice —
     * once in the CP bar, once in the assignment mail — and the second copy got it
     * wrong immediately, leaving a branch's anchor unticked in the mail and ticked in
     * the CP. WorkflowProgress.vue holds the unavoidable JS twin (`doneUntil`).
     *
     * @param  array{main: array<int, array{handle: string, label: string}>, branch: array{after: string|null}|null, current: string|null}  $steps  the output of for()
     */
    public static function doneUntilIndex(array $steps): ?int
    {
        $reached = self::reachedIndex($steps);

        if ($reached === null) {
            return null;
        }

        $onLine = collect($steps['main'])->contains(fn (array $step) => $step['handle'] === $steps['current']);

        return $onLine ? $reached - 1 : $reached;
    }

    /**
     * Whether a place is drawn off the line.
     *
     * @param  array<string, mixed>  $place
     */
    public static function isBranch(array $place): bool
    {
        return ($place[self::BRANCH] ?? $place['metadata'][self::BRANCH] ?? false) === true;
    }

    /**
     * The steps, in declaration order.
     *
     * Two subtractions, and the first one is not cosmetic — see offLine().
     *
     * @param  array<string, mixed>  $places
     * @param  array<int, string>  $offLine  handles that exist but are not steps
     * @return array<string, mixed>  handle => place, order preserved
     */
    private static function mainLine(array $places, array $offLine = []): array
    {
        return collect($places)
            ->reject(fn ($place, $handle) => in_array((string) $handle, $offLine, true))
            ->reject(fn ($place) => is_array($place) && self::isBranch($place))
            ->all();
    }

    /**
     * Places that exist in the definition but are not steps on the way.
     *
     * Two sources, one reason: neither belongs after the end of the flow.
     * `pending_revision` is APPENDED by DefinitionLoader::raw(), so leaving it in
     * would make a revision-enabled workflow appear to finish in "Unpublished
     * changes". The ageing places from `metadata.sla` are the author's own and
     * usually declared after the publish place — which is where they belong in the
     * picture, but they are what happens once the flow is over, not a step in it.
     *
     * WorkflowResolver::finalPlace() subtracts the same set for the same reason.
     *
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    private static function offLine(array $raw): array
    {
        return array_merge(
            WorkflowDefinitionWriter::RESERVED_PLACE_HANDLES,
            SlaSettings::places($raw),
        );
    }

    /**
     * Which step the branch hangs off, or null for a branch with nowhere to hang.
     *
     * Three cases, because "where did this come from" has three different answers:
     *
     * - An OFF-LINE place (injected `pending_revision`, or a configured ageing
     *   place) only ever happens to content that reached the end — so it hangs off
     *   the done place. donePlace() rather than publishPlaces() because the
     *   translation workflow declares no `allow_publish` at all and names its
     *   finish line with `seed_published_place` instead.
     * - A DECLARED branch hangs off the nearest step BEFORE it in the YAML. The
     *   author put it there; position is the only statement of intent available,
     *   and it is the same statement the reorder buttons edit.
     * - A place the definition does not contain at all gets null. That is a
     *   leftover state row — a place that was removed while a subject still sat in
     *   it — and inventing an anchor for it would draw a relationship that does
     *   not exist. Rendered standing alone, which is the honest picture and makes
     *   the leftover visible instead of silently dropping the subject off the bar.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $places
     * @param  array<string, mixed>  $main
     */
    private static function anchor(array $raw, array $places, array $main, string $current): ?string
    {
        if ($main === []) {
            return null;
        }

        if (in_array($current, self::offLine($raw), true)) {
            $done = app(WorkflowResolver::class)->donePlace($raw);

            return $done !== null && array_key_exists($done, $main)
                ? $done
                : (string) array_key_last($main);
        }

        if (! array_key_exists($current, $places)) {
            return null;
        }

        // The last step encountered before reaching $current. Null when the branch
        // is declared before any step — the writer refuses that for the initial
        // place, but a branch may legitimately sit above the first real step.
        $preceding = null;

        foreach ($places as $handle => $place) {
            if ((string) $handle === $current) {
                break;
            }

            if (array_key_exists((string) $handle, $main)) {
                $preceding = (string) $handle;
            }
        }

        return $preceding;
    }

    /**
     * Through DefinitionLoader so the resolution chain is the one every other
     * caller gets: `workflows::messages.state_<handle>`, then the YAML label, then
     * the title-cased handle.
     */
    private static function label(string $handle): string
    {
        return app(DefinitionLoader::class)->stateLabel($handle);
    }
}
