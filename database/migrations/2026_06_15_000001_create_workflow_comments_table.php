<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'workflow';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('workflow_comments')) {
            return;
        }

        Schema::connection($this->connection)->create('workflow_comments', function (Blueprint $table) {
            $table->id();
            $table->string('subject_id');        // Statamic entry id (or fixture instance id)
            $table->string('workflow');          // workflow definition name
            $table->string('passage_ref')->nullable();    // referenced passage uuid / bard set id
            $table->string('passage_handle')->nullable(); // referenced fieldset handle
            $table->text('body');
            $table->string('user_id')->nullable();
            $table->string('author_name');
            $table->timestamps();

            $table->index(['subject_id', 'workflow']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('workflow_comments');
    }
};
