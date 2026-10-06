<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'workflow';

    /**
     * Which submission of a run this row belongs to — 1 for the first, 2 for the
     * first automatic retry, and so on up to
     * `workflows.translation.plain_language.max_attempts`.
     *
     * A column rather than a derived number, because there is nothing to derive it
     * from. `attempts` next door counts POLLS of one submission and resets with every
     * new row, which is exactly what makes it useless here. Counting
     * `plain_language_failed` events instead was the obvious alternative and breaks
     * twice: a human clicking "rewrite again" writes no run-opening event, so the
     * window for counting never reopens and their attempt would start with an
     * exhausted budget — and settle() writes one event per failure MESSAGE, so a
     * single failed submission can cost two counts.
     *
     * PollPlainLanguageCommand::resubmit() carries the number forward when it writes
     * the replacement row, so the count survives the forget() that clears the old
     * one. Default 1 is right for every row written before this feature existed and
     * for every row a human's resubmit creates: that is submission 1 of a fresh run,
     * and it gets the whole budget back.
     */
    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('workflow_plain_language_jobs')) {
            return;
        }

        if (Schema::connection($this->connection)->hasColumn('workflow_plain_language_jobs', 'submission')) {
            return;
        }

        Schema::connection($this->connection)->table('workflow_plain_language_jobs', function (Blueprint $table) {
            $table->unsignedTinyInteger('submission')->default(1)->after('attempts');
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('workflow_plain_language_jobs', 'submission')) {
            return;
        }

        Schema::connection($this->connection)->table('workflow_plain_language_jobs', function (Blueprint $table) {
            $table->dropColumn('submission');
        });
    }
};
