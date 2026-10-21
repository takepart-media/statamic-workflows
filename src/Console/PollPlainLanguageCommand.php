<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Support\EventLogSuppressor;
use TakepartMedia\Workflows\Translation\PlainLanguageConfig;
use TakepartMedia\Workflows\Translation\PlainLanguageJob;
use TakepartMedia\Workflows\Translation\LegacyPlainLanguageDocument;
use TakepartMedia\Workflows\Translation\PlainLanguageJobStore;
use TakepartMedia\Workflows\Translation\PlainLanguageLeafDocument;
use TakepartMedia\Workflows\Translation\PlainLanguageSubmission;
use TakepartMedia\Workflows\Translation\PlainLanguageTranslator;
use TakepartMedia\Workflows\Translation\TranslationFailed;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowComment;
use TakepartMedia\Workflows\Workflow\WorkflowCommentStore;
use TakepartMedia\Workflows\Workflow\WorkflowEvent;
use TakepartMedia\Workflows\Workflow\WorkflowEventStore;
use TakepartMedia\Workflows\Workflow\WorkflowNotifier;
use TakepartMedia\Workflows\Workflow\WorkflowStateStore;

/**
 * Scheduled task: the second half of a plain-language run.
 *
 * The service answers `202 Accepted` and finishes later, so the transition can only
 * submit the entry and create the localization as a copy of its source. This command
 * asks after the submitted jobs and writes the rewritten document into that copy.
 *
 * How an answer maps onto the entry depends on the job's `format`. A `sections` job
 * (everything submitted since the leaf format) carries its plan: every text leaf went
 * out as its own `<section data-wf-leaf="N">`, and PlainLanguageLeafDocument writes
 * section N back into exactly that leaf — strictly: an answer whose sections do not
 * match what was sent is rejected, nothing is written, and the retry below takes
 * over. A job without a format was submitted before that and is written back by
 * LegacyPlainLanguageDocument (the leading `<h1>` becomes the title, the rest replaces
 * the content field), so a rewrite in flight during an update still lands.
 *
 * The workflow STATE is left alone here BY DEFAULT. The transition put the entry in its
 * place when the editor clicked; this command only fills in texts, and the record that
 * it did is a workflow event plus a comment carrying the service's QA report. A workflow
 * may opt into an exception: `metadata.plain_language_completed_place` names a place this
 * command moves a subject straight into once its job completes with no failures — see
 * moveToCompletedPlace(). Deliberately a direct WorkflowStateStore::set(), not a
 * transition: this runs unattended, so there is no click to gate on a required_group,
 * and the assignee/publish side effects a real transition carries are the point of that
 * distinction, not an oversight.
 *
 * That move is also the one moment this command reaches outside the installation: if
 * the place it moves into carries a `mail` action, its assignees are
 * mailed "the plain language is ready". The action lives on the PLACE and not on a
 * transition for exactly the reason above — there is no transition here. Contained,
 * like every other write in this command: a refused mail server is a log line, never a
 * failed run.
 *
 * Idempotent. A completed job is written back once and then dropped. Both open-ended
 * outcomes are bounded by the configurable poll budget (see maxPolls()) — an id
 * the service does not know, and one it
 * keeps reporting as unfinished — so neither a lost job nor a stuck one can sit in the
 * table for good.
 *
 * A terminally failed rewrite ends in one of two ways, and which one is the workflow's
 * choice. With `metadata.plain_language_failed_place` the version MOVES there and stays:
 * a place is visible in the queue, survives a reload and can have a transition leading
 * out of it, so `plain_language_resubmit` makes a second attempt one click away. Without
 * that setting the version is deleted instead — see hasEverCompleted() — on the older
 * reasoning that a localization exists only to carry the rewrite and no transition is
 * obliged to lead anywhere from wherever it landed. A version that already held real
 * content (a resubmit gone wrong) is left exactly where it is under either setting.
 */
class PollPlainLanguageCommand extends Command
{
    /**
     * Both resolved once per run, so a config change mid-run cannot make two jobs of the
     * same batch answer to different numbers. The values themselves live on
     * PlainLanguageConfig, which the instance page reads too.
     */
    private ?int $maxPolls = null;

    private ?int $maxAttempts = null;

    /** Status codes the per-job fallback fetch got back, by text id — see handle(). */
    private array $endpointErrors = [];

    private function maxPolls(): int
    {
        return $this->maxPolls ??= app(PlainLanguageConfig::class)->maxPolls();
    }

    private function maxAttempts(): int
    {
        return $this->maxAttempts ??= app(PlainLanguageConfig::class)->maxAttempts();
    }

    protected $signature = 'workflows:plain-language-poll
                            {--dry-run : Report what the service says and what would be written, without writing anything}
                            {--diff : Print the per-field diff of every write-back}';

    protected $description = 'Fetch finished plain-language rewrites and write them into their entries.';

    public function handle(
        PlainLanguageJobStore $jobs,
        PlainLanguageTranslator $service,
        LegacyPlainLanguageDocument $document,
        WorkflowEventStore $events,
        DefinitionLoader $loader,
        WorkflowStateStore $states,
    ): int {
        $open = $jobs->open();

        if ($open->isEmpty()) {
            $this->info('No outstanding plain-language jobs.');

            return self::SUCCESS;
        }

        if (! $service->isConfigured()) {
            $this->warn('Plain-language service is not configured — '.$open->count().' job(s) left untouched.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $reported = $service->fetch($open->pluck('text_id')->all());
        } catch (TranslationFailed $e) {
            $this->error($e->getMessage());

            // No answer at all — DNS, TLS, timeout. The service being down is not this
            // run's problem to escalate: leave every row exactly as it is, count no
            // attempt, and let the next tick try again. A maintenance window must not
            // park half the queue.
            if ($e->status === null) {
                return self::FAILURE;
            }

            // An answered error code is different: something is wrong with the request
            // or with one of the jobs in it, and that has to reach an end. Asked one id
            // at a time so a single poisoned job cannot take the healthy ones with it —
            // this endpoint answers for the whole batch in one call (`?id=a,b,c`), and
            // at least one service in the wild returns 500 for an id it no longer knows,
            // which killed every in-flight rewrite on every tick.
            //
            // Ids that still fail alone are left out of $reported and recorded in
            // $endpointErrors. That is deliberate rather than a special case: settle()'s
            // "service does not know this id" branch then counts an attempt per tick and
            // gives up after the poll budget, which ends that submission —
            // the same budget and the same ending as every other way a rewrite can fail.
            // The recorded reason only changes what the failure is CALLED, so the alert
            // does not claim a forgotten job when the endpoint simply errored.
            $this->warn('  falling back to one request per job to isolate the failure.');

            $reported = [];

            foreach ($open as $job) {
                try {
                    $reported += $service->fetch([$job->text_id]);
                } catch (TranslationFailed $single) {
                    // Only an answered status is recorded: a connection that drops during
                    // the per-job retry says nothing about this job, and the wording would
                    // then claim an error the service never sent. Such an id falls back to
                    // "no longer known", which is what its absence from $reported means.
                    if ($single->status !== null) {
                        $this->endpointErrors[$job->text_id] = $single->status;
                    }

                    $this->warn("  {$job->text_id}: {$single->getMessage()}");
                }
            }
        }

        // Group by subject so one entry is loaded and saved once. A plain-language run
        // submits a single job today, but the grouping costs nothing and keeps the
        // command correct if that ever changes.
        foreach ($open->groupBy(fn (PlainLanguageJob $job) => $job->subject_id.'|'.$job->workflow) as $group) {
            $this->settle($group, $reported, $jobs, $document, $events, $loader, $states, $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * Resolve one subject's jobs: write back what has finished, note what failed, and
     * leave what is still running for the next tick.
     *
     * @param  \Illuminate\Support\Collection<int, PlainLanguageJob>  $group
     * @param  array<string, array<string, mixed>>  $reported
     */
    private function settle(
        $group,
        array $reported,
        PlainLanguageJobStore $jobs,
        LegacyPlainLanguageDocument $document,
        WorkflowEventStore $events,
        DefinitionLoader $loader,
        WorkflowStateStore $states,
        bool $dryRun,
    ): void {
        $first = $group->first();
        $subjectId = $first->subject_id;
        $workflow = $first->workflow;

        $entry = Entry::find($subjectId);

        if ($entry === null) {
            // The entry was deleted while the rewrite ran. Nothing to write into, and
            // nothing to warn about — drop the rows.
            $this->line("  {$subjectId}: entry is gone — jobs dropped.");
            $dryRun || $jobs->forget($subjectId, $workflow);

            return;
        }

        $documents = [];
        $failures = [];
        $stillOpen = 0;

        foreach ($group as $job) {
            $report = $reported[$job->text_id] ?? null;

            if ($report === null) {
                // The service does not know this id. Could be eventual consistency, could
                // be a lost job — the poll budget decides which.
                if ($job->attempts + 1 >= $this->maxPolls()) {
                    $status = $this->endpointErrors[$job->text_id] ?? null;

                    $failures[] = $status === null
                        ? __('workflows::messages.translation_plain_job_lost')
                        : __('workflows::messages.translation_plain_job_endpoint_error', ['status' => $status]);

                    // The row's own error_message stays technical — it is a diagnostic
                    // column, not something an editor reads.
                    $dryRun || $jobs->touch(
                        $job,
                        PlainLanguageJob::FAILED,
                        $status === null ? 'not reported by the service' : 'status endpoint answered '.$status
                    );

                    continue;
                }

                $stillOpen++;
                $dryRun || $jobs->touch($job, $job->status);

                continue;
            }

            $status = is_string($report['status'] ?? null) ? $report['status'] : PlainLanguageJob::PENDING;

            if ($status === PlainLanguageJob::FAILED) {
                $message = is_string($report['error_message'] ?? null) && trim($report['error_message']) !== ''
                    ? $report['error_message']
                    : __('workflows::messages.translation_plain_job_failed_unknown');
                $failures[] = $message;
                $dryRun || $jobs->touch($job, PlainLanguageJob::FAILED, $message);

                continue;
            }

            if ($status !== PlainLanguageJob::COMPLETED) {
                // Still working — unless it has been "still working" for the whole poll
                // polls, at which point the service is not going to finish it. Bounded
                // for the same reason as the unknown-id branch above: an unbounded
                // pending job holds its version in the waiting place forever, with a
                // spinner and no way out.
                if ($job->attempts + 1 >= $this->maxPolls()) {
                    $failures[] = __('workflows::messages.translation_plain_job_timed_out', [
                        'polls' => $this->maxPolls(),
                    ]);
                    $dryRun || $jobs->touch($job, PlainLanguageJob::FAILED, 'still '.$status.' after '.$this->maxPolls().' polls');

                    continue;
                }

                $stillOpen++;
                $dryRun || $jobs->touch($job, $status);

                continue;
            }

            $blocks = $this->blocksFrom($report['output_text'] ?? null);

            if ($blocks === null) {
                $failures[] = __('workflows::messages.translation_plain_job_bad_output');
                $this->reportUnusable($job, $report);
                $dryRun || $jobs->touch($job, PlainLanguageJob::FAILED, 'unusable output_text');

                continue;
            }

            $documents[] = [
                'blocks' => $blocks,
                'notes' => $report['qs_notes'] ?? null,
                'format' => $job->format,
                'plan' => $job->plan,
                'text_id' => $job->text_id,
            ];
            $dryRun || $jobs->touch($job, PlainLanguageJob::COMPLETED);
        }

        // Guarded: array_merge() with no arguments is a fatal, and an entry whose only
        // job is still running reaches here with nothing collected.
        $blocks = $documents === [] ? [] : array_merge(...array_column($documents, 'blocks'));

        $this->line("  {$subjectId}: ".count($blocks).' block(s) ready, '
            .$stillOpen.' still running, '.count($failures).' failed.');

        $wroteContent = false;
        $formats = array_values(array_unique(array_map(fn ($doc) => $doc['format'] ?? null, $documents), SORT_REGULAR));

        if ($blocks !== [] && count($formats) > 1) {
            // One answer in each format cannot be combined: the legacy one replaces the
            // content wholesale, the sections one writes per leaf. Only possible when a
            // job from before the update and one after it settle in the same tick.
            Log::warning('workflows.translation.plain_language_mixed_formats', [
                'subject_id' => $subjectId,
                'formats' => $formats,
            ]);
            $failures[] = __('workflows::messages.translation_plain_job_mixed_formats');
        } elseif ($blocks !== [] && $formats === [PlainLanguageLeafDocument::FORMAT]) {
            $wroteContent = $this->settleSections($entry, $subjectId, $workflow, $documents, $loader, $failures, $dryRun);
        } elseif ($blocks !== [] && ! $dryRun) {
            // Captured BEFORE apply() overwrites it: whether the untranslated copy
            // had anything in content_sets to begin with. A post whose only
            // translatable text is its title (everything else a video, a PDF —
            // structure with nothing TranslatableText could extract) correctly gets
            // an empty content_sets back from apply() too, because there was never
            // anything to put there besides the sets it already restores on its
            // own — that is a complete answer, not a broken one. Only losing
            // content that genuinely existed is the real "unusable" signal.
            $hadContent = (array) $entry->get(LegacyPlainLanguageDocument::contentField()) !== [];
            // The raw definition goes with it: apply() needs the workflow's settings
            // for the fields a rewrite has to empty (plain_language_clear_fields).
            // Read through the loader here, like failedPlaceFor() and
            // moveToCompletedPlace() below, rather than threaded through settle()'s
            // arguments.
            $data = $document->apply($entry, $blocks, $loader->raw($workflow));

            if ($hadContent && ($data[LegacyPlainLanguageDocument::contentField()] ?? []) === []) {
                $failures[] = __('workflows::messages.translation_plain_job_bad_output');
            } else {
                EventLogSuppressor::while(function () use ($entry, $data) {
                    $entry->data($data);
                    $entry->save();
                });

                $wroteContent = true;
                $this->line('    title: '.($data[LegacyPlainLanguageDocument::titleField()] ?? '—'));
                $this->recordNotes($subjectId, $workflow, $documents);
            }
        }

        // Terminal failure: nothing still running, nothing written, at least one
        // failure, and this version never carried a rewrite before (a resubmit that
        // fails is left alone — see the class docblock). Gated on `$wroteContent`
        // rather than `$blocks`: the service can answer with blocks that still fail to
        // become usable content in apply(), and that is just as much "nothing
        // produced" as never getting a block at all.
        //
        // Where it ends is the workflow's choice. A configured failed place keeps the
        // version and parks it there, which is what makes the failure visible at all:
        // it shows up in the queue with its own label and colour, survives a reload,
        // and a transition out of it turns "try again" into a click. Without the
        // setting the copy is deleted, as before.
        //
        // This SUBMISSION is over and produced nothing usable. Before giving up on the
        // rewrite, the whole call goes to the service again — a fresh submission with a
        // fresh id, up to maxAttempts() of them. Only the last one that fails parks the
        // version. The three signals are the same ones $terminal reads below; the
        // difference is purely whether the budget allows another go.
        $submission = (int) $group->max('submission');

        if ($stillOpen === 0 && ! $wroteContent && $failures !== [] && $submission < $this->maxAttempts()) {
            if ($dryRun) {
                // Falling through would be write-free anyway, but it would announce
                // "parking" for a version a real run would retry — a lying dry run.
                $this->line("  {$subjectId}: would re-submit (attempt ".($submission + 1).' of '.$this->maxAttempts().').');

                return;
            }

            if ($jobs->hasOpen($subjectId, $workflow)) {
                // A human's "rewrite again" landed in the same minute and owns the
                // version now. Do nothing at all — not even the forget() below, which
                // would delete their fresh row.
                $this->line("  {$subjectId}: a newer job is already open — no automatic re-submit.");

                return;
            }

            $problem = $this->resubmit($entry, $workflow, $submission + 1, $jobs);

            if ($problem === null) {
                $events->log($subjectId, $workflow, 'plain_language_retry', [
                    'note' => __('workflows::messages.translation_plain_job_retry', [
                        'attempt' => $submission,
                        'max' => $this->maxAttempts(),
                        'reason' => implode(' ', $failures),
                    ]),
                ]);

                $this->warn("  {$subjectId}: attempt {$submission} of {$this->maxAttempts()} failed — re-submitted.");

                // Load-bearing, not tidiness. Everything below belongs to a subject that
                // is DONE: the terminal branch would park it, and the tail calls
                // forget($subjectId, $workflow), which deletes every row for the pair —
                // including the one resubmit() just wrote. The service would then be
                // working on an answer nobody is left to collect, and the version would
                // spin forever.
                return;
            }

            // The retry itself could not happen. The message rides along into the failure
            // events so the alert says WHY no further attempt was made, and the terminal
            // branch below parks the version — never leave it without an open job and
            // without a failure, or it drops out of open() and spins for good.
            $failures[] = $problem;
        }

        // The failure events below are reached in the PARKED case and skipped in the
        // deleting one, which still returns early. Both halves are deliberate: the
        // parked version needs them, because the instance alert and the poll-driven
        // toast read the newest plain_language_failed row — while on the delete path
        // PurgeWorkflowDataOnEntryDeleted erases that history along with the entry, so
        // writing it first would only write something about to be erased.
        $terminal = $stillOpen === 0 && ! $wroteContent && $failures !== [] && ! $dryRun
            && ! $this->hasEverCompleted($subjectId, $workflow);

        $parkedIn = null;

        if ($terminal) {
            $parkedIn = $this->failedPlaceFor($workflow, $loader);

            if ($parkedIn === null) {
                $this->warn("  {$subjectId}: rewrite never produced usable content — deleting the untranslated copy.");
                $jobs->forget($subjectId, $workflow);
                $entry->delete();

                return;
            }

            $this->warn("  {$subjectId}: rewrite never produced usable content — parking the version in [{$parkedIn}].");
            $states->set($subjectId, $workflow, $parkedIn);
        }

        foreach ($failures as $message) {
            $this->warn("  {$subjectId}: {$message}");
        }

        if ($failures !== [] && ! $dryRun) {
            // ONE event for one failed rewrite, with every cause in it. Previously each
            // message became its own row, which meant the alert — it reads the newest —
            // showed whichever cause happened to be last and hid the rest. A rewrite that
            // failed to poll AND could not be re-submitted has two things worth saying.
            //
            // The frame is applied here and only here: this is the terminal note, so
            // "given up after N attempts" is true. The same fragment inside a retry note
            // would be a lie.
            //
            // Counted on $submission, NOT on maxAttempts(): the number that matters is how
            // many submissions actually happened. They are the same when the budget is
            // spent normally, and they differ exactly when it matters — a service that
            // refuses the re-submission too parks the version after ONE attempt, and
            // claiming the configured maximum there would be a lie in the one situation
            // where somebody is reading the alert to find out what was tried.
            $events->log($subjectId, $workflow, 'plain_language_failed', [
                'note' => trans_choice(
                    'workflows::messages.translation_plain_failed_reason',
                    $submission,
                    ['reason' => implode('; ', array_unique($failures))]
                ),
                'to' => $parkedIn,
            ]);
        }

        // Only once nothing is outstanding: the rows are the record of what is still
        // running, and a re-run should start from an empty table. Failed rows go too —
        // the workflow event is the lasting record, and a row nobody will poll again is
        // just a row.
        if ($stillOpen === 0 && ! $dryRun) {
            if ($blocks !== [] && $failures === []) {
                $movedTo = $this->moveToCompletedPlace($entry, $subjectId, $workflow, $loader, $states);

                $events->log($subjectId, $workflow, 'plain_language_completed', [
                    'note' => $movedTo === null
                        ? __('workflows::messages.translation_plain_job_written', ['count' => count($blocks)])
                        : __('workflows::messages.translation_plain_job_written_moved', ['count' => count($blocks), 'place' => $movedTo]),
                    'to' => $movedTo,
                ]);
            }

            $jobs->forget($subjectId, $workflow);
        }
    }

    /**
     * Write a `sections` answer back, leaf by leaf (PlainLanguageLeafDocument).
     *
     * Returns whether content was written. A rejected answer adds ONE failure — the
     * reasons go to the log, where the request and response sit next to them — and
     * writes nothing, so the retry in settle() can resubmit. A dry run applies too,
     * so it can print what WOULD be written, and then stops before the save.
     *
     * An answer that changes nothing (a re-poll of an already written rewrite) is
     * not saved again, but still counts as written: the content IS the answer.
     *
     * @param  array<int, array<string, mixed>>  $documents
     * @param  array<int, string>  $failures
     */
    private function settleSections($entry, string $subjectId, string $workflow, array $documents, DefinitionLoader $loader, array &$failures, bool $dryRun): bool
    {
        if (count($documents) > 1) {
            Log::warning('workflows.translation.plain_language_leaf_rejected', [
                'subject_id' => $subjectId,
                'errors' => ['more than one completed sections job for one subject'],
            ]);
            $failures[] = __('workflows::messages.translation_plain_job_structure', ['reason' => 'more than one job']);

            return false;
        }

        $document = $documents[0];
        $result = app(PlainLanguageLeafDocument::class)->apply($entry, $document['blocks'], (array) $document['plan'], $loader->raw($workflow));

        foreach ($result->warnings as $warning) {
            $this->line("    warning: {$warning}");
        }

        if (! $result->ok()) {
            Log::warning('workflows.translation.plain_language_leaf_rejected', [
                'subject_id' => $subjectId,
                'text_id' => $document['text_id'] ?? null,
                'errors' => $result->errors,
                'warnings' => $result->warnings,
                'output_text' => $document['blocks'],
            ]);

            foreach ($result->errors as $error) {
                $this->warn("    rejected: {$error}");
            }

            $failures[] = __('workflows::messages.translation_plain_job_structure', [
                'reason' => $result->errors[0] ?? '?',
            ]);

            return false;
        }

        if ($dryRun || $this->option('diff')) {
            $this->printDiff($result->diff);
        }

        if (app(\TakepartMedia\Workflows\Translation\PlainLanguageConfig::class)->logsCalls()) {
            Log::info('workflows.translation.plain_language_leaf_diff', [
                'subject_id' => $subjectId,
                'text_id' => $document['text_id'] ?? null,
                'dry_run' => $dryRun,
                'changed' => $result->changed,
                'diff' => $result->diff,
                'warnings' => $result->warnings,
            ]);
        }

        if ($dryRun) {
            $this->line('    (dry run — nothing written)');

            return false;
        }

        if ($result->changed) {
            EventLogSuppressor::while(function () use ($entry, $result) {
                $entry->data($result->data);
                $entry->save();
            });
        } else {
            $this->line('    unchanged — not saved again.');
        }

        $this->line('    title: '.($result->data[PlainLanguageLeafDocument::titleField()] ?? '—'));
        $this->recordNotes($subjectId, $workflow, $documents);

        return true;
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function printDiff(array $diff): void
    {
        if ($diff === []) {
            $this->line('    diff: nothing changes.');

            return;
        }

        foreach ($diff as $field => $change) {
            if (isset($change['leaves'])) {
                if (! $change['changed']) {
                    continue;
                }

                $this->line("    {$field}: ".count($change['leaves']).' leaf/leaves changed');

                foreach ($change['leaves'] as $leaf) {
                    $this->line("      {$leaf['path']} ({$leaf['type']}): '{$leaf['from']}' → '{$leaf['to']}'");
                }

                continue;
            }

            $this->line("    {$field}: '{$change['from']}' → '{$change['to']}'");
        }
    }

    /**
     * Moves a subject straight into `metadata.plain_language_completed_place`,
     * if the workflow names one — see the class docblock for why this is a
     * direct WorkflowStateStore::set() rather than a transition. Only called
     * once a job has settled with blocks written and no failures. A failed
     * rewrite on a version that already held real content before (a resubmit)
     * leaves the entry exactly where it is, so plain_language_resubmit stays
     * the visible next step; a failure on a version that never held any is
     * handled upstream in settle() by deleting the copy instead — see
     * hasEverCompleted(). Returns the place moved to, or null when the
     * workflow has no such setting (the common case).
     */
    /**
     * Hand the whole rewrite to the service again, as submission $submission.
     *
     * Returns null on success, or a message saying why no further attempt was possible —
     * in which case the caller parks the version with that message attached.
     *
     * Order is forget() THEN submit(), the same as the UI's "rewrite again" path, and it
     * matters twice. Reversed it would delete the row it just wrote; and a service that
     * derives its job id from the content would answer a re-submit with the SAME text_id,
     * which is UNIQUE — add-before-delete would throw a QueryException inside a scheduled
     * command and abort the tick for every other subject in the batch.
     *
     * The whole body is wrapped, for the same reason: an unexpected throw here must
     * degrade into parking this one version, not kill the run.
     *
     * No transition is applied and no entry is saved. The version stays exactly where it
     * is — which is also why the spinner keeps spinning across a retry: `pendingRewrite`
     * asks hasOpen(), and a new open row exists.
     */
    private function resubmit($entry, string $workflow, int $submission, PlainLanguageJobStore $jobs): ?string
    {
        try {
            $service = app(PlainLanguageTranslator::class);

            if (! $service->isConfigured()) {
                // Unreachable in practice — handle() returns long before settle() when the
                // service is unconfigured. Here so the method is correct read on its own.
                return __('workflows::messages.translation_plain_not_configured');
            }

            $submitter = app(PlainLanguageSubmission::class);
            $source = $submitter->sourceFor($entry, $workflow);

            if ($source === null) {
                return __('workflows::messages.translation_plain_job_source_missing');
            }

            // Never rewrite out of a draft, exactly as mechanicalBlocker() refuses to.
            // Parking is the recoverable answer: a human publishes the source and starts
            // over, and that fresh run gets the full budget because a UI resubmit writes
            // submission 1.
            $readiness = app(\TakepartMedia\Workflows\Workflow\WorkflowResolver::class)
                ->sourceReadiness($source, $workflow);

            if (! $readiness['ready']) {
                return __('workflows::messages.translation_plain_job_source_unpublished');
            }

            $jobs->forget((string) $entry->id(), $workflow);

            [$pending, $error] = $submitter->submit($source, $entry, (string) $entry->locale(), $workflow, $submission);

            if ($error !== null) {
                // Refused again. The attempt is spent and no row exists, so no later tick
                // would ever look at this subject again — park it now rather than loop.
                //
                // A fragment rather than the service's own sentence: $error is the verbose
                // message meant for a 422 body ("Der Übersetzungsdienst antwortete mit
                // Status 500. — Server Error"), and dropping it into the alert frame
                // rebuilds exactly the nested, capitalised text this family was cleaned up
                // to avoid. The detail is in the log, where the request and the response
                // already sit side by side.
                return __('workflows::messages.translation_plain_job_resubmit_refused');
            }

            if ($pending === 0) {
                // submit() reports "no translatable text" as success, because in the start
                // path the copy IS the result. Here it is not: the previous submission
                // already failed and there is now no row, so treating it as success would
                // strand the version. Retrying is pointless too — an empty source stays
                // empty.
                return __('workflows::messages.translation_plain_job_no_segments');
            }

            return null;
        } catch (\Throwable $e) {
            report($e);

            // The detail goes to report() and the log; the alert says only that
            // something unexpected happened, which is all an editor can act on.
            return __('workflows::messages.translation_plain_job_unexpected');
        }
    }

    /**
     * The place a terminally failed rewrite is parked in, or null when the workflow
     * does not name one — in which case the untranslated copy is deleted instead.
     *
     * Read through the loader rather than passed down, so the two callers in settle()
     * cannot disagree about it mid-run.
     */
    private function failedPlaceFor(string $workflow, DefinitionLoader $loader): ?string
    {
        $place = $loader->raw($workflow)['metadata']['plain_language_failed_place'] ?? null;

        return is_string($place) && $place !== '' ? $place : null;
    }

    private function moveToCompletedPlace(EntryContract $entry, string $subjectId, string $workflow, DefinitionLoader $loader, WorkflowStateStore $states): ?string
    {
        $raw = $loader->raw($workflow);
        $place = $raw['metadata']['plain_language_completed_place'] ?? null;

        if (! is_string($place) || $place === '') {
            return null;
        }

        $states->set($subjectId, $workflow, $place);

        // The arrival this whole command exists to produce, announced the same way
        // a clicked one would be: through the place's own `mail` action, if it
        // carries one. This is the reason that action exists on places at all —
        // there is no transition here to hang a mail on, and no actor either, so
        // the mail leaves out the "triggered by" line.
        //
        // Contained inside notifyPlaceArrival(): a dead mail server must not turn
        // a rewrite that landed correctly into a failed scheduler run.
        app(WorkflowNotifier::class)->notifyPlaceArrival($entry, $workflow, $raw, $place);

        return $place;
    }

    /**
     * Whether this version has EVER had a successful rewrite written into it —
     * the line between "still the untranslated copy, safe to delete on failure"
     * and "a real, previously-approved version whose retry happened to fail,
     * which must not be destroyed just because this attempt did not land."
     */
    private function hasEverCompleted(string $subjectId, string $workflow): bool
    {
        return WorkflowEvent::query()
            ->where('workflow', $workflow)
            ->where('subject_id', $subjectId)
            ->where('type', 'plain_language_completed')
            ->exists();
    }

    /**
     * The rewritten HTML blocks, or null when the shape is unusable.
     *
     * In envelope mode (`input_format: json-modules`) `output_text` is a LIST of HTML
     * blocks — and deliberately not aligned with what was sent: the service merges,
     * splits and omits, so five texts can come back as nineteen blocks. Length is
     * therefore not checked; only that there is a list of non-empty strings at all.
     *
     * A single string is accepted as a one-block document, which is what the service
     * falls back to when it decides envelope mode does not apply.
     *
     * @return array<int, string>|null
     */
    private function blocksFrom($output): ?array
    {
        if (is_string($output)) {
            return trim($output) === '' ? null : [$output];
        }

        if (! is_array($output) || $output === []) {
            return null;
        }

        $blocks = array_values(array_filter(
            array_map(fn ($value) => is_string($value) ? trim($value) : '', $output),
            fn ($block) => $block !== '',
        ));

        return $blocks === [] ? null : $blocks;
    }

    /**
     * Log what an unusable result actually looked like.
     *
     * Without this a rejection is a dead end: the row is dropped, the event says only
     * that something was unusable, and the body is gone. Learned the hard way — the
     * first run against the changed API was refused and there was nothing left to
     * diagnose it with.
     *
     * @param  array<string, mixed>  $report
     */
    private function reportUnusable(PlainLanguageJob $job, array $report): void
    {
        Log::warning('workflows.translation.plain_language_unusable_output', [
            'text_id' => $job->text_id,
            'subject_id' => $job->subject_id,
            'reported_status' => $report['status'] ?? null,
            'output_type' => get_debug_type($report['output_text'] ?? null),
            'output_count' => is_array($report['output_text'] ?? null) ? count($report['output_text']) : null,
            'output_text' => $report['output_text'] ?? null,
        ]);
    }

    /**
     * File the service's QA report as a comment on the entry.
     *
     * `qs_notes` lists what was simplified, added and — the part that matters for
     * medical content — left out. That belongs where the medical review will look,
     * which is the entry's comment thread, not a technical log line.
     *
     * @param  array<int, array{blocks: array<int, string>, notes: mixed}>  $documents
     */
    private function recordNotes(string $subjectId, string $workflow, array $documents): void
    {
        $notes = collect($documents)
            ->pluck('notes')
            ->filter(fn ($note) => is_string($note) && trim($note) !== '')
            ->implode("\n\n");

        if ($notes === '') {
            return;
        }

        app(WorkflowCommentStore::class)->add([
            'subject_id' => $subjectId,
            'workflow' => $workflow,
            'body' => __('workflows::messages.translation_plain_qs_notes')."\n\n".$notes,
            'user_id' => null,
            'author_name' => __('workflows::messages.event_actor_system'),
            // Named, so the instance page can ask for THIS report rather than
            // guessing from the missing author — see WorkflowComment::KIND_*.
            'kind' => WorkflowComment::KIND_PLAIN_LANGUAGE_QS,
        ]);
    }
}
