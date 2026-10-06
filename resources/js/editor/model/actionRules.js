// Which actions a transition or place may carry, given the rest of the
// definition. Pure functions of (model, …, ctx). The option lists come in through
// `ctx` because their labels are translated by the caller; without them the
// built-in types are used with their handle as label.
//
//   ctx = {
//       actionTypeOptions: [{value, label}],       // transition types incl. project ones
//       placeActionTypeOptions: [{value, label}],  // place types incl. project ones
//   }

import { ACTION_TYPES, DIALOG_ACTION_TYPES, PLACE_ACTION_TYPES } from './constants.js';

const asOptions = (values) => values.map((value) => ({ value, label: value }));

// Localization workflows (metadata.detail === 'translation') distribute existing
// entries to more sites instead of creating new ones.
export function isLocalizationWorkflow(model) {
    return model.metadata?.detail === 'translation';
}

// Which KIND of localization manager this is. Mirrors
// WorkflowResolver::isPlainLanguageWorkflow(): claiming a target site via
// `plain_language_site` is what makes a workflow the plain-language manager.
export function isPlainLanguageWorkflow(model) {
    return isLocalizationWorkflow(model) && !!model.metadata?.plain_language_site;
}

// True when at least one place declares `allow_publish: true`.
export function hasPublishPlace(model) {
    return Object.values(model.places ?? {}).some((place) => !!place?.metadata?.allow_publish);
}

// The DEFAULT start of the flow: the first card. Mirrors
// TransitionActions::startingTransition().
export function startingTransition(model) {
    return Object.keys(model.transitions ?? {})[0] ?? null;
}

// The transition an explicit `start_localization` action pins as the one that
// creates the target version, or null when none does. Mirrors
// TransitionActions::explicitStartTransition().
export function explicitStartTransition(model) {
    for (const [handle, transition] of Object.entries(model.transitions ?? {})) {
        if ((transition?.metadata?.actions ?? []).some((a) => a.type === 'start_localization')) {
            return handle;
        }
    }

    return null;
}

// "Rewrite again" is only meaningful in the plain-language manager, and not on the
// transition that creates the version in the first place — wherever that is.
// (What TransitionActions::startsLocalization() resolves to is the explicit
// marker, and nothing else — no positional fallback.)
export function canResubmitPlainLanguage(model, handle) {
    return isPlainLanguageWorkflow(model) && explicitStartTransition(model) !== handle;
}

// Whether some OTHER action (than `current`) in the whole workflow already
// carries `start_localization` — there is exactly one place in the diagram that
// can mean (WorkflowDefinitionWriter enforces the same cap on save).
export function startLocalizationUsedElsewhere(model, current) {
    return Object.values(model.transitions ?? {}).some((t) =>
        (t?.metadata?.actions ?? []).some((a) => a !== current && a.type === 'start_localization'),
    );
}

// The type Select's options for one row of transition `handle`: every type, minus
// whatever is already unavailable — `user_assignment` when some OTHER row on the
// SAME transition already carries one (the server reads the first; the writer
// rejects a second), `start_localization` outside a localization workflow or when
// ANY OTHER transition already has one, `plain_language_resubmit` when this
// transition already carries one or can't use it at all, `mail` once per
// transition. Excluding rather than disabling the option — a stray disabled entry
// sitting in a dropdown reads as a bug, not a rule.
export function actionTypeOptionsFor(model, handle, current, ctx = {}) {
    const transition = model.transitions?.[handle];
    const actions = transition?.metadata?.actions ?? [];
    const dialogTaken = actions.some((a) => a !== current && DIALOG_ACTION_TYPES.includes(a.type));
    const startTaken = startLocalizationUsedElsewhere(model, current);
    const resubmitTaken = actions.some((a) => a !== current && a.type === 'plain_language_resubmit');
    // Wie `resubmitTaken`, nur ohne Workflow-Bedingung: mailen lässt sich in jedem
    // Workflow, aber je Transition nur einmal.
    const mailTaken = actions.some((a) => a !== current && a.type === 'mail');
    const localization = isLocalizationWorkflow(model);

    return (ctx.actionTypeOptions ?? asOptions(ACTION_TYPES)).filter((o) => {
        if (dialogTaken && DIALOG_ACTION_TYPES.includes(o.value)) return false;
        if (o.value === 'start_localization' && (startTaken || !localization)) return false;
        if (o.value === 'plain_language_resubmit' && (resubmitTaken || !canResubmitPlainLanguage(model, handle))) return false;
        if (o.value === 'mail' && mailTaken) return false;

        return true;
    });
}

// Same shape as actionTypeOptionsFor(), for the smaller place type set: exclude
// a type already used by another action on the SAME place, and hide
// `pending_rewrite_spinner` outside the plain-language manager — the writer
// rejects it there. `place` is the place's handle or the place itself.
export function placeActionTypeOptionsFor(model, place, current, ctx = {}) {
    if (typeof place === 'string') place = model.places?.[place];
    const taken = new Set(
        (place?.metadata?.actions ?? []).filter((a) => a !== current).map((a) => a.type),
    );
    const plainLanguage = isPlainLanguageWorkflow(model);

    return (ctx.placeActionTypeOptions ?? asOptions(PLACE_ACTION_TYPES)).filter((o) => {
        if (taken.has(o.value)) return false;
        if (o.value === 'pending_rewrite_spinner' && !plainLanguage) return false;

        return true;
    });
}

// The steps an `auto_transition` on this place may take: non-system
// transitions whose `from` includes it — the same rule the writer enforces.
export function autoTransitionOptions(model, placeHandle) {
    return Object.entries(model.transitions ?? {})
        .filter(([, t]) => !t?.metadata?.system && [].concat(t?.from ?? []).includes(placeHandle))
        .map(([value, t]) => ({ value, label: `${t?.metadata?.label || value} (${value})` }));
}
