<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetupPgTriggersCommand extends Command
{
    protected $signature = 'pg-events:setup-triggers {--connection=two} {--channel=pg_events}';
    protected $description = 'Создает триггерную функцию и навешивает триггеры на все таблицы в схеме public';

    public function handle()
    {
        $connectionName = $this->option('connection');
        $channel = $this->option('channel');
        $connection = DB::connection($connectionName);

        $this->info("Настройка триггеров на соединении: {$connectionName}");

        // 1. Создаем триггерную функцию
        $this->createTriggerFunction($connection, $channel);

        // 2. Получаем список всех таблиц в схеме public
        $tables = $this->getPublicTables($connection);

        foreach ($tables as $table) {
            if ($table === 'pg_events' || $table === 'migrations') {
                continue;
            }

            $this->setupTriggerForTable($connection, $table);
        }

        $this->info('Настройка завершена успешно.');
    }

    protected function createTriggerFunction($connection, $channel)
    {
        $sql = <<<SQL
CREATE OR REPLACE FUNCTION fn_pg_events_notify()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_payload      jsonb;
    v_event_id     bigint;
BEGIN
    IF TG_OP = 'INSERT' THEN
        v_payload := to_jsonb(NEW);
    ELSIF TG_OP = 'UPDATE' THEN
        v_payload := jsonb_build_object(
            'old', to_jsonb(OLD),
            'new', to_jsonb(NEW)
        );
    ELSIF TG_OP = 'DELETE' THEN
        v_payload := to_jsonb(OLD);
    END IF;

    INSERT INTO pg_events (
        event_type,
        aggregate,
        aggregate_id,
        payload,
        created_at
    )
    VALUES (
        TG_TABLE_NAME || '.' || lower(TG_OP),
        TG_TABLE_NAME,
        CASE
            WHEN TG_OP = 'DELETE' THEN OLD.id::text
            ELSE NEW.id::text
        END,
        v_payload,
        now()
    )
    RETURNING id INTO v_event_id;

    PERFORM pg_notify('{$channel}', json_build_object('event_id', v_event_id)::text);

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;
SQL;

        $connection->unprepared($sql);
        $this->line('Триггерная функция fn_pg_events_notify создана/обновлена.');
    }

    protected function getPublicTables($connection)
    {
        return $connection->table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->pluck('table_name')
            ->toArray();
    }

    protected function setupTriggerForTable($connection, $table)
    {
        $triggerName = "trg_pg_events_{$table}";

        // Удаляем старый триггер если есть
        $connection->unprepared("DROP TRIGGER IF EXISTS {$triggerName} ON {$table}");

        // Создаем новый
        $sql = <<<SQL
CREATE TRIGGER {$triggerName}
AFTER INSERT OR UPDATE OR DELETE
ON {$table}
FOR EACH ROW
EXECUTE FUNCTION fn_pg_events_notify();
SQL;

        try {
            $connection->unprepared($sql);
            $this->line("Триггер создан для таблицы: {$table}");
        } catch (\Exception $e) {
            $this->error("Ошибка при создании триггера для таблицы {$table}: " . $e->getMessage());
        }
    }
}
