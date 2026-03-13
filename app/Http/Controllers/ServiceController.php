<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    public function index()
    {
        $sites = $this->getSites();
        return view('service.index', compact('sites'));
    }

    public function gitMerge(Request $request)
    {
        $request->validate([
            'project'     => 'required|string|max:100',
            'to_branch'   => 'required|string|max:200',
            'from_branch' => 'required|string|max:200',
        ]);

        $queueFile = 'git_merge_queue.json';

        $queue = [];
        if (Storage::disk('local')->exists($queueFile)) {
            $content = Storage::disk('local')->get($queueFile);
            $queue = json_decode($content, true) ?: [];
        }

        $queue[] = [
            'project'     => $request->project,
            'to_branch'   => $request->to_branch,
            'from_branch' => $request->from_branch,
            'timestamp'   => now()->toDateTimeString(),
        ];

        Storage::disk('local')->put($queueFile, json_encode($queue));

        return response()->json([
            'status'  => 'queued',
            'message' => "Merge {$request->from_branch} → {$request->to_branch} в проекте {$request->project} поставлен в очередь.",
        ]);
    }

    public function redisCommand(Request $request)
    {
        $request->validate([
            'site'    => 'required|string|max:100',
            'command' => 'required|string|max:100',
        ]);

        $payload = ['action' => $request->command];

        $parametersRaw = trim($request->input('parameters', ''));
        if ($parametersRaw !== '') {
            $decoded = json_decode($parametersRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            } else {
                $payload['parameters'] = $parametersRaw;
            }
        }

        $payload['requested_at'] = now()->toIso8601String();

        $key = $request->site . '/commands';
        Redis::connection('telegram')->set($key, json_encode($payload));

        return response()->json([
            'status'  => 'sent',
            'message' => "Команда «{$request->command}» отправлена на сайт {$request->site}.",
        ]);
    }

    public function branches(Request $request, string $project)
    {
        $basePath = config('app.projects_base_path', '/www/wwwroot');
        $projectPath = rtrim($basePath, '/') . '/' . $project . '.our24.ru';

        if (!is_dir($projectPath . '/.git')) {
            return response()->json(['branches' => []]);
        }

        $output = [];
        exec('git -C ' . escapeshellarg($projectPath) . ' branch -r --format="%(refname:short)" 2>/dev/null', $output);

        $branches = array_values(array_filter(array_map(function ($line) {
            $line = trim($line);
            // Strip "origin/" prefix
            if (str_starts_with($line, 'origin/')) {
                $line = substr($line, 7);
            }
            return $line === 'HEAD' ? null : $line;
        }, $output)));

        sort($branches);

        return response()->json(['branches' => $branches]);
    }

    private function getSites(): array
    {
        $basePath = config('app.projects_base_path', '/www/wwwroot');

        if (!is_dir($basePath)) {
            return [];
        }

        $dirs = glob(rtrim($basePath, '/') . '/*.our24.ru', GLOB_ONLYDIR);

        if (!$dirs) {
            return [];
        }

        $sites = array_map(fn($d) => basename($d, '.our24.ru'), $dirs);
        sort($sites);

        return $sites;
    }
}
