// How places and transitions are named in the editor. Pure functions of the
// (handle-keyed) model plus a small context object:
//
//   ctx = {
//       labelLocales: string[],        // languages a place is named in
//       labelLocale: string,           // the CP's own language
//       shippedPlaceLabels: object,    // handle → locale → language-file wording | null
//       humanize: (handle) => string,  // last-resort name (states.js humanize())
//   }

import { DEFAULT_LABEL_LOCALES } from './normalize.js';

// The names the author typed, in `labelLocales` order.
export function ownPlaceLabels(model, handle, { labelLocales = DEFAULT_LABEL_LOCALES } = {}) {
    const labels = model.places?.[handle]?.labels ?? {};
    return labelLocales.map((locale) => (labels[locale] ?? '').trim()).filter(Boolean);
}

// What the place is called in `locale` while that field is empty — the same order
// as DefinitionLoader::stateLabel() below the author's own name: the language
// file, then another name the author gave (the other language, or the legacy
// string while there is none), then the handle.
export function placeLabelFallback(model, handle, locale, ctx = {}) {
    const own = ownPlaceLabels(model, handle, ctx);
    const humanize = ctx.humanize ?? ((value) => value);
    return ctx.shippedPlaceLabels?.[handle]?.[locale]
        || (own.length ? own[0] : model.places?.[handle]?.legacyLabel)
        || humanize(handle);
}

// The name the CP shows right now, in its own language.
export function placeDisplayName(model, handle, ctx = {}) {
    return (model.places?.[handle]?.labels?.[ctx.labelLocale] ?? '').trim()
        || placeLabelFallback(model, handle, ctx.labelLocale, ctx);
}

// Mirrors WorkflowController::transitionVariant() — the variant an action button
// gets when the transition declares none. Rejecting is destructive to the
// editorial flow, so it looks like it; everything else stays primary.
export function defaultVariant(handle) {
    return handle === 'reject' ? 'danger' : 'primary';
}
