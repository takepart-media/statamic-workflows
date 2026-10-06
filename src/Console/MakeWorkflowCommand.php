<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use TakepartMedia\Workflows\Workflow\DefinitionValidationException;
use TakepartMedia\Workflows\Workflow\WorkflowDefinitionWriter;
use TakepartMedia\Workflows\Workflow\WorkflowInstaller;
use TakepartMedia\Workflows\Workflow\WorkflowScaffolder;

/**
 * Scaffold a new workflow YAML in `resources/workflows/`. Two modes:
 *   - Blank: a minimal draft → published skeleton.
 *   - From example: one of the shipped example YAMLs under the new handle.
 * Built by WorkflowScaffolder and written by WorkflowDefinitionWriter::create()
 * — the same path as the CP's "create workflow" — so the handle rules, the
 * unique nav slug and the validation are the CP's. Never overwrites.
 *
 * CLI defaults differ from the CP on purpose and are kept from before: the
 * blank template is attached to `--collection` (posts), and the workflow is
 * written ACTIVE unless `--inactive` is given — whoever runs the command is
 * about to open the file anyway. An example keeps its own collections unless
 * `--collection` is passed explicitly.
 */
class MakeWorkflowCommand extends Command
{
    protected $signature = 'workflows:make
                            {name : Workflow handle (snake_case, e.g. postmanager)}
                            {--from= : Start from a shipped example (postmanager, translation, plainlanguage)}
                            {--collection=posts : Collection handle for the blank template (for --from: overrides the example\'s collections)}
                            {--label= : Display name (defaults to the handle)}
                            {--inactive : Write the workflow switched off (active: false)}';

    protected $description = 'Scaffold a new workflow YAML in resources/workflows/.';

    public function handle(WorkflowScaffolder $scaffolder, WorkflowDefinitionWriter $writer, WorkflowInstaller $installer): int
    {
        $name = (string) $this->argument('name');

        if (($key = $scaffolder->validateHandle($name)) !== null) {
            $this->error(__("workflows::messages.{$key}", ['handle' => $name, 'max' => WorkflowScaffolder::HANDLE_MAX]));

            return $key === 'create_handle_taken' ? self::FAILURE : self::INVALID;
        }

        $label = (string) ($this->option('label') ?: $name);
        $from = $this->option('from');

        if ($from && ! in_array($from, $installer->availableTypes(), true)) {
            $this->error("Example [{$from}] not found. Available: ".implode(', ', $installer->availableTypes()));

            return self::INVALID;
        }

        // `--collection` has a default, so "was it given" has to be read off
        // the raw input: an example keeps its own list unless overridden.
        $collectionGiven = $this->input->hasParameterOption('--collection');
        $collection = (string) $this->option('collection');

        try {
            $definition = $from
                ? $scaffolder->fromExample($from, $name, $label, $collectionGiven ? [$collection] : null)
                : $scaffolder->blank($name, $label, [$collection]);

            // The scaffolder builds inactive definitions (the CP's rule); the
            // CLI has always written active ones.
            $definition['active'] = ! $this->option('inactive');

            $writer->create($name, $definition);
        } catch (DefinitionValidationException $e) {
            $this->error($e->getMessage());

            foreach (array_slice($e->errors, 1) as $error) {
                $this->line("  • {$error['path']}: {$error['message']}");
            }

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created resources/workflows/{$name}.yaml".($from ? " (from example: {$from})" : '').'.');
        $this->line('Nav slug: '.$definition['metadata']['nav']['slug'].($definition['active'] ? '' : ' — inactive, switch it on in Settings › Workflows.'));

        $this->line('');
        $this->line('Next steps:');
        $this->line(" • Open resources/workflows/{$name}.yaml (or its settings editor) and define your places + transitions.");
        $this->line(' • Gate steps by group where needed (metadata.required_group) — the groups must exist in resources/users/groups.yaml.');
        $this->line(" • Reload the CP dashboard; the workflow appears in the 'workflows_overview' widget (config/statamic/cp.php → widgets).");

        return self::SUCCESS;
    }
}
