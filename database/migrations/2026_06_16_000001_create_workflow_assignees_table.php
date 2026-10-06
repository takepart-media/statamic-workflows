<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Entry;
use TakepartMedia\Workflows\Workflow\DefinitionLoader;
use TakepartMedia\Workflows\Workflow\WorkflowAssigneeStore;

return new class extends Migration
{
    /**
     * The dedicated, isolated SQLite database for the workflow engine.
     */
    protected $connection = 'workflow';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('workflow_assignees')) {
            return;
        }

        Schema::connection($this->connection)->create('workflow_assignees', function (Blueprint $table) {
            $table->id();
            $table->string('subject_id');   // Statamic entry id (the localization)
            $table->string('workflow');     // workflow definition name, e.g. post_update
            $table->string('user_id');      // assigned Statamic user id
            $table->timestamps();

            $table->unique(['subject_id', 'workflow', 'user_id'], 'wf_assignees_unique');
            $table->index(['subject_id', 'workflow']);
        });

        $this->importExistingAssignees();
    }

    /**
     * One-time migration of the previous per-entry `assignee` field values into
     * the workflow store. Reads the raw, site-specific value from each entry
     * localization so existing editorial assignments aren't lost when the field
     * is removed from the blueprint. Guarded so a headless migrate never fails.
     */
    private function importExistingAssignees(): void
    {
        if (! class_exists(Entry::class)) {
            return;
        }

        try {
            $store = app(WorkflowAssigneeStore::class);

            foreach (app(DefinitionLoader::class)->all() as $workflow) {
                $collections = app(DefinitionLoader::class)->raw($workflow)['collections'] ?? [];

                if ($collections === []) {
                    continue;
                }

                Entry::query()->whereIn('collection', $collections)->get()->each(function ($entry) use ($store, $workflow) {
                    $ids = $entry->get('assignee');
                    $ids = is_array($ids) ? $ids : ($ids === null || $ids === '' ? [] : [$ids]);
                    $ids = array_values(array_filter($ids));

                    if ($ids !== []) {
                        $store->set($entry->id(), $workflow, $ids);
                    }
                });
            }
        } catch (\Throwable $e) {
            // Stache not warm / no entries yet — assignments can still be set in the UI.
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('workflow_assignees');
    }
};
