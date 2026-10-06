<?php

namespace TakepartMedia\Workflows\Console;

use Illuminate\Console\Command;
use Symfony\Component\Workflow\Dumper\MermaidDumper;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;

class DumpWorkflowCommand extends Command
{
    protected $signature = 'workflow:dump {name : The workflow name (file basename in resources/workflows)} {--format=mermaid : Output format (mermaid)}';

    protected $description = 'Dump a workflow definition as diagram source (Mermaid).';

    public function handle(DefinitionLoader $loader): int
    {
        $name = $this->argument('name');
        $format = $this->option('format');

        if ($format !== 'mermaid') {
            $this->error("Unsupported format [{$format}]. Only 'mermaid' is supported.");

            return self::FAILURE;
        }

        try {
            $raw = $loader->raw($name);
            $definition = $loader->load($name);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $transitionType = ($raw['type'] ?? 'workflow') === 'state_machine'
            ? MermaidDumper::TRANSITION_TYPE_STATEMACHINE
            : MermaidDumper::TRANSITION_TYPE_WORKFLOW;

        $dumper = new MermaidDumper($transitionType);

        $this->output->writeln($dumper->dump($definition));

        return self::SUCCESS;
    }
}
