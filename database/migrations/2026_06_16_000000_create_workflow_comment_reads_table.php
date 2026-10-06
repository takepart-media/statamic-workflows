<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'workflow';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('workflow_comment_reads')) {
            return;
        }

        Schema::connection($this->connection)->create('workflow_comment_reads', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('subject_id');
            $table->string('workflow');
            $table->string('passage_ref');   // the fieldset/passage uuid
            $table->timestamp('seen_at');     // last time the user viewed this passage's comments
            $table->timestamps();

            $table->unique(['user_id', 'subject_id', 'workflow', 'passage_ref'], 'wf_comment_reads_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('workflow_comment_reads');
    }
};
