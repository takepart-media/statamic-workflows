<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Mail\MailContext;
use TakepartMedia\Workflows\Mail\MailTypes;
use TakepartMedia\Workflows\Jobs\SendWorkflowNotification;
use TakepartMedia\Workflows\Mail\WorkflowNotification;

/**
 * Sends the `mail` action's mail — the one moment this addon reaches outside the
 * installation on its own behalf.
 *
 * Two triggers reach it, and they are the two ways a subject can end up
 * somewhere. A TRANSITION that carries the action (WorkflowTransitioner::apply())
 * and a PLACE that carries it, entered by whatever means — a click, or
 * PollPlainLanguageCommand writing a finished rewrite into the workflow's
 * completed place with no click at all. Both go through this class so that "who
 * gets the mail, in which language, saying what" has exactly one answer.
 *
 * WHAT the mail says is a MailTypes handle the definition names. This class never
 * learns what any given type is about: it assembles the FACTS (which entry, which
 * state, which URL, which recipient) into a MailContext and hands that over. The
 * words, the data and the template are the mail type's business — see MailTypes
 * and Contracts\WorkflowMail.
 *
 * ONE MAIL PER RECIPIENT, deliberately, rather than one mail to many addresses:
 *
 * - the language is a per-user preference, and a single render can only have one;
 * - a shared To-header would show every assignee who else is on the post, which
 *   is not something a transition was asked to publish;
 * - a mail type's composer may say something person-specific (MailContext carries
 *   the recipient), which one shared render could not.
 *
 * Note that the COPY does not name the recipient — it addresses nobody, see
 * lang/{de,en}/messages.php. That is a wording decision and not a reason to merge
 * the sends: the three above stand on their own.
 *
 * The cost is one SMTP round trip per assignee, inside the click. Acceptable
 * because assignee sets are small by construction (a handful of named people, not
 * a group) — but it is the reason a failing mail server is contained per recipient
 * below rather than aborting the loop.
 */
class WorkflowNotifier
{
    public function __construct(
        private readonly WorkflowAssigneeStore $assignees,
        private readonly InstanceUrl $urls,
    ) {}

    /**
     * The place-arrival path: send whatever mail the place asks for, or nothing.
     *
     * Its own method rather than a check at each call site, because there are two
     * call sites — a transition landing here and the plain-language poller landing
     * here — and they must not be able to disagree about what a place declares or
     * about what a failure means. Every failure is contained: neither an editor's
     * click nor a scheduled poll run may be reported as broken because a mail
     * server was.
     *
     * @param  array<string, mixed>  $raw  the whole workflow definition
     * @param  string  $place  the place the subject just arrived in
     */
    public function notifyPlaceArrival(
        EntryContract $entry,
        string $workflow,
        array $raw,
        string $place,
        ?string $actorId = null,
    ): void {
        $mailType = PlaceActions::notificationMail($raw['places'][$place] ?? []);

        if ($mailType === null) {
            return;
        }

        try {
            $this->queue($entry, $workflow, $raw, $mailType, $place, CustomActions::SCOPE_PLACE, $place, $actorId);
        } catch (\Throwable $e) {
            // The notifier already contains per-recipient failures; this catches
            // the rest (a broken mail config, an unresolvable dependency). By this
            // line the arrival is a written fact, so an exception has only two
            // possible readings — a logged failure, or a completed step reported
            // as an error.
            Log::error('Workflow place arrival notification failed.', [
                'workflow' => $workflow,
                'place' => $place,
                'subject' => $entry->id(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param  EntryContract  $entry  the version the step acted on
     * @param  array<string, mixed>  $raw  the whole workflow definition. Passed
     *                                     down into every MailContext rather than
     *                                     re-read, so a composer that wants at the
     *                                     workflow's shape does not pay for a YAML
     *                                     parse per recipient — the caller already
     *                                     paid for this one.
     * @param  string  $mailType  a MailTypes handle — WHICH mail this is
     * @param  string  $to  the place the subject now sits in
     * @param  string  $scope  `transition` or `place`: what triggered this
     * @param  string  $trigger  the transition's or the place's handle, per $scope
     * @param  string|null  $actorId  who clicked; null when the SLA scheduler or the
     *                                plain-language poller drove it
     * @param  int|null  $waitingDays  how long the subject has been sitting in $to —
     *                                 only the `reminder` scope knows; `:days` in
     *                                 the mail's intro
     * @return int how many mails went out — SendPlaceRemindersCommand logs a
     *             reminder only when at least one did
     */
    public function notify(
        EntryContract $entry,
        string $workflow,
        array $raw,
        string $mailType,
        string $to,
        string $scope,
        string $trigger,
        ?string $actorId = null,
        ?int $waitingDays = null,
    ): int {
        $subjectId = (string) $entry->id();

        // Read from the store rather than taken as an argument: the transitioner has
        // already written the new set by the time this runs, so this IS the answer to
        // "who is responsible now" — including the case where the step carries
        // no `user_assignment` at all and the previous assignees simply stayed.
        $userIds = $this->assignees->idsFor($subjectId, $workflow);

        if ($userIds === []) {
            // A legitimate outcome, not a failure: a step may notify at a point
            // that happens to leave nobody assigned. Silent on purpose — logging it
            // would put a line in the log for every correct run of such a workflow.
            return 0;
        }

        // Resolved once per notify(), not once per recipient: none of the four
        // depends on who receives the mail, and `InstanceUrl` parses the workflow
        // YAML to find the nav slug.
        $title = (string) ($entry->value('title') ?: $entry->slug());
        $url = $this->urls->for($subjectId, $workflow);
        $workflowLabel = $this->workflowLabel($raw, $workflow);
        // Looked up here and not inside actorName(), so the one query it costs is
        // paid once for the whole batch rather than once per assignee — and so the
        // user object itself can travel into every context below. Both callers of
        // notify() (a transition and notifyPlaceArrival()) pass their actor id
        // through to exactly this line.
        $actor = $actorId === null ? null : User::find($actorId);
        $actorName = $this->actorName($actor);
        $type = MailTypes::resolve($mailType);
        $sent = 0;

        foreach ($userIds as $userId) {
            // Whoever triggered the step already knows about it — a creator who is
            // assigned their own new post, or someone who hands a post to a group
            // they belong to, gets no mail about their own click.
            if ($actorId !== null && (string) $userId === $actorId) {
                continue;
            }

            $user = User::find($userId);

            // An assignment can outlive the user it names (deleted account), and a
            // Statamic user is not required to have an email. Neither is an error
            // worth a log line per transition — there is simply nowhere to send.
            if ($user === null || ! $user->email()) {
                continue;
            }

            // One context per recipient, because a mail type is allowed to say
            // something person-specific and because the language is per user.
            // Everything expensive above it is already resolved.
            $mail = new WorkflowNotification(new MailContext(
                entry: $entry,
                workflow: $workflow,
                definition: $raw,
                mailType: $type,
                scope: $scope,
                handle: $trigger,
                state: $to,
                recipient: $user,
                title: $title,
                url: $url,
                workflowLabel: $workflowLabel,
                actorName: $actorName,
                actor: $actor,
                waitingDays: $waitingDays,
            ));

            try {
                Mail::to($user->email(), $user->name())
                    // The recipient's CP language, not the sender's. Everything the
                    // mail translates is resolved inside this scope — see
                    // WorkflowNotification.
                    ->locale($user->preferredLocale())
                    ->send($mail);
                $sent++;
            } catch (\Throwable $e) {
                // Per recipient, not per batch: one rejected address (a typo, a
                // full mailbox) must not silently cost everybody else their
                // notification. Caught here as well as in the caller because
                // that outer guard would end the loop at the first failure.
                Log::error('Workflow notification failed.', [
                    'workflow' => $workflow,
                    'trigger' => $trigger,
                    'mail' => $mailType,
                    'subject' => $subjectId,
                    'user' => $userId,
                    'exception' => $e,
                ]);
            }
        }

        return $sent;
    }

    /**
     * notify(), decoupled from the request that asked for it — the entry point for
     * every mail a CLICK triggers (a transition, a place arrival), so the editor
     * does not wait for one mail API call per assignee.
     *
     * `workflows.mail_delivery` picks how:
     *   - `after_response` (default): sent in the same PHP process right after the
     *     response is flushed to the browser. No queue worker needed.
     *   - `queue`: pushed onto the configured queue connection — needs a worker,
     *     buys retries.
     *   - `sync`: sent inline, the behaviour before this method existed.
     *
     * Returns nothing, unlike notify(): the mails have not gone out yet. Callers
     * that need the count (SendPlaceRemindersCommand) keep calling notify().
     *
     * @param  array<string, mixed>  $raw
     */
    public function queue(
        EntryContract $entry,
        string $workflow,
        array $raw,
        string $mailType,
        string $to,
        string $scope,
        string $trigger,
        ?string $actorId = null,
        ?int $waitingDays = null,
    ): void {
        $mode = (string) config('workflows.mail_delivery', 'after_response');

        if ($mode === 'sync') {
            $this->notify($entry, $workflow, $raw, $mailType, $to, $scope, $trigger, $actorId, $waitingDays);

            return;
        }

        $job = new SendWorkflowNotification(
            (string) $entry->id(), $workflow, $mailType, $to, $scope, $trigger, $actorId, $waitingDays,
        );

        $mode === 'queue' ? dispatch($job) : dispatch($job)->afterResponse();
    }

    /**
     * What the mail calls the workflow: its nav label, falling back to the raw
     * name. navLabel() returns an empty string for a workflow without
     * `metadata.nav` — a manager-less workflow still transitions, and "you are in
     * the workflow ''" would be worse than the handle.
     *
     * @param  array<string, mixed>  $raw
     */
    private function workflowLabel(array $raw, string $workflow): string
    {
        $label = DefinitionLoader::navLabel($raw['metadata']['nav'] ?? []);

        return $label !== '' ? $label : $workflow;
    }

    /**
     * Takes the resolved actor rather than the id it came from: the lookup belongs
     * to the batch (see notify()), and the two nulls mean the same thing here
     * either way — nobody triggered this, or the account that did is gone.
     */
    private function actorName(?UserContract $actor): ?string
    {
        // A null here means the line is left out of the mail entirely, which is the
        // honest rendering of "we no longer know who did this" — the same for a
        // deleted account and for one with neither a name nor an address.
        if ($actor === null) {
            return null;
        }

        $name = (string) ($actor->name() ?: $actor->email());

        return $name !== '' ? $name : null;
    }
}
