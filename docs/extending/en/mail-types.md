# Custom mail types

🇩🇪 [Deutsch](../de/mail-types.md)

Send your own mails from your workflows, with your own copy, your own data and, if needed, your own template.

> **At a glance**
> - Config key: `mail_types`, plus `mail_delivery`
> - Default: `[]` (= built-in types only)
> - Own code: optional `Contracts\WorkflowMail`

## Overview

The `mail` action on a transition or a place sends exactly one mail type. Which one is set by `mail_type` in the workflow YAML. The same applies to the `reminder` place action.

You can add your own types in three levels:

- **Copy only:** The type uses the shared template and reads its sentences from your language files. No PHP needed.
- **Own data:** A PHP class provides the subject and extra template variables, such as fields from the entry.
- **Own template:** A Blade template defines the mail's layout.

You can combine data and template freely.

## Quick start

This quick start adds a type that only has its own copy.

1. Publish the config if you haven't already:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Declare the handle in `config/workflows.php`:

   ```php
   'mail_types' => [
       'nudge',
   ],
   ```

3. Add the copy to `lang/vendor/workflows/en/messages.php`:

   ```php
   <?php

   return [
       'mail_type_nudge' => 'Nudge',
       'mail_nudge_subject' => 'Still open: :title',
       'mail_nudge_intro' => ':title is still waiting in ":workflow".',
   ];
   ```

   The type takes all other copy, such as heading and button, from the built-in `assigned` type.

4. Open the workflow in the CP, add the "Mail" action to a transition and select the "Nudge" type.

## Configuration

### Built-in types

| Handle | Recipients | Description |
| --- | --- | --- |
| `assigned` | assignees | Default when `mail_type` is missing, and fallback for missing copy of other types. |
| `plain_language_ready` | assignees | The plain-language version is ready. |
| `sla` | assignees | An entry has gone stale. |
| `reminder` | assignees | Default of the `reminder` place action. |
| `published` | watchers | An entry has been published. |

### Declaration (`mail_types`)

An entry takes one of two forms:

- **A bare string** such as `'nudge'`: The type uses language keys and the shared template only.
- **Handle as key, array as value:** The array may contain the following keys.

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `label` | `string` | no | see description | Label in the editor, plain text or translation key. Without `label`, `workflows::messages.mail_type_<handle>` applies, then the bare handle. |
| `mail` | `class-string` | no | – | A class implementing `Contracts\WorkflowMail`. |
| `view` | `string` | no | `workflows::mail.notification` | Name of a Blade template. |
| `recipients` | `string` | no | `assignees` | `assignees` or `watchers`. Any other value counts as `assignees`. |

### Delivery (`mail_delivery`)

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `mail_delivery` | `string` | no | `after_response` | How all workflow mails are sent. Set via `WORKFLOWS_MAIL_DELIVERY`. |

| Value | Behavior |
| --- | --- |
| `after_response` | Sent in the same process after the response has gone to the browser. No worker needed. |
| `queue` | Sent via `QUEUE_CONNECTION`, with retries. Needs a running `php artisan queue:work`. |
| `sync` | Sent inline in the request. |

```env
WORKFLOWS_MAIL_DELIVERY=queue
```

Reminders from `workflows:send-reminders` are always sent inline, regardless of this setting.

## Language keys

All copy lives in the `workflows::messages` namespace. In your project, you override it in `lang/vendor/workflows/<locale>/messages.php`. Run `php artisan vendor:publish --tag=workflows-translations` to get the complete files as a starting point.

The pattern is `mail_<handle>_<key>`:

| Key | Used for |
| --- | --- |
| `subject` | Subject. Placeholder `:title`, unformatted. |
| `heading` | Heading. |
| `intro` | Intro. Placeholders `:title` (bold), `:workflow`, `:days` (reminders only) and `:updated`. |
| `intro_undated` | Optional. Replaces `intro` when there is no update date. |
| `field_state`, `field_actor`, `field_reminder`, `field_entered` | Labels of the info lines. |
| `button` | Button text. |
| `outro` | Closing line. |
| `progress` | Footer line. Placeholders `:position` and `:total`. |

Add `mail_type_<handle>` as the editor label when the declaration sets no `label`.

If a key is missing, the addon uses the same key of `assigned`. This fallback applies **per locale**: if you translate only German, English recipients get the `assigned` copy.

A mail can go to any role. The shipped copy therefore follows three rules:

- no salutation and no name,
- no direct address, use passive voice or the infinitive instead (in German, neither "du" nor "Sie"),
- none of the machinery's vocabulary, such as "workflow", "transition" or "place".

## The mail class

A mail class implements an interface with a single method:

```php
namespace TakepartMedia\Workflows\Contracts;

use TakepartMedia\Workflows\Mail\MailContext;

interface WorkflowMail
{
    /** @return array<string, mixed> */
    public function compose(MailContext $context): array;
}
```

The addon resolves the class through the service container (`app($class)`). Constructor injection works as usual.

`compose()` returns an array. The addon reads two keys itself:

| Key | Type | Description |
| --- | --- | --- |
| `subject` | `string` | Subject. If missing, the `subject` language key applies. |
| `view` | `string` | Blade template. Takes precedence over the declaration's `view`. |

Every other key is available in the template as a variable. It overrides a default variable of the same name.

### Context object

`TakepartMedia\Workflows\Mail\MailContext` describes the mail to exactly one recipient. All properties are `readonly`.

| Property | Type | Description |
| --- | --- | --- |
| `entry` | `Statamic\Contracts\Entries\Entry` | The entry the mail is about, freshly loaded. |
| `workflow` | `string` | The workflow's name. |
| `definition` | `array` | The raw workflow definition from the YAML. |
| `mailType` | `string` | The mail type's handle. |
| `scope` | `string` | `transition`, `place` or `reminder`. |
| `handle` | `string` | The transition's or the place's handle. |
| `state` | `string` | Current place as a **handle**, not a label. |
| `recipient` | `Statamic\Contracts\Auth\User` | The recipient of this mail. |
| `title` | `string` | The entry's title, else its slug. |
| `url` | `string` | Link to the entry's summary in the workflow. |
| `workflowLabel` | `string` | The workflow's nav label, else its name. |
| `actorName` | `?string` | Name of the person who triggered it. `null` when nobody did or the account was deleted. |
| `actor` | `?Statamic\Contracts\Auth\User` | The same person as a user object. `null` under the same conditions. |
| `waitingDays` | `?int` | Days in the current place. Set for reminders only. |

| Method | Description |
| --- | --- |
| `recipientName(): string` | The recipient's name, else their email address. |
| `boldTitle(): string` | The title as Markdown bold. For the mail body only, not the subject. |
| `text(string $key, array $replace = []): string` | This type's language key, with fallback to `assigned`. |

## The template

Every template receives these variables:

| Variable | Description |
| --- | --- |
| `$context` | The `MailContext` object. |
| `$title`, `$url`, `$workflowLabel`, `$recipientName`, `$actorName` | Values from the context. |
| `$stateLabel` | Label of the current place, in the recipient's language. |
| `$autoTransition`, `$reminder`, `$enteredAt`, `$progress` | Info lines and footer of the default template. |
| `$t` | Translated sentences: `heading`, `intro`, `field_state`, `field_actor`, `field_reminder`, `field_entered`, `button`, `outro`. |

The template is chosen in this order:

1. `view` from `compose()`,
2. `view` from the declaration,
3. `workflows::mail.notification`.

Every template is rendered as a Markdown mail. You can use `<x-mail::message>`, `<x-mail::button>`, `<x-mail::panel>` and `<x-mail::subcopy>`.

## Lifecycle

1. On save, the addon checks `mail_type` against all known types. An unknown handle fails with `transition_action_mail_unknown`, `place_action_mail_unknown` or `place_action_reminder_unknown`. A missing `mail_type` means `assigned`, or `reminder` for the `reminder` action.
2. When triggered, the addon collects the recipients: the assignees or, with `recipients: watchers`, the watchers. Users without an email address and the person who triggered it get no mail.
3. Each recipient gets a separate mail with its own `MailContext`.
4. The mail renders in the recipient's CP language. `compose()` runs inside that language, once per mail.
5. With `after_response` and `queue`, the delivery job reloads the entry. `compose()` then runs in the job.
6. If the mail to one recipient fails, the error is logged. All other recipients still get their mail.

## Examples

### Press approval with own data and template

A mail with an embargo date from the entry and its own layout:

```php
// config/workflows.php
'mail_types' => [
    'press_approval' => [
        'label' => 'Press approval',
        'mail' => \App\Workflows\Mail\PressApproval::class,
        'view' => 'mail.press-approval',
    ],
],
```

```php
// lang/vendor/workflows/en/messages.php
return [
    'mail_press_approval_subject' => 'Approval needed: :title',
    'mail_press_approval_intro' => ':title is waiting in ":workflow" for press approval.',
];
```

```php
<?php

namespace App\Workflows\Mail;

use TakepartMedia\Workflows\Contracts\WorkflowMail;
use TakepartMedia\Workflows\Mail\MailContext;

class PressApproval implements WorkflowMail
{
    public function compose(MailContext $context): array
    {
        return [
            'subject' => $context->text('subject', ['title' => $context->title]),
            'deadline' => $context->entry->value('embargo'),
            'notes' => $context->entry->value('press_notes'),
        ];
    }
}
```

```blade
{{-- resources/views/mail/press-approval.blade.php --}}
<x-mail::message>
# {{ $t['heading'] }}

{{ $t['intro'] }}

@if ($deadline)
- **Embargo:** {{ $deadline }}
@endif

<x-mail::button :url="$url">
{{ $t['button'] }}
</x-mail::button>
</x-mail::message>
```

```yaml
transitions:
  submit_press:
    metadata:
      actions:
        - type: mail
          mail_type: press_approval
```

### Notifying watchers

A type without PHP that goes to the watchers instead of the assignees:

```php
// config/workflows.php
'mail_types' => [
    'correction' => [
        'label' => 'Correction published',
        'recipients' => 'watchers',
    ],
],
```

```php
// lang/vendor/workflows/en/messages.php
return [
    'mail_correction_subject' => 'Corrected: :title',
    'mail_correction_intro' => 'A correction to :title has been published.',
];
```

## Notes & limitations

> [!WARNING]
> Translate only inside `compose()`. A string translated earlier comes out in the sender's language. That is why `state` is a handle; in the template, use `$stateLabel`.

> [!WARNING]
> Templates are rendered with `html_input => 'escape'`. Raw HTML in a value shows up as visible angle brackets. Emphasize text with Markdown, for example with `boldTitle()`.

> [!WARNING]
> If `mail` names a class that is missing or doesn't implement `WorkflowMail`, the whole type is dropped. It disappears from the editor, and any YAML naming the handle can no longer be saved. An invalid `view` or `recipients` does not drop the type.

> [!NOTE]
> A stored `mail_type` that is no longer declared becomes `assigned` at runtime. The default mail goes out, not none.

> [!NOTE]
> Project entries with the handles `assigned`, `plain_language_ready`, `sla`, `reminder` or `published` are silently ignored. Built-in types take precedence.

> [!NOTE]
> Every handle must be declared, even if it only has language keys. The addon cannot derive types from the language files.

> [!TIP]
> `compose()` runs once per recipient. Per-person data belongs there, but expensive queries are paid for repeatedly.

> [!TIP]
> `actorName` and `actor` can be `null`, for example when the scheduler or the plain-language poller triggers the mail. Plan for copy without a name.

> [!TIP]
> The subject is plain text. Use `boldTitle()` in the mail body only, or the asterisks show up in the subject.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| The type doesn't appear in the picker. | The `mail` class is missing or doesn't implement `WorkflowMail`. Also check the config cache. |
| The mail arrives with the default copy. | The language key is missing in the recipient's language. Add it for every locale. |
| A sentence appears in the wrong language. | The string was translated outside `compose()`. |
| The body shows angle brackets. | A value contains HTML. Use Markdown. |
| No mail arrives. | With `queue`, no worker is running, or the recipient has no email address or triggered the step themselves. Check the log for `Workflow notification failed.`. |

## See also

- [Custom action types](actions.md)
- [DOCS.md](../../../DOCS.md), sections "Mail-Typen (`mail_type:`)" and "Eigene Daten und eigenes Template" (German)
- [config/workflows.php](../../../config/workflows.php) – comment blocks above `mail_types` and `mail_delivery`
- [src/Contracts/WorkflowMail.php](../../../src/Contracts/WorkflowMail.php)
- [src/Mail/MailContext.php](../../../src/Mail/MailContext.php)
- [src/Mail/MailTypes.php](../../../src/Mail/MailTypes.php)
- [resources/views/mail/notification.blade.php](../../../resources/views/mail/notification.blade.php) – the default template
- [lang/en/messages.php](../../../lang/en/messages.php) – the `mail_assigned_*` block with the copy rules

---

← [Custom action types](actions.md) · [Overview](README.md) · [Assignment criteria](assignment-criteria.md) →
