<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TakepartMedia\Workflows\ServiceProvider;
use TakepartMedia\Workflows\Workflow\WorkflowState;

/**
 * Point the addon at the app connection `app` and re-run its registration, as a
 * project setting `workflows.connection` would.
 */
function share_app_connection(): void
{
    config([
        'database.connections.app' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        'workflows.connection' => 'app',
    ]);
    DB::purge('workflow');
    app()->getProvider(ServiceProvider::class)->register();
}

it('resolves the workflow connection to the shared app connection', function () {
    share_app_connection();

    expect(DB::connection('workflow'))->toBe(DB::connection('app'));
});

it('commits workflow rows in the same transaction as the app models', function () {
    share_app_connection();
    Schema::connection('app')->create('workflow_states', function ($table) {
        $table->id();
        $table->string('subject_id');
        $table->string('workflow');
        $table->string('state');
        $table->timestamp('entered_at')->nullable();
        $table->timestamps();
    });

    try {
        DB::connection('app')->transaction(function () {
            WorkflowState::create(['subject_id' => 'runway::item::1', 'workflow' => 'w', 'state' => 'draft']);

            throw new RuntimeException('domain save failed');
        });
    } catch (RuntimeException) {
    }

    expect(WorkflowState::count())->toBe(0);
});
