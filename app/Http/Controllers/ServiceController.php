<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    public function index()
    {
        $sites   = $this->getSites();
        $presets = config('app.deploy_presets', []);
        return view('service.index', compact('sites', 'presets'));
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

    public function deploySite(Request $request)
    {
        $request->validate([
            'site_address'  => 'required|string|max:253',
            'source_type'   => 'required|in:preset,repo',
            'preset_id'     => 'required_if:source_type,preset|nullable|string|max:100',
            'repo_url'      => 'required_if:source_type,repo|nullable|string|max:500',
            'db_host'       => 'required|string|max:253',
            'db_port'       => 'required|integer|min:1|max:65535',
            'db_name'       => 'required|string|max:100',
            'db_user'       => 'required|string|max:100',
            'db_password'   => 'nullable|string|max:255',
        ]);

        $queueFile = 'deploy_site_queue.json';

        $queue = [];
        if (Storage::disk('local')->exists($queueFile)) {
            $queue = json_decode(Storage::disk('local')->get($queueFile), true) ?: [];
        }

        $repo = null;
        if ($request->source_type === 'preset') {
            $presets = config('app.deploy_presets', []);
            foreach ($presets as $preset) {
                if ($preset['id'] === $request->preset_id) {
                    $repo = $preset['repo'];
                    break;
                }
            }
        } else {
            $repo = $request->repo_url;
        }

        $queue[] = [
            'site_address' => $request->site_address,
            'source_type'  => $request->source_type,
            'preset_id'    => $request->preset_id,
            'repo'         => $repo,
            'db'           => [
                'host'     => $request->db_host,
                'port'     => (int) $request->db_port,
                'name'     => $request->db_name,
                'user'     => $request->db_user,
                'password' => $request->db_password ?? '',
            ],
            'queued_at' => now()->toDateTimeString(),
        ];

        Storage::disk('local')->put($queueFile, json_encode($queue, JSON_PRETTY_PRINT));

        return response()->json([
            'status'  => 'queued',
            'message' => "Развёртывание сайта {$request->site_address} поставлено в очередь.",
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

    public function sendBotMessage(Request $request)
    {
        $request->validate([
            'recipient' => 'required|string|max:100',
            'message'   => 'required|string|max:4096',
            'image_url' => 'nullable|url|max:2048',
        ]);

        $token = config('telegram.bot_token');
        if (empty($token)) {
            return response()->json(['status' => 'error', 'message' => 'Токен бота не настроен (TELEGRAM_BOT_TOKEN).'], 500);
        }

        $chatId   = $request->recipient;
        $imageUrl = trim($request->input('image_url', ''));

        if ($imageUrl !== '') {
            $apiUrl  = "https://api.telegram.org/bot{$token}/sendPhoto";
            $payload = [
                'chat_id' => $chatId,
                'photo'   => $imageUrl,
                'caption' => $request->message,
            ];
        } else {
            $apiUrl  = "https://api.telegram.org/bot{$token}/sendMessage";
            $payload = [
                'chat_id' => $chatId,
                'text'    => $request->message,
            ];
        }

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $responseRaw = curl_exec($ch);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return response()->json(['status' => 'error', 'message' => "Ошибка соединения: {$curlError}"], 502);
        }

        $tgResponse = json_decode($responseRaw, true);
        if (!($tgResponse['ok'] ?? false)) {
            $desc = $tgResponse['description'] ?? 'Неизвестная ошибка Telegram';
            return response()->json(['status' => 'error', 'message' => "Telegram API: {$desc}"], 422);
        }

        return response()->json([
            'status'  => 'sent',
            'message' => "Сообщение отправлено адресату {$chatId}.",
        ]);
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
