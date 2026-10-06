<?php

namespace TakepartMedia\Workflows\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\PlaceActions;
use TakepartMedia\Workflows\Workflow\ProgressSteps;

/**
 * The mail a `mail` action sends to every assignee of a subject — one class for
 * every mail type, because what differs between two types is the WORDS, the DATA
 * and at most the TEMPLATE, none of which needs its own Mailable.
 *
 * It carries one thing, a MailContext, and everything it says is derived from it
 * HERE rather than handed over ready-made. That is the point of the class, and
 * the reason is the recipient's language.
 *
 * `Mail::to(...)->locale($locale)` reaches this object as `$this->locale`, and
 * Mailable::send() wraps the whole render — envelope(), content(), the view — in
 * withLocale(). Anything translated OUTSIDE that scope is translated in the
 * SENDER's language instead: a German state label in an otherwise English mail,
 * which is exactly what happened when the notifier resolved
 * DefinitionLoader::stateLabel() before handing the label over.
 *
 * So the rule, for this class and for every composer it calls: a value that goes
 * through __() is computed in content() or envelope(), never before. The context
 * obeys it too — it carries the state as a HANDLE, not as a label.
 *
 * The three layers of "what does this mail say", innermost last:
 *
 * 1. the defaults below, which the shared template needs and every mail gets;
 * 2. the type's language block, `mail_<type>_*`, inheriting from `assigned`
 *    (MailTypes::text());
 * 3. its composer, if it declares one — Contracts\WorkflowMail::compose(), whose
 *    return value wins over everything above it.
 */
class WorkflowNotification extends Mailable
{
    /**
     * compose() is asked once and remembered, because envelope() and content()
     * both need the answer and a composer is allowed to be expensive (it may read
     * the entry, count things, hit a store). Memoised on the instance, which is
     * safe because there is one instance per recipient — see MailContext.
     *
     * The memo is filled by whichever of the two runs first, and that is worth
     * knowing exactly once: under send() and render() BOTH run inside the same
     * withLocale() scope, so the memo can never straddle two languages. Calling
     * envelope() by hand outside a render fills it in the SENDER's language and
     * the render then reuses that — a mistake only a test can make, and one that
     * looks like a bug in the composer rather than in the caller.
     *
     * @var array<string, mixed>|null
     */
    private ?array $composed = null;

    private ?\Illuminate\Support\Carbon $arrival = null;

    public function __construct(public readonly MailContext $context) {}

    public function envelope(): Envelope
    {
        $composed = $this->composed();

        return new Envelope(
            // Already inside withLocale() — Mailable::send() opens that scope
            // before it asks for the envelope.
            subject: is_string($composed['subject'] ?? null) && $composed['subject'] !== ''
                ? $composed['subject']
                : $this->context->text('subject', ['title' => $this->context->title]),
        );
    }

    public function content(): Content
    {
        $composed = $this->composed();

        return new Content(
            // Always a markdown mailable, including for a type that brings its own
            // template: that is what gives a project view the `x-mail::`
            // components and the responsive shell for free, instead of asking it
            // to rebuild a mail layout by hand. See Contracts\WorkflowMail.
            markdown: $this->template($composed),
            // Defaults first, composer over the top. The defaults stay COMPLETE
            // even for a type with its own view, so that view can lean on the
            // pieces it does not want to rebuild — and so the shared view keeps
            // working unchanged for every type that has no composer at all.
            with: array_merge($this->defaults(), Arr::except($composed, ['subject', 'view'])),
        );
    }

    /**
     * Which Blade renders this: the composer's choice, else the type's
     * declaration, else the shared one. Three levels because they answer
     * progressively narrower questions — "this mail", "this type", "all mail".
     *
     * @param  array<string, mixed>  $composed
     */
    private function template(array $composed): string
    {
        foreach ([$composed['view'] ?? null, MailTypes::view($this->context->mailType)] as $view) {
            if (is_string($view) && $view !== '') {
                return $view;
            }
        }

        return 'workflows::mail.notification';
    }

    /**
     * @return array<string, mixed>
     */
    private function composed(): array
    {
        return $this->composed ??= MailTypes::composer($this->context->mailType)
            ?->compose($this->context) ?? [];
    }

    /**
     * What every mail gets, whatever its type — the shared template's whole
     * vocabulary, and the floor a composer builds on.
     *
     * `t` is the six sentences as finished strings rather than keys, so the
     * template never assembles `mail_{$type}_heading` itself: the key-building
     * lives here, next to the locale rule it has to obey.
     *
     * `recipientName` is passed even though the shared template greets nobody —
     * the copy addresses no one by design (see lang/{de,en}/messages.php) — because
     * a project template may want it, and it is the only place that name is
     * available inside the render.
     *
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        $context = $this->context;

        // The content's last update, for `:updated` in an intro — the SLA mail
        // says "not updated since <date>", and this is the very clock
        // TransitionAgedPostsCommand aged the post by (ContentTimestamp). A type
        // that wants to say it without a date defines `intro_undated`; it is
        // used when there is none. Resolved inside the recipient's locale, like
        // every other date in here.
        $updated = app(ContentTimestamp::class)->for($context->entry)
            ?->locale(app()->getLocale())->isoFormat('L');
        $introKey = $updated === null && Lang::has('workflows::messages.mail_'.$context->mailType.'_intro_undated')
            ? 'intro_undated'
            : 'intro';

        return [
            'context' => $context,
            'title' => $context->title,
            'url' => $context->url,
            'workflowLabel' => $context->workflowLabel,
            'recipientName' => $context->recipientName(),
            'actorName' => $context->actorName,
            // One or the other: a deadline says more than a reminder interval,
            // and both in one mail would read like two competing promises.
            'autoTransition' => $auto = $this->autoTransition(),
            'reminder' => $auto === null ? $this->reminder() : null,
            'enteredAt' => $this->enteredAt(),
            // Resolved here, not in the notifier — see the class note.
            'stateLabel' => app(DefinitionLoader::class)->stateLabel($context->state),
            'progress' => $this->progress(),
            't' => [
                'heading' => $context->text('heading'),
                // Bold in the body, plain in the subject — see MailContext::boldTitle().
                'intro' => $context->text($introKey, [
                    'title' => $context->boldTitle(),
                    'workflow' => $context->workflowLabel,
                    'days' => $context->waitingDays,
                    'updated' => $updated,
                ]),
                'field_state' => $context->text('field_state'),
                'field_actor' => $context->text('field_actor'),
                'field_reminder' => $context->text('field_reminder'),
                'field_entered' => $context->text('field_entered'),
                'button' => $context->text('button'),
                'outro' => $context->text('outro'),
            ],
        ];
    }

    /**
     * The day the subject arrived in its current place, as a short date in the
     * recipient's locale — null when the arrival is unknown (a row from before
     * `entered_at` existed that the backfill could not date).
     */
    private function enteredAt(): ?string
    {
        $at = $this->arrival();

        return $at?->locale(app()->getLocale())->isoFormat('L');
    }

    /**
     * The deadline panel for a place with an `auto_transition`: heading and body
     * as finished sentences, the date the same one AutoTransitionCommand acts on
     * (PlaceActions::autoTransitionDue()). Null when the place has none, the
     * transition is gone, or the arrival is unknown — no date, no promise.
     *
     * @return array{heading: string, body: string, date: string}|null
     */
    private function autoTransition(): ?array
    {
        $definition = $this->context->definition;
        $place = $definition['places'][$this->context->state] ?? null;
        $auto = is_array($place) ? PlaceActions::autoTransition($place) : null;
        $arrival = $this->arrival();

        if ($auto === null || $arrival === null || ! isset($definition['transitions'][$auto['transition']])) {
            return null;
        }

        $label = fn (string $name, array $transition) => DefinitionLoader::transitionLabel(
            $name, $transition['metadata']['label'] ?? null,
        );

        $quote = fn (string $text) => str_starts_with(app()->getLocale(), 'de') ? '„'.$text.'“' : '"'.$text.'"';

        $options = collect($definition['transitions'] ?? [])
            ->filter(fn ($transition) => is_array($transition)
                && ($transition['metadata']['system'] ?? false) !== true
                && in_array($this->context->state, (array) ($transition['from'] ?? []), true))
            ->map(fn (array $transition, string $name) => $quote($label($name, $transition)))
            ->values();

        $date = PlaceActions::autoTransitionDue($arrival, $auto['after_days'])
            ->locale(app()->getLocale())->isoFormat('L');
        $replace = [
            'transition' => $quote($label($auto['transition'], $definition['transitions'][$auto['transition']])),
            'date' => $date,
            'options' => $options->count() > 1
                ? $options->slice(0, -1)->implode(', ').' '.__('workflows::messages.mail_auto_transition_or').' '.$options->last()
                : (string) $options->first(),
        ];

        return [
            'heading' => __('workflows::messages.mail_auto_transition_heading', $replace),
            // With the automatic step as the ONLY way out, "until then X is
            // possible — otherwise X runs" says the same thing twice.
            'body' => __($options->count() > 1
                ? 'workflows::messages.mail_auto_transition_body'
                : 'workflows::messages.mail_auto_transition_body_single', $replace),
            'date' => $date,
        ];
    }

    /** Memoised: both the date line and the deadline panel read it. */
    private function arrival(): ?\Illuminate\Support\Carbon
    {
        return $this->arrival ??= app(\TakepartMedia\Workflows\Workflow\WorkflowStateStore::class)
            ->enteredAt((string) $this->context->entry->id(), $this->context->workflow);
    }

    /**
     * "Reminded every N days while it sits here" — when the place the subject now
     * sits in carries a `reminder` action (PlaceActions::REMINDER), so the
     * recipient knows the mail will come back if nothing moves. Null for every
     * other place; the templates drop the line then.
     *
     * Read from the definition the context already carries, and translated here
     * because this runs inside the recipient's locale — see the class note.
     */
    private function reminder(): ?string
    {
        $place = $this->context->definition['places'][$this->context->state] ?? null;
        $reminder = is_array($place) ? PlaceActions::reminder($place) : null;

        if ($reminder === null) {
            return null;
        }

        return trans_choice('workflows::messages.mail_reminder_notice', $reminder['every_days'], [
            'days' => $reminder['every_days'],
        ]);
    }

    /**
     * The workflow's steps as one quiet line of Markdown for the FOOT of the mail —
     * the shared template puts it in an `x-mail::subcopy` below the outro, because
     * the mail should open with what it wants and not with a map: "how far along is
     * this" is a question asked after the message, not before it.
     *
     * Markdown and not markup, deliberately: Laravel parses the mail body with
     * `html_input => 'escape'`, so a `<span style>` would arrive at the reader as
     * visible angle brackets. What is left is a tick, a separator and bold — which is
     * all this needs, and it survives every mail client instead of depending on one
     * that honours inline CSS.
     *
     * No colours either. The CP bar paints the current step in its place colour, but
     * that needs the theme's variables and a 20-entry class map; in a mail it would
     * be a second, diverging notion of the same picture for no gain.
     *
     * Null where there is nothing to draw — an unknown workflow, or one whose steps
     * are all branches. The mail is still worth sending without it, so this never
     * throws.
     *
     * @return array{caption: string, line: string, branch: string|null}|null
     */
    private function progress(): ?array
    {
        $steps = ProgressSteps::for($this->context->definition, $this->context->state);

        if ($steps['main'] === []) {
            return null;
        }

        $reached = ProgressSteps::reachedIndex($steps);
        // Both rules come from ProgressSteps so the mail and the CP bar cannot
        // disagree about what counts as finished — see doneUntilIndex().
        $done = ProgressSteps::doneUntilIndex($steps);
        $parts = [];

        foreach ($steps['main'] as $i => $step) {
            $label = $step['label'];

            if ($done !== null && $i <= $done) {
                // Finished. The tick is the mail's stand-in for the CP's icon.
                $parts[] = '✓ '.$label;
            } elseif ($step['handle'] === $steps['current']) {
                $parts[] = '**'.$label.'**';
            } else {
                $parts[] = $label;
            }
        }

        return [
            'caption' => $this->context->text('progress', [
                'position' => $reached === null ? 1 : $reached + 1,
                'total' => count($steps['main']),
            ]),
            'line' => implode(' · ', $parts),
            // Its own line rather than appended to the one above: a trailing
            // "↳ Fehlgeschlagen" after the last step read as if it belonged to that
            // step. Markdown collapses a single newline into a space, so the break
            // has to be a separate paragraph in the view.
            'branch' => $steps['branch'] === null
                ? null
                : '↳ **'.$steps['branch']['label'].'**',
        ];
    }
}
