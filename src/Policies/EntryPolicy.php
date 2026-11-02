<?php

namespace TakepartMedia\Workflows\Policies;

use Statamic\Facades\User;
use Statamic\Policies\EntryPolicy as BaseEntryPolicy;
use TakepartMedia\Workflows\Support\Privileges;
use TakepartMedia\Workflows\Workflow\EditLock;
use TakepartMedia\Workflows\Workflow\RevisionPublishers;

/**
 * Gates the `publish` ability behind the workflow for regular users: they
 * cannot publish directly from the entry editor and must drive publishing
 * through the workflow. Super admins and members of a `privileged_groups` group
 * are unrestricted. While a working copy exists and the workflow names who may
 * publish revisions (RevisionPublishers), that audience and super admins decide
 * instead.
 *
 * With `assignees_only_edit` (EditLock), `edit` — and through it `update` —
 * additionally needs the user to be assigned or exempt. The lock only narrows
 * Statamic's own answer, it never grants an edit the role does not allow, and it
 * leaves `view` alone: a locked entry still opens, read-only. All other
 * abilities defer to Statamic's core policy unchanged.
 */
class EntryPolicy extends BaseEntryPolicy
{
    public function before($user, $ability = null)
    {
        if ($ability === 'publish') {
            return null;
        }

        return parent::before($user);
    }

    /** Set while view() asks the core policy, which goes through edit(). */
    private bool $ignoreLock = false;

    public function view($user, $entry)
    {
        $this->ignoreLock = true;

        try {
            return parent::view($user, $entry);
        } finally {
            $this->ignoreLock = false;
        }
    }

    public function edit($user, $entry)
    {
        if (! parent::edit($user, $entry)) {
            return false;
        }

        return $this->ignoreLock || (EditLock::allows(User::fromUser($user), $entry) ?? true);
    }

    public function publish($user, $entry)
    {
        $user = User::fromUser($user);

        return RevisionPublishers::allows($user, $entry) ?? Privileges::isPrivileged($user);
    }
}
