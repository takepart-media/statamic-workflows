<?php

namespace TakepartMedia\Workflows\Workflow;

/**
 * Reads/writes workflow assignments in the dedicated `workflow` SQLite database.
 * Assignment is per subject-version (entry localization) and per workflow, so
 * the same logical post can have different responsibles per site.
 */
class WorkflowAssigneeStore
{
    /**
     * Assigned user ids for every subject in a workflow, keyed by subject id.
     *
     * @return array<string, array<int, string>>
     */
    public function byWorkflow(string $workflow): array
    {
        return WorkflowAssignee::query()
            ->where('workflow', $workflow)
            ->get(['subject_id', 'user_id'])
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->values()->all())
            ->all();
    }

    /**
     * Subject ids assigned to one user within a workflow. The inverse of
     * idsFor(), and cheaper than filtering byWorkflow() when only one user's
     * assignments are asked for (the dashboard widgets).
     *
     * @return array<int, string>
     */
    public function subjectIdsForUser(string $userId, string $workflow): array
    {
        return WorkflowAssignee::query()
            ->where('workflow', $workflow)
            ->where('user_id', $userId)
            ->pluck('subject_id')
            ->all();
    }

    /**
     * Assigned user ids for a single subject within a workflow.
     *
     * @return array<int, string>
     */
    public function idsFor(string $subjectId, string $workflow): array
    {
        return WorkflowAssignee::query()
            ->where('workflow', $workflow)
            ->where('subject_id', $subjectId)
            ->pluck('user_id')
            ->all();
    }

    /**
     * Replace the assignees of a subject/workflow with exactly the given ids.
     *
     * Everyone this ADDS also starts watching the subject (WorkflowWatchStore),
     * so a handed-over post shows up in their "Watched" card without a click.
     * Done here because every way into an assignment ends in this method — a
     * transition, the assignee drawer, a new post, a new localization, the SLA
     * take-over — and one place cannot forget what six callers could.
     *
     * Only the newly added, not everyone in the list: somebody who stays
     * assigned and deliberately unwatched is not re-subscribed by the next
     * save of the drawer. Removing somebody leaves their watch alone — watching
     * is their own choice from then on.
     *
     * @param  array<int, string>  $userIds
     */
    public function set(string $subjectId, string $workflow, array $userIds): void
    {
        $userIds = collect($userIds)->filter()->map(fn ($id) => (string) $id)->unique()->values();
        $added = $userIds->diff(array_map('strval', $this->idsFor($subjectId, $workflow)));

        WorkflowAssignee::query()
            ->where('workflow', $workflow)
            ->where('subject_id', $subjectId)
            ->delete();

        $userIds->each(fn ($userId) => WorkflowAssignee::query()->create([
            'subject_id' => $subjectId,
            'workflow' => $workflow,
            'user_id' => $userId,
        ]));

        $watches = app(WorkflowWatchStore::class);
        $added->each(fn (string $userId) => $watches->watch($userId, $subjectId, $workflow));
    }

    /**
     * Drop the assignments of the given subjects within a workflow. Used when a
     * collection leaves a workflow — see WorkflowStateStore::deleteForSubjects.
     *
     * @param  array<int, string>  $subjectIds
     */
    public function deleteForSubjects(string $workflow, array $subjectIds): int
    {
        $deleted = 0;

        foreach (array_chunk(array_values($subjectIds), 500) as $chunk) {
            $deleted += WorkflowAssignee::query()
                ->where('workflow', $workflow)
                ->whereIn('subject_id', $chunk)
                ->delete();
        }

        return $deleted;
    }
}
