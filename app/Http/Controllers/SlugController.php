<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SlugController extends Controller
{
    public function merge(Request $request, $project, $to_branch)
    {
        $fromBranch = $request->query('from');

        if (!$fromBranch) {
            return response()->json(['error' => 'The "from" query parameter is required.'], 400);
        }

        $exitCode = Artisan::call('git:merge', [
            'project' => $project,
            'to_branch' => $to_branch,
            'from_branch' => $fromBranch,
        ]);

        $output = Artisan::output();

        if ($exitCode !== 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Merge failed',
                'output' => $output
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Successfully merged $fromBranch into $to_branch",
            'output' => $output
        ]);
    }
}
