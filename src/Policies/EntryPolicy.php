<?php

namespace TakepartMedia\Workflows\Policies;

use Statamic\Facades\User;
use Statamic\Policies\EntryPolicy as BaseEntryPolicy;
use TakepartMedia\Workflows\Support\Privileges;

/**
 * Gates the `publish` ability behind the workflow for regular users: they
 * cannot publish directly from the entry editor and must drive publishing
 * through the workflow. Super admins and members of a `privileged_groups` group
 * are unrestricted. All other abilities defer to Statamic's core policy unchanged.
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
        return Privileges::isPrivileged(User::fromUser($user));
    }
}
