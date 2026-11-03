<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowInstaller;

use function Laravel\Prompts\multiselect;

/**
 * One-shot bootstrapping for a fresh project: runs the addon migrations,
 * publishes assets, and asks the operator which workflow types to seed. Per-
 * type follow-up prompts ("which collections?", "backfill states?") live inline
 * in `handle()` — keep them there so the install flow stays self-documenting.
 *
 * A new type needs nothing but a YAML in `resources/workflows/examples/`: the
 * list, its labels and the CP modal all derive from that directory
 * (WorkflowInstaller::describedTypes), so there is no case to add here.
 */
class InstallCommand extends Command
{
    protected $signature = 'workflows:install';

    protected $description = 'Install the Workflows addon (migrate, publish assets, pick a workflow type).';

    public function handle(WorkflowInstaller $installer, DefinitionLoader $loader): int
    {
        $this->info('Workflows addon — install');
        $this->line('');

        $this->task('Run migrations', function () {
            $this->call('migrate', ['--database' => 'workflow', '--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true, '--force' => true]);
        });

        $this->task('Publish assets', function () {
            $this->call('vendor:publish', ['--tag' => 'workflows', '--force' => true]);
        });

        $described = $installer->describedTypes();

        if ($described === []) {
            $this->warn('No workflow types shipped with this addon.');
        } else {
            // Several types in one run, because they belong together: a project that
            // wants translations usually wants the post manager that feeds them, and
            // the plain-language manager alongside. Picking one and re-running the
            // whole install for the next was the only way before.
            //
            // Preselects postmanager where it is shipped and not installed yet — the
            // flow most projects start with.
            $options = collect($described)
                ->mapWithKeys(fn (array $type) => [
                    $type['value'] => $type['label'] === $type['value']
                        ? $type['value']
                        : "{$type['label']}  ({$type['value']})".($type['installed'] ? ' — already installed' : ''),
                ])
                ->all();

            $default = collect($described)
                ->contains(fn (array $type) => $type['value'] === 'postmanager' && ! $type['installed'])
                    ? ['postmanager']
                    : [];

            $this->line('');
            $picked = (array) multiselect(
                label: 'Which workflow types do you want to install?',
                options: $options,
                default: $default,
                hint: 'Space to select, arrows to move, enter to confirm.',
                scroll: 15,
            );

            if ($picked === []) {
                $this->line('  Nothing picked — no workflow installed.');
            }

            foreach ($picked as $type) {
                $this->line('');

                // An existing definition is the project's own — hand-tuned or
                // edited via the CP settings editor. We never overwrite it, but
                // we still walk through collections + backfill so a rerun can
                // attach the workflow to additional collections or (re-)seed
                // states for entries the previous run did not touch.
                if ($installer->isInstalled($type)) {
                    $this->info("resources/workflows/{$type}.yaml already exists — keeping it as-is and re-configuring collections only.");
                } else {
                    $installer->copyExampleIfMissing($type);
                    $this->info("Copied → resources/workflows/{$type}.yaml");
                }

                $this->askForCollections($installer, $type);
                $this->askForBackfill($installer, $type);
            }
        }

        $this->line('');
        $this->info('Done.');
        $this->line('Next steps:');
        $this->line(' • Edit resources/workflows/*.yaml to fine-tune places, transitions and collections.');
        $this->line(' • Ensure the user groups referenced by your workflow(s) exist in resources/users/groups.yaml.');
        $this->line(" • Open the CP dashboard; the 'workflows_overview' widget (config/statamic/cp.php → widgets) lists the posts of every active workflow.");

        return self::SUCCESS;
    }

    private function askForCollections(WorkflowInstaller $installer, string $type): void
    {
        $available = $installer->projectCollections();

        if ($available === []) {
            $this->warn('No Statamic collections in this project — leaving [collections] empty. Edit resources/workflows/'.$type.'.yaml once you have collections to attach.');
            $installer->rewriteCollections($type, []);

            return;
        }

        $this->line('');
        $picked = multiselect(
            label: "Which collections should [{$type}] govern?",
            options: array_combine($available, $available),
            hint: 'Space to select, arrows to move, enter to confirm.',
            scroll: 15,
        );

        $picked = $installer->rewriteCollections($type, (array) $picked);

        if ($picked === []) {
            $this->line('  No collections attached. Add them later in the CP settings editor or by editing the YAML.');
        } else {
            $this->info('  Collections set → '.implode(', ', $picked));
        }
    }

    private function askForBackfill(WorkflowInstaller $installer, string $type): void
    {
        $collections = $installer->currentCollections($type);

        if ($collections === []) {
            return;
        }

        if ($this->confirm("Apply [{$type}] to all existing entries of ".implode(', ', $collections)." (backfill states)?", false)) {
            $installer->backfill($type);
        }
    }

    private function task(string $label, callable $callback): void
    {
        $this->line(" • {$label}...");
        $callback();
    }
}
