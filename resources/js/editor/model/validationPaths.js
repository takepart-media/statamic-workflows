// Where a problem sits, in the editor's terms. The server reports dot paths by
// handle (`places.draft.metadata.actions.0`, `transitions.submit`,
// `metadata.sla`, `collections.1`, `removed_places.old`); the editor works by id
// (rules.js):
//
//   place:<id>[.<rest>]        a place (its `metadata.` dropped: `place:p:draft.actions.0`)
//   transition:<id>[.<rest>]   a transition
//   place:<id>.migrate_to      a place marked for deletion (`removed_places.<h>`)
//   anything else              workflow-level, unchanged (`metadata.sla.outdated_days`,
//                              `initial_place`, `collections.1`, `transitions` …)
//
// Pure helpers: map server paths onto ids, merge the client's and the server's
// lists, and say which panel tab / settings section shows a path.

import { keyOf } from './editorModel.js';

const NODE_PATH = /^(place|transition):([^.]+)(?:\.(.*))?$/;

/** `{kind, id, rest}` for a node path, else null. */
export function parsePath(path) {
    const match = typeof path === 'string' ? NODE_PATH.exec(path) : null;
    return match ? { kind: match[1], id: match[2], rest: match[3] ?? '' } : null;
}

/**
 * The payload key → id of every node right now — what a server answer for this
 * model state is mapped through. Keyed by keyOf(), so a new node without a
 * handle (sent under its id) maps too. Deleted places by their handle.
 */
export function handleMaps(model) {
    return {
        places: new Map((model.places ?? []).map((p) => [keyOf(p), p.id])),
        transitions: new Map((model.transitions ?? []).map((t) => [keyOf(t), t.id])),
        deletions: new Map((model.deletions ?? []).map((d) => [d.handle, d.id])),
    };
}

/**
 * A server path → `{path, nodeId}` in the editor's terms. A handle the maps
 * don't know (a node removed since) keeps the server's path and no node.
 */
export function mapServerPath(path, maps) {
    if (typeof path !== 'string' || path === '') return { path: null, nodeId: null };
    const segments = path.split('.');
    const [head, handle, ...rest] = segments;

    if ((head === 'places' || head === 'transitions') && handle !== undefined && handle !== '') {
        const id = head === 'places'
            ? (maps.places.get(handle) ?? maps.deletions.get(handle))
            : maps.transitions.get(handle);
        if (!id) return { path, nodeId: null };
        const tail = rest[0] === 'metadata' ? rest.slice(1) : rest;
        const kind = head === 'places' ? 'place' : 'transition';
        return { path: `${kind}:${id}${tail.length ? `.${tail.join('.')}` : ''}`, nodeId: id };
    }

    if (head === 'removed_places' && handle) {
        const id = maps.deletions.get(handle) ?? maps.places.get(handle);
        return id ? { path: `place:${id}.migrate_to`, nodeId: id } : { path, nodeId: null };
    }

    return { path, nodeId: null };
}

/**
 * One server entry (`{path, code, message, node}`) as an editor problem.
 * Falls back to `node: {kind, handle}` when the path names no node.
 */
export function mapServerProblem(entry, level, maps) {
    const mapped = mapServerPath(entry?.path ?? null, maps);
    let nodeId = mapped.nodeId;
    if (!nodeId && entry?.node?.handle) {
        nodeId = entry.node.kind === 'transition'
            ? (maps.transitions.get(entry.node.handle) ?? null)
            : (maps.places.get(entry.node.handle) ?? maps.deletions.get(entry.node.handle) ?? null);
    }
    return {
        level,
        code: entry?.code ?? null,
        path: mapped.path,
        nodeId,
        message: entry?.message ?? '',
        source: 'server',
    };
}

/**
 * The `{errors, warnings, removals}` of a validate answer (or a save's 422) as
 * one list of editor problems. A removal with a problem is an error on the
 * deleted place.
 */
export function mapServerResult({ errors = [], warnings = [], removals = [] } = {}, maps) {
    const removalErrors = (removals ?? [])
        .filter((r) => r && (r.error || r.target_error))
        .map((r) => ({ path: `removed_places.${r.place}`, code: 'needs_migration', message: r.error || r.target_error, node: { kind: 'place', handle: r.place } }));
    return [
        ...(errors ?? []).map((e) => mapServerProblem(e, 'error', maps)),
        ...removalErrors.map((e) => mapServerProblem(e, 'error', maps)),
        ...(warnings ?? []).map((w) => mapServerProblem(w, 'warning', maps)),
    ];
}

/**
 * Whether two paths talk about the same spot: equal, one inside the other
 * (`metadata.sla` ⊃ `metadata.sla.outdated_days`, `place:x` ⊃ `place:x.branch`),
 * or a workflow-wide `places` / `transitions` and any node of that kind.
 */
export function pathsRelated(a, b) {
    if (a === b) return true;
    if (!a || !b) return false;
    if (b.startsWith(`${a}.`) || a.startsWith(`${b}.`)) return true;
    const plural = { places: 'place:', transitions: 'transition:' };
    return (plural[a] && b.startsWith(plural[a])) || (plural[b] && a.startsWith(plural[b])) || false;
}

/**
 * Client problems plus what only the server found. A server entry is dropped
 * when the client already reports the same message at a related path (the
 * client's path is the more precise one), and server entries repeating each
 * other collapse. Errors first, then warnings — each in the order found.
 */
export function mergeProblems(client = [], server = []) {
    const merged = [...client];
    for (const problem of server) {
        const known = merged.some((p) => p.message === problem.message && (pathsRelated(p.path, problem.path) || (p.nodeId && p.nodeId === problem.nodeId)));
        if (!known) merged.push(problem);
    }
    const rank = { error: 0, warning: 1, info: 2 };
    return merged
        .map((p, i) => [p, i])
        .sort(([a, i], [b, j]) => (rank[a.level] ?? 3) - (rank[b.level] ?? 3) || i - j)
        .map(([p]) => p);
}

/** Problems at `path` or inside it. */
export function problemsAt(problems, path) {
    return problems.filter((p) => p.path === path || (p.path && p.path.startsWith(`${path}.`)));
}

/** Old id → new id (after a save) applied to a list of problems. */
export function remapProblems(problems, ids) {
    if (!ids) return problems;
    return problems.map((p) => {
        const node = parsePath(p.path);
        const nodeId = p.nodeId ? (ids[p.nodeId] ?? p.nodeId) : p.nodeId;
        if (!node || !ids[node.id]) return { ...p, nodeId };
        return { ...p, nodeId, path: `${node.kind}:${ids[node.id]}${node.rest ? `.${node.rest}` : ''}` };
    });
}

/** Which tab of a node's panel shows `path`. */
export function tabForPath(path) {
    const node = parsePath(path);
    if (!node) return 'general';
    const first = node.rest.split('.')[0];
    if (first === 'actions') return 'actions';
    if (node.kind === 'place' && first === 'migrate_to') return 'connections';
    if (node.kind === 'transition' && ['required_handles', 'required_by', 'required_group', 'required_role'].includes(first)) return 'permission';
    return 'general';
}

// Workflow-level metadata key → settings section (WorkflowSettingsStack.vue).
const SECTIONS = {
    nav: 'general',
    seed_published_place: 'general',
    progress_orientation: 'general',
    default_assignee_group: 'permissions',
    assign_creator: 'permissions',
    post_creator_groups: 'permissions',
    create_transition: 'permissions',
    assigner_groups: 'permissions',
    self_assignment: 'permissions',
    four_eyes: 'permissions',
    detail: 'localization',
    plain_language_site: 'localization',
    translation_source_site: 'localization',
    plain_language_completed_place: 'localization',
    plain_language_failed_place: 'localization',
    translation_source_pick: 'localization',
    translation_source_view: 'localization',
    source_draft_place: 'localization',
    site_fields: 'localization',
    plain_language_clear_fields: 'localization',
    plain_language_skip_fields: 'localization',
    content_fields: 'content',
    revisions: 'collections',
    revision_publishers: 'collections',
    sla: 'sla',
};

/** The settings section a workflow-level path belongs to; null for a node path. */
export function sectionForPath(path) {
    if (!path || parsePath(path)) return null;
    const [head, key] = path.split('.');
    if (head === 'initial_place') return 'general';
    if (head === 'collections') return 'collections';
    if (head === 'places' || head === 'transitions') return 'order';
    if (head === 'metadata') return SECTIONS[key] ?? 'advanced';
    return 'advanced';
}

/** An id-based path for people: back to `places.<handle>…` / `transitions.<handle>…`. */
export function displayPath(path, model) {
    const node = parsePath(path);
    if (!node) return path;
    const list = node.kind === 'place' ? [...(model.places ?? []), ...(model.deletions ?? [])] : (model.transitions ?? []);
    const entry = list.find((e) => e.id === node.id);
    const key = entry ? (entry.handle || entry.id) : node.id;
    return `${node.kind === 'place' ? 'places' : 'transitions'}.${key}${node.rest ? `.${node.rest}` : ''}`;
}
