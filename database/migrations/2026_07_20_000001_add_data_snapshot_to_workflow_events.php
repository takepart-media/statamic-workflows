<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'workflow';

    public function up(): void
    {
        Schema::connection($this->connection)->table('workflow_events', function (Blueprint $table) {
            if (! Schema::connection('workflow')->hasColumn('workflow_events', 'data_snapshot')) {
                // JSON-encoded entry field values at the moment the event was
                // logged. Powers the History diff view.
                $table->text('data_snapshot')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('workflow_events', function (Blueprint $table) {
            if (Schema::connection('workflow')->hasColumn('workflow_events', 'data_snapshot')) {
                $table->dropColumn('data_snapshot');
            }
        });
    }
};
