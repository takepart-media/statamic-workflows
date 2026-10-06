<?php

namespace TakepartMedia\Workflows\Support;

/**
 * Marks the addon's own writes so the draft-save logger stays out of them.
 *
 * A transition, a manual state override, a localization and a new post all save
 * the entry and/or write a Statamic revision as part of doing their job, and each
 * of them already logs a workflow event with its own snapshot. Without this guard
 * those saves would ALSO produce a `draft_save` row — and in the transition's case
 * that row would land BEFORE the transition it belongs to, because
 * WorkflowTransitioner syncs the published flag before logging the step.
 *
 * A depth counter rather than a boolean, so a nested operation (a transition that
 * internally saves, which is exactly what happens) doesn't lift the guard when the
 * inner scope ends. `finally` keeps the counter honest when the callback throws.
 */
class EventLogSuppressor
{
    private static int $depth = 0;

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Run the callback with entry-save logging suppressed, returning its value.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function while(callable $callback)
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
