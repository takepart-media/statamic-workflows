<?php

namespace TakepartMedia\Workflows\Policies;

use Statamic\Facades\User;
use Statamic\Policies\EntryPolicy as BaseEntryPolicy;
use TakepartMedia\Workflows\Support\Privileges;
use TakepartMedia\Workflows\Workflow\RevisionPublishers;

/**
 * Gates the `publish` ability behind the workflow for regular users: they
 * cannot publish directly from the entry editor and must drive publishing
 * through the workflow. Super admins and members of a `privileged_groups` group
 * are unrestricted. While a working copy exists and the workflow names who may
 * publish revisions (RevisionPublishers), that audience and super admins decide
 * instead. All other abilities defer to Statamic's core policy unchanged.
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

    public function publish($user, $entry)
    {
        $user = User::fromUser($user);

        return RevisionPublishers::allows($user, $entry) ?? Privileges::isPrivileged($user);
    }
}
