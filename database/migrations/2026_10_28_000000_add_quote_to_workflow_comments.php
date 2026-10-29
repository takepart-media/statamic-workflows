<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'workflow';

    /**
     * The marked text a ROOT comment is about, Word-style.
     *
     *   quote         the selected text, verbatim; null = the whole passage.
     *   quote_offset  where it started, in characters of the passage's plain
     *                 text. A hint, not a key: the client looks for `quote`
     *                 near it and falls back to the passage when it is gone.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('workflow_comments')) {
            return;
        }

        $schema->table('workflow_comments', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('workflow_comments', 'quote')) {
                $table->text('quote')->nullable();
            }
            if (! $schema->hasColumn('workflow_comments', 'quote_offset')) {
                $table->unsignedInteger('quote_offset')->nullable();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('workflow_comments')) {
            return;
        }

        $schema->table('workflow_comments', function (Blueprint $table) use ($schema) {
            foreach (['quote', 'quote_offset'] as $column) {
                if ($schema->hasColumn('workflow_comments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
