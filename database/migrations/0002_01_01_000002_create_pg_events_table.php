<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Таблица событий PostgreSQL (transactional outbox)
     * Новое имя: pg_events
     */
    public function up(): void
    {
        Schema::connection('one')->create('pg_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Тип события: orders.created / users.updated / ...
            $table->string('event_type');

            // Сущность / агрегат: orders, users, invoices ...
            $table->string('aggregate');

            // ID записи (text, чтобы поддерживать int/uuid/строки)
            $table->string('aggregate_id');

            // Данные события
            $table->jsonb('payload');

            // Когда событие создано в БД
            $table->timestampTz('created_at')->useCurrent();

            // Когда событие успешно отправлено в Redis/очередь
            $table->timestampTz('sent_at')->nullable();

            // Последняя ошибка отправки (если была)
            $table->text('error_text')->nullable();

            // Кол-во попыток отправки
            $table->unsignedInteger('retries')->default(0);
        });

        // Частичный индекс для быстрого выбора неподтвержденных событий
        DB::connection('one')->statement("
            CREATE INDEX idx_pg_events_unsent
            ON pg_events (sent_at, id)
            WHERE sent_at IS NULL
        ");

        // (Опционально) Индексы под аналитику/поиск
        DB::connection('one')->statement("CREATE INDEX idx_pg_events_event_type ON pg_events (event_type)");
        DB::connection('one')->statement("CREATE INDEX idx_pg_events_aggregate ON pg_events (aggregate, aggregate_id)");
    }

    public function down(): void
    {
        DB::connection('one')->statement('DROP INDEX IF EXISTS idx_pg_events_unsent');
        DB::connection('one')->statement('DROP INDEX IF EXISTS idx_pg_events_event_type');
        DB::connection('one')->statement('DROP INDEX IF EXISTS idx_pg_events_aggregate');

        Schema::connection('one')->dropIfExists('pg_events');
    }
};
