<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListDatabaseStructureCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:structure';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Выводит список схем и таблиц для соединения "one"';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $connectionName = 'one';

        try {
            $this->info("Получение структуры базы данных для соединения: {$connectionName}");

            $tables = DB::connection($connectionName)->select("
                SELECT table_schema, table_name
                FROM information_schema.tables
                WHERE table_schema NOT IN ('information_schema', 'pg_catalog')
                ORDER BY table_schema, table_name;
            ");

            if (empty($tables)) {
                $this->warn("Таблицы не найдены.");
                return self::SUCCESS;
            }

            $grouped = [];
            foreach ($tables as $table) {
                $grouped[$table->table_schema][] = $table->table_name;
            }

            foreach ($grouped as $schema => $schemaTables) {
                $this->line("");
                $this->info("Схема: {$schema}");
                foreach ($schemaTables as $tableName) {
                    $this->line("  - {$tableName}");
                }
            }

            $this->line("");
            $this->info("Всего схем: " . count($grouped));
            $this->info("Всего таблиц: " . count($tables));

        } catch (\Exception $e) {
            $this->error("Ошибка при получении структуры: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
