<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * A user assigned to a subject (Statamic entry localization) within a workflow.
 * Assignment lives here in the dedicated `workflow` SQLite connection — NOT on
 * the entry itself — so editorial responsibility is owned by the workflow.
 *
 * @property string $subject_id
 * @property string $workflow
 * @property string $user_id
 */
class WorkflowAssignee extends Model
{
    protected $connection = 'workflow';

    protected $table = 'workflow_assignees';

    protected $guarded = [];
}
