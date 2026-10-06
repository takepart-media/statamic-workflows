// The single registry of everything in a definition that names a place or a
// transition by handle. Renaming, clearing and "what points at this?" all go
// through it, so a new referencing key is added HERE once instead of being
// remembered in every rename/delete path.
//
// Each entry: { role, kind, label, slots(model) }
//   role   stable key for the reference ('initial_place', 'transition_from', …)
//   kind   what it references: 'place' | 'transition'
//   label  i18n key naming the field that holds the reference
//   slots  every concrete holder in `model`, as
//          { owner, values() → string[], replace(old, next|null) }
//          where owner is { kind: 'workflow' } | { kind: 'transition', handle }
//          | { kind: 'place', handle, index }.
//
// Works on the editor model (normalize()) as well as on a raw definition — every
// read is null-safe. The model is handle-keyed for now; the stable-id model will
// swap the slot implementations, not the API.

const L = (key) => `workflows::messages.${key}`;

// A slot for one scalar handle stored at `holder[key]`.
function scalarSlot(owner, holder, key) {
    return {
        owner,
        values: () => (holder && typeof holder[key] === 'string' && holder[key] !== '' ? [holder[key]] : []),
        replace(old, next) {
            if (holder && holder[key] === old) holder[key] = next;
        },
    };
}

// A slot for a transition's `from`, which is a handle or a list of handles
// (multi-source). In a list a cleared entry is removed rather than nulled.
function fromSlot(handle, transition) {
    return {
        owner: { kind: 'transition', handle },
        values: () => [].concat(transition?.from ?? []).filter((v) => typeof v === 'string' && v !== ''),
        replace(old, next) {
            if (Array.isArray(transition.from)) {
                if (!transition.from.includes(old)) return;
                transition.from = transition.from
                    .map((v) => (v === old ? next : v))
                    .filter((v) => v !== null && v !== undefined);
            } else if (transition.from === old) {
                transition.from = next;
            }
        },
    };
}

const workflow = { kind: 'workflow' };

function metadataSlot(key) {
    return (model) => [scalarSlot(workflow, model.metadata, key)];
}

export const REFERENCES = [
    // --- places ---
    {
        role: 'initial_place',
        kind: 'place',
        label: L('settings_field_initial_place'),
        slots: (model) => [scalarSlot(workflow, model, 'initial_place')],
    },
    {
        role: 'transition_from',
        kind: 'place',
        label: L('settings_field_from'),
        slots: (model) => Object.entries(model.transitions ?? {}).map(([h, t]) => fromSlot(h, t)),
    },
    {
        role: 'transition_to',
        kind: 'place',
        label: L('settings_field_to'),
        slots: (model) => Object.entries(model.transitions ?? {})
            .map(([h, t]) => scalarSlot({ kind: 'transition', handle: h }, t, 'to')),
    },
    {
        role: 'sla_needs_update_place',
        kind: 'place',
        label: L('sla_needs_update_place'),
        slots: (model) => [scalarSlot(workflow, model.metadata?.sla, 'needs_update_place')],
    },
    {
        role: 'sla_outdated_place',
        kind: 'place',
        label: L('sla_outdated_place'),
        slots: (model) => [scalarSlot(workflow, model.metadata?.sla, 'outdated_place')],
    },
    { role: 'seed_published_place', kind: 'place', label: L('seed_published_place'), slots: metadataSlot('seed_published_place') },
    { role: 'source_draft_place', kind: 'place', label: L('source_draft_place'), slots: metadataSlot('source_draft_place') },
    {
        role: 'plain_language_completed_place',
        kind: 'place',
        label: L('plain_language_completed_place'),
        slots: metadataSlot('plain_language_completed_place'),
    },
    {
        role: 'plain_language_failed_place',
        kind: 'place',
        label: L('plain_language_failed_place'),
        slots: metadataSlot('plain_language_failed_place'),
    },

    // --- transitions ---
    { role: 'create_transition', kind: 'transition', label: L('create_transition'), slots: metadataSlot('create_transition') },
    {
        // Every place's `auto_transition` action.
        role: 'auto_transition',
        kind: 'transition',
        label: L('action_auto_transition_transition_label'),
        slots: (model) => Object.entries(model.places ?? {}).flatMap(([h, place]) =>
            (Array.isArray(place?.metadata?.actions) ? place.metadata.actions : [])
                .map((action, index) => ({ action, index }))
                .filter(({ action }) => action?.type === 'auto_transition')
                .map(({ action, index }) => scalarSlot({ kind: 'place', handle: h, index }, action, 'transition')),
        ),
    },
];

export const PLACE_REFERENCE_ROLES = REFERENCES.filter((r) => r.kind === 'place').map((r) => r.role);
export const TRANSITION_REFERENCE_ROLES = REFERENCES.filter((r) => r.kind === 'transition').map((r) => r.role);

function entriesFor(kind, roles) {
    return REFERENCES.filter((r) => r.kind === kind && (!roles || roles.includes(r.role)));
}

/**
 * Everything that names `handle`, as [{ role, label, owner }].
 *
 * @param {object} model
 * @param {'place'|'transition'} kind
 * @param {string} handle
 */
export function referencesTo(model, kind, handle) {
    const found = [];
    for (const ref of entriesFor(kind)) {
        for (const slot of ref.slots(model)) {
            if (slot.values().includes(handle)) {
                found.push({ role: ref.role, label: ref.label, owner: slot.owner });
            }
        }
    }
    return found;
}

export const placeReferences = (model, handle) => referencesTo(model, 'place', handle);
export const transitionReferences = (model, handle) => referencesTo(model, 'transition', handle);

/**
 * Point every reference to `oldHandle` at `next` instead (null clears it; in a
 * multi-source `from` list the entry is removed). `roles` limits it to some
 * reference roles. Returns the number of slots changed.
 */
export function replaceReferences(model, kind, oldHandle, next, { roles = null } = {}) {
    let changed = 0;
    for (const ref of entriesFor(kind, roles)) {
        for (const slot of ref.slots(model)) {
            if (slot.values().includes(oldHandle)) {
                slot.replace(oldHandle, next);
                changed++;
            }
        }
    }
    return changed;
}

export const renamePlaceRefs = (model, oldHandle, newHandle, options) =>
    replaceReferences(model, 'place', oldHandle, newHandle, options);
export const renameTransitionRefs = (model, oldHandle, newHandle, options) =>
    replaceReferences(model, 'transition', oldHandle, newHandle, options);
export const clearPlaceRefs = (model, handle, options) =>
    replaceReferences(model, 'place', handle, null, options);
export const clearTransitionRefs = (model, handle, options) =>
    replaceReferences(model, 'transition', handle, null, options);

// Move `collection[oldKey]` to `collection[newKey]`. Appends, like the settings
// page always did — a renamed row is a new row at the end of the list.
function moveKey(collection, oldKey, newKey) {
    collection[newKey] = collection[oldKey];
    delete collection[oldKey];
}

// Rename a place and everything that references it.
export function renamePlace(model, oldHandle, newHandle) {
    moveKey(model.places, oldHandle, newHandle);
    renamePlaceRefs(model, oldHandle, newHandle);
}

// Rename a transition and everything that references it.
export function renameTransition(model, oldHandle, newHandle) {
    moveKey(model.transitions, oldHandle, newHandle);
    renameTransitionRefs(model, oldHandle, newHandle);
}
