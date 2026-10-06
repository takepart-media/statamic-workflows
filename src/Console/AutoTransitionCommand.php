<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\PlaceActions;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;
use TakepartMedia\Workflows\Workflow\WorkflowTransitioner;

/**
 * Scheduled task: the `auto_transition` place action (PlaceActions::AUTO_TRANSITION).
 * A subject that has sat in such a place until its deadline day — arrival day +
 * `after_days`, see PlaceActions::autoTransitionDue() — has the named transition
 * applied, exactly as if somebody had clicked it, but without an actor.
 *
 * Scheduled right BEFORE workflows:send-reminders at the same time of day, so a
 * post that moves on today gets no reminder about the place it just left.
 *
 * Idempotent: once applied, the subject is no longer in the place.
 */
class AutoTransitionCommand extends Command
{
    protected $signature = 'workflows:auto-transition
                            {--workflow= : Only this workflow (defaults to all)}
                            {--dry-run : List the transitions that are due without applying them}';

    protected $description = 'Apply the `auto_transition` of every entry whose deadline in its current place has passed.';

    public function handle(
        DefinitionLoader $loader,
        WorkflowStateStore $states,
        WorkflowTransitioner $transitioner,
    ): int {
        $only = $this->option('workflow');
        $names = $only ? [$only] : $loader->all();
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::now();
        $count = 0;

        foreach ($names as $name) {
            if (! in_array($name, $loader->all(), true)) {
                $this->warn("Workflow [{$name}] not found — skipped.");

                continue;
            }

            $raw = $loader->raw($name);

            foreach ($raw['places'] ?? [] as $place => $definition) {
                $auto = PlaceActions::autoTransition((array) $definition);

                if ($auto === null || ! isset($raw['transitions'][$auto['transition']])) {
                    continue;
                }

                foreach ($states->enteredAtInState($name, (string) $place) as $subjectId => $enteredAt) {
                    // Unknown arrival: acting on a guess would move a post for real.
                    if ($enteredAt === null) {
                        continue;
                    }

                    $due = PlaceActions::autoTransitionDue($enteredAt, $auto['after_days']);

                    if ($today->lt($due)) {
                        continue;
                    }

                    $entry = Entry::find((string) $subjectId);

                    if (! $entry instanceof EntryContract) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("[dry-run] {$name}/{$subjectId} ({$place}, due {$due->toDateString()}) → {$auto['transition']}");
                        $count++;

                        continue;
                    }

                    try {
                        $to = $transitioner->apply(
                            $entry, $name, $auto['transition'], $raw,
                            actorId: null,
                            note: trans_choice('workflows::messages.event_note_auto_transition', $auto['after_days'], ['days' => $auto['after_days']]),
                        );
                        $this->info("{$name}/{$subjectId} ({$place}) → {$auto['transition']} → {$to}");
                        $count++;
                    } catch (\Throwable $e) {
                        // Logged as well as printed: under the scheduler nobody
                        // reads the console, and a half-applied step must not
                        // go unnoticed.
                        $this->warn("{$name}/{$subjectId}: {$e->getMessage()}");
                        Log::error('Workflow auto transition failed.', [
                            'workflow' => $name,
                            'place' => $place,
                            'transition' => $auto['transition'],
                            'subject' => $subjectId,
                            'exception' => $e,
                        ]);
                    }
                }
            }
        }

        $verb = $dryRun ? 'Would apply' : 'Applied';
        $this->info("{$verb} {$count} automatic transition(s).");

        return self::SUCCESS;
    }
}
