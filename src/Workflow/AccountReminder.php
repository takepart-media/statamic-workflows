<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Auth\Passwords\PasswordReset;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\User;
use TakepartMedia\Workflows\Jobs\SendAccountReminders;
use TakepartMedia\Workflows\Mail\AccountReminderMail;
use TakepartMedia\Workflows\Support\ActivationBroker;

/**
 * "Your account is still waiting" — runs after every transition
 * (`workflows.account_reminder.enabled`, on by default).
 *
 * Every assignee of the subject whose account was never activated (no password
 * set yet) gets a mail with a fresh activation link — Statamic's own, the one
 * the CP invitation sends. Activated users get nothing.
 *
 * A new token replaces the user's previous one, so a person is reminded again
 * only once their last link has expired (ActivationBroker::linkStillValid()):
 * the link they already have keeps working, however many steps run meanwhile.
 */
final class AccountReminder
{
    /** The one mail-text family this reminder reads (MailTypes::text()). */
    public const MAIL = 'account_reminder';

    /**
     * Send now, after the response or on the queue — the same switch as every
     * other workflow mail (WorkflowNotifier::queue()).
     */
    public static function queue(EntryContract $entry, string $workflow, ?string $actorId): void
    {
        if (! config('workflows.account_reminder.enabled', true)) {
            return;
        }

        $mode = (string) config('workflows.mail_delivery', 'after_response');

        if ($mode === 'sync') {
            self::send($entry, $workflow, $actorId);

            return;
        }

        $job = new SendAccountReminders((string) $entry->id(), $workflow, $actorId);

        $mode === 'queue' ? dispatch($job) : dispatch($job)->afterResponse();
    }

    /**
     * The current assignees who would get the reminder: not activated, with an
     * address, not the person who clicked, and without a link that still works.
     *
     * @return array<int, UserContract>
     */
    public static function recipients(string $subjectId, string $workflow, ?string $actorId): array
    {
        return collect(app(WorkflowAssigneeStore::class)->idsFor($subjectId, $workflow))
            ->map(fn ($id) => User::find($id))
            ->filter(fn ($user) => WorkflowNotifier::reaches($user, $actorId) && self::pending($user)
                && ! ActivationBroker::linkStillValid($user))
            ->values()
            ->all();
    }

    /** Never activated: Statamic sets the password on activation, not before. */
    public static function pending(UserContract $user): bool
    {
        return blank($user->password());
    }

    /** @return int how many mails went out */
    public static function send(EntryContract $entry, string $workflow, ?string $actorId): int
    {
        $subjectId = (string) $entry->id();
        $recipients = self::recipients($subjectId, $workflow, $actorId);

        if ($recipients === []) {
            return 0;
        }

        $raw = app(DefinitionLoader::class)->raw($workflow);
        $label = DefinitionLoader::navLabel($raw['metadata']['nav'] ?? []);
        $actor = $actorId === null ? null : User::find($actorId);
        $actorName = $actor === null ? null : ((string) ($actor->name() ?: $actor->email()) ?: null);
        $title = (string) ($entry->value('title') ?: $entry->slug());
        $hours = ActivationBroker::expireHours();
        $sent = 0;

        foreach ($recipients as $user) {
            try {
                $url = PasswordReset::url($user->generateActivateAccountToken(), PasswordReset::BROKER_ACTIVATIONS);

                Mail::to($user->email(), $user->name())
                    ->locale($user->preferredLocale())
                    ->send(new AccountReminderMail($title, $url, $label !== '' ? $label : $workflow, $hours, $actorName));
                ActivationBroker::markSent($user);
                $sent++;
            } catch (\Throwable $e) {
                // Per recipient, like WorkflowNotifier::notify(): one bad address
                // must not cost the others their reminder.
                Log::error('Workflow account reminder failed.', [
                    'workflow' => $workflow,
                    'subject' => $subjectId,
                    'user' => $user->id(),
                    'exception' => $e,
                ]);
            }
        }

        return $sent;
    }
}
