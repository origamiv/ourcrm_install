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
        $overallProgressTables = $totalTablesAll > 0 ? ($completedTablesAll / $totalTablesAll) * 100 : 0;

        $totalRowsOne = $syncData->sum('count_one');
        $totalRowsTwo = $syncData->sum('count_two');
        $overallProgressRows = $totalRowsOne > 0 ? ($totalRowsTwo / $totalRowsOne) * 100 : 0;
        // Ограничиваем 100%, если вдруг в count_two больше записей (хотя это странно для синхронизации)
        $overallProgressRows = min(100, $overallProgressRows);

        return view('sync.index', compact(
            'syncData',
            'schemas',
            'overallProgressTables',
            'totalTablesAll',
            'completedTablesAll',
            'overallProgressRows',
            'totalRowsOne',
            'totalRowsTwo'
        ));
    }
}
