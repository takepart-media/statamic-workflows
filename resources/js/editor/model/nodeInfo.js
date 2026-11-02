// What a graph node shows about its place or transition — badges, chips, icons.
// Pure functions of the handle-keyed editor model (normalize()); the components
// only translate the codes returned here. Kept apart from the components so the
// rules are testable without a DOM.
//
//   ctx = {
//       labelLocales, labelLocale, shippedPlaceLabels, humanize,  // see labels.js
//       stateUsage: { <place>: count },
//   }

import { ACTION_TYPES, PLACE_ACTION_TYPES } from './constants.js';
import { explicitStartTransition, startingTransition } from './actionRules.js';
import { defaultVariant, placeDisplayName } from './labels.js';
import { placeReferences } from './refs.js';

// Reference role (refs.js) → the role badge a place gets for it. The two
// `transition_*` roles are the graph's edges, not badges.
export const PLACE_ROLE_BADGES = {
    initial_place: 'start',
    sla_needs_update_place: 'sla_1',
    sla_outdated_place: 'sla_2',
    seed_published_place: 'seed',
    source_draft_place: 'source_draft',
    plain_language_completed_place: 'pl_done',
    plain_language_failed_place: 'pl_failed',
};

// Badge order on the node, most structural first.
const ROLE_ORDER = ['start', 'publish', 'branch', 'gate', 'sla_1', 'sla_2', 'seed', 'source_draft', 'pl_done', 'pl_failed'];

// Place action type → icon (Statamic icon set). Anything not built in is a
// project action and gets the generic one.
export const PLACE_ACTION_ICONS = {
    mail: 'mail',
    reminder: 'bell',
    auto_transition: 'flash-bolt-lightning',
    refresh_interval: 'sync',
    pending_rewrite_spinner: 'loading',
};
export const CUSTOM_ACTION_ICON = 'puzzle-piece';

const sources = (transition) => [].concat(transition?.from ?? []).filter((v) => typeof v === 'string' && v !== '');

const isGated = (transition) =>
    (transition?.metadata?.required_by ?? 'none') !== 'none' && (transition?.metadata?.required_handles ?? []).length > 0;

// The real (non-system) transitions leaving `handle`.
export function outgoingTransitions(model, handle) {
    return Object.entries(model?.transitions ?? {})
        .filter(([, t]) => !t?.metadata?.system && sources(t).includes(handle));
}

/**
 * Whether a place is a decision point: one of its outgoing transitions is
 * gated by group/role, or it branches into two or more steps on the main line
 * (transitions into a `branch` place don't count — every published place has
 * those).
 */
export function isGatePlace(model, handle) {
    const outgoing = outgoingTransitions(model, handle);
    if (outgoing.some(([, t]) => isGated(t))) return true;
    const mainLine = outgoing.filter(([, t]) => !model.places?.[t?.to]?.metadata?.branch);
    return mainLine.length >= 2;
}

/**
 * Everything a place node shows.
 *
 * @returns {{handle, label, color, order, roles: string[], actions: {type, icon}[], usage: number|null}}
 */
export function placeInfo(model, handle, ctx = {}) {
    const place = model?.places?.[handle] ?? {};
    const meta = place.metadata ?? {};
    const roles = new Set();

    for (const ref of placeReferences(model, handle)) {
        const badge = PLACE_ROLE_BADGES[ref.role];
        if (badge) roles.add(badge);
    }
    if (meta.allow_publish) roles.add('publish');
    if (meta.branch) roles.add('branch');
    if (isGatePlace(model, handle)) roles.add('gate');

    const seen = new Set();
    const actions = [];
    for (const action of Array.isArray(meta.actions) ? meta.actions : []) {
        const type = action?.type;
        if (!type || seen.has(type)) continue;
        seen.add(type);
        actions.push({ type, icon: PLACE_ACTION_ICONS[type] ?? CUSTOM_ACTION_ICON, custom: !PLACE_ACTION_TYPES.includes(type) });
    }

    const usage = ctx.stateUsage?.[handle];

    return {
        handle,
        label: placeDisplayName(model, handle, ctx),
        color: meta.color || null,
        order: Object.keys(model?.places ?? {}).indexOf(handle) + 1,
        roles: ROLE_ORDER.filter((r) => roles.has(r)),
        actions,
        usage: Number.isFinite(Number(usage)) && usage !== null ? Number(usage) : null,
    };
}

/**
 * Everything a transition node shows.
 *
 * chips:  { code: 'gate', by: 'group'|'role', count }
 *         { code: 'assign', mode: 'manual'|'automatic' }
 *         { code: 'mail' | 'target_missing' | 'start_localization' | 'resubmit' }
 *         { code: 'custom', type }
 * badges: 'start' | 'cta' | 'system'
 */
export function transitionInfo(model, handle) {
    const transition = model?.transitions?.[handle] ?? {};
    const meta = transition.metadata ?? {};
    const chips = [];

    if (isGated(transition)) {
        chips.push({ code: 'gate', by: meta.required_by, count: meta.required_handles.length });
    }
    for (const action of Array.isArray(meta.actions) ? meta.actions : []) {
        switch (action?.type) {
            case 'user_assignment':
                chips.push({ code: 'assign', mode: action.mode === 'automatic' ? 'automatic' : 'manual' });
                break;
            case 'mail':
                chips.push({ code: 'mail' });
                break;
            case 'target_missing':
                chips.push({ code: 'target_missing' });
                break;
            case 'start_localization':
                chips.push({ code: 'start_localization' });
                break;
            case 'plain_language_resubmit':
                chips.push({ code: 'resubmit' });
                break;
            default:
                if (action?.type && !ACTION_TYPES.includes(action.type)) {
                    chips.push({ code: 'custom', type: action.type });
                }
        }
    }

    const badges = [];
    const start = explicitStartTransition(model) ?? startingTransition(model);
    if (start === handle) badges.push('start');
    if (model?.metadata?.create_transition === handle) badges.push('cta');
    if (meta.system) badges.push('system');

    return {
        handle,
        label: (typeof meta.label === 'string' && meta.label.trim()) || handle,
        variant: meta.variant || defaultVariant(handle),
        chips,
        badges,
    };
}
