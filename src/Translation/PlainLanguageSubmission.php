<?php

namespace TakepartMedia\Workflows\Translation;

use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * Handing one entry's texts to the plain-language service and recording the job.
 *
 * Extracted from WorkflowController so the scheduler can do it too. The click-driven
 * path and PollPlainLanguageCommand's automatic retry have to submit IDENTICALLY —
 * same excluded fields, same document shape, same one-request-per-entry rule — and
 * two copies of that would drift the first time one of them is fixed.
 *
 * Deliberately request-free: no `Request`, no session, no `User::current()`, no
 * localized error strings. Failures come back as the service's own message and the
 * caller words them for its audience (a 422 body in the controller, a workflow event
 * in the command).
 */
class PlainLanguageSubmission
{
    /**
     * Hand the source's text leaves to the service (as sections, see
     * PlainLanguageLeafDocument) and record the job — with its plan — against the
     * localization the rewrite is destined for.
     *
     * Returns `[submittedJobs, errorMessage]`. A refused submission leaves no row —
     * whether that is fatal is the caller's call: the start path keeps the copy and
     * reports, the retry path treats it as the attempt being used up.
     *
     * @param  int  $submission  Which submission of the run this is; see the `submission`
     *                           column on the job table.
     * @return array{0: int, 1: string|null}
     */
    public function submit($source, $localization, string $target, string $workflow, int $submission = 1): array
    {
        $raw = app(DefinitionLoader::class)->raw($workflow);

        $document = app(PlainLanguageLeafDocument::class);
        $plan = $document->plan($source, $raw);
        $texts = $document->texts($plan, $source);

        if ($texts === []) {
            // Nothing to rewrite — the copy IS the correct result, exactly as in the
            // synchronous path. Note this is reported as success: the retry path has to
            // decide for itself that "nothing to send" means it cannot try again.
            return [0, null];
        }

        $codes = app(LanguageCodes::class);
        $context = app(TranslationRunner::class)->contextOf($source);

        // ONE request for the whole entry, deliberately unchunked. The service answers
        // with a rewritten document, and splitting it would have it rewrite each piece
        // on its own — four parts, four headings, four introductions. The sections
        // inside that one request are what keeps the answer mappable per field.
        try {
            $textId = app(PlainLanguageTranslator::class)->submit(
                $texts,
                $codes->source((string) $source->locale()),
                $codes->target($target),
                $context,
                $document->requestExtras($plan),
            );
        } catch (TranslationFailed $e) {
            return [0, $e->getMessage()];
        }

        // The plan travels with the job: it is what the answer is written into, so
        // the write-back does not depend on the source still looking the same when
        // the answer arrives minutes later. The leaf paths double as `segment_keys`
        // for diagnostics.
        app(PlainLanguageJobStore::class)->add(
            $localization->id(),
            $workflow,
            $textId,
            0,
            array_column($plan['leaves'], 'path'),
            $submission,
            PlainLanguageLeafDocument::FORMAT,
            $plan,
        );

        return [1, null];
    }

    /**
     * The version a rewrite of $subject reads from, or null when it does not exist.
     *
     * The console counterpart to WorkflowController::sourceEntryFor(): same rule, minus
     * the two halves a scheduled run cannot have. No per-run source pick, because that
     * value arrives in a request — and the UI's own "rewrite again" already ignores it,
     * so a retry matches the established behaviour rather than inventing one. No
     * localized "source missing" string either; the caller words that.
     *
     * Resolution goes through the origin and its descendants, never through $subject
     * itself, which is why passing the plain-language localization gives the same answer
     * as passing the source would.
     */
    public function sourceFor($subject, string $workflow)
    {
        $resolver = app(WorkflowResolver::class);
        $root = $subject->root() ?? $subject;

        $site = $resolver->sourceSiteFor(
            app(DefinitionLoader::class)->raw($workflow),
            $resolver->groupLocales($subject),
            (string) $root->locale(),
        );

        if ($site === (string) $root->locale()) {
            return $root;
        }

        return $root->descendants()->get($site);
    }
}
