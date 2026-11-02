# Custom action types

🇩🇪 [Deutsch](../de/actions.md)

Attach your own actions to transitions and places, and run your own project code whenever a transition is applied.

> **At a glance**
> - Config key: `actions`
> - Default: `[]` (= off)
> - Own code: optional `Contracts\WorkflowAction`

## Overview

You declare custom action types in `config/workflows.php`. They show up in the graph editor next to the built-in actions and are stored in the workflow YAML just like them.

Every action belongs to exactly one scope: it sits either on a transition or on a place. Optionally, it gets input fields whose values are maintained in the editor.

If the declaration names a handler, the addon calls it after every successful transition. This is the only place where the addon calls project code. It fires no other events.

Without a handler, the action is a pure marker. Your code reads it back when needed, for example "Legally reviewed" on a place.

## Quick start

1. Publish the config if you haven't already:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Declare the action in `config/workflows.php`:

   ```php
   'actions' => [
       'press_release' => [
           'label' => 'Prepare press release',
           'scope' => 'transition',
           'handler' => \App\Workflows\PressRelease::class,
       ],
   ],
   ```

3. Write the handler:

   ```php
   <?php

   namespace App\Workflows;

   use TakepartMedia\Workflows\Contracts\WorkflowAction;
   use TakepartMedia\Workflows\Workflow\ActionContext;

   class PressRelease implements WorkflowAction
   {
       public function handle(ActionContext $context): void
       {
           logger()->info('Press release for '.$context->entry->id());
       }
   }
   ```

4. Open the workflow in the CP, select a transition and add the "Prepare press release" action. After saving, the handler runs every time this transition is applied.

## Configuration

### Declaration (`actions.<handle>`)

The array key is the action's handle. It is stored as `type` in the YAML.

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `label` | `string` | yes | – | Label in the editor. Passed through `__()`, so a translation key works as well as a plain string. |
| `scope` | `string` | yes | – | `transition` or `place`. The two scopes have separate type lists and separate validation. |
| `workflows` | `array` \| `string` | no | all | Names of the workflows that offer the action. If the key is missing or the list is empty, it applies to all workflows. |
| `handler` | `class-string` | no | – | A class implementing `Contracts\WorkflowAction`. Without a handler, the action is a pure marker. |
| `fields` | `array` | no | `[]` | Input fields. The key is the field handle; values are stored under `values`. |

### Fields (`fields.<key>`)

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `type` | `string` | no | `text` | `text`, `number`, `select` or `multiselect`. An unknown type becomes `text`. |
| `label` | `string` | no | field handle | Label in the editor, passed through `__()`. |
| `min`, `max` | `int` | no | – | `number` only. Bounds for the value, both inclusive. |
| `options` | `array` \| `string` | no | – | `select` and `multiselect` only. See [Options](#options). |

### Options

`options` accepts the same shapes as [assignment criteria](assignment-criteria.md):

- a `value => label` map, for example `['mail' => 'Email', 'wire' => 'Wire']`,
- a list `[['value' => …, 'label' => …], …]`,
- one of the strings `groups`, `roles`, `sites` or `taxonomy:<handle>`. The options are then loaded from Statamic.

## The handler

A handler implements an interface with a single method:

```php
namespace TakepartMedia\Workflows\Contracts;

use TakepartMedia\Workflows\Workflow\ActionContext;

interface WorkflowAction
{
    public function handle(ActionContext $context): void;
}
```

The addon resolves the class through the service container (`app($class)`). Constructor injection works as usual.

### Context object

`TakepartMedia\Workflows\Workflow\ActionContext` describes the moment the handler runs. All properties are `readonly`.

| Property | Type | Description |
| --- | --- | --- |
| `entry` | `Statamic\Contracts\Entries\Entry` | The entry after the transition, freshly loaded. |
| `workflow` | `string` | The workflow's name. |
| `scope` | `string` | `transition` or `place`. |
| `handle` | `string` | The transition's handle (scope `transition`) or the target place's handle (scope `place`). |
| `from` | `string` | The place the entry came from. |
| `to` | `string` | The place the entry now sits in. |
| `values` | `array<string, mixed>` | The action's field values, already cast to the declared types. |
| `actorId` | `?string` | ID of the user who triggered the transition. `null` when the scheduler runs it. |

Plus `value(string $key, mixed $default = null): mixed` as shorthand for `$context->values[$key] ?? $default`.

### Field values

Stored values are cast to the declared type when read:

| Field type | Value in the handler |
| --- | --- |
| `number` | `int` |
| `text`, `select` | `string` (numbers become strings) |
| `multiselect` | list of unique strings |

Undeclared keys and unusable values are dropped. If a value is missing, `value()` returns your default.

## In the editor

For each transition or place, the graph editor shows only the actions whose `scope` matches and that are allowed for the workflow being edited. Their fields appear below the action.

On save, the addon validates the YAML. If validation fails, the workflow is not saved:

| Error code | Cause |
| --- | --- |
| `transition_action_type_unknown`, `place_action_type_unknown` | The `type` is neither built in nor declared for this workflow and scope. |
| `action_values_not_map` | `values` is not a map. `values` may also be absent. |
| `action_value_undeclared` | A key in `values` is not a declared field. The message lists the declared fields. |
| `action_value_invalid` | The value does not match the field type: `number` must be numeric and within `min`/`max`, `multiselect` an array, `text`/`select` a string or number. |

This is how a saved action looks in the YAML:

```yaml
transitions:
  publish:
    metadata:
      actions:
        - type: press_release
          values:
            channel: mail
            lead_time: 14
```

## Lifecycle

Handlers run at the very end of a transition. By then, state, published flag, assignees, history entry and revision are already saved. The step mail and the account reminder have also been queued.

1. First, the `transition` actions of the applied transition run.
2. Then, the `place` actions of the target place run.

Within a scope, the YAML order applies, which is the order in the editor. Actions without a handler are skipped.

Every handler runs in isolation. If it throws, the addon logs the exception with the message `Workflow action handler failed.` and the keys `workflow`, `scope`, `handle`, `action` and `exception`. The next handler still runs, and the transition counts as successful.

If a handler saves the entry, no extra `draft_save` entry appears in the history.

## Examples

### Press release with fields

A transition action with channel, lead time and distribution list, available only in the `postmanager` workflow:

```php
// config/workflows.php
'actions' => [
    'press_release' => [
        'label' => 'Prepare press release',
        'scope' => 'transition',
        'workflows' => ['postmanager'],
        'handler' => \App\Workflows\PressRelease::class,
        'fields' => [
            'channel' => [
                'type' => 'select',
                'label' => 'Channel',
                'options' => ['mail' => 'Email', 'wire' => 'Wire'],
            ],
            'lead_time' => [
                'type' => 'number',
                'label' => 'Lead time (days)',
                'min' => 1,
                'max' => 90,
            ],
            'recipients' => [
                'type' => 'multiselect',
                'label' => 'Distribution',
                'options' => 'groups',
            ],
        ],
    ],
],
```

```php
<?php

namespace App\Workflows;

use App\Press\PressDesk;
use TakepartMedia\Workflows\Contracts\WorkflowAction;
use TakepartMedia\Workflows\Workflow\ActionContext;

class PressRelease implements WorkflowAction
{
    public function __construct(private PressDesk $desk) {}

    public function handle(ActionContext $context): void
    {
        $this->desk->prepare(
            entry: $context->entry,
            channel: $context->value('channel', 'mail'),
            leadDays: $context->value('lead_time', 7),
            groups: $context->value('recipients', []),
            actorId: $context->actorId, // null when the scheduler applies the transition
        );
    }
}
```

### Marker on a place

An action without a handler marks a place. Your code reads the marker back itself:

```php
// config/workflows.php
'actions' => [
    'legally_reviewed' => [
        'label' => 'Legally reviewed',
        'scope' => 'place',
    ],
],
```

```yaml
places:
  review:
    metadata:
      actions:
        - type: legally_reviewed
```

Two methods read markers back. Both return a list of `['type' => …, 'values' => […]]` in YAML order and include only declared handles:

```php
use TakepartMedia\Workflows\Workflow\PlaceActions;
use TakepartMedia\Workflows\Workflow\TransitionActions;

$placeActions = PlaceActions::custom($definition['places']['review']);
$transitionActions = TransitionActions::custom($definition['transitions']['publish']);

$reviewed = collect($placeActions)->contains('type', 'legally_reviewed');
```

## Notes & limitations

> [!WARNING]
> A handler cannot prevent a transition. It runs afterwards, and throwing undoes nothing. Anything that must be able to say "no" belongs in the workflow definition, for example `required_group` or `target_missing`.

> [!WARNING]
> `actorId` is `null` when the scheduler applies the transition (`TransitionAgedPostsCommand`). Check for it before you load a user.

> [!WARNING]
> Invalid declarations are dropped, not repaired. This covers a missing `label`, a missing or invalid `scope`, a reserved handle, and a `handler` class that is missing or does not implement the contract. The action disappears from the editor, and any YAML naming the handle can no longer be saved. Workflows saved earlier keep running but ignore the action.

> [!NOTE]
> Reserved handles are `user_assignment`, `target_missing`, `start_localization`, `plain_language_resubmit`, `mail`, `account_reminder`, `pending_rewrite_spinner`, `refresh_interval`, `reminder`, `auto_transition`, `site_modal`, `user_modal` and `notify_assignees`. Built-in types win every collision.

> [!TIP]
> On save, `select` values are checked against the type only, not against `options`. If your code needs valid values, check them in the handler.

> [!NOTE]
> `workflows` only applies in the editor and on save. At runtime, the addon only checks whether the handle is declared at all.

> [!NOTE]
> Config changes take effect immediately. With `php artisan config:cache`, they take effect only after you cache again.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| The action doesn't appear in the editor. | `label` or `scope` is missing, the handle is reserved, or the `handler` class is missing or doesn't implement `WorkflowAction`. Also check `workflows` and the config cache. |
| The workflow can't be saved (`…_action_type_unknown`). | The YAML names a handle that was dropped or isn't declared for this workflow or scope. |
| The handler doesn't run. | The action sits on the wrong scope, or the handler throws. Search the log for `Workflow action handler failed.`. |

## See also

- [Custom mail types](mail-types.md)
- [Assignment criteria](assignment-criteria.md) – the same `options` shapes
- [DOCS.md](../../../DOCS.md), section "Eigene Action-Typen aus dem Projekt" (German)
- [config/workflows.php](../../../config/workflows.php) – comment block above `actions`
- [src/Contracts/WorkflowAction.php](../../../src/Contracts/WorkflowAction.php)
- [src/Workflow/ActionContext.php](../../../src/Workflow/ActionContext.php)
- [src/Workflow/CustomActions.php](../../../src/Workflow/CustomActions.php)

---

[Overview](README.md) · [Custom mail types](mail-types.md) →
