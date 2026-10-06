/**
 * Framework-free helpers for CreateWorkflowModal: deriving a handle from the
 * typed name, the client-side handle check, and mapping a 422 from
 * `POST workflows/api/settings/create` onto the modal's fields.
 *
 * The server (WorkflowScaffolder::validateHandle) checks everything again and
 * alone knows the full truth — these only spare the round trip for the common
 * mistakes and keep the "create" button honest.
 */

const TRANSLIT = { ä: 'ae', ö: 'oe', ü: 'ue', ß: 'ss', æ: 'ae', ø: 'oe', å: 'aa', œ: 'oe' };

/**
 * "Übersetzung (EN)" → "uebersetzung_en". Always matches ^[a-z][a-z0-9_]*$ or
 * is empty (nothing usable typed yet, or only digits/symbols).
 */
export function slugifyHandle(text, max = 64) {
    // NFC first: a decomposed "u + ¨" must still be spelled out as "ue".
    let s = String(text ?? '').toLowerCase().normalize('NFC');
    s = s.replace(/[äöüßæøåœ]/g, (c) => TRANSLIT[c] ?? c);
    s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
    s = s.replace(/[^a-z0-9]+/g, '_');
    // A handle starts with a letter: drop leading digits/underscores.
    s = s.replace(/^[^a-z]+/, '');
    s = s.replace(/_+/g, '_');
    s = s.slice(0, max).replace(/_+$/, '');

    return s;
}

/**
 * `base`, or `base_2`, `base_3` … — the first one not in `taken` (lowercased
 * handles) and not reserved, within `max` characters. Used only while the
 * handle is still derived from the name, so picking an installed template does
 * not open the modal on a collision error.
 */
export function uniqueHandle(base, taken, reserved = [], max = 64) {
    if (!base) return base;
    const blocked = (h) => taken.has(h.toLowerCase()) || reserved.includes(h.toLowerCase());
    if (!blocked(base)) return base;

    for (let i = 2; i < 1000; i++) {
        const suffix = `_${i}`;
        const candidate = base.slice(0, max - suffix.length).replace(/_+$/, '') + suffix;
        if (!blocked(candidate)) return candidate;
    }

    return base;
}

/**
 * The first problem with a handle, as a lang key (the same keys the server
 * answers with) — or null. `required` for an empty one.
 */
export function handleProblem(handle, { pattern, max, reserved = [], taken = new Set() }) {
    const h = String(handle ?? '');
    if (h === '') return 'required';
    if (h.length > max || !new RegExp(pattern).test(h)) return 'create_handle_invalid';
    if (reserved.map((r) => String(r).toLowerCase()).includes(h.toLowerCase())) return 'create_handle_reserved';
    if (taken.has(h.toLowerCase())) return 'create_handle_taken';

    return null;
}

/**
 * Laravel-style 422 errors → the modal's fields. `collections.N` collapses onto
 * `collections`; `definition.<path>` and anything the modal has no field for
 * end up in `general` (definition problems preferably from the richer
 * `definition_errors` list, which carries the path).
 */
export function mapCreateErrors(body) {
    const fields = {};
    const general = [];
    const known = ['handle', 'label', 'from', 'template', 'copy_of', 'collections'];
    const errors = body?.errors && typeof body.errors === 'object' ? body.errors : {};
    const definitionList = Array.isArray(body?.definition_errors) ? body.definition_errors : null;

    for (const [key, messages] of Object.entries(errors)) {
        const list = Array.isArray(messages) ? messages : [messages];
        const field = key.startsWith('collections.') ? 'collections' : key;

        if (known.includes(field)) {
            (fields[field] ??= []).push(...list);
        } else if (key.startsWith('definition.')) {
            if (!definitionList) {
                const path = key.slice('definition.'.length);
                list.forEach((message) => general.push({ path, message }));
            }
        } else {
            list.forEach((message) => general.push({ path: key, message }));
        }
    }

    if (definitionList) {
        definitionList
            // A name clash the writer found is mapped to `handle` server-side.
            .filter((e) => e?.path !== 'name')
            .forEach((e) => general.push({ path: e?.path ?? '', message: e?.message ?? '' }));
    }

    if (!Object.keys(fields).length && !general.length && body?.message) {
        general.push({ path: '', message: body.message });
    }

    return { fields, general };
}
