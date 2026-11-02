# Content fields

🇩🇪 [Deutsch](../de/content-fields.md)

Use `content_fields` to tell the addon what your blueprint fields are called: title, intro, body, SEO and entity reference.

> **At a glance**
> - Config key: `workflows.content_fields`
> - Default: `title => 'title'`, `body => 'content'`, `seo => 'seo'`, everything else `null` / `[]`
> - Own code: no

## Overview

The addon does not know your project's blueprints. `content_fields` therefore maps global **roles** to your field handles. The content preview, the plain-language rewrite and the translation context read these roles.

*Which* fields make up a workflow's content is not set here. You pick them per workflow in the workflow settings (stored as `metadata.content_fields`). The `intro` and `body` roles only drive auto-detection when a workflow has no selection.

Typical case: your articles have a Bard field `intro` and a main Bard field `content_sets`. You set both roles, and the preview and plain language find the text without further configuration.

## Quick start

1. Publish the config:

   ```bash
   php artisan vendor:publish --tag=workflows-config
   ```

2. Set the roles in `config/workflows.php` to your field handles:

   ```php
   'content_fields' => [
       'title'  => 'title',
       'intro'  => 'intro',          // Bard field with the opening paragraph
       'body'   => 'content_sets',   // main Bard field
       'seo'    => 'seo',            // SEO Pro field
       'entity' => 'entities',       // terms field: which module the entry belongs to
       'scope'  => 'in_entities',    // set field limiting a block to certain entities
   ],
   ```

3. Optionally pick the content fields per workflow: workflow settings → section **Content**. Without a selection the editor shows "automatic: <field>".

4. Open a workflow detail page. The content panel now shows the resolved fields.

## Configuration

Keys are merged with the defaults **one by one**. If you only set `body`, you keep all other defaults. A key that is missing, `null` or blank switches off the feature that needs it. There are no env variables.

| Key | Type | Required | Default | Description |
| --- | --- | --- | --- | --- |
| `title` | `string` | no | `'title'` | Title field. With plain language, the returned `<h1>` is written here. The title is never sent to the service as a content block. |
| `intro` | `string\|null` | no | `null` | Bard field with the opening paragraph. Used for auto-detection. With plain language, this block is wrapped in `<div class="intro">`, and only then is `generate_intro` `true`. Source of the derived `seo.description`. |
| `body` | `string` | no | `'content'` | Main content field. Used for auto-detection. Older plain-language jobs (`format = null`) replace this field on write-back. |
| `seo` | `string` | no | `'seo'` | SEO Pro field. Never sent to the plain-language service. `seo.title` and `seo.description` are derived from the answer instead. |
| `entity` | `string\|array\|null` | no | `null` | Terms field (single or list) holding the entry's module. The handles are sent as `modules` in the request. Only the plain-language service uses this context; DeepL ignores it. |
| `scope` | `string\|array\|null` | no | `null` | Field **inside** sets or rows that limits a block to certain entities. The list travels as a `<div data-modules="…">` wrapper. Nested scopes are intersected with the outer one. |
| `sources_title`, `sources_text` | `string\|null` | no | `null` | **Deprecated.** Still loaded, read by nothing. |
| `podcast_link`, `podcast_code` | `string\|null` | no | `null` | **Deprecated.** Still loaded, read by nothing. |
| `sets` | `array` | no | `[]` | **Deprecated.** Format `type => [kind, field, extra]`, kind `html`, `heading`, `video`, `asset` or `code`. Still loaded, read by nothing. |

What the deprecated keys used to show, you now pick as a top-level field in `metadata.content_fields`.

## Which fields are content

The addon resolves an entry's content fields in this order:

1. **Workflow selection:** `metadata.content_fields`, filtered to the fields the entry's blueprint has. Order is kept, duplicates are dropped.
2. **Auto-detection:** `intro` and `body` from `content_fields` if the blueprint has them. Otherwise the first field of type `bard`, `markdown` or `replicator`.
3. **Nothing:** the entry has no content to show.

Only these field types can be picked in the workflow settings: `bard`, `replicator`, `grid`, `group`, `markdown`, `text`, `textarea`, `code`, `video`, `assets`.

## In the Control Panel

- **Content panel on the workflow detail page:** shows the resolved fields in both views, including the source column of a translation. Replicator, Grid, Group and Bard sets are walked generically. The preview is read-only and links to the entry editor.
- **Comments:** every preview block is a commentable passage. When its text changes, a "text changed" badge appears. Unread counts and mention mails use the same blocks.
- **Plain language:** sends exactly the resolved content fields, minus `translation_exclude_fields`, `seo` and `title`. It writes back `title`, the text blocks and the derived SEO texts.
- **Payload preview:** `…/instances/{id}/translation-payload` lists `leaves`, `content_fields` and `skipped`.
- **Workflow settings and "Create workflow":** the **Content** section sets `metadata.content_fields`. The linter warns when a collection auto-detects nothing (`content_field_auto_none`) or when picked fields are missing in some blueprints (`content_field_partial`).
- **History diff:** does **not** use `content_fields`. It compares event snapshots over all top-level fields.

## Examples

### Rename only the main field

Your project calls its Bard field `article_body`. All other defaults fit:

```php
'content_fields' => [
    'body' => 'article_body',
],
```

`title` and `seo` keep their defaults because keys are merged one by one.

### Limit a block to modules

Entries belong to modules via the terms field `entities`. Some sets have an `in_entities` field:

```php
'content_fields' => [
    'entity' => 'entities',
    'scope'  => 'in_entities',
],
```

The plain-language service receives the modules as `modules` and each limited block inside `<div data-modules="…">`.

## Notes & limitations

> [!NOTE]
> `content_fields` no longer decides *which* fields are content. `intro` and `body` only matter for workflows without `metadata.content_fields`.

> [!NOTE]
> The preview skips `toggle`, `select`, `link`, `entries`, taxonomy fields, and `hidden` and `computed` fields.

> [!TIP]
> `entity` and `scope` accept a single string (terms field with `max_items: 1`) or a list.

> [!WARNING]
> If a workflow lists only fields an entry's blueprint lacks, that entry falls back to auto-detection. This is intentional, and the linter warns about it.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| The content panel is empty. | No workflow selection, and auto-detection finds neither `intro`/`body` nor a Bard, Markdown or Replicator field. | Pick content fields in the **Content** section, or set `body` to the right handle. |
| A field cannot be picked in the **Content** section. | The field type is not supported. | Pick a field of a supported type (list above). |
| Linter warning `content_field_partial` | Picked fields are missing in some of the collection's blueprints. | Add the fields, or accept that those entries fall back to auto-detection. |

## See also

- [Translation & plain language](translation.md)
- [DOCS.md](../../../DOCS.md): §7 "Content-Preview: `EntryContentPreview`", "Config `content_fields`", §5.10 "Content-Preview & History-Diff", "Rückschreiben pro Textblatt (Format `sections`)"
- [README.md](../../../README.md): "Content fields (Inhaltsfelder)"
- [config/workflows.php](../../../config/workflows.php)
- [src/Support/ContentFields.php](../../../src/Support/ContentFields.php)
- [src/Content/EntryContentPreview.php](../../../src/Content/EntryContentPreview.php)
- [src/Content/ContentFieldResolver.php](../../../src/Content/ContentFieldResolver.php)
- [src/Content/ContentFieldCatalog.php](../../../src/Content/ContentFieldCatalog.php)
- [src/Translation/PlainLanguageLeafDocument.php](../../../src/Translation/PlainLanguageLeafDocument.php)
- [src/Translation/TranslationRunner.php](../../../src/Translation/TranslationRunner.php)

---

← [Dashboard widgets](dashboard-widgets.md) · [Overview](README.md) · [Translation & plain language](translation.md) →
