<?php

namespace TakepartMedia\Workflows\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use TakepartMedia\Workflows\Workflow\CommentMentionNotifier;
use TakepartMedia\Workflows\Workflow\WorkflowComment;

/**
 * CommentMentionNotifier::notify(), decoupled from the request — see
 * CommentMentionNotifier::queue() and SendWorkflowNotification.
 */
class SendCommentMentionNotification implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @param  array<int, string>  $userIds
     */
    public function __construct(
        public readonly int $commentId,
        public readonly array $userIds,
        public readonly ?string $actorId = null,
    ) {}

    public function handle(CommentMentionNotifier $notifier): void
    {
        $comment = WorkflowComment::query()->find($this->commentId);

        // Deleted between the save and the send — nothing left to point at.
        if ($comment === null) {
            return;
        }

        try {
            $notifier->notify($comment, $this->userIds, $this->actorId);
        } catch (\Throwable $e) {
            Log::error('Workflow comment mention mail failed.', [
                'comment' => $this->commentId,
                'exception' => $e,
            ]);
        }
    }
}
