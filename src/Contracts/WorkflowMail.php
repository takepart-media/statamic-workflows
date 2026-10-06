<?php

namespace TakepartMedia\Workflows\Contracts;

use TakepartMedia\Workflows\Mail\MailContext;

/**
 * What you write to give a mail type its own words, its own data, or its own
 * template.
 *
 * A mail type without one of these is just a block of language keys read into the
 * shared template — which is the right answer for most of them and stays the
 * default. This contract is for the rest: a mail that has to look up something on
 * the entry, list something, or lay itself out differently.
 *
 * The wiring runs over three layers, and the handle ties them together, exactly
 * like WorkflowAction:
 *
 * 1. `MailTypes::BUILT_IN` (in this addon) or `config/workflows.php → mail_types`
 *    (in a project) maps a handle to THIS class.
 * 2. A workflow YAML puts that handle in `mail_type` on a `mail` action — that is
 *    WHERE it is sent, and it is picked in the CP from a dropdown.
 * 3. WorkflowNotification asks it for the mail's parts while rendering.
 *
 * Resolved through the container (`app($class)`), so constructor injection works.
 *
 * WHAT compose() MAY RETURN — keys the addon reads, everything else is passed
 * through to the template as a variable:
 *
 *   'subject' => string   the envelope subject. Omit to use `mail_<handle>_subject`.
 *   'view'    => string   a Blade view name. Omit to use the declaration's `view`,
 *                         or failing that the shared `workflows::mail.notification`.
 *
 * The template is always rendered as a MARKDOWN mailable, so a view of your own
 * gets Laravel's `<x-mail::message>`, `<x-mail::button>`, `<x-mail::panel>` and
 * `<x-mail::subcopy>` — and the same escaping rules: the body is parsed with
 * `html_input => 'escape'`, so raw markup in a value arrives at the reader as
 * visible angle brackets.
 *
 * Your view also receives everything the shared one gets, so it can lean on the
 * pieces it does not want to rebuild: `t` (the six translated sentences),
 * `stateLabel`, `progress`, `title`, `url`, `actorName`, `recipientName`,
 * `workflowLabel`, and `context`. Anything you return under those names wins.
 *
 * ONE RULE, and it is the reason this is a single method rather than three:
 *
 * **compose() runs inside the recipient's locale.** WorkflowNotification calls it
 * from envelope()/content(), which Mailable::send() wraps in
 * `withLocale($recipientLocale)` — so `__()` in here translates into the language
 * of the person receiving the mail, which is what you want. The corollary is that
 * you must do it HERE and not earlier: a string translated in your own code
 * before the mail is handed over comes out in the SENDER's language, and the
 * result is a German sentence in an otherwise English mail. Splitting this into
 * subject()/view()/data() would have made three places to get that wrong.
 *
 * One instance is composed PER RECIPIENT (`$context->recipient`), so per-person
 * data is fine. Keep it cheap for the same reason.
 */
interface WorkflowMail
{
    /**
     * @return array<string, mixed>
     */
    public function compose(MailContext $context): array;
}
