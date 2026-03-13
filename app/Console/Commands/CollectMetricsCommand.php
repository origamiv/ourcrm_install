<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CollectMetricsCommand extends Command
{
    protected $signature   = 'metrics:collect';
    protected $description = 'Collect CPU, memory, and site activity metrics (runs every minute)';

    public function handle(): void
    {
        $metricsDir = storage_path('app/metrics');
        if (!is_dir($metricsDir)) {
            mkdir($metricsDir, 0755, true);
        }

        $historyFile  = $metricsDir . '/history.json';
        $prevStatFile = $metricsDir . '/cpu_prev.json';

        $cpu = $this->getCpuUsage($prevStatFile);
        $mem = $this->getMemUsage();

        $history = [];
        if (file_exists($historyFile)) {
            $history = json_decode(file_get_contents($historyFile), true) ?? [];
        }

        $history[] = [
            'ts'  => now()->format('H:i'),
            'cpu' => $cpu,
            'mem' => $mem,
        ];

        // Keep only last 300 entries (5 hours × 60 minutes)
        if (count($history) > 300) {
            $history = array_slice($history, -300);
        }

        file_put_contents($historyFile, json_encode($history));

        $this->collectSiteActivity($metricsDir);

        $this->info("Metrics collected: CPU={$cpu}%, MEM={$mem}%");
    }

    private function getCpuUsage(string $prevStatFile): float
    {
        $stat = $this->readProcStat();

        if (file_exists($prevStatFile)) {
            $prev      = json_decode(file_get_contents($prevStatFile), true);
            $diffTotal = $stat['total'] - ($prev['total'] ?? 0);
            $diffIdle  = $stat['idle']  - ($prev['idle']  ?? 0);
            $cpu       = $diffTotal > 0 ? round((1 - $diffIdle / $diffTotal) * 100, 1) : 0.0;
        } else {
            $cpu = 0.0;
        }

        file_put_contents($prevStatFile, json_encode($stat));

        return max(0.0, min(100.0, $cpu));
    }

    private function readProcStat(): array
    {
        $line = '';
        if (file_exists('/proc/stat')) {
            $fh = fopen('/proc/stat', 'r');
            if ($fh) {
                $line = fgets($fh);
                fclose($fh);
            }
        }

        $parts   = preg_split('/\s+/', trim($line));
        $user    = (int) ($parts[1] ?? 0);
        $nice    = (int) ($parts[2] ?? 0);
        $system  = (int) ($parts[3] ?? 0);
        $idle    = (int) ($parts[4] ?? 0);
        $iowait  = (int) ($parts[5] ?? 0);
        $irq     = (int) ($parts[6] ?? 0);
        $softirq = (int) ($parts[7] ?? 0);
        $steal   = (int) ($parts[8] ?? 0);

        $total     = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal;
        $idleTotal = $idle + $iowait;

        return ['total' => $total, 'idle' => $idleTotal];
    }

    private function getMemUsage(): float
    {
        if (!file_exists('/proc/meminfo')) {
            return 0.0;
        }

        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/',     $meminfo, $m1);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m2);

        $total     = (int) ($m1[1] ?? 1);
        $available = (int) ($m2[1] ?? 0);

        return round(($total - $available) / $total * 100, 1);
    }

    private function collectSiteActivity(string $metricsDir): void
    {
        $sitesDir = $metricsDir . '/sites';
        if (!is_dir($sitesDir)) {
            mkdir($sitesDir, 0755, true);
        }

        $basePath = config('app.projects_base_path', '/www/wwwroot');
        $dirs     = glob(rtrim($basePath, '/') . '/*.our24.ru', GLOB_ONLYDIR) ?: [];

        foreach ($dirs as $dir) {
            $domain       = basename($dir);
            $logFile      = $dir . '/storage/logs/laravel.log';
            $activityFile = $sitesDir . '/' . $domain . '.json';

            $activity = [];
            if (file_exists($activityFile)) {
                $activity = json_decode(file_get_contents($activityFile), true) ?? [];
            }

            $count = 0;
            if (file_exists($logFile)) {
                $since   = now()->subMinute()->format('Y-m-d H:i');
                $current = now()->format('Y-m-d H:i');

                $size = filesize($logFile);
                $fh   = fopen($logFile, 'r');
                fseek($fh, max(0, $size - 100000));
                $content = fread($fh, 100000);
                fclose($fh);

                preg_match_all('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2})/', $content, $matches);
                foreach ($matches[1] as $tsStr) {
                    if ($tsStr >= $since && $tsStr <= $current) {
                        $count++;
                    }
                }
            }

            $activity[] = $count;
            if (count($activity) > 30) {
                $activity = array_slice($activity, -30);
            }

            file_put_contents($activityFile, json_encode($activity));
        }
    }
}
