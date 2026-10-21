<?php

namespace TakepartMedia\Workflows\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "You were mentioned in a comment." One instance per recipient, sent by
 * Workflow\CommentMentionNotifier.
 *
 * Carries FACTS only (names, title, excerpt, url) and translates nothing until
 * envelope()/content() — which Mailable::send() runs inside
 * withLocale($recipientLocale). Same rule as WorkflowNotification, same reason:
 * a string resolved before that scope comes out in the sender's language.
 */
class CommentMentionMail extends Mailable
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $actorName,
        public readonly ?string $passageLabel,
        public readonly string $excerpt,
        public readonly string $workflowLabel,
        public readonly bool $isReply = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: MailTypes::text(MailTypes::COMMENT_MENTION, 'subject', [
                'title' => $this->title,
                'actor' => $this->actorName,
            ]),
        );
    }

    public function content(): Content
    {
        $type = MailTypes::COMMENT_MENTION;
        $boldTitle = '**'.preg_replace('/([\\\\*_`\[\]~])/u', '\\\\$1', $this->title).'**';

        return new Content(
            markdown: 'workflows::mail.comment-mention',
            with: [
                'title' => $this->title,
                'url' => $this->url,
                'actorName' => $this->actorName,
                'passageLabel' => $this->passageLabel,
                'excerpt' => $this->excerpt,
                'workflowLabel' => $this->workflowLabel,
                't' => [
                    'heading' => MailTypes::text($type, 'heading'),
                    'intro' => MailTypes::text($type, $this->isReply ? 'intro_reply' : 'intro', [
                        'actor' => $this->actorName,
                        'title' => $boldTitle,
                        'workflow' => $this->workflowLabel,
                    ]),
                    'field_passage' => MailTypes::text($type, 'field_passage'),
                    'field_workflow' => MailTypes::text($type, 'field_workflow'),
                    'button' => MailTypes::text($type, 'button'),
                    'outro' => MailTypes::text($type, 'outro'),
                ],
            ],
        );
    }
}
