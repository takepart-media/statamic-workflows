<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * Reads/writes watches in the dedicated `workflow` SQLite database. Keyed like
 * every other per-subject table — subject-version (entry localization) plus
 * workflow — so watching the German version says nothing about the English one,
 * exactly as the detail page the button sits on shows one version.
 */
class WorkflowWatchStore
{
    public function isWatching(?string $userId, string $subjectId, string $workflow): bool
    {
        if ($userId === null) {
            return false;
        }

        return WorkflowWatch::query()
            ->where('user_id', $userId)
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->exists();
    }

    /**
     * Idempotent: watching twice is still one watch.
     */
    public function watch(string $userId, string $subjectId, string $workflow): void
    {
        WorkflowWatch::query()->firstOrCreate([
            'user_id' => $userId,
            'subject_id' => $subjectId,
            'workflow' => $workflow,
        ]);
    }

    public function unwatch(string $userId, string $subjectId, string $workflow): void
    {
        WorkflowWatch::query()
            ->where('user_id', $userId)
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->delete();
    }

    /**
     * Who watches one subject within a workflow — the recipients of a mail type
     * declared `recipients: watchers` (MailTypes::PUBLISHED).
     *
     * @return array<int, string>
     */
    public function userIdsFor(string $subjectId, string $workflow): array
    {
        return WorkflowWatch::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->orderBy('id')
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Subject ids one user watches within a workflow, most recently watched
     * first.
     *
     * @return array<int, string>
     */
    public function subjectIdsForUser(string $userId, string $workflow): array
    {
        return WorkflowWatch::query()
            ->where('workflow', $workflow)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('subject_id')
            ->all();
    }

    /**
     * Drop the watches on the given subjects within a workflow — for whoever
     * watches them. Used by WorkflowDataPurger.
     *
     * @param  array<int, string>  $subjectIds
     */
    public function deleteForSubjects(string $workflow, array $subjectIds): int
    {
        $deleted = 0;

        foreach (array_chunk(array_values($subjectIds), 500) as $chunk) {
            $deleted += WorkflowWatch::query()
                ->where('workflow', $workflow)
                ->whereIn('subject_id', $chunk)
                ->delete();
        }

        return $deleted;
    }
}
