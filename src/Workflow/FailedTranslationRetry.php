<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Symfony\Component\HttpKernel\Exception\HttpException;
use TakepartMedia\Workflows\Http\Controllers\WorkflowController;

/**
 * "Restart the failed translations" — the one action the dashboard's QueueStats
 * row offers, and the only thing on that row that is not read-only.
 *
 * Everything about the retry lives here: which subjects are parked as failed,
 * which step leads back out, whether the person asking may take it, and the
 * bulk run itself. The widget asks whether to draw the button
 * (retryableBy()), the endpoint asks it to act (run()), and neither of them
 * knows how a transition is applied.
 *
 * WHY IT GOES THROUGH THE CONTROLLER. The step out of the failed place is not a
 * plain state change. In the shipped plain-language manager it carries the
 * `plain_language_resubmit` action, and WorkflowController::applyTransition()
 * answers that with a branch of its own: it resolves the plain-language version
 * and its source, refuses when the service is not configured, applies the
 * transition to the TARGET version, drops the stale job rows and re-submits the
 * rewrite. Calling WorkflowTransitioner directly would do the first of those
 * five things and none of the others — the subject would land in "wird
 * übersetzt" with no job behind it and sit there forever. So this class builds a
 * request and calls the same endpoint method the instance page calls, which is
 * also what makes the revision, the event and the mails come out identical to a
 * click.
 *
 * That coupling is deliberately concentrated in this one class rather than
 * spread over the widget and a second controller action.
 */
class FailedTranslationRetry
{
    /**
     * Where a rewrite goes to die when the workflow does not say otherwise. The
     * real answer is `metadata.plain_language_failed_place` (the setting
     * PollPlainLanguageCommand parks a finally-failed version in); this is the
     * handle the shipped example uses, kept as a fallback so a workflow that
     * names the place by convention rather than by setting still counts.
     */
    public const DEFAULT_FAILED_PLACE = 'failed_translation';

    public function __construct(
        private readonly DefinitionLoader $loader,
        private readonly WorkflowStateStore $states,
    ) {}

    /**
     * Subjects currently parked in their workflow's failed place, per workflow.
     * Workflows with nothing parked are dropped.
     *
     * Stored states only, no entry resolution: this is the same figure the
     * dashboard card shows, and resolving 40 entries to count them would make a
     * dashboard load pay for a question nobody asked. run() resolves the few it
     * actually touches.
     *
     * @param  array<int, string>|null  $only  Restrict to these workflow names.
     * @return array<string, array<int, string>>
     */
    public function subjects(?array $only = null): array
    {
        $found = [];

        foreach ($this->workflows($only) as $workflow) {
            $place = $this->failedPlace($workflow);
            $matching = [];

            foreach ($this->states->statesFor($workflow) as $subjectId => $state) {
                if ((string) $state === $place) {
                    $matching[] = (string) $subjectId;
                }
            }

            if ($matching !== []) {
                $found[$workflow] = $matching;
            }
        }

        return $found;
    }

    /** How many subjects are parked as failed, across the given workflows. */
    public function count(?array $only = null): int
    {
        return array_sum(array_map('count', $this->subjects($only)));
    }

    /**
     * Whether the current user could restart AT LEAST ONE of them — the question
     * the widget asks before it draws a button.
     *
     * Returns on the first subject that passes rather than checking them all:
     * the button says "there is something here for you", not "how much". The
     * failed set is small by nature (a parked rewrite is an incident, not a
     * queue), so the worst case is a handful of entry loads.
     */
    public function retryableBy(?array $only = null): bool
    {
        foreach ($this->subjects($only) as $workflow => $subjectIds) {
            $step = $this->retryStep((string) $workflow);

            if ($step === null) {
                continue;
            }

            foreach ($subjectIds as $subjectId) {
                if ($this->mayRetry((string) $subjectId, (string) $workflow, $step)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Restart every failed subject the current user may restart.
     *
     * A subject is SKIPPED, never reported as an error, when its entry is gone,
     * when the workflow offers no single unambiguous way out of the failed place,
     * when the user may not take that step, or when the endpoint refuses it (a
     * rewrite already running, the service unconfigured). The action is a bulk
     * one and a partial result is the honest outcome: the numbers say what
     * happened and `remaining_failed` says what is left.
     *
     * @return array{retried: int, skipped: int, remaining_failed: int}
     */
    public function run(?array $only = null): array
    {
        $retried = 0;
        $skipped = 0;

        foreach ($this->subjects($only) as $workflow => $subjectIds) {
            $workflow = (string) $workflow;
            $step = $this->retryStep($workflow);

            if ($step === null) {
                // Ambiguous (or no) way out: a workflow whose failed place has two
                // exits is asking a question — restart, or discard? — and a bulk
                // button must not answer it on the operator's behalf.
                $skipped += count($subjectIds);

                continue;
            }

            foreach ($subjectIds as $subjectId) {
                if ($this->apply((string) $subjectId, $workflow, $step)) {
                    $retried++;
                } else {
                    $skipped++;
                }
            }
        }

        return [
            'retried' => $retried,
            'skipped' => $skipped,
            // Re-counted from the store rather than subtracted: the transition
            // moved the subjects out, and reading the truth back costs one query
            // per workflow and cannot drift from what the card will show on the
            // next dashboard load.
            'remaining_failed' => $this->count($only),
        ];
    }

    /**
     * The single step that leads out of a workflow's failed place, as
     * `[name, transition]` — or null when there is not exactly one.
     *
     * Exactly one, on purpose. With none there is nothing to offer; with two the
     * definition has made the way out a decision, and this action has no way to
     * make it. System transitions do not count: they are never offered as
     * buttons either (WorkflowController::entryActions()).
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function retryStep(string $workflow): ?array
    {
        $raw = $this->loader->raw($workflow);
        $place = $this->failedPlace($workflow);
        $found = [];

        foreach ($raw['transitions'] ?? [] as $name => $transition) {
            if (! is_array($transition) || ($transition['metadata']['system'] ?? false) === true) {
                continue;
            }

            if (in_array($place, (array) ($transition['from'] ?? []), true)) {
                $found[] = [(string) $name, $transition];
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * The place a finally-failed rewrite is parked in
     * (`metadata.plain_language_failed_place`), or the shipped default. A
     * workflow that declares no such place simply matches nothing.
     */
    public function failedPlace(string $workflow): string
    {
        $configured = $this->loader->raw($workflow)['metadata']['plain_language_failed_place'] ?? null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : self::DEFAULT_FAILED_PLACE;
    }

    /**
     * Permission for one subject, asked of the addon's single authority.
     *
     * @param  array{0: string, 1: array<string, mixed>}  $step
     */
    private function mayRetry(string $subjectId, string $workflow, array $step): bool
    {
        $entry = Entry::find($subjectId);

        // A state row whose entry is gone. Not this action's problem to clean up
        // — the managers surface those — but nothing can be restarted either.
        if ($entry === null) {
            return false;
        }

        [$name, $transition] = $step;

        return app(WorkflowController::class)->canApplyTransition($transition, $entry, $workflow, $name);
    }

    /**
     * One retry. True when the transition went through.
     *
     * @param  array{0: string, 1: array<string, mixed>}  $step
     */
    private function apply(string $subjectId, string $workflow, array $step): bool
    {
        if (! $this->mayRetry($subjectId, $workflow, $step)) {
            return false;
        }

        [$name] = $step;

        // The workflow travels in the QUERY string because that is where
        // applyTransition() reads it (`$request->query('workflow')`) — the
        // instance page sends `?workflow=…` for the same reason: a shared
        // collection needs to say which manager is asking.
        $request = Request::create('/?workflow='.urlencode($workflow), 'POST');

        try {
            $response = app(WorkflowController::class)->applyTransition($request, $subjectId, $name);
        } catch (HttpException $e) {
            // Its own abort_if()s — a vanished entry, an unresolvable workflow.
            // Between the check above and here that is a race, not a bug.
            return false;
        }

        return $response->getStatusCode() === 200;
    }

    /**
     * The active workflows this run looks at.
     *
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
