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
        if (Schema::connection($this->connection)->hasTable('workflow_states')) {
            return;
        }

        Schema::connection($this->connection)->create('workflow_states', function (Blueprint $table) {
            $table->id();
            $table->string('subject_id');   // Statamic entry id
            $table->string('workflow');     // workflow definition name, e.g. post_update
            $table->string('state');        // workflow place handle
            $table->timestamps();

            $table->unique(['subject_id', 'workflow']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('workflow_states');
    }
};
