<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class ApplyPgEventsCommand extends Command
{
    protected $signature = 'pg-events:apply
        {--channel=pg_events : Канал pg_notify}
        {--timeout=10 : Таймаут ожидания уведомления в секундах}
        {--max=0 : Максимум обрабатываемых уведомлений (0 — без ограничений)}
        {--dry-run : Показать действия без применения}
        {--checkpoint=default : Имя файла контрольной точки}
    ';

    protected $description = 'Слушает pg_notify/pg_events и применяет обнаруженные события к базе two';

    protected ?int $lastProcessedId = null;
    protected int $processedCount = 0;
    protected bool $shouldStop = false;

    public function handle(): int
    {
        $source = DB::connection('one');
        $target = DB::connection('two');
        $channel = $this->option('channel');
        $timeout = max(1, (int) $this->option('timeout'));
        $maxEvents = max(0, (int) $this->option('max'));
        $checkpoint = $this->option('checkpoint');
        $dryRun = $this->option('dry-run');

        $this->lastProcessedId = $this->readCheckpoint($checkpoint);
        $listener = $this->createListenConnection($channel);

        $this->info(sprintf('LISTEN %s (таймаут %ds). Последний id: %s.', $channel, $timeout, $this->lastProcessedId ?? 'отсутствует'));

        $result = self::SUCCESS;

        try {
            while (! $this->shouldStop) {
                $notified = @pg_wait_for_notify($listener, $timeout);

                if ($notified === false) {
                    if (pg_connection_status($listener) !== PGSQL_CONNECTION_OK) {
                        $this->error('Связь с PostgreSQL по каналу уведомлений потеряна.');
                        break;
                    }

                    continue;
                }

                while (! $this->shouldStop && ($notification = pg_get_notify($listener, PGSQL_ASSOC))) {
                    $this->line(sprintf('Уведомление pid=%s channel=%s payload=%s', $notification['pid'] ?? '?', $notification['message'] ?? $channel, $notification['payload'] ?? '')); // phpcs:ignore
                    $this->processNotification($notification, $source, $target, $dryRun, $checkpoint, $maxEvents);
                }
            }
        } catch (Throwable $exception) {
            $this->error(sprintf('Сбой при обработке уведомлений: %s', $exception->getMessage()));
            $result = self::FAILURE;
        } finally {
            pg_query($listener, 'UNLISTEN *');
            pg_close($listener);
        }

        if ($this->processedCount === 0 && $dryRun) {
            $this->info('Событий не применено — режим dry-run.');
        }

        return $result;
    }

    protected function createListenConnection(string $channel)
    {
        $config = config('database.connections.one');
        $segments = [
            'host=' . ($config['host'] ?? '127.0.0.1'),
            'port=' . ($config['port'] ?? '5432'),
            'dbname=' . ($config['database'] ?? ''),
            'user=' . ($config['username'] ?? ''),
            'password=' . ($config['password'] ?? ''),
        ];

        if (! empty($config['options'])) {
            $segments[] = "options={$config['options']}"; // специальные параметры из конфига
        }

        if (! empty($config['search_path'])) {
            $segments[] = "options='-c search_path={$config['search_path']}'";
        }

        $connectionString = implode(' ', array_filter($segments, 'strlen'));

        $resource = @pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);

        if (! $resource) {
            throw new \RuntimeException('Не удалось подключиться к источнику pg_notify.');
        }

        $escapedChannel = pg_escape_identifier($resource, $channel);
        if (pg_query($resource, "LISTEN {$escapedChannel}") === false) {
            $error = pg_last_error($resource);
            pg_close($resource);
            throw new \RuntimeException('LISTEN не смог подключиться: ' . $error);
        }

        return $resource;
    }

    protected function processNotification(array $notification, Connection $source, Connection $target, bool $dryRun, string $checkpoint, int $maxEvents): void
    {
        $payload = [];

        if (! empty($notification['payload'])) {
            $decoded = json_decode($notification['payload'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        $eventId = Arr::get($payload, 'event_id') ?? Arr::get($payload, 'id') ?? Arr::get($payload, 'pg_event_id');

        if ($eventId === null) {
            $this->warn('Уведомление не содержит идентификатор события: ' . ($notification['payload'] ?? '')); // phpcs:ignore
            return;
        }

        if ($this->lastProcessedId !== null && $eventId <= $this->lastProcessedId) {
            $this->line(sprintf('Событие #%s уже применено (checkpoint).', $eventId));
            return;
        }

        $event = $source->table('pg_events')->where('id', $eventId)->first();

        if (! $event) {
            $this->warn(sprintf('Событие #%s не найдено в таблице pg_events.', $eventId));
            return;
        }

        $this->line(sprintf('Применение события #%s (%s)', $event->id, $this->describeOperation($event)));

        try {
            $this->applyEvent($event, $target, $dryRun);
        } catch (Throwable $exception) {
            $this->shouldStop = true;
            $this->error(sprintf('Сбой при применении события #%s: %s', $event->id, $exception->getMessage()));
            throw $exception;
        }

        if (! $dryRun) {
            $this->writeCheckpoint($event->id, $checkpoint);
            $this->lastProcessedId = $event->id;
        }

        $this->processedCount++;

        if ($maxEvents > 0 && $this->processedCount >= $maxEvents) {
            $this->info(sprintf('Лимит %s событий достигнут, завершаем.', $maxEvents));
            $this->shouldStop = true;
        }
    }

    protected function applyEvent(object $event, Connection $target, bool $dryRun): void
    {
        $payload = $this->normalizePayload($event);

        if (! empty($payload['sql'])) {
            $this->applyRawSql($payload['sql'], $target, $dryRun);
            return;
        }

        $operation = strtolower($payload['operation'] ?? $event->operation ?? '');
        $table = $payload['table'] ?? $event->table ?? $event->target_table ?? null;

        if (empty($table)) {
            throw new \RuntimeException('Не удалось определить таблицу: payload и столбец table пусты.');
        }

        $qualifiedTable = $payload['schema'] ? ($payload['schema'] . '.' . $table) : $table;

        switch ($operation) {
            case 'insert':
                $data = $this->resolveData($payload);
                $this->executeInsert($qualifiedTable, $data, $target, $dryRun);
                return;
            case 'update':
                $data = $this->resolveData($payload);
                $this->executeUpdate($qualifiedTable, $data, $payload, $target, $dryRun);
                return;
            case 'delete':
                $this->executeDelete($qualifiedTable, $payload, $target, $dryRun);
                return;
            default:
                throw new \RuntimeException(sprintf('Неизвестная операция %s в событии #%s', $operation, $event->id));
        }
    }

    protected function resolveData(array $payload): array
    {
        if (! empty($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        if (! empty($payload['values']) && is_array($payload['values'])) {
            return $payload['values'];
        }

        if (! empty($payload['row']) && is_array($payload['row'])) {
            return $payload['row'];
        }

        throw new \RuntimeException('Данные для операции отсутствуют или не являются массивом.');
    }

    protected function executeInsert(string $table, array $data, Connection $target, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line(sprintf('dry-run insert %s (%s)', $table, json_encode($data, JSON_THROW_ON_ERROR)));
            return;
        }

        $target->table($table)->insert($data);
    }

    protected function executeUpdate(string $table, array $data, array $payload, Connection $target, bool $dryRun): void
    {
        $conditions = $this->buildConditions($payload['filters'] ?? $payload['conditions'] ?? $payload['identity'] ?? null, $data);

        if (empty($conditions)) {
            throw new \RuntimeException('Невозможно обновить запись без условий.');
        }

        if ($dryRun) {
            $this->line(sprintf('dry-run update %s where %s set %s', $table, json_encode($conditions, JSON_THROW_ON_ERROR), json_encode($data, JSON_THROW_ON_ERROR)));
            return;
        }

        $query = $target->table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        $query->update($data);
    }

    protected function executeDelete(string $table, array $payload, Connection $target, bool $dryRun): void
    {
        $conditions = $this->buildConditions($payload['filters'] ?? $payload['conditions'] ?? $payload['identity'] ?? null);

        if (empty($conditions)) {
            throw new \RuntimeException('Невозможно удалить запись без условий.');
        }

        if ($dryRun) {
            $this->line(sprintf('dry-run delete %s where %s', $table, json_encode($conditions, JSON_THROW_ON_ERROR)));
            return;
        }

        $query = $target->table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        $query->delete();
    }

    protected function applyRawSql(string $sql, Connection $target, bool $dryRun): void
    {
        if ($dryRun) {
            $this->line(sprintf('dry-run raw sql: %s', $sql));
            return;
        }

        $target->unprepared($sql);
    }

    protected function buildConditions($filters, ?array $fallback = null): array
    {
        if (is_string($filters)) {
            return $this->parseConditionsFromString($filters);
        }

        if ($filters instanceof \stdClass) {
            $filters = (array) $filters;
        }

        if (is_array($filters) && count($filters)) {
            return array_filter($filters, fn ($value) => $value !== null || $value === 0 || $value === '0' || $value === '');
        }

        if (is_array($fallback) && isset($fallback['id'])) {
            return ['id' => $fallback['id']];
        }

        return [];
    }

    protected function parseConditionsFromString(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $result = [];
        foreach (explode(',', $raw) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $pair, 2));
            if ($key === '') {
                continue;
            }

            $result[$key] = $this->castConditionValue($value);
        }

        return $result;
    }

    protected function castConditionValue(string $value): mixed
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        if (preg_match('/^\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }

    protected function normalizePayload(object $event): array
    {
        $payload = [];

        if (! empty($event->payload)) {
            $decoded = json_decode($event->payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        return array_merge($payload, [
            'operation' => $payload['operation'] ?? strtolower($event->operation ?? ''),
            'table' => $payload['table'] ?? $event->table ?? $event->target_table ?? null,
            'schema' => $payload['schema'] ?? $event->schema ?? null,
            'filters' => $payload['filters'] ?? $payload['conditions'] ?? $payload['identity'] ?? null,
        ]);
    }

    protected function describeOperation(object $event): string
    {
        $payload = $this->normalizePayload($event);

        return strtoupper($payload['operation'] ?? $event->operation ?? 'unknown');
    }

    protected function readCheckpoint(string $checkpoint): ?int
    {
        $path = $this->checkpointPath($checkpoint);

        if (! File::exists($path)) {
            return null;
        }

        $payload = json_decode(File::get($path), true);

        return isset($payload['last_id']) ? (int) $payload['last_id'] : null;
    }

    protected function writeCheckpoint(int $lastId, string $checkpoint): void
    {
        $path = $this->checkpointPath($checkpoint);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'last_id' => $lastId,
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function checkpointPath(string $checkpoint): string
    {
        $slug = Str::slug($checkpoint ?: 'default');

        return storage_path("app/pg-events-checkpoint-{$slug}.json");
    }
}
