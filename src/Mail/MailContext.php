<?php

namespace TakepartMedia\Workflows\Mail;

use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Everything a mail is told about the moment it is sent.
 *
 * A value object rather than a parameter list, for the same reason
 * ActionContext is one: the list would be a dozen long and most of it is
 * something any given mail does not care about. It also leaves room — a later
 * field is additive here and breaks nobody's compose().
 *
 * Handed out to code outside this addon (see Contracts\WorkflowMail), so
 * everything on it is readonly.
 *
 * Two fields deliberately arrive UNRESOLVED, and it matters:
 *
 * - `$state` is a place HANDLE, not a label. Resolving it means `__()`, and that
 *   may only happen inside the recipient's locale — see WorkflowNotification.
 *   `$stateLabel` in the view is the resolved form.
 * - `$definition` is the raw YAML the caller already parsed. Passed along rather
 *   than re-read so a composer that wants at the workflow's shape (its places,
 *   its metadata) does not pay for a second parse per recipient.
 */
class MailContext
{
    /**
     * @param  EntryContract  $entry  the version the mail is about — already fresh,
     *                                so a composer reading fields sees what the step
     *                                left behind
     * @param  string  $workflow  the workflow's name
     * @param  array<string, mixed>  $definition  the raw workflow definition
     * @param  string  $mailType  which mail this is — a MailTypes handle
     * @param  string  $scope  `transition`, `place` or `reminder`: what triggered
     *                         it. A step that ran, an arrival, or a subject still
     *                         sitting in a place. Worth knowing for a mail type
     *                         used in several.
     * @param  string  $handle  the transition's or the place's handle, per $scope
     * @param  string  $state  the place the subject now sits in, as a HANDLE — see
     *                         the class note
     * @param  UserContract  $recipient  who this copy goes to. One mail per
     *                                   recipient, so this is a single user, and
     *                                   per-person data in compose() is fine.
     * @param  string  $title  the entry's title, or its slug when it has none
     * @param  string  $url  the subject's summary inside this workflow
     * @param  string  $workflowLabel  the workflow's nav label, or its name
     * @param  string|null  $actorName  who triggered the step — null when nobody
     *                                  did. The SLA scheduler drives transitions
     *                                  and the plain-language poller has no actor
     *                                  at all, so a mail that assumes a name
     *                                  breaks exactly in the unattended case,
     *                                  which is the worst place to find out.
     * @param  UserContract|null  $actor  the same person as $actorName, unresolved —
     *                                    null under exactly the same conditions
     *                                    (nobody triggered it, or the account is
     *                                    gone). Here for a composer that needs more
     *                                    of the actor than a name, their groups
     *                                    being the case that asked for it; a mail
     *                                    that only prints who clicked keeps using
     *                                    $actorName and does not care that this
     *                                    exists.
     * @param  int|null  $waitingDays  how many days the subject has been sitting in
     *                                 $state — set only by the `reminder` scope
     *                                 (SendPlaceRemindersCommand), `:days` in the
     *                                 intro; null everywhere else
     */
    public function __construct(
        public readonly EntryContract $entry,
        public readonly string $workflow,
        public readonly array $definition,
        public readonly string $mailType,
        public readonly string $scope,
        public readonly string $handle,
        public readonly string $state,
        public readonly UserContract $recipient,
        public readonly string $title,
        public readonly string $url,
        public readonly string $workflowLabel,
        public readonly ?string $actorName = null,
        public readonly ?UserContract $actor = null,
        public readonly ?int $waitingDays = null,
    ) {}

    /**
     * What to call the recipient: their name, falling back to their address.
     *
     * The shipped copy uses it nowhere — it addresses nobody on purpose, see the
     * rules in lang/{de,en}/messages.php. It exists for a template or composer
     * that does want a name, and the fallback lives here because a Statamic user
     * is not required to have one.
     */
    public function recipientName(): string
    {
        return (string) ($this->recipient->name() ?: $this->recipient->email());
    }

    /**
     * The title as it should appear in the BODY of a mail: bold, so it stands out
     * of the sentence around it. For `:title` in an intro or outro — never in
     * the subject, which is plain text and would show the asterisks.
     *
     * Markdown rather than markup because the body is parsed with
     * `html_input => 'escape'` (see WorkflowNotification::progress()); a `<strong>`
     * would reach the reader as angle brackets. The title's own Markdown
     * punctuation is backslash-escaped first, so "5 * 3" or "snake_case" survive
     * as written instead of opening a second emphasis. `<`, `>` and `&` are left
     * to Blade's `{{ }}`: escaping them here too would turn the entity Blade
     * makes into visible text.
     */
    public function boldTitle(): string
    {
        $escaped = preg_replace('/([\\\\*_`\[\]~])/u', '\\\\$1', $this->title);

        return '**'.$escaped.'**';
    }

    /**
     * One line of this mail type's copy, with the inheritance from the fallback
     * type applied — `MailTypes::text()` bound to this context's type.
     *
     * Here so a composer can reach the type's own language block without
     * repeating the handle, which is the most common thing it will want: build one
     * value, then wrap it in the type's own sentence.
     *
     * @param  array<string, mixed>  $replace
     */
    public function text(string $key, array $replace = []): string
    {
        return MailTypes::text($this->mailType, $key, $replace);
    }
}
