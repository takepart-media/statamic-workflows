<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Collection;

/**
 * Reads/writes workflow comments in the dedicated `workflow` SQLite database.
 */
class WorkflowCommentStore
{
    /**
     * All comments for a subject within a workflow, oldest first.
     *
     * @return Collection<int, WorkflowComment>
     */
    public function forSubject(string $subjectId, string $workflow): Collection
    {
        return WorkflowComment::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(array $attributes): WorkflowComment
    {
        return WorkflowComment::query()->create($attributes);
    }

    /**
     * The most recent comment of one kind for a subject, or null.
     *
     * Newest wins because a rewrite can be repeated (`plain_language_resubmit`),
     * and the report an editor needs is the one describing the text currently in
     * the entry — the earlier ones stay in the thread as history.
     */
    public function latestOfKind(string $subjectId, string $workflow, string $kind): ?WorkflowComment
    {
        return WorkflowComment::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->where('kind', $kind)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The user's last-seen timestamp per passage for a subject/workflow.
     *
     * @return array<string, \Illuminate\Support\Carbon>
     */
    public function seenAt(?string $userId, string $subjectId, string $workflow): array
    {
        if (! $userId) {
            return [];
        }

        return WorkflowCommentRead::query()
            ->where('user_id', $userId)
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->get()
            ->keyBy('passage_ref')
            ->map(fn ($read) => $read->seen_at)
            ->all();
    }

    /**
     * Total comments per subject in a workflow, keyed by subject_id. Subjects
     * without comments are absent.
     *
     * Feeds the grey comment-count flag in the index, which is why it is
     * user-independent and aggregated in SQL — it runs for every row that has
     * comments, not just the ones with something unread.
     *
     * Counts only comments with a `passage_ref`, matching what the UI can actually
     * show. It does NOT exclude comments whose passage has since disappeared: that
     * needs the entry's parsed content per subject, which is far too expensive for
     * every row. A removed set can therefore leave the total one too high until its
     * comments are cleaned up — visible only as a count, never as a stuck marker
     * (the unread side does filter them, see WorkflowController::newCommentCount).
     *
     * @return array<string, int>
     */
    public function countBySubject(string $workflow): array
    {
        return WorkflowComment::query()
            ->where('workflow', $workflow)
            ->whereNotNull('passage_ref')
            ->groupBy('subject_id')
            ->selectRaw('subject_id, count(*) as aggregate')
            ->pluck('aggregate', 'subject_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * How many comments the user has not seen, per subject — written by someone
     * else, after the user last looked at that passage. Subjects with nothing
     * unread are absent, so the result doubles as a lookup map.
     *
     * The bulk counterpart to seenAt() + forSubject(), in the style of
     * WorkflowAssigneeStore::byWorkflow(): the listings need this for every row at
     * once, and doing it per row would be two queries per entry. Two queries total
     * here, joined in PHP — the read markers are keyed by the same
     * (subject, passage) pair the comments carry, and SQLite has no cheap way to
     * express "newer than the matching marker, or no marker at all" in one
     * statement that stays readable.
     *
     * Mirrors the `is_new` rule in WorkflowController::commentResource(), including
     * treating a null author as somebody else. It does NOT filter comments whose
     * passage has since disappeared from the entry — that needs the entry's parsed
     * content, so callers that care do it per subject (see
     * WorkflowController::hasNewComments).
     *
     * @return array<string, int>
     */
    public function newCountBySubject(?string $userId, string $workflow): array
    {
        if (! $userId) {
            return [];
        }

        $seen = WorkflowCommentRead::query()
            ->where('workflow', $workflow)
            ->where('user_id', $userId)
            ->get(['subject_id', 'passage_ref', 'seen_at'])
            ->mapWithKeys(fn ($read) => [$read->subject_id.'|'.$read->passage_ref => $read->seen_at])
            ->all();

        return WorkflowComment::query()
            ->where('workflow', $workflow)
            ->whereNotNull('passage_ref')
            // A comment you wrote yourself is never new to you. Null authors are
            // somebody else, same as commentResource()'s `user_id !== $uid`.
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $userId))
            ->get(['subject_id', 'passage_ref', 'created_at'])
            ->filter(function ($comment) use ($seen) {
                $marker = $seen[$comment->subject_id.'|'.$comment->passage_ref] ?? null;

                return $marker === null
                    || ($comment->created_at !== null && $comment->created_at->gt($marker));
            })
            ->groupBy(fn ($comment) => (string) $comment->subject_id)
            ->map(fn ($comments) => $comments->count())
            ->all();
    }

    /**
     * Mark a passage's comments as seen by the user (now).
     */
    public function markRead(?string $userId, string $subjectId, string $workflow, string $passageRef): void
    {
        if (! $userId) {
            return;
        }

        WorkflowCommentRead::query()->updateOrCreate(
            ['user_id' => $userId, 'subject_id' => $subjectId, 'workflow' => $workflow, 'passage_ref' => $passageRef],
            ['seen_at' => now()],
        );
    }

    /**
     * Drop the comments AND the per-user read markers of the given subjects
     * within a workflow. Used when a collection leaves a workflow — see
     * WorkflowStateStore::deleteForSubjects. Read markers go too: without their
     * comments they would silently suppress the "new" badge if the collection is
     * ever re-added.
     *
     * @param  array<int, string>  $subjectIds
     */
    public function deleteForSubjects(string $workflow, array $subjectIds): int
    {
        $deleted = 0;

        foreach (array_chunk(array_values($subjectIds), 500) as $chunk) {
            $deleted += WorkflowComment::query()
                ->where('workflow', $workflow)
                ->whereIn('subject_id', $chunk)
                ->delete();

            WorkflowCommentRead::query()
                ->where('workflow', $workflow)
                ->whereIn('subject_id', $chunk)
                ->delete();
        }

        return $deleted;
    }
}
