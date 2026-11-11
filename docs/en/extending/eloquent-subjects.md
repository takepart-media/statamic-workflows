# Eloquent models as subjects

🇩🇪 [Deutsch](../../de/extending/eloquent-subjects.md)

A workflow can govern Eloquent models instead of entries, as long as the model is a [Runway](https://runway.duncanmcclean.com) resource. State, assignees, comments, history, mails and the dashboard widgets work the same way; site versions, translation, content preview, revisions and content ageing stay entry-only.

## Bind a workflow to a resource

List the Runway resource handles under `runway:` instead of (or next to) `collections:`:

```yaml
name: review
runway: [ticket]
metadata:
  state_attribute: status   # optional: mirror the place into this model attribute
places: { … }
transitions: { … }
```

The subject id of a model is Runway's reference, `runway::<resource>::<key>`. Add the `workflow_actions` field to the resource's blueprint to show the workflow panel on the edit form. Runway's own `view <resource>` and `edit <resource>` permissions replace the site checks entries go through.

## Keep the place in a model attribute

With `metadata.state_attribute`, every state change (a transition, a manual state change, a place migration by command) also writes the place into that attribute. The write happens in the same database transaction as the state row, so a save the model refuses rolls the state back as well.

That only holds if the workflow tables and the model share one connection. Set it in `config/workflows.php`:

```php
'connection' => env('WORKFLOWS_DB_CONNECTION'), // e.g. 'mysql'
```

`workflow` then resolves to that very connection; `database` is ignored.

## Refuse a transition: guards

A guard is declared like an [action](actions.md), with a `guard` class instead of (or next to) a `handler`:

```php
'actions' => [
    'four_eyes_check' => [
        'label' => 'Four-eyes check',
        'scope' => 'transition',
        'guard' => \App\Workflows\FourEyesCheck::class,
    ],
],
```

```php
use TakepartMedia\Workflows\Contracts\TransitionGuard;
use TakepartMedia\Workflows\Workflow\ActionContext;

class FourEyesCheck implements TransitionGuard
{
    public function check(ActionContext $context): ?string
    {
        return $context->actorId === $context->entry->value('author_id')
            ? 'You cannot approve your own work.'
            : null;
    }
}
```

Put the action on a transition in the editor or in the YAML. The guard runs before anything is written: the button is disabled with the reason as its tooltip, and `WorkflowTransitioner::apply()` refuses with `TransitionBlocked` for every caller, commands included. `check()` must not write anything.

## React inside the transaction: events

| Event | When | Notes |
| --- | --- | --- |
| `Events\TransitionApplying` | Guards passed, state row locked, nothing written yet | Inside the transaction. Do the domain work the step stands for here (record an approval, close a run); the place written right after then agrees with it. Throwing aborts the step. |
| `Events\TransitionApplied` | After state, assignees and history are written, before mails and action handlers | Inside the transaction. A listener may write its own records in the same commit; throwing rolls the step back. Throw `TransitionBlocked` to show a reason to the editor. |
| `Events\AssigneesChanged` | Whenever the assignees of a subject are set | Carries the new and the previous user ids. |

`ActionContext::$entry` and `MailContext::$entry` hold an `EloquentSubject` for a model. It offers `id()`, `value()`, `get()`, `title()`, `model()`, `resourceHandle()` and `editUrl()`.
