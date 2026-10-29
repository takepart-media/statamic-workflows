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
 * @property int|null    $parent_id         the ROOT comment of a reply; null = root
 * @property \Illuminate\Support\Carbon|null $resolved_at  roots only
 * @property string|null $resolved_by
 * @property string|null $resolved_by_name
 * @property \Illuminate\Support\Carbon|null $edited_at
 * @property array<int, string>|null $mentions  mentioned user ids
 * @property string|null $quote             the marked text (roots only); null = whole passage
 * @property int|null    $quote_offset      where `quote` started in the passage's plain text
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

    protected $casts = [
        'parent_id' => 'integer',
        'resolved_at' => 'datetime',
        'edited_at' => 'datetime',
        'mentions' => 'array',
        'quote_offset' => 'integer',
    ];

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Only comments in OPEN threads: an unresolved root, or a reply whose root is
     * unresolved. Replies never carry `resolved_at` themselves — resolving is a
     * property of the thread, stored on its root.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeOpenThreads($query)
    {
        return $query
            ->whereNull('resolved_at')
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhereIn(
                'parent_id',
                fn ($sub) => $sub->select('id')->from('workflow_comments')
                    ->whereNull('parent_id')
                    ->whereNull('resolved_at'),
            ));
    }
}
