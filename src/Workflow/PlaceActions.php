<?php

namespace TakepartMedia\Workflows\Workflow;

use TakepartMedia\Workflows\Mail\MailTypes;

/**
 * The actions a PLACE carries while a subject sits in it — the place-side
 * counterpart to TransitionActions. Deliberately its own, smaller type set:
 * a place has no click moment, so there is no dialog concept and no
 * `DIALOG_TYPES` limit here.
 *
 * Two kinds of statement live here, and the difference is worth naming.
 * `pending_rewrite_spinner` and
 * `refresh_interval` say "WHILE a subject sits here"; `mail` says "WHEN one
 * arrives". The second kind is not a click moment — that is exactly
 * the point of it. A transition-side notification only fires when somebody
 * clicks, and the moment this addon most needs to announce (a plain-language
 * rewrite landing) has no click behind it at all: PollPlainLanguageCommand sets
 * the state itself. A place is the only thing both paths pass through.
 *
 * `mail` is also the one type this class SHARES with TransitionActions — one
 * action an author may hang on either, see MailAction.
 *
 * Every type is an opt-in, workflow-author choice, same as every
 * TransitionActions type: absent by default, and `WorkflowDefinitionWriter` is
 * the only place that decides whether a given type means anything in a given
 * workflow.
 */
class PlaceActions
{
    /**
     * Opts the plain-language manager's instance page into a loading state:
     * while a subject sits in THIS place AND has an OPEN rewrite job
     * (PlainLanguageJobStore::hasOpen()), its content panel shows a spinner
     * instead of the blocks — which are either the just-copied source (about to
     * be overwritten) or a previous, superseded rewrite. Showing them as if
     * they were current would be showing stale data as if it were the answer.
     *
     * Nothing to configure — its presence is the whole statement. The writer
     * only checks that it sits in a workflow with `plain_language_site` set —
     * a job store entry cannot exist without one.
     */
    public const PENDING_REWRITE_SPINNER = 'pending_rewrite_spinner';

    /**
     * A client-side reminder: while a subject sits in this place, wait `minutes`
     * then ask the server whether anything changed (current state, or the
     * entry's own last-modified timestamp) and only THEN offer a reload — never
     * silently, and never when nothing actually changed (the timer just runs
     * again). General-purpose, unlike the spinner above: nothing ties it to the
     * plain-language service, so it works as a plain "this has been sitting
     * here a while" nudge in any workflow.
     */
    public const REFRESH_INTERVAL = 'refresh_interval';

    /**
     * Mails every assignee of the subject when it ARRIVES in this place.
     *
     * The SAME action as TransitionActions::MAIL — same handle, same key, same
     * mail types, and MailAction owns both so they cannot drift apart. What the
     * scope changes is only the trigger: on a transition it means "after this step
     * ran", here it means "however a subject got here".
     *
     * Why it exists on a place at all: a transition-side mail needs a transition,
     * and the one arrival this addon most wants to announce has none.
     * PollPlainLanguageCommand moves a finished rewrite into
     * `metadata.plain_language_completed_place` with a direct
     * WorkflowStateStore::set() — deliberately, since the scheduler runs
     * unattended and there is no click to gate or to log. Hanging the mail on the
     * PLACE means it fires for both ways in, which is what "when this subject gets
     * here, tell the people responsible" should mean.
     *
     * Configured exactly like the transition-side one: a `mail_type` naming a
     * MailTypes handle, omitted meaning `assigned`. At most one per place — the
     * writer enforces it, same reasoning as on a transition.
     *
     * Two things it deliberately does NOT do. It does not fire when an admin sets
     * the state by hand (WorkflowController::setState()): that is an intervention,
     * not a step, and a mail would be a surprise. And when the transition that
     * brought the subject here ALREADY sent a mail, this one is skipped — see
     * WorkflowTransitioner::apply(), the only place both can meet.
     */
    public const MAIL = MailAction::TYPE;

    /**
     * Mails every assignee again and again while the subject SITS in this place:
     * first `every_days` after it arrived, then every `every_days` after the
     * previous reminder, for as long as it stays. No upper limit — the reminder
     * stops when the subject moves on, which is the thing it is asking for.
     *
     * Not a click moment and not an arrival: SendPlaceRemindersCommand runs once
     * a day from the scheduler and counts from WorkflowStateStore's `entered_at`
     * and the newest `reminder_sent` event. `mail_type` works as on `mail`, but
     * omitted means `reminder`, not `assigned` — "still waiting" is a different
     * sentence from "this is yours now".
     */
    public const REMINDER = 'reminder';

    /**
     * Runs one of the place's own outgoing transitions by itself once a subject
     * has sat here for `after_days` — "approved automatically unless somebody
     * objects". `transition` names it; it must leave THIS place and must not be a
     * system transition (the writer enforces both).
     *
     * The deadline is a DATE, not a timestamp: arrival day + `after_days`, acted
     * on by the first daily run on or after that day (AutoTransitionCommand). The
     * mails print that same date, so what they announce is what happens.
     * Applied through WorkflowTransitioner like a click, without an actor — so
     * the transition's own assignment and mail run as usual.
     */
    public const AUTO_TRANSITION = 'auto_transition';

    /** The mail a `reminder` sends when it names none. */
    public const REMINDER_MAIL_TYPE = MailTypes::REMINDER;

    /** Every type that may be stored in a place's `metadata.actions`. */
    public const TYPES = [self::PENDING_REWRITE_SPINNER, self::REFRESH_INTERVAL, self::MAIL, self::REMINDER, self::AUTO_TRANSITION];

    /**
     * A place's actions, normalised: every entry has a `type`, a `minutes`, a
     * `mail_type` (both null for types that do not use one) and a `values` bag for
     * project-declared types — see TransitionActions::normalise() for why those
     * are kept apart from the built-in keys rather than merged flat.
     *
     * @param  array<string, mixed>  $place
     * @return array<int, array{type: string, minutes: int|null, every_days: int|null, after_days: int|null, transition: string|null, mail_type: string|null, values: array<string, mixed>}>
     */
    public static function for(array $place): array
    {
        $metadata = $place['metadata'] ?? [];

        if (isset($metadata['actions'])) {
            return collect((array) $metadata['actions'])
                ->filter(fn ($action) => is_array($action) && is_string($action['type'] ?? null))
                ->map(fn (array $action) => [
                    // `notify_assignees` folded to `mail`, and the older `mail`
                    // key read as `mail_type` — see MailAction. The values bag is
                    // looked up under the ORIGINAL handle, because a project type
                    // is never one of the two being folded.
                    'type' => MailAction::handle($action['type']),
                    'minutes' => is_numeric($action['minutes'] ?? null) ? (int) $action['minutes'] : null,
                    'every_days' => is_numeric($action['every_days'] ?? null) ? (int) $action['every_days'] : null,
                    'after_days' => is_numeric($action['after_days'] ?? null) ? (int) $action['after_days'] : null,
                    'transition' => is_string($action['transition'] ?? null) && $action['transition'] !== '' ? $action['transition'] : null,
                    MailAction::KEY => MailAction::storedType($action),
                    'values' => CustomActions::values($action['type'], $action['values'] ?? null),
                ])
                ->values()
                ->all();
        }

        return self::fromLegacyKeys($metadata);
    }

    /**
     * The place's PROJECT-declared actions, in YAML order — the place-side twin of
     * TransitionActions::custom(), and the way project code reads them back.
     *
     * @param  array<string, mixed>  $place
     * @return array<int, array{type: string, values: array<string, mixed>}>
     */
    public static function custom(array $place): array
    {
        return collect(self::for($place))
            ->filter(fn (array $action) => CustomActions::find($action['type']) !== null)
            ->map(fn (array $action) => ['type' => $action['type'], 'values' => $action['values']])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $place
     */
    public static function showsPendingRewriteSpinner(array $place): bool
    {
        foreach (self::for($place) as $action) {
            if ($action['type'] === self::PENDING_REWRITE_SPINNER) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured `refresh_interval` in minutes, or null when the place
     * carries none.
     *
     * @param  array<string, mixed>  $place
     */
    public static function refreshIntervalMinutes(array $place): ?int
    {
        foreach (self::for($place) as $action) {
            if ($action['type'] === self::REFRESH_INTERVAL) {
                return $action['minutes'];
            }
        }

        return null;
    }

    /**
     * WHICH mail this place sends on arrival, or null when it sends none. The same
     * question TransitionActions::notificationMail() asks, answered by the same
     * code — MailAction — because it is the same action.
     *
     * @param  array<string, mixed>  $place
     */
    public static function notificationMail(array $place): ?string
    {
        return MailAction::typeIn(self::for($place));
    }

    /**
     * The place's `reminder`, or null when it carries none: the interval in days
     * and the resolved mail type. An interval below one day is treated as absent
     * — the writer rejects it, so only a hand-edited YAML can carry one, and
     * mailing every scheduler run is not what anybody meant.
     *
     * @param  array<string, mixed>  $place
     * @return array{every_days: int, mail_type: string}|null
     */
    public static function reminder(array $place): ?array
    {
        foreach (self::for($place) as $action) {
            if ($action['type'] !== self::REMINDER) {
                continue;
            }

            if (($action['every_days'] ?? 0) < 1) {
                return null;
            }

            return [
                'every_days' => $action['every_days'],
                'mail_type' => MailTypes::resolve($action[MailAction::KEY] ?? self::REMINDER_MAIL_TYPE),
            ];
        }

        return null;
    }

    /**
     * The place's `auto_transition`, or null when it carries none or an unusable
     * one (under a day, no transition named) — the writer rejects both, so only a
     * hand-edited YAML gets here.
     *
     * @param  array<string, mixed>  $place
     * @return array{after_days: int, transition: string}|null
     */
    public static function autoTransition(array $place): ?array
    {
        foreach (self::for($place) as $action) {
            if ($action['type'] !== self::AUTO_TRANSITION) {
                continue;
            }

            if (($action['after_days'] ?? 0) < 1 || $action['transition'] === null) {
                return null;
            }

            return ['after_days' => $action['after_days'], 'transition' => $action['transition']];
        }

        return null;
    }

    /**
     * The day an `auto_transition` fires for a subject that arrived at
     * `$enteredAt`: arrival day + `after_days`, at the start of that day.
     */
    public static function autoTransitionDue(\DateTimeInterface $enteredAt, int $afterDays): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::instance($enteredAt)->startOfDay()->addDays($afterDays);
    }

    /**
     * The pre-`actions` metadata key, read as an action — the same
     * two-spellings tolerance TransitionActions gives `assign_from_group`.
     * `pending_rewrite_spinner` briefly existed as a solo boolean on a place
     * (before this class did); reading it here means the definitions that
     * already carry it keep working without a forced rewrite. The settings
     * editor writes only the list form from here on.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<int, array{type: string, minutes: int|null, every_days: int|null, after_days: int|null, transition: string|null, mail_type: string|null, values: array<string, mixed>}>
     */
    private static function fromLegacyKeys(array $metadata): array
    {
        if (($metadata['pending_rewrite_spinner'] ?? false) === true) {
            return [['type' => self::PENDING_REWRITE_SPINNER, 'minutes' => null, 'every_days' => null, 'after_days' => null, 'transition' => null, MailAction::KEY => null, 'values' => []]];
        }

        return [];
    }
}
