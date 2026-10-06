<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'workflow';

    public function up(): void
    {
        Schema::connection($this->connection)->table('workflow_comments', function (Blueprint $table) {
            if (! Schema::connection('workflow')->hasColumn('workflow_comments', 'content_hash')) {
                // SHA-256 of the passage payload at comment-write time. Used to
                // detect that a comment refers to an outdated version of its
                // passage — surfaced as a "stale" badge in the comment drawer.
                // Nullable: comments written before this migration lack the
                // hash and are never flagged as stale.
                $table->string('content_hash', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('workflow_comments', function (Blueprint $table) {
            if (Schema::connection('workflow')->hasColumn('workflow_comments', 'content_hash')) {
                $table->dropColumn('content_hash');
            }
        });
    }
};
