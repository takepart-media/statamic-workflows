<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Content\ContentTimestamp;
use TakepartMedia\Workflows\Support\EventLogSuppressor;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowResolver;

/**
 * Writes the initial `content_updated_at` for content that predates the field.
 *
 * The seed is ContentTimestamp::seed() — the entry's publication date, falling back
 * to the file's last-modified date for an undated collection. Deliberately the same
 * method the fallback in `for()` uses, so the backfill only ever writes down what
 * was already being read; a second source here would mean running this command
 * silently moved every deadline.
 *
 * Strictly a convenience, therefore: the SLA works before this ever runs. What it
 * buys is a value that can be sorted and compared per version — `own()` only
 * answers once a version has one of its own, and that is what the stale-marker
 * reads.
 *
 * Idempotent: an entry that already carries its own value is left alone, so a
 * second run reports zero and touches nothing. `--force` overwrites instead, which
 * is what a project needs after the seed source itself changes — and is a rewrite
 * of every deadline, so it is opt-in rather than the default.
 */
class BackfillContentTimestampCommand extends Command
{
    protected $signature = 'workflows:backfill-content-timestamp
        {--workflow= : Only the collections of this workflow (defaults to all authoring workflows)}
        {--force : Overwrite entries that already carry a timestamp}
        {--dry-run : Report what would be written, change nothing}';

    protected $description = 'Seed content_updated_at from Statamic\'s last-modified date for entries that have no value yet.';

    public function handle(DefinitionLoader $loader, WorkflowResolver $resolver, ContentTimestamp $timestamps): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('workflow');
        $collections = $this->collections($loader, $resolver, $only);

        if ($collections === []) {
            $this->warn('No authoring workflow governs any collection — nothing to backfill.');

            return self::SUCCESS;
        }

        $this->line('Collections: '.implode(', ', $collections));

        $written = 0;
        $skipped = 0;

        $force = (bool) $this->option('force');

        foreach (Entry::query()->whereIn('collection', $collections)->get() as $entry) {
            if (! $force && $timestamps->own($entry) !== null) {
                $skipped++;

                continue;
            }

            $seed = $timestamps->seed($entry);

            if ($seed === null) {
                // Neither a stored timestamp nor a readable file date. Writing
                // "now" would reset the clock of the oldest content in the
                // project, which is the opposite of what a backfill is for.
                $this->warn("{$entry->id()} ({$entry->locale()}): no last-modified date — skipped.");

                continue;
            }

            $written++;

            if ($dryRun) {
                $this->line("[dry-run] {$entry->id()} ({$entry->locale()}) → ".ContentTimestamp::stamp($seed));

                continue;
            }

            EventLogSuppressor::while(function () use ($entry, $seed) {
                $entry->set(ContentTimestamp::FIELD, ContentTimestamp::stamp($seed));
                $entry->save();
            });
        }

        $verb = $dryRun ? 'Would write' : 'Wrote';
        $this->info("{$verb} {$written} timestamp(s); {$skipped} already had one.");

        return self::SUCCESS;
    }

    /**
     * The collections whose entries carry a clock: those of the AUTHORING
     * workflows, mirroring InjectWorkflowFields. A localization workflow adds
     * nothing here — its versions live in the same collections.
     *
     * @return array<int, string>
     */
    private function collections(DefinitionLoader $loader, WorkflowResolver $resolver, ?string $only): array
    {
        $names = $only !== null && $only !== '' ? [$only] : $loader->all();
        $collections = [];

        foreach ($names as $name) {
            if (! in_array($name, $loader->all(), true)) {
                $this->warn("Workflow [{$name}] not found — skipped.");

                continue;
            }

            $raw = $loader->raw($name);

            if ($resolver->isLocalizationWorkflow($raw)) {
                continue;
            }

            foreach ((array) ($raw['collections'] ?? []) as $collection) {
                if (is_string($collection) && $collection !== '') {
                    $collections[$collection] = true;
                }
            }
        }

        return array_keys($collections);
    }
}
