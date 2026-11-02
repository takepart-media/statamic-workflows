<?php

namespace TakepartMedia\Workflows\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use TakepartMedia\Workflows\Workflow\AccountReminder;

/**
 * "A post is waiting for you — activate your account first." One instance per
 * recipient, sent by Workflow\AccountReminder.
 *
 * Facts only until envelope()/content(), which run inside the recipient's
 * locale — same rule as WorkflowNotification.
 */
class AccountReminderMail extends Mailable
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $workflowLabel,
        public readonly ?string $actorName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: MailTypes::text(AccountReminder::MAIL, 'subject', ['title' => $this->title]),
        );
    }

    public function content(): Content
    {
        $type = AccountReminder::MAIL;
        $boldTitle = '**'.preg_replace('/([\\\\*_`\[\]~])/u', '\\\\$1', $this->title).'**';

        return new Content(
            markdown: 'workflows::mail.account-reminder',
            with: [
                'url' => $this->url,
                'workflowLabel' => $this->workflowLabel,
                'actorName' => $this->actorName,
                't' => [
                    'heading' => MailTypes::text($type, 'heading'),
                    'intro' => MailTypes::text($type, 'intro', ['title' => $boldTitle, 'workflow' => $this->workflowLabel]),
                    'field_workflow' => MailTypes::text($type, 'field_workflow'),
                    'field_actor' => MailTypes::text($type, 'field_actor'),
                    'button' => MailTypes::text($type, 'button'),
                    'outro' => MailTypes::text($type, 'outro'),
                ],
            ],
        );
    }
}
