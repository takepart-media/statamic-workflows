<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;

/**
 * Scaffold a new workflow YAML in `resources/workflows/`. Two modes:
 *   - Blank: creates a minimal draft → published skeleton.
 *   - From example: copies one of the shipped example YAMLs and renames it.
 * Never overwrites; refuses if the target file already exists.
 */
class MakeWorkflowCommand extends Command
{
    protected $signature = 'workflows:make
                            {name : Workflow handle (snake_case, e.g. postmanager)}
                            {--from= : Start from a shipped example (postmanager, translation)}
                            {--collection=posts : Collection handle for the blank template}';

    protected $description = 'Scaffold a new workflow YAML in resources/workflows/.';

    public function handle(DefinitionLoader $loader): int
    {
        $name = $this->argument('name');

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $this->error("Invalid handle [{$name}] — use snake_case, start with a letter.");

            return self::INVALID;
        }

        File::ensureDirectoryExists($loader->directory());
        $target = $loader->directory().'/'.$name.'.yaml';

        if (File::exists($target)) {
            $this->error("resources/workflows/{$name}.yaml already exists — refusing to overwrite.");

            return self::FAILURE;
        }

        if ($from = $this->option('from')) {
            $source = $loader->examplesDirectory().'/'.$from.'.yaml';

            if (! File::exists($source)) {
                $available = collect(glob($loader->examplesDirectory().'/*.yaml') ?: [])
                    ->map(fn ($f) => basename($f, '.yaml'))
                    ->implode(', ');
                $this->error("Example [{$from}] not found. Available: {$available}");

                return self::INVALID;
            }

            $yaml = File::get($source);
            // Rewrite the `name:` line so the new file identifies itself correctly.
            $yaml = preg_replace('/^name:\s*\S+/m', "name: {$name}", $yaml, 1);
            File::put($target, $yaml);
            $this->info("Created resources/workflows/{$name}.yaml (from example: {$from})");
        } else {
            File::put($target, $this->blankTemplate($name, $this->option('collection')));
            $this->info("Created resources/workflows/{$name}.yaml");
        }

        // Nothing has read this file yet, so there is no stale memo to drop — but
        // every writer of a definition flushing is a rule that holds without being
        // rechecked, and one line is cheaper than the next person wondering.
        DefinitionLoader::flush();

        $this->line('');
        $this->line('Next steps:');
        $this->line(" • Open resources/workflows/{$name}.yaml and define your places + transitions.");
        $this->line(' • Ensure the user groups you reference (metadata.required_group) exist in resources/users/groups.yaml.');
        $this->line(" • Reload the CP dashboard; the workflow appears in the 'workflows_overview' widget (config/statamic/cp.php → widgets).");

        return self::SUCCESS;
    }

    private function blankTemplate(string $name, string $collection): string
    {
        $navSlug = str_replace('_', '-', $name);

        return <<<YAML
name: {$name}
type: state_machine
marking_store: { type: method, property: current_state }
collections: [{$collection}]
initial_place: draft
metadata:
  label: '{$name}'
  nav: { label: '{$name}', slug: {$navSlug}, icon: tasks, order: 10 }
places:
  draft:
    label: Draft
    metadata: { required_group: editor }
  published:
    label: Published
    metadata: { required_group: editor, allow_publish: true }
transitions:
  publish:
    from: draft
    to: published
    metadata: { label: 'Publish', required_group: editor }
  unpublish:
    from: published
    to: draft
    metadata: { label: 'Unpublish', required_group: editor }
YAML;
    }
}
