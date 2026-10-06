<?php

namespace TakepartMedia\Workflows\Workflow;

use TakepartMedia\Workflows\Translation\PlainLanguageJob;
use TakepartMedia\Workflows\Translation\PlainLanguageJobStore;

/**
 * Erases every trace of given subjects from ONE workflow: state, assignments,
 * history, comments (incl. read markers), watches and outstanding plain-language
 * jobs.
 *
 * Irreversible, and deliberately dumb: it deletes what it is handed. Deciding WHICH
 * subjects deserve that is the caller's job — removing a collection from a workflow
 * (WorkflowController::saveSettings, behind a confirmation), pruning rows nothing
 * points at any more (PruneStatesCommand), or an entry being deleted outright
 * (PurgeWorkflowDataOnEntryDeleted).
 *
 * It exists as a class rather than a helper on the controller because two of those
 * three callers are not HTTP. Every table is workflow-scoped, so purging one
 * workflow never touches what another one recorded about the same entry — the
 * whole point of keying on (subject_id, workflow).
 */
class WorkflowDataPurger
{
    public function __construct(
        private WorkflowStateStore $states,
        private WorkflowAssigneeStore $assignees,
        private WorkflowEventStore $events,
        private WorkflowCommentStore $comments,
        private PlainLanguageJobStore $jobs,
        private WorkflowWatchStore $watches,
    ) {}

    /**
     * Every subject this workflow holds data for, across all seven tables.
     *
     * The read counterpart of purge(), and it lives here for the same reason: the
     * list of tables belongs in one place. Reading the models directly rather than
     * asking the stores, because none of them exposes "all subjects" — and a subject
     * can well sit in one table only. That is exactly the case worth finding: 95 of
     * the orphaned event rows in the reference project belong to entries whose state
     * row is long gone.
     *
     * @return array<int, string>
     */
    public function subjectIds(string $workflow): array
    {
        $models = [
            WorkflowState::class,
            WorkflowAssignee::class,
            WorkflowEvent::class,
            WorkflowComment::class,
            WorkflowCommentRead::class,
            WorkflowWatch::class,
            PlainLanguageJob::class,
        ];

        return collect($models)
            ->flatMap(fn (string $model) => $model::query()
                ->where('workflow', $workflow)
                ->distinct()
                ->pluck('subject_id')
                ->all())
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $subjectIds
     * @return array<string, int>  rows removed per table, for the caller to report
     */
    public function purge(string $workflow, array $subjectIds): array
    {
        $subjectIds = array_values(array_unique(array_filter($subjectIds)));

        if ($subjectIds === []) {
            return [];
        }

        return [
            'states' => $this->states->deleteForSubjects($workflow, $subjectIds),
            'assignees' => $this->assignees->deleteForSubjects($workflow, $subjectIds),
            'events' => $this->events->deleteForSubjects($workflow, $subjectIds),
            'comments' => $this->comments->deleteForSubjects($workflow, $subjectIds),
            'watches' => $this->watches->deleteForSubjects($workflow, $subjectIds),
            'plain_language_jobs' => $this->jobs->deleteForSubjects($workflow, $subjectIds),
        ];
    }
}
