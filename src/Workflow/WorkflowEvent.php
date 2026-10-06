<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable log entry for the workflow history: transitions applied, admin
 * state overrides, entry creation, localizations, and every draft save that
 * changed something. Rows never mutate — one row per event. Rendered in the
 * workflow instance summary as History.
 *
 * @property string $subject_id
 * @property string $workflow
 * @property string $type
 * @property string|null $transition
 * @property string|null $from_state
 * @property string|null $to_state
 * @property string|null $user_id
 * @property string|null $note
 * @property array<string, mixed>|null $data_snapshot
 */
class WorkflowEvent extends Model
{
    protected $connection = 'workflow';

    protected $table = 'workflow_events';

    protected $guarded = [];

    protected $casts = [
        'data_snapshot' => 'array',
    ];
}
