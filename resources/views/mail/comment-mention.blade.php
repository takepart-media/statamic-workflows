{{--
    Mention mail (Mail\CommentMentionMail). Same rules as notification.blade.php:
    no __() in here — every sentence arrives translated in `$t`, rendered inside
    the recipient's locale. The excerpt is the comment's plain text, already
    shortened; Blade escapes it, and the Markdown parser runs with
    `html_input => 'escape'`, so a comment cannot inject markup.
--}}
<x-mail::message>
# {{ $t['heading'] }}

{{ $t['intro'] }}

@if ($passageLabel)
- **{{ $t['field_passage'] }}:** {{ $passageLabel }}
@endif
- **{{ $t['field_workflow'] }}:** {{ $workflowLabel }}

<x-mail::panel>
{{ $excerpt }}
</x-mail::panel>

<x-mail::button :url="$url">
{{ $t['button'] }}
</x-mail::button>

{{ $t['outro'] }}
</x-mail::message>
