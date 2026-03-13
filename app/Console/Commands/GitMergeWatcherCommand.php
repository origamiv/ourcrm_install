<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class GitMergeWatcherCommand extends Command
{
    protected $signature = 'git:merge-watcher';
    protected $description = 'Watch for merge requests in a file and execute them';

    public function handle()
    {
        $this->info('Watcher started. Waiting for merge requests...');
        $queueFile = 'git_merge_queue.json';

        while (true) {
            if (Storage::disk('local')->exists($queueFile)) {
                $content = Storage::disk('local')->get($queueFile);
                $queue = json_decode($content, true) ?: [];

                if (!empty($queue)) {
                    $this->info('Found ' . count($queue) . ' merge requests.');

                    // Очищаем файл сразу, чтобы не обрабатывать повторно
                    Storage::disk('local')->put($queueFile, json_encode([]));

                    foreach ($queue as $job) {
                        $this->info("Processing merge for project: {$job['project']}");

                        $exitCode = Artisan::call('git:merge', [
                            'project' => $job['project'],
                            'to_branch' => $job['to_branch'],
                            'from_branch' => $job['from_branch'],
                        ]);

                        $output = Artisan::output();
                        $this->line($output);

                        // Можно записывать результат в лог или другой файл, если нужно
                        $logFile = 'git_merge_results.log';
                        $logEntry = "[" . date('Y-m-d H:i:s') . "] Project: {$job['project']}, Status: " . ($exitCode === 0 ? 'Success' : 'Failed') . "\nOutput: $output\n" . str_repeat('-', 20) . "\n";
                        Storage::disk('local')->append($logFile, $logEntry);
                    }
                }
            }

            sleep(2); // Пауза 2 секунды перед следующей проверкой
        }
    }
}
