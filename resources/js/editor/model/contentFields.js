// `metadata.content_fields` — which top-level blueprint fields are a workflow's
// content, in reading order. Pure helpers for the settings section and the
// create modal; the field lists come from the server (ContentFieldCatalog):
//
//   options = { <collection>: [{handle, label, type, supported, blueprints}] }
//   auto    = { <collection>: <handle> | null }
//
// An empty list means "automatic": each collection reads what auto-detection
// picks for it.

/**
 * The fields a workflow on `collections` can pick: every SUPPORTED field of any
 * of them, deduplicated by handle in collection order. `collections` lists
 * where a field exists, `onlyIn` is that list when it is not all of them
 * (empty otherwise).
 *
 * @returns {Array<{handle, label, type, collections: string[], onlyIn: string[]}>}
 */
export function contentFieldChoices(options, collections) {
    const selected = (collections ?? []).filter((c) => typeof c === 'string' && c !== '');
    const byHandle = new Map();

    for (const collection of selected) {
        for (const field of options?.[collection] ?? []) {
            if (!field?.supported) continue;
            const entry = byHandle.get(field.handle);
            if (entry) {
                if (!entry.collections.includes(collection)) entry.collections.push(collection);
            } else {
                byHandle.set(field.handle, {
                    handle: field.handle,
                    label: field.label || field.handle,
                    type: field.type,
                    collections: [collection],
                });
            }
        }
    }

    return [...byHandle.values()].map((field) => ({
        ...field,
        onlyIn: field.collections.length < selected.length ? [...field.collections] : [],
    }));
}

/**
 * Options for a multi-select: "Label (handle · type)", plus `onlyInText` for a
 * field missing from some of the collections. Stored handles that are no
 * longer a choice stay listed (`stale: true`) — otherwise the Select would
 * silently hide them while the YAML still has them.
 *
 * @param {Function} [onlyInText]  (collections: string[]) → suffix
 * @param {Function} [staleText]   (handle) → label of a stale value
 */
export function contentFieldSelectOptions(options, collections, current = [], { onlyInText = null, staleText = null } = {}) {
    const choices = contentFieldChoices(options, collections);
    const list = choices.map((field) => {
        const base = `${field.label} (${field.handle} · ${field.type})`;
        return {
            value: field.handle,
            label: field.onlyIn.length && onlyInText ? `${base} — ${onlyInText(field.onlyIn)}` : base,
        };
    });
    for (const handle of current ?? []) {
        if (typeof handle === 'string' && !choices.some((c) => c.handle === handle)) {
            list.push({ value: handle, label: staleText ? staleText(handle) : handle, stale: true });
        }
    }

    return list;
}

/**
 * Per selected collection, what auto-detection reads when no field is picked:
 * `{collection, handle, label}` — handle/label null when nothing qualifies.
 */
export function autoContentFields(options, auto, collections) {
    return (collections ?? [])
        .filter((c) => typeof c === 'string' && c !== '')
        .map((collection) => {
            const handle = auto?.[collection] ?? null;
            const field = handle ? (options?.[collection] ?? []).find((f) => f.handle === handle) : null;
            return { collection, handle, label: handle ? (field?.label || handle) : null };
        });
}

/**
 * Options for the plain-language intro (`metadata.plain_language_intro_field`):
 * the picked content fields in reading order, labelled like the content-field
 * select. A stored intro that is no longer a picked content field stays listed
 * (`stale: true`) so the Select does not hide what the YAML still says — the
 * rule (rules.js) reports it.
 *
 * @param {Array<{value, label}>} selectOptions  contentFieldSelectOptions() result
 * @param {string[]} contentFields               the picked content fields
 * @param {string|null} current                  the stored intro
 */
export function introFieldSelectOptions(selectOptions, contentFields, current = null) {
    const labels = new Map((selectOptions ?? []).map((option) => [option.value, option.label]));
    const list = (contentFields ?? [])
        .filter((handle) => typeof handle === 'string' && handle !== '')
        .map((handle) => ({ value: handle, label: labels.get(handle) ?? handle }));
    if (typeof current === 'string' && current !== '' && !list.some((o) => o.value === current)) {
        list.push({ value: current, label: current, stale: true });
    }

    return list;
}
