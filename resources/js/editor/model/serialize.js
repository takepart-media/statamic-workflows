// Editor model → the payload the settings endpoint stores as YAML. Pure: the
// model is never mutated (except by the explicit handle helpers at the bottom,
// which exist to mutate it). Counterpart of normalize.js.
//
// Round-trip contract: metadata keys this module does not know about go through
// untouched — `metadata.editor` (node positions) included. Only the keys named
// below are reshaped or dropped.
//
//   ctx = {
//       customActions: { transition: [...], place: [...] },  // project action declarations
//       labelLocales: string[],
//       reservedPlaces: string[], reservedTransitions: string[],
//   }

import {
    CREDENTIAL_METADATA_KEYS,
    DEFAULT_MAIL_TYPE,
    DEFAULT_PROGRESS_ORIENTATION,
    DEFAULT_REMINDER_MAIL_TYPE,
    SLA_STAGES,
    TMP_PLACE_HANDLE,
    TMP_TRANSITION_HANDLE,
} from './constants.js';
import { numOrNull } from './normalize.js';
import { ownPlaceLabels } from './labels.js';
import { renamePlace, renameTransition } from './refs.js';

/**
 * The whole payload, minus request flags such as `confirm_collection_removal`.
 *
 * @returns {{places, transitions, metadata, collections, initial_place, marking_store, type}}
 */
export function serialize(model, ctx = {}) {
    // Clearing the colour/variant Selects leaves a null behind — drop those
    // keys so the YAML stays free of null-only entries and the place or
    // transition falls back to its built-in default.
    const places = cleanPlaceActions(stripEmptyMeta(serializePlaceLabels(model.places ?? {}), 'color'), ctx);
    const transitions = cleanTransitionActions(stripEmptyMeta(model.transitions ?? {}, 'variant'), ctx);

    return {
        places,
        transitions,
        metadata: serializeMetadata(model.metadata ?? {}),
        collections: model.collections ?? [],
        initial_place: model.initial_place,
        marking_store: model.marking_store,
        type: model.type,
    };
}

// The workflow-level metadata. Shallow copy: unknown keys (and
// `metadata.editor`) pass through as they are.
export function serializeMetadata(source) {
    const metadata = { ...source };
    // SLA collapsed: a stage goes along only as a COMPLETE pair. Days without a
    // step could never fire and a step without days would never be reached — the
    // writer refuses either half, so sending one would turn a half-filled form
    // into a save error instead of the "stage is off" the author meant. Nothing
    // left → drop the block, so the on-disk YAML doesn't grow a null-only key.
    const sla = source.sla ?? {};
    const stages = {};
    for (const stage of SLA_STAGES) {
        const days = numOrNull(sla[`${stage}_days`]);
        const place = sla[`${stage}_place`] || null;
        if (days !== null && place) {
            stages[`${stage}_days`] = days;
            stages[`${stage}_place`] = place;
            // Only a complete pick goes along: a type without anyone chosen
            // would assign nobody, and the writer refuses it.
            const assign = sla[`${stage}_assign`];
            if (assign?.by && assign.handles?.length) {
                stages[`${stage}_assign`] = { by: assign.by, handles: [...assign.handles] };
            }
        }
    }
    if (Object.keys(stages).length === 0) {
        delete metadata.sla;
    } else {
        metadata.sla = stages;
    }
    // Cleared Selects → an absent key rather than a null:
    // - source_draft_place: the version stays where it is when its source drops
    //   to draft;
    // - create_transition: the New-post button only creates;
    // - plain_language_site / translation_source_site: "no plain-language
    //   manager" / "translate from the origin";
    // - plain_language_completed_place: stays put until a human clicks
    //   submit_plain_language;
    // - plain_language_failed_place: the untranslated copy is deleted on a
    //   terminal failure instead of being parked.
    for (const key of [
        'source_draft_place',
        'seed_published_place',
        'create_transition',
        'plain_language_site',
        'translation_source_site',
        'plain_language_completed_place',
        'plain_language_failed_place',
    ]) {
        if (!metadata[key]) delete metadata[key];
    }
    // Skip rules: trimmed, non-empty, unique — and no key at all when there are none.
    metadata.plain_language_skip_fields = [...new Set((metadata.plain_language_skip_fields ?? [])
        .filter((rule) => typeof rule === 'string')
        .map((rule) => rule.trim())
        .filter(Boolean))];
    if (!metadata.plain_language_skip_fields.length) {
        delete metadata.plain_language_skip_fields;
    }
    // Only persist the free source pick where it is on — the writer rejects it in
    // a plain-language workflow, so writing `false` everywhere would make that
    // manager unsaveable.
    if (!metadata.translation_source_pick) {
        delete metadata.translation_source_pick;
    }
    // A rule without handles names nobody: dropped, not written. No rules at
    // all → no key, since "everyone sees both" is what an absent key means.
    metadata.translation_source_view = (metadata.translation_source_view ?? [])
        .filter((rule) => Array.isArray(rule?.handles) && rule.handles.length > 0)
        .map((rule) => ({ mode: rule.mode, by: rule.by, handles: [...rule.handles] }));
    if (!metadata.translation_source_view.length) {
        delete metadata.translation_source_view;
    }
    // Nur die Abweichung wandert in die YAML. `horizontal` ist der Standard des
    // Lesers (ProgressSteps::orientation()). Auch ein leerer Wert fällt heraus:
    // ein null oder '' würde der Writer mit „unbekannter Wert [NULL]" ablehnen.
    if (!metadata.progress_orientation || metadata.progress_orientation === DEFAULT_PROGRESS_ORIENTATION) {
        delete metadata.progress_orientation;
    }
    // Credentials never belong in this YAML.
    for (const key of CREDENTIAL_METADATA_KEYS) {
        delete metadata[key];
    }
    // An empty creator/assigner list is MEANINGFUL — it says "super admins only"
    // and must override the project config, so it is written as [] rather than
    // dropped. Dropping it would silently re-enable the config fallback.
    metadata.post_creator_groups = [...(metadata.post_creator_groups ?? [])];
    // Cleared picker → no default group, which is an absent key.
    if (!metadata.default_assignee_group) {
        delete metadata.default_assignee_group;
    }
    metadata.assigner_groups = [...(metadata.assigner_groups ?? [])];
    // The content fields in reading order. None picked means "automatic"
    // (ContentFieldCatalog::autoDetect()), which is an absent key — an empty
    // list would say the same in a noisier YAML.
    if (Array.isArray(metadata.content_fields) && metadata.content_fields.length) {
        metadata.content_fields = [...metadata.content_fields];
    } else if (Array.isArray(metadata.content_fields) || metadata.content_fields == null) {
        delete metadata.content_fields;
    }

    return metadata;
}

// Form → YAML: the filled languages as a map. With none filled, the legacy string
// goes back as it came (or nothing, for a place never named) — saving an
// untouched place must not change what it is called.
export function serializePlaceLabels(places) {
    const out = {};
    for (const [handle, place] of Object.entries(places)) {
        const { labels = {}, legacyLabel = '', ...rest } = place;
        const names = Object.fromEntries(
            Object.entries(labels)
                .map(([locale, value]) => [locale, (value ?? '').trim()])
                .filter(([, value]) => value),
        );
        if (Object.keys(names).length) {
            rest.label = names;
        } else if (legacyLabel) {
            rest.label = legacyLabel;
        }
        out[handle] = rest;
    }
    return out;
}

// Copy a keyed collection, dropping `metadata.<key>` where the value is empty.
// Keeps cleared Select values out of the persisted YAML.
export function stripEmptyMeta(target, key) {
    const out = {};
    for (const [handle, item] of Object.entries(target)) {
        const meta = { ...(item.metadata ?? {}) };
        if (meta[key] === null || meta[key] === '' || meta[key] === undefined) {
            delete meta[key];
        }
        out[handle] = { ...item, metadata: meta };
    }
    return out;
}

// Die Felddeklaration zu einem projekteigenen Typ, oder null für die eingebauten.
export function customActionFor(customActions, scope, type) {
    const list = (scope === 'place' ? customActions?.place : customActions?.transition) ?? [];

    return list.find((a) => a.value === type) ?? null;
}

// Was von einer Action auf die Platte geht. Für einen eingebauten Typ ohne
// Konfiguration bleibt es beim nackten `type`; ein projekteigener nimmt die Werte
// mit, aber nur die DEKLARIERTEN — ein Schlüssel, den die Config nicht mehr nennt
// (umbenannt, entfernt), verschwindet beim nächsten Speichern statt den Writer zu
// einer Ablehnung zu zwingen, die der Admin im Editor nicht auflösen könnte.
export function customActionEntry(customActions, scope, a) {
    const declared = customActionFor(customActions, scope, a.type);

    if (!declared) return { type: a.type };

    const values = {};

    for (const field of declared.fields ?? []) {
        const value = a.values?.[field.handle];
        const empty = value === null || value === undefined || value === ''
            || (Array.isArray(value) && value.length === 0);

        if (!empty) values[field.handle] = value;
    }

    return Object.keys(values).length ? { type: a.type, values } : { type: a.type };
}

export function isCriteriaGroup(node) {
    return !!node && Array.isArray(node.criteria);
}

// Spiegelt TransitionActions::criteriaList(): ein Kriterium ohne Wert geht nur
// als `matches_entry` durch, eine leer geräumte Gruppe fällt ganz weg — sie
// träfe auf niemanden zu, und der Writer lehnt sie ohnehin ab.
export function cleanCriteria(list, nested = false) {
    return (list ?? [])
        .map((node) => {
            // `nested` spiegelt die Ebenen-Grenze des Servers: innerhalb einer
            // Gruppe ist eine weitere Gruppe kein gültiger Eintrag.
            if (! nested && isCriteriaGroup(node)) {
                const inner = cleanCriteria(node.criteria, true);

                return inner.length ? { match: node.match ?? 'any', criteria: inner } : null;
            }

            if (!node?.field) return null;
            if (node.operator === 'matches_entry') return { field: node.field, operator: node.operator };
            if ((node.value ?? '') === '') return null;

            return { field: node.field, operator: node.operator ?? 'is', value: node.value };
        })
        .filter(Boolean);
}

// Write `actions` and nothing else: an empty list is dropped so the YAML stays
// free of empty keys, and the two pre-`actions` spellings go with it —
// normalize() already carried their meaning into the list, so leaving them would
// persist the same dialog twice under two names.
//
// Per-type whitelists, deliberately: a key that no type claims here never
// reaches the YAML, which is what keeps a stale `groups` from an old
// `user_modal` out of a definition that is now a `user_assignment`.
export function cleanTransitionActions(transitions, ctx = {}) {
    const out = {};

    for (const [handle, item] of Object.entries(transitions)) {
        const meta = { ...(item.metadata ?? {}) };
        delete meta.assign_from_group;
        delete meta.needs_site_pick;

        // Das Gate zurück in die zwei Schlüssel, die canApplyTransition() liest.
        // Immer nur einer von beiden — der Editor kennt einen Typ je Transition.
        const handles = (meta.required_handles ?? []).filter((h) => h !== '');
        delete meta.required_by;
        delete meta.required_handles;
        delete meta.required_group;
        delete meta.required_role;

        if (handles.length && item.metadata?.required_by === 'group') {
            meta.required_group = [...handles];
        } else if (handles.length && item.metadata?.required_by === 'role') {
            meta.required_role = [...handles];
        }
        // Superseded by the `plain_language_resubmit` action — normalize()
        // already folded a `true` boolean into the list.
        delete meta.plain_language_resubmit;

        const actions = (meta.actions ?? [])
            .filter((a) => a?.type)
            .map((a) => {
                if (a.type === 'user_assignment') {
                    return {
                        type: a.type,
                        mode: a.mode ?? 'manual',
                        match: a.match ?? 'all',
                        // A criterion without a value is only valid for
                        // `matches_entry` — an unfinished row of any other kind
                        // is dropped rather than sent for the writer to reject.
                        criteria: cleanCriteria(a.criteria),
                    };
                }
                if (a.type === 'target_missing') return { type: a.type, site: a.site ?? null };
                // Der einzige Schlüssel, den die Mail-Action trägt.
                if (a.type === 'mail') return { type: a.type, mail_type: a.mail_type ?? DEFAULT_MAIL_TYPE };

                return customActionEntry(ctx.customActions, 'transition', a);
            });

        if (actions.length) {
            meta.actions = actions;
        } else {
            delete meta.actions;
        }

        out[handle] = { ...item, metadata: meta };
    }

    return out;
}

// Place-side counterpart. `pending_rewrite_spinner` gets no dedicated case
// below (unlike `refresh_interval`): it carries no configuration, same as
// `start_localization` on the transition side.
export function cleanPlaceActions(places, ctx = {}) {
    const out = {};

    for (const [handle, item] of Object.entries(places)) {
        const meta = { ...(item.metadata ?? {}) };
        delete meta.pending_rewrite_spinner;

        // Nur das gesetzte Kennzeichen wandert in die YAML — der Leser
        // (ProgressSteps) prüft ohnehin auf `=== true`.
        if (!meta.branch) {
            delete meta.branch;
        }

        const actions = (meta.actions ?? [])
            .filter((a) => a?.type)
            .map((a) => {
                if (a.type === 'refresh_interval') return { type: a.type, minutes: a.minutes ?? null };
                if (a.type === 'mail') return { type: a.type, mail_type: a.mail_type ?? DEFAULT_MAIL_TYPE };
                if (a.type === 'reminder') return { type: a.type, every_days: a.every_days ?? null, mail_type: a.mail_type ?? DEFAULT_REMINDER_MAIL_TYPE };
                if (a.type === 'auto_transition') return { type: a.type, after_days: a.after_days ?? null, transition: a.transition ?? null };

                return customActionEntry(ctx.customActions, 'place', a);
            });

        if (actions.length) {
            meta.actions = actions;
        } else {
            delete meta.actions;
        }

        out[handle] = { ...item, metadata: meta };
    }

    return out;
}

// Letters spelled out rather than stripped — the same table as
// components/createWorkflowForm.js slugifyHandle().
const TRANSLIT = { ä: 'ae', ö: 'oe', ü: 'ue', ß: 'ss', æ: 'ae', ø: 'oe', å: 'aa', œ: 'oe' };

// Slugify: lowercase, umlauts spelled out ("Prüfung" → "pruefung"), other
// diacritics stripped, non-alphanumeric → underscore, collapse & trim. The
// transliteration has to come first: NFKD splits "ü" into "u" + a combining
// mark, so after stripping there is no "ü" left to spell out.
// Same shape as the YAML handles the editor already accepts.
export function slugify(value) {
    return String(value ?? '')
        .toLowerCase()
        .normalize('NFC')
        .replace(/[äöüßæøåœ]/g, (c) => TRANSLIT[c] ?? c)
        .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')  // strip remaining diacritics
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

// --- handles of new, not-yet-saved rows ---
// `pending` = { places: {tmpOrHandle: typedHandle}, transitions: {…} } — the
// rows added this session, keyed by their current key, valued with what the
// author typed into the handle field.

// Move a new place to the handle typed for it, taking every reference along.
// A taken or reserved handle resets the input instead.
export function commitPlaceHandle(model, pending, oldHandle, { reservedPlaces = [] } = {}) {
    const desired = (pending.places[oldHandle] || '').trim();
    if (!desired || desired === oldHandle) return;
    if (Object.prototype.hasOwnProperty.call(model.places, desired) || reservedPlaces.includes(desired)) {
        pending.places[oldHandle] = oldHandle;
        return;
    }
    renamePlace(model, oldHandle, desired);
    delete pending.places[oldHandle];
    pending.places[desired] = desired;
}

export function commitTransitionHandle(model, pending, oldHandle, { reservedTransitions = [] } = {}) {
    const desired = (pending.transitions[oldHandle] || '').trim();
    if (!desired || desired === oldHandle) return;
    if (Object.prototype.hasOwnProperty.call(model.transitions, desired) || reservedTransitions.includes(desired)) {
        pending.transitions[oldHandle] = oldHandle;
        return;
    }
    renameTransition(model, oldHandle, desired);
    delete pending.transitions[oldHandle];
    pending.transitions[desired] = desired;
}

// Called before serializing so new-but-uncommitted rows (`Date.now()`-tmp handle
// in place, author never blurred out of the handle input) still land under a
// sensible key. Auto-slugs from the label when the handle field was left empty
// — otherwise the timestamp-tmp would silently make it into the YAML.
export function finalizeNewHandles(model, pending, ctx = {}) {
    for (const tmp of [...Object.keys(pending.places)]) {
        if (!(pending.places[tmp] || '').trim()) {
            const slug = slugify(ownPlaceLabels(model, tmp, ctx)[0] ?? '');
            if (slug) pending.places[tmp] = slug;
        }
        commitPlaceHandle(model, pending, tmp, ctx);
    }
    for (const tmp of [...Object.keys(pending.transitions)]) {
        if (!(pending.transitions[tmp] || '').trim()) {
            const slug = slugify(model.transitions[tmp]?.metadata?.label ?? '');
            if (slug) pending.transitions[tmp] = slug;
        }
        commitTransitionHandle(model, pending, tmp, ctx);
    }
}

// The new rows still sitting on a tmp key — the author left both handle and
// label empty. Saving must stop rather than persist a timestamp.
export function danglingHandles(pending) {
    return [
        ...Object.keys(pending.places).filter((h) => TMP_PLACE_HANDLE.test(h)),
        ...Object.keys(pending.transitions).filter((h) => TMP_TRANSITION_HANDLE.test(h)),
    ];
}
