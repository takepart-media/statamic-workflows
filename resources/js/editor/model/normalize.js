// YAML definition → the shape the editor binds to. Framework-free and pure: the
// input is deep-copied, never mutated. The counterpart is serialize.js; the two
// together must round-trip (unknown metadata keys and `metadata.editor` included).
//
// The model is keyed by handle, exactly like the YAML.

import { ASSIGNMENT_MATCHES, ASSIGNMENT_MODES, DEFAULT_MAIL_TYPE, SLA_STAGES } from './constants.js';

export const DEFAULT_LABEL_LOCALES = ['de', 'en'];

/**
 * @param {object} def          the definition as the server sends it
 * @param {object} [options]
 * @param {string[]} [options.labelLocales]  languages a place is named in
 *                                            (DefinitionLoader::LABEL_LOCALES)
 * @returns {object} the editor model
 */
export function normalize(def, { labelLocales = DEFAULT_LABEL_LOCALES } = {}) {
    const copy = JSON.parse(JSON.stringify(def || {}));
    copy.metadata ??= {};
    copy.metadata.nav ??= {};
    // Bind the checkbox to a real boolean — an absent YAML key would leave the
    // Checkbox in an undefined (indeterminate) state.
    copy.metadata.assign_creator ??= false;
    // SLA block always present in the form; serialize() drops it when both
    // fields are empty so the YAML stays clean.
    copy.metadata.sla ??= {};
    copy.metadata.sla.needs_update_days = numOrNull(copy.metadata.sla.needs_update_days);
    copy.metadata.sla.outdated_days = numOrNull(copy.metadata.sla.outdated_days);
    // Bind the Selects to null rather than undefined, so clearing one is a real
    // value the save whitelist can see instead of a missing key.
    copy.metadata.sla.needs_update_place = copy.metadata.sla.needs_update_place || null;
    copy.metadata.sla.outdated_place = copy.metadata.sla.outdated_place || null;
    // Who takes over per stage: always an object, so the two pickers have a model.
    for (const stage of SLA_STAGES) {
        const assign = copy.metadata.sla[`${stage}_assign`];
        copy.metadata.sla[`${stage}_assign`] = {
            by: assign?.by ?? null,
            handles: [...(assign?.handles ?? [])],
        };
    }
    // The self-assignment guard: always an object, so the picker has a model;
    // `by: null` is "off" and serialize() drops it.
    const selfAssignment = copy.metadata.self_assignment;
    copy.metadata.self_assignment = {
        by: selfAssignment?.by ?? null,
        handles: [...(selfAssignment?.handles ?? [])],
        hint: selfAssignment?.hint ?? '',
    };
    // Who may publish a working copy: always a list of {by, handles} rows.
    copy.metadata.revision_publishers = Array.isArray(copy.metadata.revision_publishers)
        ? copy.metadata.revision_publishers.map((row) => ({ by: row?.by ?? 'group', handles: [...(row?.handles ?? [])] }))
        : [];
    // The four-eyes guard, same shape.
    const fourEyes = copy.metadata.four_eyes;
    copy.metadata.four_eyes = {
        by: fourEyes?.by ?? null,
        handles: [...(fourEyes?.handles ?? [])],
        hint: fourEyes?.hint ?? '',
    };
    copy.metadata.source_draft_place = copy.metadata.source_draft_place || null;
    // Bind the Select to null rather than undefined, so clearing it is a real
    // value the save step can recognise and drop. Same reason for the
    // plain-language site.
    copy.metadata.seed_published_place ??= null;
    copy.metadata.create_transition ??= null;
    copy.metadata.plain_language_site ??= null;
    copy.metadata.plain_language_completed_place ??= null;
    copy.metadata.plain_language_failed_place ??= null;
    // A real list for the comma-separated input; serialize() drops it when empty.
    copy.metadata.plain_language_skip_fields = Array.isArray(copy.metadata.plain_language_skip_fields)
        ? [...copy.metadata.plain_language_skip_fields]
        : [];
    copy.metadata.translation_source_site ??= null;
    // Real boolean for the Checkbox — an absent YAML key would leave it
    // indeterminate.
    copy.metadata.translation_source_pick ??= false;
    // The rule list needs a real array to push into; each rule's handles too.
    copy.metadata.translation_source_view = Array.isArray(copy.metadata.translation_source_view)
        ? copy.metadata.translation_source_view.map((rule) => ({ ...rule, handles: [...(rule?.handles ?? [])] }))
        : [];
    // Der Select hat keinen leeren Zustand — ein fehlender Schlüssel bedeutet
    // „waagerecht", also wird das hier auch hingeschrieben. serialize() nimmt es
    // beim Standard wieder heraus, damit die YAML nicht mit dem Offensichtlichen
    // volläuft.
    copy.metadata.progress_orientation ??= 'horizontal';
    // Multiselect needs a real array to push into.
    copy.metadata.post_creator_groups ??= [];
    // Bind the Select to null rather than an empty string or undefined;
    // serialize() drops the key when cleared.
    copy.metadata.default_assignee_group ||= null;
    copy.metadata.assigner_groups ??= [];
    // Bind the multiselect to a real array — an absent YAML key would leave the
    // Select without a model to push into.
    copy.collections ??= [];
    copy.places ??= {};
    copy.transitions ??= {};
    for (const key of Object.keys(copy.places)) {
        copy.places[key].metadata ??= {};
        // Retired — see the place card. Dropped on load so nothing binds to it and
        // serialize() cannot write it back.
        delete copy.places[key].metadata.required_group;
        // Echtes Boolean für die Checkbox — ein fehlender YAML-Schlüssel ließe sie
        // im unbestimmten Zustand stehen. Dieselbe Behandlung wie die
        // Workflow-Booleans oben.
        copy.places[key].metadata.branch ??= false;
        copy.places[key].metadata.actions = toPlaceActionArray(copy.places[key].metadata);
        // `label` is a per-language map (what this editor writes) or a legacy
        // string. The form edits the map; the legacy string rides along untouched
        // and is only replaced once the author names the place — see
        // serializePlaceLabels().
        const label = copy.places[key].label;
        copy.places[key].labels = emptyLabels(labelLocales);
        if (label && typeof label === 'object') {
            for (const locale of labelLocales) {
                copy.places[key].labels[locale] = typeof label[locale] === 'string' ? label[locale] : '';
            }
        }
        copy.places[key].legacyLabel = typeof label === 'string' ? label : '';
        delete copy.places[key].label;
    }
    for (const key of Object.keys(copy.transitions)) {
        copy.transitions[key].metadata ??= {};
        // Die zwei gespeicherten Gate-Schlüssel in das eine Formular-Paar falten.
        // Rolle gewinnt, wenn beide dastehen: das kann nur eine handgeschriebene
        // YAML sein, und dort ist die Rolle die speziellere Aussage. Beim
        // Speichern bleibt dann nur noch sie übrig — siehe cleanTransitionActions().
        const rollen = toGroupArray(copy.transitions[key].metadata.required_role);
        const gruppen = toGroupArray(copy.transitions[key].metadata.required_group);
        copy.transitions[key].metadata.required_by = rollen.length ? 'role' : (gruppen.length ? 'group' : 'none');
        copy.transitions[key].metadata.required_handles = rollen.length ? rollen : gruppen;
        copy.transitions[key].metadata.actions = toActionArray(copy.transitions[key].metadata);
    }
    return copy;
}

// The editor always works on a list, whichever spelling the YAML uses. Two older
// ones still turn up: `type: user_modal` with a `groups` list, and — older still
// — the ad-hoc key `assign_from_group`. Reading them here (and dropping them in
// serialize()) is what migrates a definition the first time somebody saves it.
// Mirrors Workflow\TransitionActions on the server; the two have to agree on what
// a definition means.
//
// The legacy `needs_site_pick` is deliberately NOT read into an action. The
// language dialog follows whichever transition creates the target version
// (`start_localization`) and the writer rejects a stored `site_modal` outright —
// turning the old flag into one would have made those definitions unsaveable.
// serialize() still deletes the key.
export function toActionArray(metadata) {
    let actions;

    if (Array.isArray(metadata.actions)) {
        actions = metadata.actions
            .filter((a) => a && typeof a === 'object')
            .map((a) => toAction(a));
    } else {
        actions = [];
        if (metadata.assign_from_group) {
            actions.push(toAction({ type: 'user_modal', groups: metadata.assign_from_group }));
        }
    }

    // The solo boolean this used to be, still honoured until the next save
    // rewrites it as a proper action — same tolerance TransitionActions
    // documents for `assign_from_group` above.
    if (metadata.plain_language_resubmit && ! actions.some((a) => a.type === 'plain_language_resubmit')) {
        actions.push(toAction({ type: 'plain_language_resubmit' }));
    }

    return actions;
}

// Der gespeicherte Mail-Typ, beide Schlüssel tolerierend — Zwilling von
// MailAction::storedType(). Der Standard steht hier und nicht erst beim Speichern,
// damit das Auswahlfeld nie leer dasteht.
export function storedMailType(a) {
    for (const key of ['mail_type', 'mail']) {
        if (typeof a[key] === 'string' && a[key] !== '') return a[key];
    }

    return DEFAULT_MAIL_TYPE;
}

// One stored action in the shape the form binds to. A `user_modal` becomes a
// manual assignment whose criteria are its groups — one `group is <handle>`
// each, joined by `any`, which is exactly what "the union of the members of
// these groups" meant. Mirrors TransitionActions::normalise().
export function toAction(a) {
    const raw = a.type ?? 'user_assignment';
    // Zwei Alt-Schreibweisen, je eine Zeile: `user_modal` wird eine manuelle
    // Zuweisung (unten), `notify_assignees` wird `mail`. Spiegelt
    // MailAction::handle() auf dem Server.
    const type = raw === 'user_modal' ? 'user_assignment' : (raw === 'notify_assignees' ? 'mail' : raw);

    const action = {
        type,
        site: typeof a.site === 'string' && a.site !== '' ? a.site : null,
        // Der ältere Schlüssel hieß `mail`. Fehlen beide, ist es eine Mail-Action
        // von vor dem Auswahlfeld — und die meinte die Zuweisungs-Mail. Spiegelt
        // MailAction::storedType() und MailTypes::FALLBACK auf dem Server.
        mail_type: storedMailType(a),
        mode: null,
        match: null,
        criteria: [],
        // Der Beutel für projekteigene Typen — spiegelt
        // TransitionActions::normalise() auf dem Server. Roh übernommen; welche
        // Schlüssel bleiben, entscheidet beim Speichern die Deklaration.
        values: a.values && typeof a.values === 'object' ? { ...a.values } : {},
    };

    if (type !== 'user_assignment') return action;

    if (a.type === 'user_modal') {
        return {
            ...action,
            mode: 'manual',
            match: 'any',
            criteria: toGroupArray(a.groups ?? a.group).map((value) => ({
                field: 'group',
                operator: 'is',
                value,
            })),
        };
    }

    return {
        ...action,
        mode: ASSIGNMENT_MODES.includes(a.mode) ? a.mode : 'manual',
        match: ASSIGNMENT_MATCHES.includes(a.match) ? a.match : 'all',
        criteria: toCriteria(a.criteria),
    };
}

// Der Ladepfad für die Kriterien-Liste, Gegenstück zu cleanCriteria() beim
// Speichern und zu TransitionActions::criteriaList() auf dem Server.
//
// Die Gruppen-Zeile ist der Grund, warum das eine eigene Funktion ist: sie trägt
// kein `field`, und der Filter davor prüfte genau darauf. Eine gespeicherte
// Gruppe verschwand deshalb beim Einlesen der Definition wieder aus dem Formular
// — und der nächste Speichervorgang schrieb sie endgültig weg.
export function toCriteria(list, nested = false) {
    return (Array.isArray(list) ? list : [])
        .filter((c) => c && typeof c === 'object')
        .map((c) => {
            if (! nested && Array.isArray(c.criteria)) {
                const inner = toCriteria(c.criteria, true);

                return inner.length
                    ? { match: ASSIGNMENT_MATCHES.includes(c.match) ? c.match : 'any', criteria: inner }
                    : null;
            }

            if (typeof c.field !== 'string' || c.field === '') return null;

            return {
                field: c.field,
                operator: typeof c.operator === 'string' && c.operator !== '' ? c.operator : 'is',
                value: c.value === null || c.value === undefined || c.value === '' ? null : String(c.value),
            };
        })
        .filter(Boolean);
}

// Place-side counterpart: folds the solo boolean `pending_rewrite_spinner`
// used to be into the list form — see PlaceActions::fromLegacyKeys() on the
// server, which the settings editor has to agree with on what a definition
// means.
export function toPlaceActionArray(metadata) {
    if (Array.isArray(metadata.actions)) {
        return metadata.actions
            .filter((a) => a && typeof a === 'object' && typeof a.type === 'string')
            .map((a) => ({
                // Alt-Schreibweise gefaltet, wie auf der Transition-Seite.
                type: a.type === 'notify_assignees' ? 'mail' : a.type,
                minutes: Number.isFinite(Number(a.minutes)) && a.minutes !== null && a.minutes !== '' ? Number(a.minutes) : null,
                every_days: Number.isFinite(Number(a.every_days)) && a.every_days !== null && a.every_days !== '' ? Number(a.every_days) : null,
                after_days: Number.isFinite(Number(a.after_days)) && a.after_days !== null && a.after_days !== '' ? Number(a.after_days) : null,
                transition: typeof a.transition === 'string' && a.transition !== '' ? a.transition : null,
                mail_type: a.type === 'reminder' && !a.mail_type ? 'reminder' : storedMailType(a),
                values: a.values && typeof a.values === 'object' ? { ...a.values } : {},
            }));
    }

    if (metadata.pending_rewrite_spinner === true) {
        return [{ type: 'pending_rewrite_spinner', minutes: null, mail_type: DEFAULT_MAIL_TYPE, values: {} }];
    }

    return [];
}

// Legacy YAML kept `required_group` as either a scalar string or a list.
// The Combobox in multi-select mode only understands arrays, so we coerce
// on load (and on server-returned payloads).
export function toGroupArray(value) {
    if (value === null || value === undefined || value === '') return [];
    if (Array.isArray(value)) return value.map(String).filter((v) => v !== '');
    return [String(value)];
}

// --- place names per language ---
export function emptyLabels(labelLocales = DEFAULT_LABEL_LOCALES) {
    return Object.fromEntries(labelLocales.map((locale) => [locale, '']));
}

// A non-negative finite number, or null for anything else (empty input included).
export function numOrNull(value) {
    if (value === '' || value === null || value === undefined) return null;
    const n = Number(value);
    return Number.isFinite(n) && n >= 0 ? n : null;
}
