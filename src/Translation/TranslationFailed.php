<?php

namespace TakepartMedia\Workflows\Translation;

use RuntimeException;

/**
 * A translation request did not produce a usable result.
 *
 * The message is written to be shown to an editor: it says what happened and,
 * where the provider's answer allows it, what to do about it. It never carries
 * the API key or the submitted content.
 */
class TranslationFailed extends RuntimeException
{
    /**
     * The HTTP status the service answered with, or null when the call never got an
     * answer at all (DNS, TLS, timeout).
     *
     * The distinction is load-bearing for PollPlainLanguageCommand: an answered error
     * code says something is wrong with this request or this job, so it counts toward
     * the job's attempt budget and eventually parks it. No answer says the service is
     * down, which is nobody's job to escalate — the next tick simply tries again, and
     * every row is left exactly as it was.
     */
    public function __construct(string $message = '', public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
