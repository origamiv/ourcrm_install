<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Таблица событий PostgreSQL для соединения two
     */
    public function up(): void
    {
        Schema::connection('two')->create('pg_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type');
            $table->string('aggregate');
            $table->string('aggregate_id');
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('sent_at')->nullable();
            $table->text('error_text')->nullable();
            $table->unsignedInteger('retries')->default(0);
        });

        DB::connection('two')->statement("
            CREATE INDEX idx_pg_events_unsent
            ON pg_events (sent_at, id)
            WHERE sent_at IS NULL
        ");

        DB::connection('two')->statement("CREATE INDEX idx_pg_events_event_type ON pg_events (event_type)");
        DB::connection('two')->statement("CREATE INDEX idx_pg_events_aggregate ON pg_events (aggregate, aggregate_id)");
    }

    public function down(): void
    {
        Schema::connection('two')->dropIfExists('pg_events');
    }
};
