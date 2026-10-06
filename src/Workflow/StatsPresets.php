<?php

namespace TakepartMedia\Workflows\Workflow;

use Carbon\Carbon;

/**
 * What the three project-wide cards of the dashboard's stat row count — "awaiting
 * approval", "failed translations", "published this week" — as subject-id sets,
 * and the names under which the "Alle Beiträge" table can be narrowed to the
 * same posts (the listing's `preset` parameter).
 *
 * Extracted from QueueStats when the cards stopped opening a stack of their own
 * and started filtering the overview table instead: the card's figure and the
 * table it filters must read the SAME sets, and two copies of "which subjects
 * sit at a gate" would sooner or later disagree about one workflow. Both callers
 * then turn the sets into POSTS through the one row matcher in
 * WorkflowController (matchingPostCount() / the listing's preset filter), so the
 * number on the card is the number of rows the table shows.
 *
 * What counts is read off the DEFINITION, never off a hardcoded list of place
 * handles — but not all three the same way, and the difference is the point:
 *
 * - awaiting approval counts the STRUCTURE of the workflow
 *   (TransitionGates::gatePlaces(): a place whose exits name a group or role, or
 *   a non-branch place with more than one exit — a decision, not a hand-over);
 * - failed translations counts the one place a workflow NAMES as its failure
 *   park (`metadata.plain_language_failed_place`, see FailedTranslationRetry);
 * - published this week counts the state COLOR, because "done" genuinely is
 *   what `metadata.color: green` says, and the same reading already drives the
 *   urgency order in PersonalWorkItems::URGENCY.
 *
 * "Awaiting approval" used to read the color too — blue meant "waiting". It
 * counted 278 on a live project, because the initial place of the plain-language
 * manager is blue and 277 posts have simply never been translated. Nobody is
 * waiting for a decision on those; a color says how a place LOOKS, and only the
 * transitions say whether somebody has to act.
 */
class StatsPresets
{
    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const FAILED_TRANSLATIONS = 'failed_translations';

    public const PUBLISHED_THIS_WEEK = 'published_this_week';

    public const ALL = [self::AWAITING_APPROVAL, self::FAILED_TRANSLATIONS, self::PUBLISHED_THIS_WEEK];

    /**
     * "Finished". The same family PersonalWorkItems::meta() reads as "done"
     * when it decides between a duration and a date.
     */
    private const DONE_COLORS = ['green', 'emerald'];

    public function __construct(
        private readonly DefinitionLoader $loader,
        private readonly WorkflowStateStore $states,
        private readonly WorkflowEventStore $events,
        private readonly FailedTranslationRetry $failed,
    ) {}

    public static function isPreset(mixed $preset): bool
    {
        return is_string($preset) && in_array($preset, self::ALL, true);
    }

    /**
     * The subjects behind a preset, per workflow, within `$only` (null: every
     * active workflow). Workflows contributing nothing are dropped.
     *
     * @param  array<int, string>|null  $only
     * @return array<string, array<int, string>>
     */
    public function subjects(string $preset, ?array $only = null): array
    {
        return match ($preset) {
            self::AWAITING_APPROVAL => $this->atGates($this->workflows($only)),
            self::FAILED_TRANSLATIONS => $this->failed->subjects($only),
            self::PUBLISHED_THIS_WEEK => $this->published($only, Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()),
            default => [],
        };
    }

    /**
     * The workflows a preset CAN draw from — read off the definitions, not off
     * today's data, so a card points at the same table whether it says 0 or 30.
     * One name: the overview switches to that workflow's button; several: to
     * "Alle". Entry-backed workflows only — the overview lists nothing else.
     *
     * @param  array<int, string>|null  $only
     * @return array<int, string>
     */
    public function scope(string $preset, ?array $only = null): array
    {
        return array_values(array_filter(
            $this->workflows($only),
            fn (string $workflow) => ! empty($this->loader->raw($workflow)['collections'] ?? []) && match ($preset) {
                self::AWAITING_APPROVAL => TransitionGates::gatePlaces($this->loader->raw($workflow)) !== [],
                self::FAILED_TRANSLATIONS => array_key_exists(
                    $this->failed->failedPlace($workflow),
                    $this->loader->raw($workflow)['places'] ?? []
                ),
                self::PUBLISHED_THIS_WEEK => $this->doneStates([$workflow]) !== [],
                default => false,
            }
        ));
    }

    /**
     * Distinct subjects that ENTERED a done state in the window, per workflow.
     *
     * Transitions, not the current state: "published this week" is an event,
     * and a subject that went out on Monday and back into revision on Thursday
     * still went out this week. The stored state cannot answer that; the event
     * log can. Public for the card's comparison figure (last week), which asks
     * the same question of a different window.
     *
     * @param  array<int, string>|null  $only
     * @return array<string, array<int, string>>
     */
    public function published(?array $only, Carbon $from, Carbon $to): array
    {
        $workflows = $this->workflows($only);

        return array_intersect_key(
            $this->events->subjectIdsEnteringStates($this->doneStates($workflows), $from, $to),
            array_flip($workflows)
        );
    }

    /**
     * Subject ids per workflow whose CURRENT stored state is one of that
     * workflow's approval gates.
     *
     * The gate set is computed ONCE PER WORKFLOW from the raw definition and the
     * stored states are then matched against it. The other way round — asking
     * per subject — would re-walk the transitions for every row.
     *
     * @param  array<int, string>  $workflows
     * @return array<string, array<int, string>>
     */
    private function atGates(array $workflows): array
    {
        $found = [];

        foreach ($workflows as $workflow) {
            $gates = TransitionGates::gatePlaces($this->loader->raw($workflow));

            if ($gates === []) {
                continue;
            }

            $matching = [];

            foreach ($this->states->statesFor($workflow) as $subjectId => $state) {
                if (in_array((string) $state, $gates, true)) {
                    $matching[] = (string) $subjectId;
                }
            }

            if ($matching !== []) {
                $found[$workflow] = $matching;
            }
        }

        return $found;
    }

    /**
     * Every place handle across the given workflows whose color reads as
     * "done". Handles rather than colors, because the event log records the
     * place a transition landed in, not its color.
     *
     * @param  array<int, string>  $workflows
     * @return array<int, string>
     */
    private function doneStates(array $workflows): array
    {
        $handles = [];

        foreach ($workflows as $workflow) {
            foreach (array_keys($this->loader->raw($workflow)['places'] ?? []) as $handle) {
                if (in_array($this->loader->stateColor((string) $handle), self::DONE_COLORS, true)) {
                    $handles[] = (string) $handle;
                }
            }
        }

        // Place handles are unique across workflows in practice, but two
        // workflows declaring the same green place would otherwise bind it
        // twice in the IN clause.
        return array_values(array_unique($handles));
    }

    /**
     * @param  array<int, string>|null  $only
     * @return array<int, string>
     */
    private function workflows(?array $only): array
    {
        return collect($this->loader->all())
            ->when($only !== null, fn ($names) => $names->intersect($only)->values())
            ->all();
    }
}
