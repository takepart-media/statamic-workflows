<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * `metadata.assignees_only_edit` — only the people a post is assigned to may
 * edit it.
 *
 *   assignees_only_edit: true
 *   edit_exempt:             # optional AudienceRows; may edit regardless
 *     - by: group
 *       handles: [chefredaktion]
 *
 * Applies to entries a workflow governs (WorkflowResolver::forEntry()) and only
 * while the version sits in a place — a version not in the workflow yet has
 * nobody assigned and stays open. Super admins are always exempt. Everyone else
 * needs to be a current assignee of the version in its workflow or match
 * `edit_exempt`; the entry editor then opens read-only (Policies\EntryPolicy),
 * the workflow panel stays usable.
 */
final class EditLock
{
    public const KEY = 'assignees_only_edit';

    public const EXEMPT = 'edit_exempt';

    /**
     * Whether the user may edit this entry — or null when the lock does not
     * apply (no workflow, setting off, version not in the workflow), so the
     * caller falls back to its usual answer.
     *
     * @param  \Statamic\Contracts\Auth\User|null  $user
     */
    public static function allows($user, $entry): ?bool
    {
        if (! $entry instanceof EntryContract || $entry->id() === null) {
            return null;
        }

        $resolver = app(WorkflowResolver::class);
        $workflow = $resolver->forEntry($entry);

        if ($workflow === null) {
            return null;
        }

        $raw = app(DefinitionLoader::class)->raw($workflow);

        if (($raw['metadata'][self::KEY] ?? false) !== true || $resolver->stateFor($entry, $workflow, $raw) === null) {
            return null;
        }

        if ($user === null) {
            return false;
        }

        if ($user->isSuper()) {
            return true;
        }

        $assignees = array_map('strval', app(WorkflowAssigneeStore::class)->idsFor((string) $entry->id(), $workflow));

        if (in_array((string) $user->id(), $assignees, true)) {
            return true;
        }

        $exempt = AudienceRows::clean($raw['metadata'][self::EXEMPT] ?? null);

        return $exempt !== null && AudienceRows::matches($user, $exempt);
    }
}
