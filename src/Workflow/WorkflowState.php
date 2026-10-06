<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted workflow state for a subject (Statamic entry) within a workflow.
 * Lives in the dedicated, isolated `workflow` SQLite connection.
 *
 * @property string $subject_id
 * @property string $workflow
 * @property string $state
 * @property \Illuminate\Support\Carbon|null $entered_at
 */
class WorkflowState extends Model
{
    protected $connection = 'workflow';

    protected $table = 'workflow_states';

    protected $guarded = [];

    protected $casts = [
        'entered_at' => 'datetime',
    ];
}
