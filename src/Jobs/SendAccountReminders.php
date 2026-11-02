<?php

namespace TakepartMedia\Workflows\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Workflow\AccountReminder;

/**
 * The `account_reminder` mails of one step, sent outside the click — see
 * AccountReminder::queue(). Scalars only, for the reason SendWorkflowNotification
 * gives.
 */
class SendAccountReminders implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly string $subjectId,
        public readonly string $workflow,
        public readonly ?string $actorId = null,
    ) {}

    public function handle(): void
    {
        $entry = Entry::find($this->subjectId);

        if (! $entry instanceof EntryContract) {
            return;
        }

        try {
            AccountReminder::send($entry, $this->workflow, $this->actorId);
        } catch (\Throwable $e) {
            Log::error('Workflow account reminder failed.', [
                'workflow' => $this->workflow,
                'subject' => $this->subjectId,
                'exception' => $e,
            ]);
        }
    }
}
