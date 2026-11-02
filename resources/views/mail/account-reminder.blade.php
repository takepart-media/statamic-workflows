{{--
    Account reminder (Mail\AccountReminderMail). Same rules as notification.blade.php:
    no __() in here — every sentence arrives translated in `$t`, rendered inside
    the recipient's locale.
--}}
<x-mail::message>
# {{ $t['heading'] }}

{{ $t['intro'] }}

- **{{ $t['field_workflow'] }}:** {{ $workflowLabel }}
@if ($actorName)
- **{{ $t['field_actor'] }}:** {{ $actorName }}
@endif

<x-mail::button :url="$url">
{{ $t['button'] }}
</x-mail::button>

{{ $t['outro'] }}
</x-mail::message>
