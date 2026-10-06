<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'workflow';

    /**
     * What a comment IS, beyond who wrote it.
     *
     * Almost every row is an editor's remark and stays `null`. The exception is
     * the plain-language service's QA report (`qs_notes`), which
     * PollPlainLanguageCommand files as a comment so the medical review finds it
     * where it is already looking. Marking it lets a reader ask for that one
     * report by name instead of inferring it from `user_id IS NULL` — an
     * inference that holds today only because exactly one code path writes a
     * system comment, and that would break silently the first time a second one
     * does.
     */
    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('workflow_comments')) {
            return;
        }

        if (! Schema::connection($this->connection)->hasColumn('workflow_comments', 'kind')) {
            Schema::connection($this->connection)->table('workflow_comments', function (Blueprint $table) {
                $table->string('kind')->nullable()->after('author_name');
                $table->index(['subject_id', 'workflow', 'kind'], 'wf_comments_kind');
            });
        }

        // Backfill. Every author-less comment that exists at this point is a QA
        // report: recordNotes() is the only writer that passes `user_id: null`
        // (the comment endpoint always has a user). Doing it here rather than
        // leaving history unmarked means the alert works for rewrites that
        // already ran, not just for the next one.
        DB::connection($this->connection)
            ->table('workflow_comments')
            ->whereNull('user_id')
            ->whereNull('kind')
            ->update(['kind' => 'plain_language_qs']);
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('workflow_comments', 'kind')) {
            return;
        }

        Schema::connection($this->connection)->table('workflow_comments', function (Blueprint $table) {
            $table->dropIndex('wf_comments_kind');
            $table->dropColumn('kind');
        });
    }
};
