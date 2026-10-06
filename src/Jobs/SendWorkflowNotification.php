<?php

namespace TakepartMedia\Workflows\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowNotifier;

/**
 * One workflow mail batch (every assignee of one subject), sent outside the
 * click that caused it — see WorkflowNotifier::queue() for when and how.
 *
 * Scalars only. A Statamic entry or user does not survive serialisation in any
 * useful shape, and re-reading both at send time is what a queued mail should
 * do anyway: it describes the subject as it IS when the mail goes out.
 */
class SendWorkflowNotification implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly string $subjectId,
        public readonly string $workflow,
        public readonly string $mailType,
        public readonly string $to,
        public readonly string $scope,
        public readonly string $trigger,
        public readonly ?string $actorId = null,
        public readonly ?int $waitingDays = null,
    ) {}

    public function handle(DefinitionLoader $loader, WorkflowNotifier $notifier): void
    {
        $entry = Entry::find($this->subjectId);

        // Deleted between the click and the send — nothing left to announce.
        if (! $entry instanceof EntryContract) {
            return;
        }

        try {
            $notifier->notify(
                $entry, $this->workflow, $loader->raw($this->workflow), $this->mailType, $this->to,
                $this->scope, $this->trigger, $this->actorId, $this->waitingDays,
            );
        } catch (\Throwable $e) {
            // The notifier contains per-recipient failures; this is the rest (a
            // broken mail config, an unreadable definition). Logged, never
            // rethrown: the step it announces is already a written fact.
            Log::error('Workflow notification failed.', [
                'workflow' => $this->workflow,
                'trigger' => $this->trigger,
                'mail' => $this->mailType,
                'subject' => $this->subjectId,
                'exception' => $e,
            ]);
        }
    }
}
