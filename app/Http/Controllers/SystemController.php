<?php

namespace App\Http\Controllers;

class SystemController extends Controller
{
    public function index()
    {
        return view('system.index');
    }

    public function data()
    {
        $history = $this->loadHistory();

        return response()->json([
            'cpuHistory' => array_map(fn($h) => ['ts' => $h['ts'], 'v' => $h['cpu']], $history),
            'memHistory' => array_map(fn($h) => ['ts' => $h['ts'], 'v' => $h['mem']], $history),
            'topCpu'     => $this->getTopProcessesByCpu(),
            'topMem'     => $this->getTopProcessesByMem(),
            'disk'       => $this->getDiskUsage(),
            'sites'      => $this->getSitesData(),
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function loadHistory(): array
    {
        $file = storage_path('app/metrics/history.json');
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file), true) ?? [];
        return array_slice($data, -300);
    }

    private function getTopProcessesByCpu(): array
    {
        $output = [];
        exec('ps aux --sort=-%cpu 2>/dev/null | head -6', $output);
        return $this->parsePs(array_slice($output, 1));
    }

    private function getTopProcessesByMem(): array
    {
        $output = [];
        exec('ps aux --sort=-%mem 2>/dev/null | head -6', $output);
        return $this->parsePs(array_slice($output, 1));
    }

    private function parsePs(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line), 11);
            if (count($parts) < 11) {
                continue;
            }
            $cmd      = $parts[10];
            $cmdShort = mb_substr($cmd, 0, 60);
            $name     = basename(explode(' ', $cmd)[0]);

            $result[] = [
                'pid'  => $parts[1],
                'cpu'  => (float) $parts[2],
                'mem'  => (float) $parts[3],
                'name' => $name,
                'cmd'  => $cmdShort,
            ];
        }
        return array_slice($result, 0, 5);
    }

    private function getDiskUsage(): array
    {
        $total = (int) @disk_total_space('/');
        $free  = (int) @disk_free_space('/');
        $used  = $total - $free;

        return [
            'total'       => $total,
            'free'        => $free,
            'used'        => $used,
            'usedPercent' => $total > 0 ? round($used / $total * 100, 1) : 0,
        ];
    }

    private function getSitesList(): array
    {
        $basePath = config('app.projects_base_path', '/www/wwwroot');
        if (!is_dir($basePath)) {
            return [];
        }
        return glob(rtrim($basePath, '/') . '/*.our24.ru', GLOB_ONLYDIR) ?: [];
    }

    private function getSitesData(): array
    {
        $dirs = $this->getSitesList();

        $crontabLines = [];
        exec('crontab -l 2>/dev/null', $crontabLines);
        $crontab = implode("\n", $crontabLines);

        $sites = [];
        foreach ($dirs as $dir) {
            $domain       = basename($dir);
            $hasScheduler = str_contains($crontab, $dir) || str_contains($crontab, $domain);
            $activity     = $this->getSiteActivity($dir);

            $sites[] = [
                'domain'    => $domain,
                'scheduler' => $hasScheduler,
                'activity'  => $activity,
            ];
        }

        usort($sites, fn($a, $b) => strcmp($a['domain'], $b['domain']));

        return $sites;
    }

    private function getSiteActivity(string $sitePath): array
    {
        // Prefer pre-collected per-minute buckets
        $activityFile = storage_path('app/metrics/sites/' . basename($sitePath) . '.json');
        if (file_exists($activityFile)) {
            $data = json_decode(file_get_contents($activityFile), true) ?? [];
            $data = array_slice($data, -30);
            // Pad to 30 entries
            while (count($data) < 30) {
                array_unshift($data, 0);
            }
            return $data;
        }

        // Fallback: parse the log file on the fly
        $logFile = $sitePath . '/storage/logs/laravel.log';
        $buckets = array_fill(0, 30, 0);

        if (!file_exists($logFile)) {
            return $buckets;
        }

        $size = filesize($logFile);
        $fh   = fopen($logFile, 'r');
        fseek($fh, max(0, $size - 50000));
        $content = fread($fh, 50000);
        fclose($fh);

        $now = time();
        preg_match_all('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2})/', $content, $matches);

        foreach ($matches[1] as $tsStr) {
            $ts         = strtotime($tsStr);
            $minutesAgo = (int) (($now - $ts) / 60);
            if ($minutesAgo >= 0 && $minutesAgo < 30) {
                $buckets[29 - $minutesAgo]++;
            }
        }

        return $buckets;
    }
}
