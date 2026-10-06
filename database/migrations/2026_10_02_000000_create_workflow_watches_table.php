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
        if (Schema::connection($this->connection)->hasTable('workflow_watches')) {
            return;
        }

        Schema::connection($this->connection)->create('workflow_watches', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');      // the watching Statamic user id
            $table->string('subject_id');   // Statamic entry id (the localization)
            $table->string('workflow');     // workflow definition name
            $table->timestamps();

            $table->unique(['user_id', 'subject_id', 'workflow'], 'wf_watches_unique');
            $table->index(['user_id', 'workflow']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('workflow_watches');
    }
};
