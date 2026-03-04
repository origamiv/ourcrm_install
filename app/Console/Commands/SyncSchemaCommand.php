<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSchemaCommand extends Command
{
    protected $signature = 'db:sync {schema : The name of the schema to sync}';
    protected $description = 'Sync database schema and data from connection "one" to "two"';

    public function handle()
    {
        $schema = $this->argument('schema');
        $connOne = 'one';
        $connTwo = 'two';

        $this->info("Starting sync for schema: $schema");

        try {
            $this->dropObjects($connTwo, $schema);
            $this->syncSequences($connOne, $connTwo, $schema);
            $this->syncStructure($connOne, $connTwo, $schema);
            $this->syncData($connOne, $connTwo, $schema);

            $this->info("Sync completed successfully!");
        } catch (\Exception $e) {
            $this->error("Error during sync: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function dropObjects($connection, $schema)
    {
        $this->comment("Dropping tables and sequences in $connection.$schema...");

        $tables = DB::connection($connection)->select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = ? AND table_type = 'BASE TABLE'
        ", [$schema]);

        foreach ($tables as $table) {
            DB::connection($connection)->statement("DROP TABLE IF EXISTS $schema.\"$table->table_name\" CASCADE");
        }

        $sequences = DB::connection($connection)->select("
            SELECT sequence_name
            FROM information_schema.sequences
            WHERE sequence_schema = ?
        ", [$schema]);

        foreach ($sequences as $seq) {
            DB::connection($connection)->statement("DROP SEQUENCE IF EXISTS $schema.\"$seq->sequence_name\" CASCADE");
        }
    }

    protected function syncStructure($from, $to, $schema)
    {
        $this->comment("Syncing structure from $from to $to...");

        $tables = DB::connection($from)->select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = ? AND table_type = 'BASE TABLE'
        ", [$schema]);

        //dd($tables);

        foreach ($tables as $table) {
            $tableName = $table->table_name;
            $this->line("  Creating table structure: $tableName");

            $ddl = $this->getTableDDL($from, $schema, $tableName);

            //dump($ddl);
            if ($ddl) {
                DB::connection($to)->statement($ddl);
            }
        }
    }

    protected function getTableDDL($connection, $schema, $table)
    {
        $columns = DB::connection($connection)->select("
            SELECT
                column_name,
                udt_name as data_type,
                character_maximum_length,
                is_nullable,
                column_default,
                numeric_precision,
                numeric_scale
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
            ORDER BY ordinal_position
        ", [$schema, $table]);

        $colDefs = [];
        foreach ($columns as $col) {
            $type = $col->data_type;

            if ($col->character_maximum_length) {
                $type .= "($col->character_maximum_length)";
            } elseif ($col->numeric_precision !== null && !in_array($type, ['int2', 'int4', 'int8', 'float4', 'float8', 'bool', 'timestamp', 'timestamptz', 'json', 'jsonb'])) {
                $type .= "($col->numeric_precision,$col->numeric_scale)";
            }

            $def = "\"$col->column_name\" $type";

            if ($col->is_nullable === 'NO') {
                $def .= " NOT NULL";
            }
            if ($col->column_default) {
                $def .= " DEFAULT $col->column_default";
            }
            $colDefs[] = $def;
        }

        $sql = "CREATE TABLE $schema.\"$table\" (\n  " . implode(",\n  ", $colDefs);

        $pkQuery = "
            SELECT a.attname
            FROM pg_index i
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            WHERE i.indrelid = '$schema.$table'::regclass
            AND i.indisprimary
        ";

        try {
            $pk = DB::connection($connection)->select($pkQuery);
            if (!empty($pk)) {
                $pkCols = array_map(fn($p) => "\"$p->attname\"", $pk);
                $sql .= ",\n  PRIMARY KEY (" . implode(', ', $pkCols) . ")";
            }
        } catch (\Exception $e) {
            // Skip PK errors
        }

        $sql .= "\n)";

        return $sql;
    }

    protected function syncData($from, $to, $schema)
    {
        $this->comment("Syncing data from $from to $to...");

        $tables = DB::connection($from)->select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = ? AND table_type = 'BASE TABLE'
        ", [$schema]);

        $useTriggers = false;

        $this->comment("Disabling triggers manually...");
        $useTriggers = true;
        foreach ($tables as $table) {
            DB::connection($to)->statement("ALTER TABLE $schema.\"$table->table_name\" DISABLE TRIGGER ALL");
        }


        foreach ($tables as $table) {
            $tableName = $table->table_name;
            $fullTableName = "$schema.$tableName";

            $count = DB::connection($from)->table($fullTableName)->count();
            $this->line("  Copying table: $tableName ($count rows)");

            if ($count === 0) continue;

            $bar = $this->output->createProgressBar($count);
            $bar->start();

            DB::connection($from)->table($fullTableName)->orderByRaw('1')->chunk(2000, function ($rows) use ($to, $fullTableName, $bar) {
                $data = array_map(fn($row) => (array)$row, $rows->toArray());
                DB::connection($to)->table($fullTableName)->insert($data);
                $bar->advance(count($data));
            });

            $bar->finish();
            $this->line("");
        }

        foreach ($tables as $table) {
            DB::connection($to)->statement("ALTER TABLE $schema.\"$table->table_name\" ENABLE TRIGGER ALL");
        }

    }

    protected function syncSequences($from, $to, $schema)
    {
        $this->comment("Syncing sequences from $from to $to...");

        $sequences = DB::connection($from)->select("
            SELECT sequence_name, start_value, minimum_value, maximum_value, increment, cycle_option
            FROM information_schema.sequences
            WHERE sequence_schema = ?
        ", [$schema]);

        foreach ($sequences as $seq) {
            $seqName = $seq->sequence_name;
            $fullSeqName = "$schema.\"$seqName\"";

            try {
                $this->line("  Creating sequence: $seqName");

                $createSql = "CREATE SEQUENCE IF NOT EXISTS $fullSeqName " .
                    "INCREMENT BY $seq->increment " .
                    "MINVALUE $seq->minimum_value " .
                    "MAXVALUE $seq->maximum_value " .
                    "START WITH $seq->start_value " .
                    ($seq->cycle_option === 'YES' ? "CYCLE" : "NO CYCLE");

                DB::connection($to)->statement($createSql);

                $val = DB::connection($from)->selectOne("SELECT last_value, is_called FROM $fullSeqName");

                if ($val && $val->last_value !== null) {
                    DB::connection($to)->statement("SELECT setval(?, ?, ?)", [$fullSeqName, $val->last_value, $val->is_called]);
                    $this->line("    Sequence $seqName set to $val->last_value (is_called: " . ($val->is_called ? 'true' : 'false') . ")");
                }
            } catch (\Exception $e) {
                $this->warn("    Could not sync sequence $seqName: " . $e->getMessage());
            }
        }
    }
}
