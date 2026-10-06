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
            if (! Schema::connection('workflow')->hasColumn('workflow_comments', 'container_hash')) {
                // SHA-256 of the surrounding Bard-field container (all blocks
                // that share the same `container` key in EntryContentPreview).
                // Complements `content_hash`: `content_hash` detects "this
                // specific passage was edited", `container_hash` detects
                // "something else in the same field-level container changed —
                // sibling set added/removed/reordered/edited". Nullable, so
                // pre-migration comments never get the "container changed"
                // flag either.
                $table->string('container_hash', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('workflow_comments', function (Blueprint $table) {
            if (Schema::connection('workflow')->hasColumn('workflow_comments', 'container_hash')) {
                $table->dropColumn('container_hash');
            }
        });
    }
};
