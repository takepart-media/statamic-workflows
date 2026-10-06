<?php

namespace TakepartMedia\Workflows\Support;

use Statamic\Facades\User;

/**
 * Who bypasses the workflow gates: super admins and members of any group in
 * `config/workflows.php → privileged_groups` (default `['admins']`).
 *
 * One place so the controller, the entry policy and the actions fieldtype cannot
 * disagree about it.
 */
class Privileges
{
    public static function isPrivileged($user = null): bool
    {
        $user = $user ?? User::current();

        if ($user === null) {
            return false;
        }

        if ($user->isSuper()) {
            return true;
        }

        foreach ((array) config('workflows.privileged_groups', ['admins']) as $group) {
            if ((bool) $user->isInGroup($group)) {
                return true;
            }
        }

        return false;
    }
}
