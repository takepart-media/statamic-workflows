<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowStateSeeder;

/**
 * Backfill workflow states for entries that predate the workflow. The rules live
 * in WorkflowStateSeeder (shared with the install command and the settings
 * editor); this is the manual entry point for an existing project.
 *
 * Idempotent — entries that already carry a state are skipped, so it is safe to
 * re-run after adding a collection or installing another workflow.
 */
class SeedStatesCommand extends Command
{
    protected $signature = 'workflows:seed-states
        {--workflow= : Seed only this workflow (defaults to all)}
        {--redo : Also re-derive entries that already have a state, as long as nobody has worked on them}';

    protected $description = 'Backfill workflow states for existing entries. Idempotent.';

    public function handle(DefinitionLoader $loader, WorkflowStateSeeder $seeder): int
    {
        $only = $this->option('workflow');
        $redo = (bool) $this->option('redo');
        $available = $loader->all();
        $names = $only ? [$only] : $available;
        $total = 0;

        if ($redo) {
            $this->line('Re-deriving existing states. Entries with workflow history are left untouched.');
        }

        foreach ($names as $name) {
            if (! in_array($name, $available, true)) {
                // An explicitly named workflow that exists but is switched off is
                // worth its own message: the YAML is right there, and "not found"
                // would send the operator looking for a missing file.
                if (in_array($name, $loader->allIncludingInactive(), true)) {
                    $this->warn("Workflow [{$name}] is switched off (active: false) — skipped. Activate it in Settings > Workflows first.");
                } else {
                    $this->warn("Workflow [{$name}] not found — skipped.");
                }

                continue;
            }

            // A workflow without collections has no entries to seed.
            if (($loader->raw($name)['collections'] ?? []) === []) {
                continue;
            }

            $seeded = $seeder->seed($name, null, $redo);
            $total += $seeded;

            $this->line(" • {$name}: {$seeded} state(s)");

            // Worth saying out loud: with no "finished" place EVERY entry lands in
            // the initial place, which is rarely what the operator pictured.
            // `metadata.seed_published_place` fixes it.
            if ($seeded > 0 && $seeder->lacksDonePlace($name)) {
                $this->warn("   [{$name}] declares neither a publish place nor metadata.seed_published_place — every entry was seeded into the initial place.");
            }
        }

        $this->info("Seeded {$total} entry state(s).");

        return self::SUCCESS;
    }
}
