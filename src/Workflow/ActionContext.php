<?php

namespace TakepartMedia\Workflows\Workflow;

use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Everything a project's action handler is told about the moment it runs.
 *
 * A value object rather than a parameter list, because the list would be seven
 * long and every one of them is something a handler might not care about —
 * ordering them into a signature would mean every implementation repeats
 * arguments it ignores. It also leaves room: a later field is additive here and
 * breaks nobody's `handle()`.
 *
 * Handed out to code outside this addon, so everything on it is readonly. A
 * handler that wants to change something changes the ENTRY, not this.
 */
class ActionContext
{
    /**
     * @param  EntryContract  $entry  the version the transition acted on — already
     *                                fresh, so a handler reading fields sees the
     *                                state the transition left behind
     * @param  string  $workflow  the workflow's name
     * @param  string  $scope  `transition` or `place` — which kind of action this
     *                         handler was declared for. Worth knowing for a class
     *                         registered under both.
     * @param  string  $handle  the transition's or the place's handle, per $scope
     * @param  string  $from  the place the subject came from
     * @param  string  $to  the place it now sits in
     * @param  array<string, mixed>  $values  the action's own field values, as
     *                                        declared in `config/workflows.php`
     * @param  string|null  $actorId  who clicked — null when nobody did. The
     *                                scheduled aging command runs transitions too
     *                                (TransitionAgedPostsCommand), and a handler
     *                                that assumes a user is present breaks there
     *                                and nowhere else, which is the worst place to
     *                                find out.
     */
    public function __construct(
        public readonly EntryContract $entry,
        public readonly string $workflow,
        public readonly string $scope,
        public readonly string $handle,
        public readonly string $from,
        public readonly string $to,
        public readonly array $values = [],
        public readonly ?string $actorId = null,
    ) {}

    /**
     * One field value, or the fallback. Saves every handler the null-coalescing
     * dance over a bag whose contents are declared elsewhere.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}
