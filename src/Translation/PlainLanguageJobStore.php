<?php

namespace TakepartMedia\Workflows\Translation;

use Illuminate\Support\Collection;

/**
 * Reads/writes outstanding plain-language jobs in the dedicated `workflow` database.
 *
 * Deliberately narrow, like the other stores: rows in, rows out. It knows nothing
 * about entries or the HTTP service — the command owns that flow.
 */
class PlainLanguageJobStore
{
    /**
     * Record a submitted chunk.
     *
     * @param  array<int, string>  $segmentKeys  segment paths, in the order they were sent
     * @param  int  $submission  Which submission of the run this row is — see the
     *                           `submission` column. Defaults to 1, which is right for
     *                           every caller that starts a run: the transition click and
     *                           a human's "rewrite again" both begin at 1 and get the
     *                           full retry budget. Only PollPlainLanguageCommand's
     *                           automatic retry passes anything else.
     */
    public function add(string $subjectId, string $workflow, string $textId, int $chunk, array $segmentKeys, int $submission = 1): PlainLanguageJob
    {
        return PlainLanguageJob::create([
            'subject_id' => $subjectId,
            'workflow' => $workflow,
            'text_id' => $textId,
            'chunk' => $chunk,
            'segment_keys' => array_values($segmentKeys),
            'status' => PlainLanguageJob::PENDING,
            'submission' => max(1, $submission),
        ]);
    }

    /**
     * Everything still worth asking about, oldest first.
     *
     * @return Collection<int, PlainLanguageJob>
     */
    public function open(): Collection
    {
        return PlainLanguageJob::query()
            ->whereIn('status', PlainLanguageJob::OPEN)
            ->orderBy('id')
            ->get();
    }

    /**
     * The jobs of one subject, whatever their state — the summary uses this to say
     * "a rewrite is still running".
     *
     * @return Collection<int, PlainLanguageJob>
     */
    public function forSubject(string $subjectId, string $workflow): Collection
    {
        return PlainLanguageJob::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->orderBy('chunk')
            ->get();
    }

    /**
     * Which submission is currently running for this subject, or null when nothing is.
     *
     * Read by the instance page to say "attempt 2 of 3" beside the spinner, so an editor
     * watching a slow rewrite can tell a retry from the first try. Only OPEN rows count —
     * a settled run has nothing in flight to report.
     */
    public function currentSubmission(string $subjectId, string $workflow): ?int
    {
        $submission = PlainLanguageJob::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->whereIn('status', PlainLanguageJob::OPEN)
            ->max('submission');

        return $submission === null ? null : max(1, (int) $submission);
    }

    /** Whether this subject has anything outstanding. */
    public function hasOpen(string $subjectId, string $workflow): bool
    {
        return PlainLanguageJob::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->whereIn('status', PlainLanguageJob::OPEN)
            ->exists();
    }

    /**
     * Mirror the status the service reported. `attempts` counts polls, not retries of
     * the request — it is what lets the command give up on a job that never resolves.
     */
    public function touch(PlainLanguageJob $job, string $status, ?string $error = null): PlainLanguageJob
    {
        $job->status = $status;
        $job->error_message = $error;
        $job->attempts = $job->attempts + 1;
        $job->save();

        return $job;
    }

    /**
     * Drop the finished rows of a subject. Called once every chunk of a run has been
     * written back, so a re-translation starts from a clean slate rather than
     * accumulating history the workflow log already keeps.
     */
    public function forget(string $subjectId, string $workflow): void
    {
        PlainLanguageJob::query()
            ->where('subject_id', $subjectId)
            ->where('workflow', $workflow)
            ->delete();
    }

    /**
     * The bulk sibling of forget(), for erasing whole subjects from a workflow — a
     * deleted entry, or a collection that left the workflow. Same signature as the
     * other stores' deleteForSubjects() so WorkflowDataPurger can treat all five
     * tables alike.
     *
     * @param  array<int, string>  $subjectIds
     * @return int  rows removed
     */
    public function deleteForSubjects(string $workflow, array $subjectIds): int
    {
        if ($subjectIds === []) {
            return 0;
        }

        return PlainLanguageJob::query()
            ->where('workflow', $workflow)
            ->whereIn('subject_id', $subjectIds)
            ->delete();
    }
}
