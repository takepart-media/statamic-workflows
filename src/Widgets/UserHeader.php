<?php

namespace TakepartMedia\Workflows\Widgets;

use Illuminate\Support\Str;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Widgets\Widget;
use TakepartMedia\Workflows\Workflow\AssignmentCriteria;

/**
 * CP dashboard card introducing the current user in workflow terms: their
 * name, their group memberships, and one line per configured assignment
 * criterion that reads a user field (e.g. the project's "entities" — whatever
 * `config/workflows.php → assignment_criteria` declares, nothing hardcoded).
 *
 * A component widget, not a Blade one: component() hands the dashboard the Vue
 * component `workflows-user-header-widget` (registered in
 * `resources/js/addon.js`), which draws the avatar with Statamic's own `Avatar`
 * — the component the assignee lists use, unreachable from server-side Blade.
 *
 * No widget config of its own. The card renders whatever lines() returns, so a
 * further line only has to be appended there; the component needs no change.
 * The last one, "Verantwortlicher" — who answers for the current user's
 * affiliation — is config-driven rather than hardcoded: `header_responsibles`
 * in `config/workflows.php` names the group and the shared user field, and
 * without both the row does not exist.
 */
class UserHeader extends Widget
{
    protected static $handle = 'workflows_user_header';

    public function component()
    {
        $user = User::current();

        // No html() either, and DashboardController drops a widget only when
        // both are empty — so null is how this card hides itself.
        if ($user === null) {
            return null;
        }

        return [
            'name' => 'workflows-user-header-widget',
            'props' => [
                'user' => [
                    'id' => (string) $user->id(),
                    'name' => $user->name() ?? $user->email(),
                    // Same source as the CP's own avatar: the avatar field or
                    // gravatar where `statamic.users.avatars` enables them
                    // (then a URL), otherwise null — `Avatar` then falls back
                    // to the initials on a name-derived gradient.
                    'avatar' => $user->avatar(),
                    'initials' => $user->initials(),
                ],
                'lines' => $this->lines($user),
            ],
        ];
    }

    /**
     * The label/values rows below the name: groups first, then ONE
     * "Zugehörigkeit" row collecting every value the configured criteria read
     * off the person, and finally the "Verantwortlicher" row naming the people
     * who answer for that affiliation.
     *
     * One affiliation row rather than one per criterion or field: several
     * criteria may read the same field (one per entry side they compare
     * against), the person only has the one value, and what all these fields
     * express is the same sentence — where this user belongs. Grouped per field
     * internally so each field's values are labeled through the option sources
     * of exactly the criteria that read it.
     *
     * @param  \Statamic\Contracts\Auth\User  $user
     * @return array<int, array{label: string, values: array<int, string>}>
     */
    private function lines($user): array
    {
        $groups = $user->groups()->map->title()->filter()->unique()->values()->all();

        $lines = [[
            'label' => __('workflows::messages.widget_header_groups'),
            'values' => $groups !== []
                ? $groups
                : [__('workflows::messages.widget_header_no_groups')],
        ]];

        // Built-ins (group/role) declare no user field; groups are already
        // their own line above.
        $affiliation = collect(AssignmentCriteria::all())
            ->filter(fn (array $criterion) => $criterion['user_field'] !== null)
            ->groupBy('user_field')
            ->flatMap(fn ($sharing, $field) => $this->userValues($user, (string) $field, $sharing->all()))
            ->unique()
            ->values()
            ->all();

        // Omitted rather than rendered as an empty row when the user carries
        // no values in any criteria field.
        if ($affiliation !== []) {
            $lines[] = [
                'label' => __('workflows::messages.widget_header_affiliation'),
                'values' => $affiliation,
            ];
        }

        $responsibles = $this->responsibles($user);

        if ($responsibles !== []) {
            $lines[] = [
                'label' => __('workflows::messages.widget_header_responsible'),
                'values' => $responsibles,
            ];
        }

        return $lines;
    }

    /**
     * The people responsible for this user's affiliation: members of the
     * configured group whose values in the configured field overlap the current
     * user's values in the SAME field. Display names, naturally sorted.
     *
     * Config-driven (`workflows.header_responsibles`) rather than derived from
     * the assignment criteria, because it is a different question: the criteria
     * say who a POST may be handed to, this says who a PERSON asks. The two use
     * the same comparison — overlapping bare slugs — but not the same fields,
     * and a project pairs them as it likes.
     *
     * Empty, and therefore no row at all, in four cases: the config is off, the
     * group does not exist, the current user carries no values in the field, or
     * nobody in the group shares one. The user is always dropped from their own
     * row — a topic owner is not their own point of contact.
     *
     * @param  \Statamic\Contracts\Auth\User  $user
     * @return array<int, string>
     */
    private function responsibles($user): array
    {
        $config = (array) config('workflows.header_responsibles', []);

        $group = is_string($config['group'] ?? null) && $config['group'] !== '' ? $config['group'] : null;
        $field = is_string($config['user_field'] ?? null) && $config['user_field'] !== '' ? $config['user_field'] : null;

        if ($group === null || $field === null) {
            return [];
        }

        $mine = $this->bareValues($user->get($field));

        if ($mine === []) {
            return [];
        }

        $id = (string) $user->id();

        return collect(UserGroup::find($group)?->users() ?? [])
            ->reject(fn ($candidate) => (string) $candidate->id() === $id)
            ->filter(fn ($candidate) => array_intersect($mine, $this->bareValues($candidate->get($field))) !== [])
            ->map(fn ($candidate) => (string) ($candidate->name() ?? $candidate->email()))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * A stored field value as a comparable list of bare slugs — the same
     * normalisation AssignmentCriteria::valueList() does (private there), so
     * `themen::gesundheit` on one person matches `gesundheit` on the next, and
     * either form matches the bare slug the option lists carry.
     *
     * Restated here rather than opened up over there: it is one expression, and
     * AssignmentCriteria's private helpers are private because they serve its
     * own matching — a widget is not a reason to widen that API. Both callers in
     * this class (userValues() for the labels, responsibles() for the overlap)
     * go through it.
     *
     * @return array<int, string>
     */
    private function bareValues($raw): array
    {
        return collect(is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]))
            ->filter(fn ($value) => is_string($value) || is_numeric($value))
            ->map(fn ($value) => str_contains((string) $value, '::') ? Str::after((string) $value, '::') : (string) $value)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The user's values for one field, as display labels: raw field values
     * mapped through the option lists of every criterion reading the field
     * (taxonomy terms, groups, roles, sites — whatever the config names, first
     * match wins), falling back to the bare slug where no option matches
     * (deleted term, free-typed handle).
     *
     * @param  \Statamic\Contracts\Auth\User  $user
     * @param  array<int, array{options: mixed}>  $criteria
     * @return array<int, string>
     */
    private function userValues($user, string $field, array $criteria): array
    {
        // Bare slugs, because that is what the options below carry: a terms
        // field stores `themen::gesundheit` when it may point at several
        // taxonomies. See bareValues().
        $values = collect($this->bareValues($user->get($field)));

        if ($values->isEmpty()) {
            return [];
        }

        $labels = collect($criteria)
            ->flatMap(fn (array $criterion) => AssignmentCriteria::options($criterion))
            ->reverse()
            ->mapWithKeys(fn (array $option) => [$option['value'] => $option['label']]);

        return $values
            ->map(fn (string $value) => (string) ($labels[$value] ?? $value))
            ->all();
    }
}
