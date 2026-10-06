<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * A user watching a subject (Statamic entry localization) within a workflow —
 * a personal bookmark, not a responsibility: it puts the subject on the user's
 * "Watched" dashboard card and nothing else. No mail, no gate, no assignment.
 *
 * @property string $user_id
 * @property string $subject_id
 * @property string $workflow
 */
class WorkflowWatch extends Model
{
    protected $connection = 'workflow';

    protected $table = 'workflow_watches';

    protected $guarded = [];
}
