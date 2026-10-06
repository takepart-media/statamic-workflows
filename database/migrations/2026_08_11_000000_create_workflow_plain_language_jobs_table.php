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
        if (Schema::connection($this->connection)->hasTable('workflow_plain_language_jobs')) {
            return;
        }

        Schema::connection($this->connection)->create('workflow_plain_language_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_id');            // the TARGET localization the texts get written into
            $table->string('workflow');              // workflow definition name, e.g. translation
            $table->string('text_id')->unique();     // the service's own job id
            $table->unsignedInteger('chunk');        // which request of the run this was
            // The segment paths this job's texts came from, in order — `output_text[i]`
            // belongs to `segment_keys[i]`. Persisted rather than recomputed: the split
            // used to be reproducible from the source (see TranslationRun), but the
            // write-back now happens minutes later against an entry an editor may have
            // touched in the meantime, and a shifted path writes text into the wrong
            // field. Storing the keys removes the assumption entirely.
            $table->json('segment_keys');
            $table->string('status')->default('pending');   // pending|processing|completed|failed
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['status', 'id'], 'wf_pl_jobs_status');
            $table->index(['subject_id', 'workflow'], 'wf_pl_jobs_subject');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('workflow_plain_language_jobs');
    }
};
