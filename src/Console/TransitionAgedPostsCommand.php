<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\PlaceMover;
use TakepartMedia\Workflows\Workflow\SlaSettings;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;
use TakepartMedia\Workflows\Workflow\WorkflowTransitioner;

/**
 * Scheduled task: move published posts on once they have gone too long without an
 * update. Opt-in per workflow via `metadata.sla` — a day count and, for each
 * stage, one of the workflow's OWN places (see SlaSettings).
 *
 * Two things changed the shape of this command, and both removed code rather than
 * adding it.
 *
 * AGE COMES FROM THE ENTRY. It used to be the newest `to_state = <publish>` row in
 * `workflow_events`, with `workflow_states.updated_at` as a fallback for content
 * that had no such row — a timestamp only this command could read, backed by a
 * fallback that `migrateState()` silently skips. It is now `content_updated_at` on
 * the entry (ContentTimestamp), which the listing sorts by and the stale marker
 * compares against. One number, readable everywhere.
 *
 * THE STEPS BELONG TO THE AUTHOR. The two stages used to be injected places reached
 * by injected transitions, with their own assignment dialect
 * (`sla.by`/`assign_to`/`dismiss_by`) running parallel to the action system every
 * other step uses. Now the author builds the places, and how the post gets in is
 * their choice:
 *
 * - declare a transition INTO the place and this command applies it like any
 *   editor would — with its assignment, its mail, its permission gate;
 * - declare none and the post is moved straight in (PlaceMover), with the place's
 *   own arrival actions firing and responsibility left where it was.
 *
 * Either way a stage may name who takes over (`<stage>_assign`, see
 * SlaSettings::assign()). That assignment is set BEFORE the move, so a `mail`
 * action on the stage's place already reaches the new people; a transition that
 * carries its own `user_assignment` still has the last word, because it runs after.
 *
 * The second case is the normal one, and requiring the first was a mistake worth
 * recording: nobody ever CLICKS the way into an ageing place. That step is what the
 * scheduler is for, so demanding a button for it meant demanding a button no human
 * would ever press. The way OUT is the one an editor needs — "back to current",
 * "hand it to an author" — and those are ordinary transitions the author writes.
 *
 * Idempotent: a post whose clock is younger than the threshold, or that already
 * sits in the target place, is left alone.
 */
class TransitionAgedPostsCommand extends Command
{
    protected $signature = 'workflows:transition-aged
                            {--workflow= : Only this workflow (defaults to all)}
                            {--dry-run : Print the planned transitions without applying them}';

    protected $description = 'Move published posts into their configured needs-update / outdated places once their content age is reached.';

    /** History row type for a move this command made without a transition. */
    public const EVENT_TYPE = 'sla_aged';

    public function handle(
        DefinitionLoader $loader,
        WorkflowResolver $resolver,
        WorkflowStateStore $states,
        WorkflowTransitioner $transitioner,
        ContentTimestamp $timestamps,
    ): int {
        $only = $this->option('workflow');
        $names = $only ? [$only] : $loader->all();
        $dryRun = (bool) $this->option('dry-run');
        $applied = 0;

        foreach ($names as $name) {
            if (! in_array($name, $loader->all(), true)) {
                $this->warn("Workflow [{$name}] not found — skipped.");

                continue;
            }

            $raw = $loader->raw($name);
            $stages = SlaSettings::stages($raw);

            if ($stages === []) {
                continue;
            }

            // Ageing is the authoring workflow's business. A localization manager
            // reads the post's clock and reacts to its draft state; a deadline of
            // its own would be a second answer for the same post.
            if ($resolver->isLocalizationWorkflow($raw)) {
                $this->warn("Workflow [{$name}] is a localization workflow — SLA ignored.");

                continue;
            }

            $agingPlaces = SlaSettings::places($raw);
            $publishPlaces = array_values(array_filter(
                $resolver->publishPlaces($raw),
                fn (string $place) => ! in_array($place, $agingPlaces, true)
                    && ($raw['places'][$place]['metadata']['revision_pending'] ?? false) !== true,
            ));

            if ($publishPlaces === []) {
                continue; // Workflow doesn't gate publishing — nothing to age from.
            }

            // Every place a post can age OUT of: the publish place it was released
            // into, plus the first stage once that has been reached. The second
            // stage is measured from the same clock, so a post that has sat in the
            // first one long enough moves straight on.
            $first = $stages[SlaSettings::STAGE_NEEDS_UPDATE]['place'] ?? null;
            $from = $first === null ? $publishPlaces : array_merge($publishPlaces, [$first]);

            foreach ($from as $place) {
                foreach ($states->subjectsInState($name, $place) as $subjectId) {
                    $applied += $this->age(
                        $subjectId, $name, $place, $stages, $raw, $transitioner, $timestamps, $dryRun
                    ) ? 1 : 0;
                }
            }
        }

        $verb = $dryRun ? 'Would apply' : 'Applied';
        $this->info("{$verb} {$applied} transition(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{days: int, place: string, assign: array|null}>  $stages
     * @param  array<string, mixed>  $raw
     */
    private function age(
        string $subjectId,
        string $workflow,
        string $current,
        array $stages,
        array $raw,
        WorkflowTransitioner $transitioner,
        ContentTimestamp $timestamps,
        bool $dryRun,
    ): bool {
        $entry = Entry::find($subjectId);

        if (! $entry instanceof EntryContract) {
            return false;
        }

        $updatedAt = $timestamps->for($entry);

        if ($updatedAt === null) {
            // No timestamp and no file date to fall back on. Ageing content whose
            // age is unknown would be guessing, and the guess takes a post offline.
            return false;
        }

        $ageDays = (int) floor($updatedAt->diffInSeconds(Carbon::now()) / 86_400);
        $stage = $this->targetFor($stages, $ageDays, $current);

        if ($stage === null) {
            return false;
        }

        $target = $stage['place'];
        $takeOver = $stage['assign'] !== null ? SlaSettings::assigneeIds($stage['assign']) : [];

        $transition = $this->transitionInto($raw, $current, $target);
        $how = $transition ?? 'direct';

        if ($dryRun) {
            $who = $stage['assign'] === null ? '' : ' (assign: '.count($takeOver).' user(s))';
            $this->line("[dry-run] {$workflow}/{$subjectId} ({$current}, {$ageDays}d) → {$how} → {$target}{$who}");

            return true;
        }

        if ($stage['assign'] !== null && $takeOver === []) {
            // Nobody matches (an emptied group, a deleted person). Handing the post
            // to nobody would hide it from everyone's list — keep who had it.
            $this->warn("{$workflow}/{$subjectId}: stage assignment matches nobody — assignees kept.");
        }

        $assignees = app(WorkflowAssigneeStore::class);
        $previous = $assignees->idsFor($subjectId, $workflow);

        if ($takeOver !== []) {
            $assignees->set($subjectId, $workflow, $takeOver);
        }

        try {
            $to = $transition !== null
                ? $transitioner->apply($entry, $workflow, $transition, $raw, actorId: null)
                : app(PlaceMover::class)->move(
                    $entry, $workflow, $raw, $target, self::EVENT_TYPE,
                    __('workflows::messages.event_note_sla_aged', ['days' => $ageDays]),
                );

            if ($to === null) {
                $this->restore($assignees, $subjectId, $workflow, $previous, $takeOver);

                return false;
            }

            $this->info("{$workflow}/{$subjectId} → {$to} ({$ageDays}d, {$how})");

            return true;
        } catch (\Throwable $e) {
            $this->restore($assignees, $subjectId, $workflow, $previous, $takeOver);
            $this->warn("{$workflow}/{$subjectId}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Undo the stage assignment when the move did not happen — the post stays
     * where it was, so must its responsibility.
     *
     * @param  array<int, string>  $previous
     * @param  array<int, string>  $takeOver
     */
    private function restore(WorkflowAssigneeStore $assignees, string $subjectId, string $workflow, array $previous, array $takeOver): void
    {
        if ($takeOver !== []) {
            $assignees->set($subjectId, $workflow, $previous);
        }
    }

    /**
     * The stage this post should be in at $ageDays, or null when it is already
     * there or not old enough.
     *
     * The later stage is tried first: both thresholds are measured from the same
     * clock, so a post old enough for both belongs in the second one directly
     * rather than stepping through the first at the next tick.
     *
     * @param  array<string, array{days: int, place: string, assign: array|null}>  $stages
     * @return array{days: int, place: string, assign: array|null}|null
     */
    private function targetFor(array $stages, int $ageDays, string $current): ?array
    {
        foreach ([SlaSettings::STAGE_OUTDATED, SlaSettings::STAGE_NEEDS_UPDATE] as $stage) {
            $configured = $stages[$stage] ?? null;

            if ($configured === null || $ageDays < $configured['days']) {
                continue;
            }

            return $configured['place'] === $current ? null : $configured;
        }

        return null;
    }

    /**
     * The author's transition from $current into $target, or null when they never
     * connected the two — in which case the post is moved straight in.
     *
     * First match wins. Two transitions between the same pair of places is a
     * legitimate thing to build (a friendly one and a blunt one), and neither is
     * more correct from here — the author's order is the only statement of
     * preference available, and it is the one the settings editor edits.
     *
     * @param  array<string, mixed>  $raw
     */
    private function transitionInto(array $raw, string $current, string $target): ?string
    {
        foreach ($raw['transitions'] ?? [] as $handle => $transition) {
            if (($transition['to'] ?? null) !== $target) {
                continue;
            }

            if (in_array($current, (array) ($transition['from'] ?? []), true)) {
                return (string) $handle;
            }
        }

        return null;
    }
}
