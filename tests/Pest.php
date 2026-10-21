<?php

use TakepartMedia\Workflows\Tests\Concerns\CreatesContent;
use TakepartMedia\Workflows\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Every test under tests/ runs on the addon TestCase: Statamic + the addon
| booted, `workflow` connection in memory, Stache/blueprints/workflow YAMLs in
| a per-test temp directory. CreatesContent adds makeCollection(),
| makeBlueprint(), makeEntry(), writeWorkflow() and minimalWorkflow().
|
*/

pest()->extend(TestCase::class)->use(CreatesContent::class)->in(__DIR__);
