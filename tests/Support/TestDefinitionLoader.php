<?php

namespace TakepartMedia\Workflows\Tests\Support;

use TakepartMedia\Workflows\Workflow\DefinitionLoader;

/**
 * DefinitionLoader reading from a test's temp directory instead of the
 * project's `resource_path('workflows')`. Bound in the container by TestCase,
 * so everything that resolves the loader (WorkflowDefinitionWriter, the
 * resolver, controllers, commands) sees the same directory.
 */
class TestDefinitionLoader extends DefinitionLoader
{
    public function __construct(private string $directory) {}

    public function directory(): string
    {
        return $this->directory;
    }
}
