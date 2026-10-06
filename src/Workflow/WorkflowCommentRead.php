<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user "last seen" marker for a fieldset/passage's comments. Comments
 * created after this timestamp (by other users) count as new/unread.
 *
 * @property string $user_id
 * @property string $subject_id
 * @property string $workflow
 * @property string $passage_ref
 * @property \Illuminate\Support\Carbon $seen_at
 */
class WorkflowCommentRead extends Model
{
    protected $connection = 'workflow';

    protected $table = 'workflow_comment_reads';

    protected $guarded = [];

    protected $casts = [
        'seen_at' => 'datetime',
    ];
}
