<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\PlaceActions;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowNotifier;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/**
 * Scheduled task: the `reminder` place action (PlaceActions::REMINDER). While a
 * subject sits in a place that carries one, its assignees are mailed every
 * `every_days` — the first time that long after it ARRIVED, every later time
 * that long after the previous reminder.
 *
 * TWO CLOCKS, the later one wins:
 *
 * - `workflow_states.entered_at` — when the subject arrived in its current place.
 *   WorkflowStateStore::set() writes it only on a real change, so a self-loop
 *   does not restart it.
 * - the newest `reminder_sent` event logged from this place. Counted only when it is not
 *   older than the arrival, so a reminder from an earlier stay in the same place
 *   does not shorten the new one.
 *
 * Idempotent per day: a reminder that was just sent pushes the next one
 * `every_days` out, so a second run on the same day sends nothing. A subject with
 * nobody assigned gets no mail AND no event — there is nothing to remind, and an
 * event would silently eat the first reminder once somebody is assigned.
 */
class SendPlaceRemindersCommand extends Command
{
    protected $signature = 'workflows:send-reminders
                            {--workflow= : Only this workflow (defaults to all)}
                            {--dry-run : List the reminders that are due without sending them}';

    protected $description = 'Mail the assignees of every entry that has been sitting in a place with a `reminder` action for its interval.';

    /** History row type for a reminder that went out. */
    public const EVENT_TYPE = 'reminder_sent';

    public function handle(
        DefinitionLoader $loader,
        WorkflowStateStore $states,
        WorkflowEventStore $events,
        WorkflowNotifier $notifier,
    ): int {
        $only = $this->option('workflow');
        $names = $only ? [$only] : $loader->all();
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();
        $count = 0;

        foreach ($names as $name) {
            if (! in_array($name, $loader->all(), true)) {
                $this->warn("Workflow [{$name}] not found — skipped.");

                continue;
            }

            $raw = $loader->raw($name);

            foreach ($raw['places'] ?? [] as $place => $definition) {
                $reminder = PlaceActions::reminder((array) $definition);

                if ($reminder === null) {
                    continue;
                }

                foreach ($states->enteredAtInState($name, $place) as $subjectId => $enteredAt) {
                    $count += $this->remind(
                        (string) $subjectId, $name, $raw, (string) $place, $reminder, $enteredAt,
                        $now, $events, $notifier, $dryRun,
                    ) ? 1 : 0;
                }
            }
        }

        $verb = $dryRun ? 'Would send' : 'Sent';
        $this->info("{$verb} {$count} reminder(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array{every_days: int, mail_type: string}  $reminder
     */
    private function remind(
        string $subjectId,
        string $workflow,
        array $raw,
        string $place,
        array $reminder,
        ?Carbon $enteredAt,
        Carbon $now,
        WorkflowEventStore $events,
        WorkflowNotifier $notifier,
        bool $dryRun,
    ): bool {
        // Unknown arrival: guessing would either mail on the first run or never.
        if ($enteredAt === null) {
            return false;
        }

        $last = $events->latestOfTypeInPlace($subjectId, $workflow, self::EVENT_TYPE, $place);
        $since = $last !== null && $last->gte($enteredAt) ? $last : $enteredAt;

        if ($now->lt($since->copy()->addDays($reminder['every_days']))) {
            return false;
        }

        $entry = Entry::find($subjectId);

        if (! $entry instanceof EntryContract) {
            return false;
        }

        $waitingDays = (int) floor($enteredAt->diffInSeconds($now) / 86_400);

        if ($dryRun) {
            $this->line("[dry-run] {$workflow}/{$subjectId} ({$place}, {$waitingDays}d) → {$reminder['mail_type']}");

            return true;
        }

        try {
            $sent = $notifier->notify(
                $entry, $workflow, $raw, $reminder['mail_type'], $place,
                PlaceActions::REMINDER, $place, actorId: null, waitingDays: $waitingDays,
            );
        } catch (\Throwable $e) {
            $this->warn("{$workflow}/{$subjectId}: {$e->getMessage()}");
            Log::error('Workflow reminder failed.', [
                'workflow' => $workflow,
                'place' => $place,
                'subject' => $subjectId,
                'exception' => $e,
            ]);

            return false;
        }

        if ($sent === 0) {
            return false;
        }

        $events->log($subjectId, $workflow, self::EVENT_TYPE, [
            // Only `from`: a reminder is not an arrival — see
            // WorkflowEventStore::latestOfTypeInPlace().
            'from' => $place,
            'user_id' => null,
            'note' => __('workflows::messages.event_note_reminder_sent', ['days' => $waitingDays]),
        ]);

        $this->info("{$workflow}/{$subjectId} ({$place}, {$waitingDays}d) → {$sent} mail(s)");

        return true;
    }
}
