// Live validation on the editor model (editorModel.js): the writer's rules the
// client can decide on its own, checked on every edit instead of on save. The
// server stays the authority — useServerValidation.js adds what only it knows
// (sites, mail types, criteria fields …) and the save re-checks everything.
//
// Pure: (model, ctx) → problems. Every problem:
//
//   { level: 'error'|'warning', code, path, nodeId, message }
//
// `path` is id-based (validationPaths.js): `place:<id>.actions.0`,
// `transition:<id>.to`, `metadata.sla.needs_update_place`, `initial_place` …
// — the server's dot paths with `places.<handle>` / `transitions.<handle>`
// swapped for the node's id and the node's `metadata.` dropped. Fields carry the
// same string as `data-path`, so a problem can be focused.
//
// Messages: where the writer (WorkflowDefinitionWriter) or the linter
// (DefinitionLinter) has the same rule, its lang key and replacements are used
// verbatim — the texts then match the server's, and the two lists dedupe
// (validationPaths.mergeProblems()). Replacements name nodes by keyOf() — the
// key the node has in the payload, i.e. what the server would print.
//
//   ctx = {
//       t(key, replace) → string,   // key without the `workflows::messages.` prefix
//       reservedPlaces, reservedTransitions,
//   }

import { SLA_STAGES } from './constants.js';
import { cleanCriteria } from './serialize.js';
import { findPlace, findTransition, handleProblems, keyOf } from './editorModel.js';

const defaultT = (key) => key;

// How PHP prints a value the writer puts into a message (is_scalar ? value :
// gettype) — so a mirrored message reads exactly like the server's.
function printable(value) {
    if (value === null || value === undefined) return 'NULL';
    if (typeof value === 'boolean') return value ? '1' : '';
    if (typeof value === 'object') return Array.isArray(value) ? 'array' : 'object';
    return String(value);
}

// PHP: is_numeric($v) && (int) $v == $v && between.
function wholeInRange(value, min, max) {
    if (value === null || value === undefined || value === '' || typeof value === 'boolean') return false;
    const n = Number(value);
    return Number.isInteger(n) && n >= min && n <= max;
}

const placePath = (id, rest) => `place:${id}${rest ? `.${rest}` : ''}`;
const transitionPath = (id, rest) => `transition:${id}${rest ? `.${rest}` : ''}`;

/**
 * Every problem the client can see in `model`.
 *
 * @param {object} model  the editor model (editorModel.js)
 * @param {object} [ctx]
 * @returns {Array<{level: string, code: string, path: string, nodeId: string|null, message: string}>}
 */
export function validate(model, ctx = {}) {
    const t = ctx.t ?? defaultT;
    const out = [];
    const add = (level, code, path, nodeId, key, replace = {}) => out.push({ level, code, path, nodeId: nodeId ?? null, message: t(key, replace) });
    const error = (code, path, nodeId, key, replace) => add('error', code, path, nodeId, key, replace);
    const warning = (code, path, nodeId, key, replace) => add('warning', code, path, nodeId, key, replace);

    const meta = model.workflow?.metadata ?? {};
    const places = model.places ?? [];
    const transitions = model.transitions ?? [];
    const placeIds = new Set(places.map((p) => p.id));
    const placeKey = (id) => {
        const place = findPlace(model, id);
        return place ? keyOf(place) : id;
    };
    const localization = meta.detail === 'translation';
    const plainLanguageSite = meta.plain_language_site || null;
    const initialId = placeIds.has(model.workflow?.initial_place) ? model.workflow.initial_place : null;

    // --- handles of new nodes ---
    for (const { id, problem } of handleProblems(model, ctx)) {
        const kind = findPlace(model, id) ? 'place' : 'transition';
        error(`handle_${problem}`, `${kind}:${id}.handle`, id, `editor_handle_${problem}`);
    }

    // --- the workflow's frame ---
    if (!places.length) {
        error('no_places', 'places', null, 'writer_no_places');
    }
    if (!initialId) {
        const raw = model.workflow?.initial_place;
        error('initial_place_unknown', 'initial_place', null, 'writer_initial_place_unknown', { place: raw ? placeKey(raw) : '' });
    } else if (findPlace(model, initialId)?.metadata?.branch === true) {
        error('place_branch_initial', placePath(initialId, 'branch'), initialId, 'writer_place_branch_initial', { handle: placeKey(initialId) });
    }

    // --- transitions: ends, gate, actions ---
    let startCount = 0;
    const starts = [];
    for (const transition of transitions) {
        const id = transition.id;
        const handle = keyOf(transition);
        const tmeta = transition.metadata ?? {};

        if (!transition.from?.length) {
            error('transition_no_from', transitionPath(id, 'from'), id, 'editor_rule_transition_no_from', { handle });
        } else {
            const unknown = transition.from.find((p) => !placeIds.has(p));
            if (unknown) error('transition_from_unknown', transitionPath(id, 'from'), id, 'writer_transition_from_unknown', { handle, place: unknown });
        }
        if (!transition.to || !placeIds.has(transition.to)) {
            error('transition_to_unknown', transitionPath(id, 'to'), id, 'writer_transition_to_unknown', { handle, place: transition.to ?? '' });
        }

        const by = tmeta.required_by ?? 'none';
        if (by !== 'none' && !(tmeta.required_handles ?? []).filter((h) => h !== '').length) {
            error('required_handles_missing', transitionPath(id, 'required_handles'), id, 'editor_rule_required_handles_missing', { handle });
        }

        const actions = Array.isArray(tmeta.actions) ? tmeta.actions : [];
        let dialogs = 0;
        let assignments = 0;
        let mails = 0;
        let resubmits = 0;
        actions.forEach((action, index) => {
            const path = transitionPath(id, `actions.${index}`);
            switch (action?.type) {
                case 'user_assignment': {
                    assignments++;
                    const mode = action.mode ?? 'manual';
                    if (mode === 'manual') dialogs++;
                    if (mode === 'automatic' && !cleanCriteria(action.criteria).length) {
                        error('assignment_automatic_without_criteria', path, id, 'writer_assignment_automatic_without_criteria', { handle, action: 'user_assignment' });
                    }
                    break;
                }
                case 'mail':
                    mails++;
                    break;
                case 'target_missing':
                    if (typeof action.site !== 'string' || action.site === '') {
                        const site = typeof action.site === 'string' ? 'string' : printable(action.site);
                        error('transition_target_missing_site_unknown', path, id, 'writer_transition_target_missing_site_unknown', { handle, site });
                    }
                    break;
                case 'start_localization':
                    startCount++;
                    starts.push({ id, index });
                    if (!localization) {
                        error('transition_start_localization_needs_detail', path, id, 'writer_transition_start_localization_needs_detail', { handle, action: 'start_localization' });
                    }
                    break;
                case 'plain_language_resubmit':
                    resubmits++;
                    if (!plainLanguageSite) {
                        error('transition_resubmit_action_needs_site', path, id, 'writer_transition_resubmit_action_needs_site', { handle, action: 'plain_language_resubmit' });
                    }
                    break;
                default:
            }
        });

        // The writer stops at the first of these three (one unit); a fourth the
        // writer does not count, said in the same words.
        const listPath = transitionPath(id, 'actions');
        if (dialogs > 1) {
            error('transition_multiple_dialogs', listPath, id, 'writer_transition_multiple_dialogs', { handle });
        } else if (assignments > 1) {
            error('transition_multiple_assignments', listPath, id, 'writer_transition_multiple_assignments', { handle, action: 'user_assignment' });
        } else if (mails > 1) {
            error('transition_multiple_mails', listPath, id, 'writer_transition_multiple_mails', { handle, action: 'mail' });
        }
        if (resubmits > 1) {
            error('transition_multiple_resubmits', listPath, id, 'writer_transition_multiple_mails', { handle, action: 'plain_language_resubmit' });
        }
    }

    // --- localization: exactly one start, out of the start place ---
    if (startCount > 1) {
        for (const { id, index } of starts) {
            error('start_localization_once', transitionPath(id, `actions.${index}`), id, 'writer_start_localization_once', { action: 'start_localization' });
        }
    }
    if (localization && startCount === 0) {
        error('start_localization_required', 'transitions', null, 'writer_start_localization_required', { action: 'start_localization' });
    }
    if (localization && startCount === 1 && initialId) {
        const start = findTransition(model, starts[0].id);
        if (start && (start.from.length !== 1 || start.from[0] !== initialId)) {
            error('start_localization_from_initial', transitionPath(start.id, 'from'), start.id, 'editor_rule_start_localization_from_initial', {
                handle: keyOf(start),
                place: placeKey(initialId),
            });
        }
    }
    // "Rewrite again" on the step that creates the version in the first place.
    if (startCount === 1) {
        const start = findTransition(model, starts[0].id);
        (start?.metadata?.actions ?? []).forEach((action, index) => {
            if (action?.type === 'plain_language_resubmit' && plainLanguageSite) {
                error('resubmit_on_start', transitionPath(start.id, `actions.${index}`), start.id, 'editor_rule_resubmit_on_start', {
                    handle: keyOf(start),
                    action: 'plain_language_resubmit',
                });
            }
        });
    }

    // --- places: actions ---
    for (const place of places) {
        const id = place.id;
        const handle = keyOf(place);
        const actions = Array.isArray(place.metadata?.actions) ? place.metadata.actions : [];
        const counts = new Map();

        actions.forEach((action, index) => {
            const type = action?.type;
            if (!type) return;
            counts.set(type, (counts.get(type) ?? 0) + 1);
            const path = placePath(id, `actions.${index}`);

            if (type === 'pending_rewrite_spinner' && !plainLanguageSite) {
                error('place_spinner_action_needs_site', path, id, 'writer_place_spinner_action_needs_site', { handle, action: 'pending_rewrite_spinner' });
            }
            if (type === 'refresh_interval' && !wholeInRange(action.minutes, 1, 1440)) {
                error('place_refresh_interval_invalid', path, id, 'writer_place_refresh_interval_invalid', { handle, value: printable(action.minutes) });
            }
            if (type === 'reminder') {
                if (!wholeInRange(action.every_days, 1, 365)) {
                    error('place_reminder_invalid', path, id, 'writer_place_reminder_invalid', { handle, value: printable(action.every_days) });
                } else if (!action.mail_type) {
                    error('place_reminder_mail_type_missing', path, id, 'editor_rule_reminder_mail_type', { handle });
                }
            }
            if (type === 'auto_transition') {
                if (!wholeInRange(action.after_days, 1, 365)) {
                    error('place_auto_transition_days_invalid', path, id, 'writer_place_auto_transition_days_invalid', { handle, value: printable(action.after_days) });
                } else {
                    const target = action.transition ? findTransition(model, action.transition) : null;
                    if (!target || target.metadata?.system === true || !target.from.includes(id)) {
                        const name = target ? keyOf(target) : printable(action.transition);
                        error('place_auto_transition_invalid', path, id, 'writer_place_auto_transition_invalid', { handle, transition: name });
                    }
                }
            }
        });

        // Once per type. The writer's own three first, in its order; any other
        // type said in the generic "more than one [:action]" words.
        const listPath = placePath(id, 'actions');
        if ((counts.get('auto_transition') ?? 0) > 1) {
            error('place_multiple_auto_transitions', listPath, id, 'writer_place_multiple_auto_transitions', { handle });
        }
        if ((counts.get('reminder') ?? 0) > 1) {
            error('place_multiple_reminders', listPath, id, 'writer_place_multiple_reminders', { handle });
        }
        for (const [type, count] of counts) {
            if (count > 1 && !['auto_transition', 'reminder'].includes(type)) {
                error(type === 'mail' ? 'place_multiple_mails' : 'place_multiple_actions', listPath, id, 'writer_place_multiple_mails', { handle, action: type });
            }
        }
    }

    // --- workflow-level references ---
    const createId = meta.create_transition;
    if (createId) {
        const target = findTransition(model, createId);
        const path = 'metadata.create_transition';
        const fallback = initialId ?? places[0]?.id ?? null;
        if (!target) {
            error('create_transition_unknown', path, null, 'writer_create_transition_unknown', { transition: createId });
        } else if (target.metadata?.system) {
            error('create_transition_system', path, null, 'writer_create_transition_system', { transition: keyOf(target) });
        } else if (!fallback || !target.from.includes(fallback)) {
            error('create_transition_not_from_initial', path, null, 'writer_create_transition_not_from_initial', {
                transition: keyOf(target),
                place: fallback ? placeKey(fallback) : '',
            });
        } else if (localization) {
            error('create_transition_needs_entry_workflow', path, null, 'writer_create_transition_needs_entry_workflow');
        }
    }

    const sla = meta.sla ?? {};
    const configured = {};
    for (const stage of SLA_STAGES) {
        const days = sla[`${stage}_days`];
        const hasDays = days !== null && days !== undefined && days !== '';
        const place = sla[`${stage}_place`] || null;
        if (!hasDays && !place) continue;
        if (!hasDays || !place) {
            error('sla_stage_incomplete', `metadata.sla.${stage}_${hasDays ? 'place' : 'days'}`, null, 'writer_sla_stage_incomplete', { stage });
            continue;
        }
        configured[stage] = Number(days);
        const assign = sla[`${stage}_assign`];
        if (assign && (assign.by || assign.handles?.length) && !(assign.by && assign.handles?.length)) {
            error('sla_assign_invalid', `metadata.sla.${stage}_assign`, null, 'writer_sla_assign_invalid', { stage });
        }
    }
    if (configured.needs_update !== undefined && configured.outdated !== undefined && configured.outdated < configured.needs_update) {
        error('sla_days_order', 'metadata.sla.outdated_days', null, 'writer_sla_days_order', {
            needs_update: configured.needs_update,
            outdated: configured.outdated,
        });
    }

    if (meta.source_draft_place) {
        const path = 'metadata.source_draft_place';
        if (!localization) {
            error('source_draft_not_localization', path, null, 'writer_source_draft_not_localization');
        } else if (findPlace(model, meta.source_draft_place)?.metadata?.allow_publish === true) {
            error('source_draft_place_publishes', path, null, 'writer_source_draft_place_publishes', { place: placeKey(meta.source_draft_place) });
        }
    }

    if (meta.plain_language_completed_place && !plainLanguageSite) {
        error('completed_place_needs_site', 'metadata.plain_language_completed_place', null, 'writer_completed_place_needs_site');
    }
    if (meta.plain_language_failed_place && !plainLanguageSite) {
        error('failed_place_needs_site', 'metadata.plain_language_failed_place', null, 'writer_failed_place_needs_site');
    }

    // --- places marked for deletion ---
    for (const deletion of model.deletions ?? []) {
        if (!(deletion.usage > 0)) continue;
        const path = placePath(deletion.id, 'migrate_to');
        if (!deletion.migrateTo) {
            error('removed_place_needs_target', path, deletion.id, 'removed_place_needs_target', { place: deletion.handle, count: deletion.usage });
        } else if (!placeIds.has(deletion.migrateTo)) {
            error('removed_place_target_invalid', path, deletion.id, 'removed_place_target_invalid', { place: deletion.handle, target: placeKey(deletion.migrateTo) });
        }
    }

    // --- warnings: the shape of the graph ---
    const connected = new Set();
    for (const transition of transitions) {
        transition.from.forEach((p) => connected.add(p));
        if (transition.to) connected.add(transition.to);
    }
    for (const place of places) {
        if (!connected.has(place.id)) {
            warning('place_isolated', placePath(place.id), place.id, 'editor_rule_place_isolated', { place: keyOf(place) });
        }
    }

    // Reachable from the start — or from a place the addon moves subjects into
    // by itself (DefinitionLinter::systemTargets()).
    if (initialId) {
        const queue = [initialId, ...systemTargets(meta).filter((id) => placeIds.has(id))];
        const seen = new Set(queue);
        while (queue.length) {
            const current = queue.shift();
            for (const transition of transitions) {
                if (transition.to && !seen.has(transition.to) && transition.from.includes(current)) {
                    seen.add(transition.to);
                    queue.push(transition.to);
                }
            }
        }
        for (const place of places) {
            if (!seen.has(place.id)) {
                warning('place_unreachable', placePath(place.id), place.id, 'lint_place_unreachable', { place: keyOf(place) });
            }
        }
    }

    if (places.length && !places.some((p) => p.metadata?.allow_publish) && !meta.seed_published_place) {
        warning('no_publish_place', 'metadata.seed_published_place', null, 'editor_rule_no_publish_place');
    }

    return out;
}

// The places the addon moves subjects into without a transition.
function systemTargets(meta) {
    return [
        meta.seed_published_place,
        meta.plain_language_completed_place,
        meta.plain_language_failed_place,
        meta.source_draft_place,
        meta.sla?.needs_update_place,
        meta.sla?.outdated_place,
    ].filter((v) => typeof v === 'string' && v !== '');
}

