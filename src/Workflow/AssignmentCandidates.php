<?php

namespace TakepartMedia\Workflows\Workflow;

use Illuminate\Support\Collection;
use Statamic\Facades\User;

/**
 * Who a `user_assignment` action may hand a subject to.
 *
 * THE single authority for that question, and deliberately so. Four callers want
 * the same answer and used to derive it separately (or could not derive it at
 * all): what the picker offers, what `applyTransition()` accepts back, whom the
 * automatic mode assigns, and whether the automatic mode has anybody to assign
 * at all. Splitting those apart is how "offered" and "accepted" drift into two
 * different sets — the bug the group union was already careful to avoid, now
 * structural rather than maintained by hand.
 *
 * An empty criteria list means EVERYBODY, not nobody. The filter is optional by
 * design: an assignment with no criteria is "let the editor pick anyone", which
 * is the same pool the assignee drawer offers. The writer refuses that
 * combination for the automatic mode, where it would mean assigning a post to
 * the entire user base.
 *
 * Not a static class, unlike its neighbours: it holds a per-request memo, and
 * the ServiceProvider registers it scoped so the memo lives exactly one request.
 * Worth it because `entryActions()` resolves the same action twice per
 * transition — once for `can_apply` (through mechanicalBlocker) and once for
 * `pickable_assignees` — over the full user list each time.
 */
class AssignmentCandidates
{
    /** @var array<string, \Illuminate\Support\Collection> */
    private array $memo = [];

    /**
     * The users satisfying the action's criteria, for this subject.
     *
     * @param  array{mode: string|null, match: string|null, criteria: array<int, array<string, mixed>>}  $action
     * @param  \Statamic\Contracts\Entries\Entry|null  $entry
     * @return \Illuminate\Support\Collection<int, \Statamic\Contracts\Auth\User>
     */
    public function for(array $action, $entry = null): Collection
    {
        $key = md5(json_encode([
            $action['match'] ?? null,
            $action['criteria'] ?? [],
            $entry?->id(),
        ]));

        return $this->memo[$key] ??= $this->resolve($action, $entry);
    }

    /**
     * The same set as ids, which is the shape both the store and the request
     * validation speak.
     *
     * @param  array{mode: string|null, match: string|null, criteria: array<int, array<string, mixed>>}  $action
     * @param  \Statamic\Contracts\Entries\Entry|null  $entry
     * @return array<int, string>
     */
    public function ids(array $action, $entry = null): array
    {
        return $this->for($action, $entry)
            ->map(fn ($user) => (string) $user->id())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array{mode: string|null, match: string|null, criteria: array<int, array<string, mixed>>}  $action
     * @param  \Statamic\Contracts\Entries\Entry|null  $entry
     * @return \Illuminate\Support\Collection<int, \Statamic\Contracts\Auth\User>
     */
    private function resolve(array $action, $entry): Collection
    {
        $criteria = $action['criteria'] ?? [];

        // Same full list the assignee drawer works from (assignableUsers()).
        // Nothing narrower would be correct: the criteria are the filter, and a
        // pre-filter on top of them could only ever remove somebody the workflow
        // explicitly asked for.
        $users = collect(User::all());

        if ($criteria === []) {
            return $users->values();
        }

        $match = $action['match'] ?? TransitionActions::MATCH_ALL;

        return $users
            ->filter(fn ($user) => self::satisfies($user, $entry, $criteria, $match))
            ->values();
    }

    /**
     * Does this user satisfy a criteria list under the given join?
     *
     * An entry in the list is either a plain criterion or a GROUP — a `match` of
     * its own plus its own `criteria`. That is what makes a mixed condition
     * expressible: "in the editorial group AND (the post's category or its entity
     * matches mine)" is an `all` list holding one criterion and one `any` group.
     * A single flat `match` could only ever say all-AND or all-OR.
     *
     * One level deep, and the writer enforces that. Deeper nesting would be free
     * here — the recursion does not care — but it would need a settings editor
     * that can draw arbitrary trees, and a condition nobody can read in the UI is
     * worse than one the format refuses.
     *
     * @param  array<int, array<string, mixed>>  $criteria
     */
    private static function satisfies($user, $entry, array $criteria, string $match): bool
    {
        if ($criteria === []) {
            // An empty group admits nobody under `all` and nobody under `any`
            // either — there is no satisfied condition to admit them. The writer
            // refuses to store one, so this only guards a hand-edited YAML.
            return false;
        }

        $test = fn (array $node) => TransitionActions::isCriteriaGroup($node)
            ? self::satisfies($user, $entry, $node['criteria'], $node['match'])
            : AssignmentCriteria::matches($user, $entry, $node);

        return $match === TransitionActions::MATCH_ANY
            ? collect($criteria)->contains($test)
            : collect($criteria)->every($test);
    }
}
