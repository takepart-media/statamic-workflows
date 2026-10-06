<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dedicated, isolated SQLite database for the workflow engine.
     */
    protected $connection = 'workflow';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('workflow_events')) {
            return;
        }

        Schema::connection($this->connection)->create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->string('subject_id');          // Statamic entry id (the localization)
            $table->string('workflow');            // workflow definition name
            // Free-form on purpose — new event kinds need no migration. Entry-scoped:
            // 'transition' | 'set_state' | 'created' | 'localized' | 'revision_sync' |
            // 'draft_save'. Workflow-scoped (subject_id = 'workflow:<name>'):
            // 'definition_saved' | 'place_deleted' | 'collections_removed'.
            $table->string('type');
            $table->string('transition')->nullable();
            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();
            $table->string('user_id')->nullable(); // who caused the event; nullable for system events
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['subject_id', 'workflow']);
            $table->index(['workflow', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('workflow_events');
    }
};
