<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `format` and `plan` on the plain-language job rows.
 *
 * `format` says how a job's answer has to be written back: null is the old
 * whole-document format (LegacyPlainLanguageDocument), `sections` the per-leaf
 * format (PlainLanguageLeafDocument). Nullable so rows submitted before this
 * migration keep meaning exactly what they meant when they were sent.
 *
 * `plan` is what a `sections` job was sent: the content fields, the text leaves
 * (path, type, hash) and the raw values of the content fields at submit time
 * (the skeleton the answer is written into). JSON in a text column — SQLite has
 * no native JSON type and the model casts it.
 */
return new class extends Migration
{
    protected $connection = 'workflow';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('workflow_plain_language_jobs')) {
            return;
        }

        Schema::connection($this->connection)->table('workflow_plain_language_jobs', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('workflow_plain_language_jobs', 'format')) {
                $table->string('format')->nullable();
            }

            if (! Schema::connection($this->connection)->hasColumn('workflow_plain_language_jobs', 'plan')) {
                $table->longText('plan')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('workflow_plain_language_jobs')) {
            return;
        }

        foreach (['format', 'plan'] as $column) {
            if (Schema::connection($this->connection)->hasColumn('workflow_plain_language_jobs', $column)) {
                Schema::connection($this->connection)->table('workflow_plain_language_jobs', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
