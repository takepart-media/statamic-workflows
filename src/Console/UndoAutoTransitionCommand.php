<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Content\EntrySnapshot;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\PublishedFlag;
use TakepartMedia\Workflows\Workflow\WorkflowEvent;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowState;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/**
 * Take back the newest automatic transition of one entry (AutoTransitionCommand):
 * the subject goes back to the place it came from, the published flag follows,
 * and the History gets a `set_state` row saying so.
 *
 * Found by the History note AutoTransitionCommand writes — so a step that left
 * no History row (the half-applied runs before the `$note` fix) cannot be found.
 * `--to=` covers that case: set the place explicitly instead.
 *
 * Assignments are NOT restored — the previous set is recorded nowhere. Fix those
 * in the entry's assignee drawer.
 *
 * `entered_at` restarts now, so the subject is not due again at the next run.
 * `--days-ago=N` backdates it instead — for testing reminders and deadlines.
 */
class UndoAutoTransitionCommand extends Command
{
    protected $signature = 'workflows:undo-auto-transition
                            {entry : The entry id}
                            {--workflow= : The workflow (defaults to the one the entry has a state in)}
                            {--to= : Set this place instead of the one before the last automatic transition}
                            {--days-ago= : Backdate the arrival in the restored place by N days}
                            {--dry-run : Show what would change without changing it}';

    protected $description = 'Undo the newest automatic transition of an entry (or move it to --to=).';

    /** Both languages the History note is written in — see event_note_auto_transition. */
    private const NOTE_PREFIXES = ['Automatisch ausgeführt', 'Applied automatically'];

    public function handle(DefinitionLoader $loader, WorkflowStateStore $states, WorkflowEventStore $events): int
    {
        $id = (string) $this->argument('entry');
        $entry = Entry::find($id);

        if (! $entry instanceof EntryContract) {
            $this->error("Entry [{$id}] not found.");

            return self::FAILURE;
        }

        $workflow = $this->option('workflow') ?: $this->workflowOf($id);

        if ($workflow === null) {
            $this->error("Entry [{$id}] has no workflow state — or several; pass --workflow=.");

            return self::FAILURE;
        }

        $raw = $loader->raw($workflow);
        $current = $states->get($id, $workflow);
        $to = $this->option('to');
        $event = null;

        if ($to === null) {
            $event = $this->lastAutoTransition($id, $workflow);

            if ($event === null) {
                $this->error('No automatic transition in the History of this entry. Pass --to=<place> to set one explicitly.');

                return self::FAILURE;
            }

            if ($current !== $event->to_state) {
                $this->error("The entry is in [{$current}], no longer in [{$event->to_state}] where the automatic transition left it.");

                return self::FAILURE;
            }

            $to = $event->from_state;
        }

        if (! array_key_exists($to, $raw['places'] ?? [])) {
            $this->error("Place [{$to}] does not exist in workflow [{$workflow}].");

            return self::FAILURE;
        }

        $daysAgo = $this->option('days-ago');
        $enteredAt = is_numeric($daysAgo) ? Carbon::now()->subDays((int) $daysAgo) : Carbon::now();
        $what = $event !== null
            ? "undo [{$event->transition}] of {$event->created_at}"
            : 'set explicitly';

        $this->line("{$workflow}/{$id}: {$current} → {$to} ({$what}), entered_at {$enteredAt}");

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing changed.');

            return self::SUCCESS;
        }

        $states->set($id, $workflow, $to);
        WorkflowState::query()
            ->where('subject_id', $id)
            ->where('workflow', $workflow)
            ->update(['entered_at' => $enteredAt]);
        app(PublishedFlag::class)->sync($entry, $raw, $to);

        $fresh = $entry->fresh() ?? $entry;
        $events->log($id, $workflow, 'set_state', [
            'from' => $current,
            'to' => $to,
            'user_id' => null,
            'note' => $event !== null
                ? __('workflows::messages.event_note_auto_transition_undone', ['transition' => $event->transition])
                : __('workflows::messages.event_note_set_state'),
            'snapshot' => app(EntrySnapshot::class)->take($fresh),
        ]);

        $this->info('Done. Assignees were left as they are.');

        return self::SUCCESS;
    }

    /** The only workflow the entry has a state in, or null when none or several. */
    private function workflowOf(string $id): ?string
    {
        $workflows = WorkflowState::query()->where('subject_id', $id)->pluck('workflow')->all();

        return count($workflows) === 1 ? (string) $workflows[0] : null;
    }

    private function lastAutoTransition(string $id, string $workflow): ?WorkflowEvent
    {
        return WorkflowEvent::query()
            ->where('subject_id', $id)
            ->where('workflow', $workflow)
            ->where('type', 'transition')
            ->where(function ($query) {
                foreach (self::NOTE_PREFIXES as $prefix) {
                    $query->orWhere('note', 'like', $prefix.'%');
                }
            })
            ->orderByDesc('id')
            ->first();
    }
}
