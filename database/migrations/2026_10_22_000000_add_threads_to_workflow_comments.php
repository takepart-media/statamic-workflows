<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'workflow';

    /**
     * Word-like comment threads.
     *
     *   parent_id         a reply's ROOT comment. One level deep by design: a
     *                     reply always points at a root, never at another reply.
     *   resolved_at/_by   set on a ROOT only — resolving closes the whole thread.
     *   resolved_by_name  frozen display name, like `author_name`, so a deleted
     *                     account still reads as somebody.
     *   edited_at         last edit by the author; null = never edited.
     *   mentions          JSON list of mentioned user ids.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('workflow_comments')) {
            return;
        }

        $schema->table('workflow_comments', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('workflow_comments', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable()->index('wf_comments_parent');
            }
            if (! $schema->hasColumn('workflow_comments', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable();
            }
            if (! $schema->hasColumn('workflow_comments', 'resolved_by')) {
                $table->string('resolved_by')->nullable();
            }
            if (! $schema->hasColumn('workflow_comments', 'resolved_by_name')) {
                $table->string('resolved_by_name')->nullable();
            }
            if (! $schema->hasColumn('workflow_comments', 'edited_at')) {
                $table->timestamp('edited_at')->nullable();
            }
            if (! $schema->hasColumn('workflow_comments', 'mentions')) {
                $table->text('mentions')->nullable();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('workflow_comments')) {
            return;
        }

        if ($schema->hasColumn('workflow_comments', 'parent_id')) {
            $schema->table('workflow_comments', fn (Blueprint $table) => $table->dropIndex('wf_comments_parent'));
        }

        $schema->table('workflow_comments', function (Blueprint $table) use ($schema) {
            foreach (['parent_id', 'resolved_at', 'resolved_by', 'resolved_by_name', 'edited_at', 'mentions'] as $column) {
                if ($schema->hasColumn('workflow_comments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
