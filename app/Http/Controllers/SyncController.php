<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    public function index()
    {
        $syncData = DB::connection('one')->table('install_sync')->get();

        $schemas = $syncData->groupBy('schema_name')->map(function ($tables, $schemaName) {
            $totalTables = $tables->count();
            $completedTables = $tables->where('status', 'completed')->count();
            $progress = $totalTables > 0 ? ($completedTables / $totalTables) * 100 : 0;

            return [
                'name' => $schemaName,
                'total' => $totalTables,
                'completed' => $completedTables,
                'progress' => round($progress, 2),
            ];
        });

        $totalTablesAll = $syncData->count();
        $completedTablesAll = $syncData->where('status', 'completed')->count();
        $overallProgress = $totalTablesAll > 0 ? ($completedTablesAll / $totalTablesAll) * 100 : 0;

        return view('sync.index', compact('syncData', 'schemas', 'overallProgress', 'totalTablesAll', 'completedTablesAll'));
    }
}
