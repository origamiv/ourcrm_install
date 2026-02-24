<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Триггерная функция PostgreSQL:
     * пишет события (INSERT/UPDATE/DELETE) в таблицу pg_events
     *
     * ВАЖНО:
     * - функция универсальная, но предполагает наличие поля id у таблицы
     * - триггеры на конкретные таблицы создаются отдельной миграцией
     */
    public function up(): void
    {
        DB::connection('one')->statement(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_pg_events_emit()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_payload      jsonb;
    v_aggregate_id text;
    v_event_type   text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        v_payload      := to_jsonb(NEW);
        v_aggregate_id := NEW.id::text;
        v_event_type   := TG_TABLE_NAME || '.created';

    ELSIF TG_OP = 'UPDATE' THEN
        v_payload := jsonb_build_object(
            'old', to_jsonb(OLD),
            'new', to_jsonb(NEW)
        );
        v_aggregate_id := NEW.id::text;
        v_event_type   := TG_TABLE_NAME || '.updated';

    ELSIF TG_OP = 'DELETE' THEN
        v_payload      := to_jsonb(OLD);
        v_aggregate_id := OLD.id::text;
        v_event_type   := TG_TABLE_NAME || '.deleted';

    ELSE
        RAISE EXCEPTION 'fn_pg_events_emit: unsupported TG_OP=%', TG_OP;
    END IF;

    INSERT INTO pg_events (
        event_type,
        aggregate,
        aggregate_id,
        payload,
        created_at
    )
    VALUES (
        v_event_type,
        TG_TABLE_NAME,
        v_aggregate_id,
        v_payload,
        now()
    );

    -- Возвращаем корректную запись для триггера
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;
SQL);

        // Опциональный комментарий к функции (удобно в psql/pgAdmin)
        DB::connection('one')->statement(<<<'SQL'
COMMENT ON FUNCTION fn_pg_events_emit()
IS 'Универсальная trigger-функция: пишет INSERT/UPDATE/DELETE события в pg_events';
SQL);
    }

    public function down(): void
    {
        // CASCADE удалит зависимости (триггеры), если такие уже были созданы.
        // Если не хочешь удалять триггеры автоматически — убери CASCADE.
        DB::connection('one')->statement('DROP FUNCTION IF EXISTS fn_pg_events_emit() CASCADE');
    }
};
