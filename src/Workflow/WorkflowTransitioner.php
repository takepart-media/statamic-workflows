<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Content\EntrySnapshot;
use TakepartMedia\Workflows\Support\EventLogSuppressor;

/**
 * Applies a workflow transition end-to-end: writes the state, syncs the
 * Statamic-published flag from the target place's metadata, applies the
 * transition's `user_assignment` action if it has one, logs a WorkflowEvent,
 * creates a Statamic revision, mails the assignees if the transition — or the
 * place it lands in — says to, and finally runs the project's own action
 * handlers. Callable from both the HTTP
 * controller and the scheduled aging command — pass `actorId: null` for system
 * contexts.
 *
 * Those last two steps make this the addon's only outward-facing moment: nothing
 * else here fires an event, sends a mail or calls project code. Anything a project
 * wants to happen "when this step runs" happens at the bottom of apply().
 *
 * Callers are expected to have validated the transition's `from`-guard and
 * permission gates BEFORE invoking apply(); this class only records the fact
 * that a transition happened.
 */
class WorkflowTransitioner
{
    public function __construct(
        private readonly WorkflowStateStore $states,
        private readonly WorkflowAssigneeStore $assignees,
        private readonly WorkflowEventStore $events,
        private readonly WorkflowResolver $resolver,
        private readonly AssignmentCandidates $candidates,
    ) {}

    /**
     * @param  array<string, mixed>  $raw   The raw workflow definition (after DefinitionLoader::raw)
     * @param  array<int, string>|null  $explicitAssignees  Who takes over — the caller's already
     *                                    resolved answer, which is how a MANUAL `user_assignment`
     *                                    arrives (the controller validated the picks). Null hands
     *                                    the question back here: an AUTOMATIC action is resolved
     *                                    from the subject, anything else leaves assignment alone.
     * @param  string|null  $note  a History note — only for unattended callers
     * @param  bool  $notify  false skips the step's mail (WorkflowNotifier::stepMail()) for
     *                        this run — the editor switched it off in the assignee picker
     */
    public function apply(
        EntryContract $entry,
        string $workflow,
        string $transitionName,
        array $raw,
        ?string $actorId = null,
        ?array $explicitAssignees = null,
        ?string $note = null,
        bool $notify = true,
    ): string {
        $transition = $raw['transitions'][$transitionName] ?? null;

        if ($transition === null) {
            throw new \InvalidArgumentException("Transition [{$transitionName}] not defined on workflow [{$workflow}].");
        }

        // Same authority the caller validated against — reading the store raw here
        // used to reject any entry that predates the workflow (no row yet) with a
        // confusing "not allowed from []", even though the caller's own check had
        // just passed on the derived state. The row is written below either way.
        $current = $this->resolver->stateFor($entry, $workflow, $raw);
        $from = (array) ($transition['from'] ?? []);

        // A null state is "not part of this workflow" (an unpublished post in a
        // localization manager) and satisfies no `from` — said out loud, because
        // "not allowed from []" would read like a broken definition.
        if ($current === null) {
            throw new \RuntimeException("Transition [{$transitionName}] not allowed: entry has no state in workflow [{$workflow}].");
        }

        if (! in_array($current, $from, true)) {
            throw new \RuntimeException("Transition [{$transitionName}] not allowed from [{$current}].");
        }

        $to = $transition['to'];

        // The publish-flag sync and the revision below are saves the addon makes on
        // the editor's behalf. Suppressed as one scope so they don't show up in the
        // History as draft saves — this step logs its own event, with its own
        // snapshot, a few lines down.
        return EventLogSuppressor::while(function () use ($entry, $workflow, $transitionName, $transition, $raw, $actorId, $explicitAssignees, $current, $to, $note, $notify) {
            $this->states->set($entry->id(), $workflow, $to);
            app(PublishedFlag::class)->sync($entry, $raw, $to);
            $this->stampContentTimestamp($entry, $raw, $to);

            // Assignment happens ONLY where a transition says so — its
            // `user_assignment` action (TransitionActions::USER_ASSIGNMENT),
            // resolved by the caller into $explicitAssignees.
            //
            // A transition without one leaves the responsible people exactly as
            // they were. It used to hand the subject to the destination place's
            // group instead, which is why a step could quietly widen
            // responsibility from two named people back to a whole group: the
            // place had the last word, not the action. Now nothing assigns behind
            // the action's back, and "who is responsible" is answerable by reading
            // the transitions alone.
            //
            // The consequence is deliberate and worth knowing: a workflow whose
            // transitions declare no `user_assignment` never changes assignment
            // after the subject entered it (initialAssignees() at creation), and
            // since canApplyTransition() gates on being an assignee, a downstream
            // group cannot act until some transition hands the subject over. The
            // hand-over is now something the definition has to state.
            $takeOver = $explicitAssignees ?? $this->automaticAssignees($transition, $entry);

            if ($takeOver !== null) {
                $this->assignees->set($entry->id(), $workflow, $takeOver);
            }

            $fresh = $entry->fresh() ?? $entry;

            $this->events->log($entry->id(), $workflow, 'transition', [
                'transition' => $transitionName,
                'from' => $current,
                'to' => $to,
                'user_id' => $actorId,
                // No note: the History row already prints the transition label,
                // localized via DefinitionLoader::transitionLabel(). Storing the
                // raw YAML label here would repeat it in whatever language the
                // definition happens to be written in.
                // A caller that is not a click (AutoTransitionCommand) may say
                // why it ran; a click never passes one.
                'note' => $note,
                'snapshot' => app(EntrySnapshot::class)->take($fresh),
            ]);

            $this->createStatamicRevision(
                $fresh,
                DefinitionLoader::transitionLabel($transitionName, $transition['metadata']['label'] ?? null),
                $actorId
            );

            // The one built-in action with an EFFECT, and therefore the only one
            // called from here rather than merely read somewhere. It does not go
            // through runHandlers() below: that path resolves handlers via
            // CustomActions, which by design only knows PROJECT declarations.
            //
            // Placed after everything the addon writes and before anything the
            // project runs. A recipient following the button lands on a summary
            // that already shows the new state, and a project handler cannot
            // preempt the notification by throwing.
            //
            // Isolated for the same reason runHandlers() isolates each handler: by
            // this line the transition is a completed fact, so an unreachable mail
            // server has only two possible readings — a logged failure, or a
            // successful click reported as an error. The notifier already contains
            // per-recipient failures; this catches the rest (a broken mail config,
            // an unresolvable dependency).
            //
            // Which mail, if any, is WorkflowNotifier::stepMail()'s answer: the
            // transition's own `mail` action, else the one on the place it lands
            // in (sent exactly as notifyPlaceArrival() would). The assignee picker
            // announces the mail from the same method, so its hint cannot drift
            // from this send.
            //
            // `$notify` is the picker's toggle: an editor may hand over without
            // announcing it. It silences this one send and nothing else.
            $mail = $notify ? WorkflowNotifier::stepMail($raw, $transitionName) : null;

            if ($mail !== null) {
                try {
                    app(WorkflowNotifier::class)->queue(
                        $fresh, $workflow, $raw, $mail['type'], $to,
                        $mail['scope'], $mail['trigger'], $actorId,
                    );
                } catch (\Throwable $e) {
                    Log::error('Workflow step notification failed.', [
                        'workflow' => $workflow,
                        'transition' => $transitionName,
                        'scope' => $mail['scope'],
                        'subject' => $entry->id(),
                        'exception' => $e,
                    ]);
                }
            }

            // Project handlers last, when the transition is a completed fact —
            // state, published flag, assignees, history and revision are all
            // written, so a handler reading the entry sees what everyone else
            // will. First the STEP's own actions, then the ones on the place it
            // arrived in: "this happened" before "you are here".
            //
            // Inside the suppressor, so a handler that saves the entry does not
            // add a `draft_save` next to the transition it belongs to — the same
            // treatment the addon's own writes above get.
            $this->runHandlers(
                CustomActions::SCOPE_TRANSITION,
                TransitionActions::custom($transition),
                $fresh, $workflow, $transitionName, $current, $to, $actorId,
            );

            $this->runHandlers(
                CustomActions::SCOPE_PLACE,
                PlaceActions::custom($raw['places'][$to] ?? []),
                $fresh, $workflow, $to, $current, $to, $actorId,
            );

            return $to;
        });
    }

    /**
     * Run one scope's project handlers, in the order the definition lists them.
     *
     * Every handler is isolated: a throw is logged and the next one still runs.
     * By this point the transition has happened and cannot be taken back, so an
     * exception has only two possible meanings — swallow the rest of the work, or
     * bubble up and make a successful click look like a failure. Both are worse
     * than a log line naming exactly which action broke.
     *
     * An action without a handler (`handler` omitted in the config) is a pure
     * marker and skipped here — that is a legitimate declaration, not an error.
     *
     * @param  array<int, array{type: string, values: array<string, mixed>}>  $actions
     */
    private function runHandlers(
        string $scope,
        array $actions,
        EntryContract $entry,
        string $workflow,
        string $handle,
        string $from,
        string $to,
        ?string $actorId,
    ): void {
        foreach ($actions as $action) {
            try {
                CustomActions::handler($action['type'])?->handle(new ActionContext(
                    entry: $entry,
                    workflow: $workflow,
                    scope: $scope,
                    handle: $handle,
                    from: $from,
                    to: $to,
                    values: $action['values'],
                    actorId: $actorId,
                ));
            } catch (\Throwable $e) {
                Log::error('Workflow action handler failed.', [
                    'workflow' => $workflow,
                    'scope' => $scope,
                    'handle' => $handle,
                    'action' => $action['type'],
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Reaching a publish place means this version is now up to date — so stamp its
     * clock. That single rule serves two purposes at once, which is why there is no
     * SLA special case anywhere near it:
     *
     * - on the POST it resets the ageing deadline, whether the step that got there
     *   was the ordinary `publish` or an author-defined "back to current" out of a
     *   stale place;
     * - on a LOCALIZATION it clears the stale marker that its own approval earns,
     *   and only its own approval — the post being refreshed does not silently
     *   declare a translation nobody touched to be current.
     *
     * `revision_pending` is excluded for the same reason PublishedFlag::sync() excludes
     * it: that place means "published, with a working copy open", not a fresh
     * approval, and stamping there would restart the deadline on every draft.
     */
    private function stampContentTimestamp(EntryContract $entry, array $raw, string $state): void
    {
        if (($raw['places'][$state]['metadata']['revision_pending'] ?? false) === true) {
            return;
        }

        if (! in_array($state, $this->resolver->publishPlaces($raw), true)) {
            return;
        }

        app(ContentTimestamp::class)->touch($entry);
    }


    /**
     * Who an AUTOMATIC `user_assignment` hands the subject to, or null when this
     * transition has nothing to say about assignment.
     *
     * Resolved here rather than only in the controller because a transition also
     * fires with no request behind it — TransitionAgedPostsCommand runs the two
     * SLA steps from the scheduler, and those are automatic by nature (there is
     * no click, so there can be no picker). Leaving the resolution to callers
     * meant the aging steps quietly assigned nobody.
     *
     * A MANUAL action is deliberately not resolved here: its answer is a person's
     * choice, and the caller that collected it passes it in as $explicitAssignees
     * having already checked it against the same candidate set. Resolving it here
     * would mean assigning every eligible user — the opposite of asking.
     *
     * @param  array<string, mixed>  $transition
     * @return array<int, string>|null
     */
    private function automaticAssignees(array $transition, EntryContract $entry): ?array
    {
        $assignment = TransitionActions::userAssignment($transition);

        if ($assignment === null || ($assignment['mode'] ?? null) !== TransitionActions::MODE_AUTOMATIC) {
            return null;
        }

        return $this->candidates->ids($assignment, $entry);
    }

    private function createStatamicRevision(EntryContract $entry, string $message, ?string $actorId): void
    {
        if (! method_exists($entry, 'createRevision')) {
            return;
        }

        try {
            $entry->createRevision([
                'user' => (string) ($actorId ?? ''),
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            // No Pro license / driver failure / disk write error — non-fatal.
        }
    }
}
