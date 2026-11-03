# Dashboard header responsibles

🇩🇪 [Deutsch](../../de/extending/header-responsibles.md)

Show in the dashboard header whom a person can turn to with questions about their topics.

> **At a glance**
> - Config key: `header_responsibles` in `config/workflows.php`
> - Default: `['group' => null, 'user_field' => null]` (= off)
> - Own code: no

## Overview

The `workflows_user_header` dashboard widget introduces the logged-in user: name, avatar, groups and "Affiliation". `header_responsibles` adds a "Responsible" row.

This row lists the members of one user group who cover the same topics as the user. A typical case: editors see at a glance who their topic owners are.

## Quick start

1. Publish the config if you have not done so yet:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Create a user group for the responsibles, for example `topic_owners`. Add a field for the topics to the User blueprint, for example `entity`.

3. Add both to the config:

   ```php
   // config/workflows.php
   'header_responsibles' => [
       'group' => 'topic_owners',
       'user_field' => 'entity',
   ],
   ```

4. Fill in the `entity` field for the responsibles and the other users. The dashboard now shows the "Responsible" row.

## Configuration

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `group` | `string` \| `null` | yes | `null` | Handle of the Statamic user group whose members can be responsibles. `null` or `''` turns the row off. |
| `user_field` | `string` \| `null` | yes | `null` | Handle of a field on the User blueprint. It is read on **both** sides: on the current user and on the group members. `null` or `''` turns the row off. |

Both values must be set, or the row is missing. The widget itself has no widget options of its own.

## On the dashboard

The `workflows_user_header` widget shows up to three rows below the name:

1. **Functions:** the user's Statamic groups, or "No functions".
2. **Affiliation:** every value the [assignment criteria](assignment-criteria.md) with a `user_field` read off the person. They are labeled through the criteria's `options`, for example with the term title instead of the slug. Without a matching option the slug is shown. If the user has no values, the row is missing.
3. **Responsible:** every member of `group` whose `user_field` values overlap the user's values in the same field.

For the "Responsible" row:

- Values are compared by bare slug, just like the assignment criteria. So `themen::gesundheit` matches `gesundheit`.
- It shows the name, falling back to the email address, sorted naturally.
- The user never appears in their own row.

Without a logged-in user the widget hides itself.

## Examples

### One field for assignment and header

When an assignment criterion uses the same user field, "Affiliation" and "Responsible" line up. The affiliation then also gets the term titles as labels:

```php
// config/workflows.php
'assignment_criteria' => [
    'responsibility' => [
        'label' => 'Responsibility',
        'user_field' => 'entity',
        'entry_field' => 'categories',
        'options' => 'taxonomy:categories',
    ],
],

'header_responsibles' => [
    'group' => 'topic_owners',
    'user_field' => 'entity',
],
```

An editor with `entity: [health]` sees the title of the "health" term under "Affiliation". "Responsible" lists every member of `topic_owners` whose `entity` also contains `health`.

## Notes & limitations

> [!WARNING]
> Group and field are **not** checked against blueprints, unlike the assignment criteria. A typo silently makes the row disappear.

> [!NOTE]
> There is only **one** field for both sides. Different fields for the user and the responsibles are not supported.

> [!NOTE]
> `header_responsibles` is independent of `assignment_criteria`. The criteria decide who an entry may be assigned to. This row shows whom a person asks.

> [!NOTE]
> You cannot add further rows through config, only in the addon's code.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| The "Responsible" row is missing. | `group` or `user_field` is not set. | Set both values. |
| | The group does not exist, or the field name has a typo. | Compare the handles with the CP. |
| | The user has no value in the field. | Fill in the field on the user. |
| | No group member shares a value with the user. | Fill in the field on the responsibles. |
| The whole widget is missing. | It is not part of the dashboard layout. | See [Dashboard widgets](dashboard-widgets.md). |

## See also

- [Assignment criteria](assignment-criteria.md)
- [Dashboard widgets](dashboard-widgets.md)
- [Working with workflows](../using.md) – the dashboard and its widgets
- [Configuration](../configuration.md)
- [config/workflows.php](../../../config/workflows.php), comment on `header_responsibles`
- [src/Widgets/UserHeader.php](../../../src/Widgets/UserHeader.php)

---

← [Assignment criteria](assignment-criteria.md) · [Overview](README.md) · [Dashboard widgets](dashboard-widgets.md) →
