<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetupPgTriggersCommand extends Command
{
    protected $signature = 'pg-events:setup-triggers {--connection=two} {--channel=pg_events} {--remove : Удалить триггеры со всех таблиц}';
    protected $description = 'Создает/удаляет триггеры на всех таблицах во всех схемах';

    public function handle()
    {
        $connectionName = $this->option('connection');
        $channel = $this->option('channel');
        $remove = $this->option('remove');
        $connection = DB::connection($connectionName);

        if ($remove) {
            $this->info("Удаление триггеров на соединении: {$connectionName}");
        } else {
            $this->info("Настройка триггеров на соединении: {$connectionName}");
            // 1. Создаем триггерную функцию (только при установке)
            $this->createTriggerFunction($connection, $channel);
        }

        // 2. Получаем список всех таблиц во всех схемах (кроме системных)
        $tables = $this->getAllTables($connection);

        foreach ($tables as $tableData) {
            $schema = $tableData->table_schema;
            $table = $tableData->table_name;

            if ($table === 'pg_events' || $table === 'migrations') {
                continue;
            }

            if ($remove) {
                $this->removeTriggerFromTable($connection, $schema, $table);
            } else {
                $this->setupTriggerForTable($connection, $schema, $table);
            }
        }

        $this->info($remove ? 'Удаление завершено.' : 'Настройка завершена успешно.');
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

    INSERT INTO public.pg_events (
        event_type,
        aggregate,
        aggregate_id,
        payload,
        created_at
    )
    VALUES (
        TG_TABLE_SCHEMA || '.' || TG_TABLE_NAME || '.' || lower(TG_OP),
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

    protected function getAllTables($connection)
    {
        return $connection->table('information_schema.tables')
            ->select('table_schema', 'table_name')
            ->whereNotIn('table_schema', ['information_schema', 'pg_catalog'])
            ->where('table_type', 'BASE TABLE')
            ->get()
            ->toArray();
    }

    protected function setupTriggerForTable($connection, $schema, $table)
    {
        $fullTableName = "\"{$schema}\".\"{$table}\"";
        $triggerName = "trg_pg_events_{$schema}_{$table}";

        // Удаляем старый триггер если есть
        $connection->unprepared("DROP TRIGGER IF EXISTS \"{$triggerName}\" ON {$fullTableName}");

        // Создаем новый
        $sql = <<<SQL
CREATE TRIGGER "{$triggerName}"
AFTER INSERT OR UPDATE OR DELETE
ON {$fullTableName}
FOR EACH ROW
EXECUTE FUNCTION fn_pg_events_notify();
SQL;

        try {
            $connection->unprepared($sql);
            $this->line("Триггер создан для таблицы: {$fullTableName}");
        } catch (\Exception $e) {
            $this->error("Ошибка при создании триггера для таблицы {$fullTableName}: " . $e->getMessage());
        }
    }

    protected function removeTriggerFromTable($connection, $schema, $table)
    {
        $fullTableName = "\"{$schema}\".\"{$table}\"";
        $triggerName = "trg_pg_events_{$schema}_{$table}";

        try {
            $connection->unprepared("DROP TRIGGER IF EXISTS \"{$triggerName}\" ON {$fullTableName}");
            $this->line("Триггер удален с таблицы: {$fullTableName}");
        } catch (\Exception $e) {
            $this->error("Ошибка при удалении триггера с таблицы {$fullTableName}: " . $e->getMessage());
        }
    }
}
