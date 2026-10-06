<?php

namespace TakepartMedia\Workflows\Workflow\Filters;

use Statamic\Query\Scopes\Filter;

/**
 * Base for the workflow queue's listing filters. Each filter is a single
 * select whose options are injected as context by the controller (derived from
 * the current rows). The actual row filtering happens in the controller's
 * `listing()`, which builds its rows from entries rather than a query builder,
 * so the query-based apply() is a no-op — these classes exist to produce the
 * native filter definition (fields + fieldtype meta) the CP Listing expects,
 * and the active-filter badges.
 */
abstract class WorkflowFilter extends Filter
{
    public function fieldItems()
    {
        return [
            static::handle() => [
                'type' => 'select',
                'clearable' => true,
                'placeholder' => $this->placeholder(),
                'options' => $this->context['options'] ?? [],
            ],
        ];
    }

    abstract protected function placeholder(): string;

    public function apply($query, $values)
    {
        // Filtering is applied to the assembled rows in WorkflowController::listing().
    }

    public function badge($values)
    {
        $value = $values[static::handle()] ?? null;
        $options = $this->context['options'] ?? [];

        return $this->title().': '.($options[$value] ?? $value);
    }
}
