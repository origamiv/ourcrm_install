<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAllSchemasCommand extends Command
{
    protected $signature = 'db:sync_all';
    protected $description = 'Sequentially call db:sync for each schema in connection "one"';

    public function handle()
    {
        $connOne = 'one';
        $connTwo = 'two';

        $this->info("Fetching schemas from connection: $connOne");

        // Очищаем таблицу статусов перед началом
        DB::connection($connOne)->table('public.install_sync')->truncate();

        try {
            // Получаем список всех схем, исключая системные
            $schemas = DB::connection($connOne)->select("
                SELECT schema_name
                FROM information_schema.schemata
                WHERE schema_name NOT IN ('information_schema', 'pg_catalog', 'pg_toast')
                AND schema_name NOT LIKE 'pg_temp_%'
                AND schema_name NOT LIKE 'pg_toast_temp_%'
            ");

            if (empty($schemas)) {
                $this->warn("No schemas found in connection '$connOne'.");
                return Command::SUCCESS;
            }

            $schemaNames = array_map(fn($s) => $s->schema_name, $schemas);
            $this->info("Found schemas: " . implode(', ', $schemaNames));

            // Предварительно заполняем таблицу всеми схемами и таблицами
            $this->info("Pre-filling sync status table...");
            $schemaCounts = [];
            foreach ($schemaNames as $schema) {
                $schemaCounts[$schema] = 0;
                $tables = DB::connection($connOne)->select("
                    SELECT table_name
                    FROM information_schema.tables
                    WHERE table_schema = ? AND table_type = 'BASE TABLE'
                ", [$schema]);

                foreach ($tables as $table) {
                    try {
                        $countOne = DB::connection($connOne)->table("$schema.$table->table_name")->count('id');
                    }
                    catch (\Exception $e) {
                        $countOne = DB::connection($connOne)->table("$schema.$table->table_name")->count();
                    }
                    $this->info("$schema.$table->table_name - $countOne");
                    $schemaCounts[$schema] += $countOne;
                    DB::connection($connOne)->table('public.install_sync')->insert([
                        'schema_name' => $schema,
                        'table_name' => $table->table_name,
                        'count_one' => $countOne,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Сортируем схемы по количеству записей по возрастанию
            asort($schemaCounts);
            $schemaNames = array_keys($schemaCounts);
            $this->info("Sorted schemas by record count: " . implode(', ', $schemaNames));

            foreach ($schemaNames as $schema) {
                $this->newLine();
                $this->info("--------------------------------------------------");
                $this->info("Processing schema: $schema");
                $this->info("--------------------------------------------------");

                // Проверяем существование схемы в целевом соединении и создаем, если её нет
                $this->ensureSchemaExists($connTwo, $schema);

                $exitCode = $this->call('db:sync', [
                    'schema' => $schema,
                ]);

                if ($exitCode !== Command::SUCCESS) {
                    $this->error("Failed to sync schema: $schema");
                    if (!$this->confirm("Do you want to continue with the next schema?", true)) {
                        return Command::FAILURE;
                    }
                }
            }

            $this->newLine();
            $this->info("All schemas processed.");

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Ensure the schema exists in the target connection.
     */
    protected function ensureSchemaExists(string $connection, string $schema): void
    {
        $exists = DB::connection($connection)->selectOne("
            SELECT 1 FROM information_schema.schemata WHERE schema_name = ?
        ", [$schema]);

        if (!$exists) {
            $this->comment("Schema '$schema' does not exist in connection '$connection'. Creating...");
            DB::connection($connection)->statement("CREATE SCHEMA \"$schema\"");
            $this->info("Schema '$schema' created successfully.");
        }
    }
}
