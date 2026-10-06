<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'workflow';

    /**
     * When the subject ARRIVED in its current place — the clock the `reminder`
     * place action counts from (SendPlaceRemindersCommand).
     *
     * A column rather than `updated_at`, because `updated_at` is not that
     * moment: migrateState() refreshes it in bulk and the seeder rewrites it,
     * which is why TransitionAgedPostsCommand stopped trusting it too.
     * WorkflowStateStore::set() writes `entered_at` only when the state really
     * changes, so a self-loop (idea → idea) does not restart the clock.
     *
     * Backfill: the newest event that landed the subject in its current place,
     * else `updated_at` — the best guess available for rows written before this
     * column existed.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('workflow_states') || $schema->hasColumn('workflow_states', 'entered_at')) {
            return;
        }

        $schema->table('workflow_states', function (Blueprint $table) {
            $table->timestamp('entered_at')->nullable()->after('state');
        });

        $db = DB::connection($this->connection);
        $hasEvents = $schema->hasTable('workflow_events');

        $db->table('workflow_states')->orderBy('id')->chunkById(500, function ($rows) use ($db, $hasEvents) {
            foreach ($rows as $row) {
                $entered = $hasEvents
                    ? $db->table('workflow_events')
                        ->where('subject_id', $row->subject_id)
                        ->where('workflow', $row->workflow)
                        ->where('to_state', $row->state)
                        // Comments and saves carry the state they happened IN,
                        // not one they arrived in.
                        ->whereNotIn('type', ['comment_created', 'draft_save', 'reminder_sent'])
                        ->max('created_at')
                    : null;

                $db->table('workflow_states')
                    ->where('id', $row->id)
                    ->update(['entered_at' => $entered ?? $row->updated_at ?? $row->created_at]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('workflow_states', 'entered_at')) {
            return;
        }

        Schema::connection($this->connection)->table('workflow_states', function (Blueprint $table) {
            $table->dropColumn('entered_at');
        });
    }
};
