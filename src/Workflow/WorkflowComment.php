<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * A comment on a workflow subject (Statamic entry), optionally referencing a
 * specific content passage. Lives in the dedicated `workflow` SQLite connection.
 *
 * @property string      $subject_id
 * @property string      $workflow
 * @property string|null $passage_ref
 * @property string|null $passage_handle
 * @property string      $body
 * @property string|null $user_id
 * @property string      $author_name
 * @property string|null $kind
 */
class WorkflowComment extends Model
{
    /**
     * The plain-language service's QA report — what it simplified, added and left
     * out. Written by PollPlainLanguageCommand once a rewrite lands, and the one
     * comment the instance page pulls back out to show as an alert.
     */
    public const KIND_PLAIN_LANGUAGE_QS = 'plain_language_qs';


    protected $connection = 'workflow';

    protected $table = 'workflow_comments';

    protected $guarded = [];
}
