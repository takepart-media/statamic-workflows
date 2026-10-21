<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Content\EntryContentPreview;
use TakepartMedia\Workflows\Jobs\SendCommentMentionNotification;
use TakepartMedia\Workflows\Mail\CommentMentionMail;

/**
 * Mails the users a comment @-mentions. The comment-layer counterpart of
 * WorkflowNotifier, and it follows that class's rules:
 *
 * - one mail per recipient, in the recipient's CP language;
 * - delivery per `workflows.mail_delivery` (after_response / queue / sync), so
 *   saving a comment never waits for a mail API;
 * - every failure is contained and logged — the comment is already saved.
 *
 * WHO: only the ids the caller passes — on create every mention, on edit only the
 * ones the edit ADDED (WorkflowController decides that). Never the author, never a
 * user without an address. `workflows.comment_mention_mail` (default true)
 * switches it off globally.
 */
class CommentMentionNotifier
{
    public function __construct(
        private readonly InstanceUrl $urls,
    ) {}

    /**
     * @param  array<int, string>  $userIds
     */
    public function queue(WorkflowComment $comment, array $userIds, ?string $actorId): void
    {
        $userIds = $this->recipients($comment, $userIds, $actorId);

        if ($userIds === [] || ! config('workflows.comment_mention_mail', true)) {
            return;
        }

        $mode = (string) config('workflows.mail_delivery', 'after_response');

        if ($mode === 'sync') {
            $this->notify($comment, $userIds, $actorId);

            return;
        }

        $job = new SendCommentMentionNotification((int) $comment->id, $userIds, $actorId);

        $mode === 'queue' ? dispatch($job) : dispatch($job)->afterResponse();
    }

    /**
     * Send now. Returns how many mails went out.
     *
     * @param  array<int, string>  $userIds
     */
    public function notify(WorkflowComment $comment, array $userIds, ?string $actorId): int
    {
        $userIds = $this->recipients($comment, $userIds, $actorId);

        if ($userIds === []) {
            return 0;
        }

        $entry = Entry::find($comment->subject_id);
        $title = $entry ? (string) ($entry->value('title') ?: $entry->slug()) : (string) $comment->subject_id;
        $actorName = (string) ($comment->author_name ?: '—');
        $excerpt = Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $comment->body)), 280);
        $sent = 0;

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if ($user === null || ! $user->email()) {
                continue;
            }

            try {
                Mail::to($user->email(), $user->name())
                    ->locale($user->preferredLocale())
                    ->send(new CommentMentionMail(
                        title: $title,
                        url: $this->url($comment),
                        actorName: $actorName,
                        passageLabel: $this->passageLabel($comment, $entry),
                        excerpt: $excerpt,
                        workflowLabel: $this->workflowLabel($comment->workflow),
                        isReply: $comment->parent_id !== null,
                    ));
                $sent++;
            } catch (\Throwable $e) {
                Log::error('Workflow comment mention mail failed.', [
                    'workflow' => $comment->workflow,
                    'subject' => $comment->subject_id,
                    'comment' => $comment->id,
                    'user' => $userId,
                    'exception' => $e,
                ]);
            }
        }

        return $sent;
    }

    /**
     * The instance page, opened on the comment: `?comment=<id>` for the client to
     * open the thread, `#passage-<uuid>` to scroll to the passage.
     */
    public function url(WorkflowComment $comment): string
    {
        $url = $this->urls->for((string) $comment->subject_id, $comment->workflow);
        $url .= (str_contains($url, '?') ? '&' : '?').'comment='.$comment->id;

        if ($comment->passage_ref) {
            $url .= '#passage-'.$comment->passage_ref;
        }

        return $url;
    }

    /**
     * Unique, never the actor, never the author.
     *
     * @param  array<int, string>  $userIds
     * @return array<int, string>
     */
    private function recipients(WorkflowComment $comment, array $userIds, ?string $actorId): array
    {
        return collect($userIds)
            ->map(fn ($id) => (string) $id)
            ->filter(fn (string $id) => $id !== ''
                && $id !== (string) $actorId
                && $id !== (string) $comment->user_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The passage's display label as the content panel shows it, falling back to
     * the stored field handle when the passage is gone or the entry unreadable.
     */
    private function passageLabel(WorkflowComment $comment, $entry): ?string
    {
        if (! $comment->passage_ref) {
            return null;
        }

        if ($entry) {
            try {
                $block = collect(app(EntryContentPreview::class)->blocks($entry, $comment->workflow))
                    ->firstWhere('uuid', $comment->passage_ref);

                if (is_array($block) && is_string($block['label'] ?? null) && $block['label'] !== '') {
                    return $block['label'];
                }
            } catch (\Throwable) {
                // Fall through to the handle.
            }
        }

        return $comment->passage_handle ?: null;
    }

    private function workflowLabel(string $workflow): string
    {
        try {
            $label = DefinitionLoader::navLabel(app(DefinitionLoader::class)->raw($workflow)['metadata']['nav'] ?? []);
        } catch (\Throwable) {
            $label = '';
        }

        return $label !== '' ? $label : $workflow;
    }
}
