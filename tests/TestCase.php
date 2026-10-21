<?php

namespace TakepartMedia\Workflows\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Stache;
use Statamic\Testing\AddonTestCase;
use TakepartMedia\Workflows\ServiceProvider;
use TakepartMedia\Workflows\Tests\Support\TestDefinitionLoader;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;

/**
 * Base for every addon test. Boots Statamic + the addon through Statamic's
 * AddonTestCase (Orchestra Testbench underneath) and keeps every write away
 * from real content:
 *
 *  - the addon's `workflow` connection is an in-memory SQLite database, migrated
 *    fresh for each test;
 *  - every Stache store, the blueprint/fieldset directories, revisions and the
 *    project's `resources/workflows` directory point into a per-test temp
 *    directory that is deleted again in tearDown().
 */
abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    /** Per-test scratch root; see tempPath(). */
    protected string $tempRoot;

    /** Stache stores redirected into the temp root (store key => sub directory). */
    private const STACHE_STORES = [
        'taxonomies' => 'content/taxonomies',
        'terms' => 'content/taxonomies',
        'collections' => 'content/collections',
        'entries' => 'content/collections',
        'navigation' => 'content/navigation',
        'globals' => 'content/globals',
        'global-variables' => 'content/globals',
        'asset-containers' => 'content/assets',
        'nav-trees' => 'content/structures/navigation',
        'collection-trees' => 'content/structures/collections',
        'form-submissions' => 'content/submissions',
        'users' => 'users',
    ];

    protected function setUp(): void
    {
        // getEnvironmentSetUp() runs inside parent::setUp(), so the root has to
        // exist before it.
        $this->tempRoot = sys_get_temp_dir().'/workflows-tests-'.bin2hex(random_bytes(6));
        mkdir($this->tempRoot, 0777, true);

        parent::setUp();

        Blueprint::setDirectory($this->tempPath('blueprints'));
        Fieldset::setDirectory($this->tempPath('fieldsets'));

        File::ensureDirectoryExists($this->workflowsDirectory());
        $this->app->bind(DefinitionLoader::class, fn () => new TestDefinitionLoader($this->workflowsDirectory()));
        DefinitionLoader::flush();

        $this->artisan('migrate', ['--database' => 'workflow'])->run();
    }

    protected function tearDown(): void
    {
        DefinitionLoader::flush();

        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->tempRoot);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $memory = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        // Both keys: the provider copies `workflows.database` into the
        // connection at register time, and the order of that versus this
        // method is Testbench's business, not ours.
        $app['config']->set('workflows.database', $memory);
        $app['config']->set('database.connections.workflow', $memory);

        foreach (self::STACHE_STORES as $store => $dir) {
            $app['config']->set("statamic.stache.stores.$store.directory", $this->tempPath($dir));
        }

        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.revisions.path', $this->tempPath('revisions'));
        $app['config']->set('statamic.system.multisite', false);
    }

    /**
     * An absolute path inside this test's scratch directory.
     */
    protected function tempPath(string $path = ''): string
    {
        return rtrim($this->tempRoot.'/'.ltrim($path, '/'), '/');
    }

    /**
     * Where DefinitionLoader looks for workflow YAMLs during this test (the
     * stand-in for the project's `resources/workflows`).
     */
    protected function workflowsDirectory(): string
    {
        return $this->tempPath('resources/workflows');
    }

    /**
     * Clear the Stache so data saved earlier in a test is re-read from disk.
     */
    protected function refreshStache(): void
    {
        Stache::clear();
    }
}
