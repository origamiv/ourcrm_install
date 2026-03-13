<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SlugController extends Controller
{
    public function merge(Request $request, $project, $to_branch)
    {
        $fromBranch = $request->query('from');

        if (!$fromBranch) {
            return response()->json(['error' => 'The "from" query parameter is required.'], 400);
        }

        $queueFile = 'git_merge_queue.json';

        // Читаем текущую очередь
        $queue = [];
        if (Storage::disk('local')->exists($queueFile)) {
            $content = Storage::disk('local')->get($queueFile);
            $queue = json_decode($content, true) ?: [];
        }

        // Добавляем новую задачу
        $queue[] = [
            'project' => $project,
            'to_branch' => $to_branch,
            'from_branch' => $fromBranch,
            'timestamp' => now()->toDateTimeString(),
        ];

        // Сохраняем очередь
        Storage::disk('local')->put($queueFile, json_encode($queue));

        return response()->json([
            'status' => 'queued',
            'message' => "Merge request for $project ($fromBranch -> $to_branch) has been queued.",
            'info' => 'The task will be processed by the background watcher shortly.'
        ]);
    }
}
