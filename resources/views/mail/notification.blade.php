{{--
    The DEFAULT template of the `mail` action — the one every mail type gets unless
    it declares a `view` of its own. A markdown mailable on purpose: Laravel's own
    `mail::` components bring a working button and a responsive shell, so this file
    holds the STRUCTURE and nothing else. Header and footer (the app name as the
    logo line, the copyright line) come from Laravel's default theme — a project
    that wants its own runs `php artisan vendor:publish --tag=laravel-mail`, which
    is the project's call and not this addon's.

    No __() and no language keys here. Every sentence arrives in `$t`, already
    translated, because WHICH keys to read depends on the mail type and the whole
    render has to happen inside withLocale($recipientLocale) — both of which are
    WorkflowNotification's job. That is what makes the cheap kind of mail type two
    blocks in the language files and nothing else: this template never learns it
    exists.

    NO SALUTATION, and no name — see the copy rules in lang/{de,en}/messages.php.
    A mail here goes to whoever happens to be assigned, so it addresses nobody and
    stays in passive or noun forms. `$recipientName` is still handed in for a
    template that wants to greet, but this one deliberately does not.

    A type that needs more than different words declares a composer instead
    (Contracts\WorkflowMail) and may bring its own template. Such a template still
    receives everything below — `$t`, `$stateLabel`, `$progress`, `$url`,
    `$actorName`, plus `$context` — so it can keep the parts it likes and replace
    only what it came for. See WorkflowNotification::defaults().
--}}
<x-mail::message>
# {{ $t['heading'] }}

{{ $t['intro'] }}

- **{{ $t['field_state'] }}:** {{ $stateLabel }}
@if ($enteredAt)
- **{{ $t['field_entered'] }}:** {{ $enteredAt }}
@endif
{{-- Left out rather than filled with a placeholder when nobody triggered the step:
     the SLA scheduler and the plain-language poller both run without an actor, so
     there is nothing to name. --}}
@if ($actorName)
- **{{ $t['field_actor'] }}:** {{ $actorName }}
@endif
{{-- Only when the current place carries a `reminder` action — see
     WorkflowNotification::reminder(). --}}
@if ($reminder)
- **{{ $t['field_reminder'] }}:** {{ $reminder }}
@endif

{{-- Deadline of an `auto_transition` on the current place — see
     WorkflowNotification::autoTransition(). Replaces the reminder line. --}}
@if ($autoTransition)
<x-mail::panel>
**{{ $autoTransition['heading'] }}**

{{ $autoTransition['body'] }}
</x-mail::panel>

@endif
<x-mail::button :url="$url">
{{ $t['button'] }}
</x-mail::button>

{{ $t['outro'] }}

{{-- Wo der Beitrag im Workflow steht — als FUSSZEILE, unter allem anderen. Die
     Entscheidung des Hauses: die Mail soll mit ihrer Bitte anfangen, nicht mit
     einer Landkarte. Der Schrittstand beantwortet „wie weit ist das hier", und
     das ist eine Frage, die man NACH der Nachricht stellt — wer sie nicht hat,
     überliest den Block, statt an ihm vorbeilesen zu müssen.

     `subcopy` statt `panel`: subcopy IST der Fußbereich (Haarlinie darüber,
     kleinere Schrift) und sagt damit von selbst, dass hier die Nachricht zu Ende
     ist und eine Randnotiz folgt. Beide sind tabellenbasiert mit inline
     gesetzten Stilen, also in jedem Mail-Client stabil, und beide parsen ihren
     Slot durch Markdown (Illuminate\Mail\Markdown::parse im Component) —
     Häkchen und Fettung überleben den Weg also auch hier. Nachgeprüft, nicht
     angenommen.

     Markdown und nicht Markup: Laravel parst den Body mit `html_input =>
     'escape'`, ein `<span style>` käme als sichtbare spitze Klammern beim Leser
     an. Häkchen, Trenner und Fettung reichen — siehe
     WorkflowNotification::progress().

     Derselbe Block steht wortgleich in jedem eigenen Template eines Projekts
     (`$progress` liegt in den Defaults jeder View) — wer ihn hier ändert, ändert
     ihn dort mit. --}}
@if ($progress)
<x-mail::subcopy>
{{ $progress['caption'] }}

{{ $progress['line'] }}
@if ($progress['branch'])

{{ $progress['branch'] }}
@endif
</x-mail::subcopy>
@endif
</x-mail::message>
