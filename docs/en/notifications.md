# Notifications

🇩🇪 [Deutsch](../de/notifications.md)

The addon mails the people responsible for an entry when a step runs, while an entry waits, when someone mentions them and when their account still needs activating.

## Overview

| Mail | Trigger | Recipients | Switch |
| --- | --- | --- | --- |
| Step mail | A transition or place with a `mail` action | Assignees, or watchers for `published` | per workflow |
| Place reminder | Daily task, while an entry sits in a place with a `reminder` action | Assignees | `reminder_scheduler` |
| Comment mention | Someone `@`-mentions a user in a comment | The mentioned users | `comment_mention_mail` |
| Account reminder | After every transition | Assignees who never activated their account | `account_reminder.enabled` |

Every mail is sent separately to each recipient, in that person's CP language. No recipient sees who else got the mail. The person who triggered a step never gets a mail about their own click, and users without an email address are skipped.

Mails go out through your Laravel mail configuration (`MAIL_MAILER`, `MAIL_FROM_ADDRESS`, …).

## Step mails

A step mail is the `mail` action. You can attach it to a transition or to a place:

- **On a transition:** the mail goes out after the transition has run.
- **On a place:** the mail goes out whenever an entry arrives in that place, also when nobody clicked, for example when a plain-language rewrite lands or the [ageing task](sla.md) moves an entry.

```yaml
transitions:
  approve_expert:
    from: expert_review
    to: final_approve
    metadata:
      actions:
        - type: mail
          mail_type: assigned

places:
  plain_review:
    metadata:
      actions:
        - type: mail
          mail_type: plain_language_ready
```

In the [workflow editor](editor.md), add the "Send mail" action and pick the "Mail type".

Rules:

- A transition or place carries at most one `mail` action.
- If a transition has its own `mail` action, the mail on the place it lands in is skipped. One click never sends two mails.
- Without `mail_type`, the action sends `assigned`.
- Setting a state manually ("Set status manually (admin)") sends no mail.
- In the assignee picker, "Send email notification" lets the person running the transition skip the step mail for this one run.

### Mail types

| Handle | Label in the editor | Recipients | Says |
| --- | --- | --- | --- |
| `assigned` | Entry assigned | Assignees | "An entry is waiting to be edited": the entry has been listed for editing in the workflow. The default type. |
| `plain_language_ready` | Plain language is ready | Assignees | "The plain-language version is ready" and can be proofread. Meant for the place where finished rewrites land. |
| `sla` | Update due | Assignees | "A post is due for an update": it has not been updated since a given date, with a request to review it. Meant for the ageing places of [Content ageing](sla.md). |
| `reminder` | Reminder: still waiting | Assignees | "A post is still waiting to be worked on": it has been in the same state for N days. The default of the `reminder` action. |
| `published` | Published (to watchers) | Watchers | "A post has been published". Goes to everyone watching the entry instead of the assignees. |

The assignees are read after the step has run, so a mail on a transition with an assignment reaches the new people. Watchers are everyone who clicked "Watch" on the entry, plus everyone who was ever assigned to it and has not stopped watching.

To add your own types, see [Custom mail types](extending/mail-types.md).

### What a mail contains

Every step mail uses the same layout:

- a heading and an intro naming the entry and the workflow,
- "Status": the state the entry is in now,
- "Triggered by": who ran the step. Left out when nobody clicked, for example for scheduled moves,
- "Reminder": the reminder interval, when the current place has a `reminder` action,
- a notice "Automatically … on …" with the deadline, when the current place has an automatic transition. It replaces the "Reminder" line,
- "Received on": when the entry arrived in its current state,
- a button to the entry's workflow summary,
- a footer "Step N of M".

## Place reminders

The `reminder` place action mails the assignees again and again while an entry waits in a place.

```yaml
places:
  in_review:
    metadata:
      actions:
        - type: reminder
          every_days: 3
```

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `every_days` | `int` | – | Interval in days, at least `1`. |
| `mail_type` | `string` | `reminder` | Which mail type to send. |

In the editor, this is the place action "Reminder every X days (email)".

How it behaves:

1. The first reminder goes out `every_days` after the entry arrived in the place.
2. Every further reminder goes out `every_days` after the previous one.
3. Reminders stop as soon as the entry leaves the place. There is no upper limit.
4. Each reminder is logged in the history as "Reminder sent".
5. An entry with nobody assigned gets no reminder and no history entry.

Reminders are sent by `workflows:send-reminders`, which the addon registers with the Laravel scheduler once a day:

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `reminder_scheduler` | `WORKFLOWS_REMINDER_SCHEDULER` | `true` | Registers the daily task. `false` turns reminders off globally. |
| `reminder_time` | `WORKFLOWS_REMINDER_TIME` | `08:00` | Time of day the task runs. |

The same switch and time also run `workflows:auto-transition`, right before the reminders.

You can run the task by hand:

```bash
# List the reminders that are due, without sending
php artisan workflows:send-reminders --dry-run

# Only one workflow
php artisan workflows:send-reminders --workflow=postmanager
```

> [!IMPORTANT]
> The scheduler only runs if `php artisan schedule:run` is on cron in your project. See [Commands & scheduler](commands.md).

## Comment mentions

When someone mentions a user with `@` in a comment, that user gets a mail "New mention in a comment". It names who mentioned them, the entry, the "Section" (the commented passage, if any) and the "Area" (the workflow). The button "Open the comment" opens the workflow summary with the comment shown.

- On a new comment or reply, every mentioned user gets the mail.
- On an edit, only users the edit newly mentions get it.
- The comment's author never gets it.

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `comment_mention_mail` | `WORKFLOWS_COMMENT_MENTION_MAIL` | `true` | `false` turns mention mails off. Mentions still work in the CP. |

## Account reminder

People are often assigned before they ever logged in, for example right after a Statamic CP invitation. After every transition, the addon checks the entry's assignees. Everyone whose account was never activated (no password set yet) gets a mail "Activate your account: … is waiting" with a fresh activation link. The mail names the entry, the workflow and who handed it over, and says how long the link is valid.

The reminder pauses while the person's last activation link is still valid. That includes the link from Statamic's CP invitation. A new mail only goes out after the previous link has expired, however many steps run in between. Activated users never get it.

It runs after every transition, including transitions run by the scheduler. It doesn't depend on the "Send email notification" switch: that switch only silences the step mail.

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `account_reminder.enabled` | `WORKFLOWS_ACCOUNT_REMINDER` | `true` | `false` turns the account reminder off. |
| `account_reminder.expire_hours` | `WORKFLOWS_ACTIVATION_EXPIRE_HOURS` | `null` | How long an activation link stays valid, in hours. `null` keeps the duration of Statamic's activation password broker (`statamic.users.passwords.activations`). |

> [!WARNING]
> `expire_hours` changes the activation link for Statamic's CP invitations as well. Both use the same link, so there is no way to set the duration for the reminder alone.

## Delivery

Mails triggered by a click (step mails, mention mails, account reminders) don't make the editor wait for the mail server. `mail_delivery` decides how they are sent:

| Value | Behavior |
| --- | --- |
| `after_response` | Default. Sent in the same PHP process right after the response has gone to the browser. No queue worker needed. |
| `queue` | Pushed onto your `QUEUE_CONNECTION`, with retries. Needs a running `php artisan queue:work`. |
| `sync` | Sent inline, inside the request. |

```env
WORKFLOWS_MAIL_DELIVERY=queue
```

Place reminders from `workflows:send-reminders` are always sent inline.

If the mail to one recipient fails, the error is logged and all other recipients still get theirs. A failed mail never undoes the transition.

## Customizing the texts

All mail texts live in the addon's `workflows::messages` translation namespace. To change them, publish the language files:

```bash
php artisan vendor:publish --tag=workflows-translations
```

Then edit `lang/vendor/workflows/<locale>/messages.php`. Mail keys follow the pattern `mail_<type>_<key>`, for example:

```php
// lang/vendor/workflows/en/messages.php
return [
    'mail_assigned_subject' => 'Your turn: :title',
    'mail_reminder_intro' => '":title" has been waiting in ":workflow" for :days days.',
];
```

| Keys | Mail |
| --- | --- |
| `mail_assigned_*` | `assigned`, and the fallback for every other type |
| `mail_plain_language_ready_*`, `mail_sla_*`, `mail_reminder_*`, `mail_published_*` | the other built-in types |
| `mail_reminder_notice` | the "Reminder" line |
| `mail_auto_transition_*` | the automatic-transition notice |
| `mail_comment_mention_*` | comment mentions |
| `mail_account_reminder_*`, `duration_days`, `duration_hours` | account reminder |

A key a type doesn't define is taken from `assigned`, per language. If you translate a key in German only, English recipients get the `assigned` text for it.

The shipped texts address nobody by name and avoid technical words such as "transition" or "place", because a mail can reach any role. Keep that in mind when you write your own.

For all keys and their placeholders, see [Custom mail types](extending/mail-types.md#language-keys).

## Next steps

- [Custom mail types](extending/mail-types.md): your own mails with your own data and template.
- [Content ageing (SLA)](sla.md): the `sla` mail and the ageing places.
- [Commands & scheduler](commands.md): cron setup and all scheduled tasks.
- [Translation & plain language](localization.md): the place where finished rewrites land.

---

← [Working with workflows](using.md) · [Docs home](README.md) · [Translation & plain language](localization.md) →
