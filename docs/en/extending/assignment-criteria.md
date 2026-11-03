# Assignment criteria

🇩🇪 [Deutsch](../../de/extending/assignment-criteria.md)

Custom assignment criteria let you decide who gets an entry based on fields on the person and on the entry.

> **At a glance**
> - Config key: `assignment_criteria` in `config/workflows.php`
> - Default: `[]` (only the built-in criteria `group` and `role`)
> - Own code: no

## Overview

A `user_assignment` action on a transition filters the possible assignees through criteria. The addon ships two criteria: `group` (Statamic user group) and `role` (Statamic role).

With `assignment_criteria` you declare more criteria. Each one reads a field on the User blueprint, a field on an entry blueprint, or both. This answers questions like "Who handles this entry's category?".

The criteria appear in the action's dropdown in the graph editor. In manual mode they narrow the picker; in automatic mode they decide the assignees.

## Quick start

1. Publish the config if you have not done so yet:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Create the fields the criterion will read. In this example: a taxonomy field `entities` on the User blueprint and a taxonomy field `categories` on the entry blueprint, both pointing at `categories`.

3. Declare the criterion:

   ```php
   // config/workflows.php
   'assignment_criteria' => [
       'category' => [
           'label' => 'Category',
           'user_field' => 'entities',
           'entry_field' => 'categories',
           'options' => 'taxonomy:categories',
       ],
   ],
   ```

4. Open the workflow in the graph editor, pick the "User assignment" action on a transition and add criteria. The editor writes them to the workflow YAML like this:

   ```yaml
   actions:
     - type: user_assignment
       mode: automatic
       match: all
       criteria:
         - { field: group, operator: is, value: editors }
         - { field: category, operator: matches_entry }
   ```

   Every member of the `editors` group whose `entities` overlap the entry's `categories` is now assigned.

## Configuration

One entry per criterion. The array key is the handle stored as `field` in the YAML.

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `label` | `string` | no | the handle | Name shown in the editor. Passed through `__()`, so a translation key works too. |
| `user_field` | `string` | at least one of the two | – | Field handle on the User blueprint. |
| `entry_field` | `string` | at least one of the two | – | Field handle on any entry blueprint of the project. |
| `operators` | `array` | no | all the fields allow | List of `is`, `is_not`, `matches_entry`. Narrows the operators. Order matters: the first one is what a new row starts on. |
| `options` | `array` \| `string` | no | none | Suggestions for the value input (see below). |

### Value suggestions (`options`)

| Shape | Example | Result |
| --- | --- | --- |
| Map `value => label` | `['news' => 'News']` | Entries in this order. |
| List of pairs | `[['value' => 'news', 'label' => 'News']]` | Like the map, but allows duplicate labels. |
| `'groups'` | – | All user groups, sorted by title. |
| `'roles'` | – | All roles, sorted by title. |
| `'sites'` | – | All sites. |
| `'taxonomy:<handle>'` | `'taxonomy:categories'` | All terms of the taxonomy, once per slug, labeled with the default site's title. |

## Operators

Which operators a criterion offers depends on the fields you declare:

| Declared fields | `is` / `is_not` check | Additionally |
| --- | --- | --- |
| `user_field` only | the **person's** field against a fixed value | – |
| `entry_field` only | the **entry's** field against a fixed value | – |
| both | the **person's** field against a fixed value | `matches_entry` |

- `is`: the value read contains `value`. `is_not` is the inverse.
- `matches_entry`: the person's and the entry's values overlap. No `value` is needed; the entry supplies it.
- A criterion with only `entry_field` holds or fails the same way for every person. It acts as a precondition for the whole assignment.

### How values are compared

- Field values are always treated as lists, whether the field stores one value or several.
- Every value is reduced to its bare slug, so `themen::gesundheit` matches `gesundheit`.
- The stored values are compared, not the labels.

## In the editor

- The dropdown lists `group`, `role` and every valid project criterion.
- Each criterion offers only its allowed operators.
- The value input shows the `options` as suggestions. You can also type a value that does not exist yet.
- Criteria can be grouped one level deep, each group with its own `match` (`all` or `any`).

## Lifecycle

1. **Save:** the editor rejects unknown criteria, operators that are not allowed, and a missing value for `is`/`is_not` with an error message.
2. **Run:** when the transition fires, every criterion is checked for every user.
3. **When in doubt, nobody:** an unknown criterion, an unknown operator, a missing value or a missing entry always counts as "does not match". This mainly affects hand-edited YAML.

## Examples

### The person responsible for the entry's category

A criterion meant to answer exactly one question. `operators` hides `is` and `is_not`:

```php
'assignment_criteria' => [
    'responsibility' => [
        'label' => 'Responsibility',
        'user_field' => 'entity',
        'entry_field' => 'categories',
        'operators' => ['matches_entry'],
    ],
],
```

```yaml
criteria:
  - { field: responsibility, operator: matches_entry }
```

### A precondition on the entry

A criterion with only `entry_field`, combined with a criterion that selects people:

```php
'assignment_criteria' => [
    'post_category' => [
        'label' => "Entry's category",
        'entry_field' => 'categories',
        'options' => 'taxonomy:categories',
    ],
],
```

```yaml
actions:
  - type: user_assignment
    mode: automatic
    match: all
    criteria:
      - { field: post_category, operator: is, value: health }
      - { field: group, operator: is, value: specialists }
```

Health entries go to the `specialists` group. Other entries are assigned to nobody.

### A group with its own `match`

```yaml
actions:
  - type: user_assignment
    mode: automatic
    match: all
    criteria:
      - { field: category, operator: matches_entry }
      - match: any
        criteria:
          - { field: role, operator: is, value: editor }
          - { field: group, operator: is, value: chief_editors }
```

## Notes & limitations

> [!WARNING]
> Field handles are checked. `user_field` must exist on the User blueprint, `entry_field` on at least one entry blueprint. A criterion naming a missing field is dropped: it disappears from the dropdown, and a YAML using it can no longer be saved. Always create the field first, then the criterion.

> [!WARNING]
> In automatic mode at least one criterion must narrow people (`group`, `role`, or one with a `user_field`). With `match: any` this must hold for **all** criteria. An empty criteria list is not allowed in automatic mode. Groups are checked under their own `match`.

> [!NOTE]
> An entry with neither `user_field` nor `entry_field` is dropped. An operator in `operators` that the fields cannot support (for example `matches_entry` without `entry_field`) is silently removed. If no operator is left, the criterion is dropped.

> [!NOTE]
> The `entry_field` check is project-wide. A field that only exists in another collection counts as present.

> [!NOTE]
> Two taxonomies sharing a slug compare as equal.

> [!TIP]
> `options` are suggestions only. A typo in a value is not detected, so pick values from the list where you can.

> [!NOTE]
> A project criterion with the handle `group` or `role` replaces the built-in one.

> [!NOTE]
> The values of every criterion with a `user_field` also show up as the "Affiliation" row of the dashboard header, see [Dashboard header responsibles](header-responsibles.md).

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| The criterion is missing from the dropdown. | A declared field does not exist, or no operator is left. | Check the field handles and `operators`. |
| The workflow can no longer be saved. | The YAML uses a criterion that was dropped. | Create the missing field or remove the criterion from the action. |
| Saving fails in automatic mode. | No criterion narrows people, or not all of them do with `match: any`. | Add a criterion using `group`, `role` or a `user_field`. |
| Nobody is assigned. | No user meets all criteria, or the entry has no value in `entry_field`. | Check the values on the people and the entry. |

## See also

- [Dashboard header responsibles](header-responsibles.md)
- [Defining workflows](../workflows.md) – the `user_assignment` action
- [Workflow editor](../editor.md) – picking criteria in the settings editor
- [config/workflows.php](../../../config/workflows.php), comment on `assignment_criteria`
- [src/Workflow/AssignmentCriteria.php](../../../src/Workflow/AssignmentCriteria.php)
- [src/Workflow/AssignmentCandidates.php](../../../src/Workflow/AssignmentCandidates.php)
- [src/Workflow/WorkflowDefinitionWriter.php](../../../src/Workflow/WorkflowDefinitionWriter.php)

---

← [Custom mail types](mail-types.md) · [Overview](README.md) · [Dashboard header responsibles](header-responsibles.md) →
