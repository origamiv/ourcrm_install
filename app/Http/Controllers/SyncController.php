<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    public function index()
    {
        return view('sync.index');
    }

    public function data()
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
        })->values();

        $totalTablesAll = $syncData->count();
        $completedTablesAll = $syncData->where('status', 'completed')->count();
        $overallProgressTables = $totalTablesAll > 0 ? ($completedTablesAll / $totalTablesAll) * 100 : 0;

        $totalRowsOne = $syncData->sum('count_one');
        $totalRowsTwo = $syncData->sum('count_two');
        $overallProgressRows = $totalRowsOne > 0 ? ($totalRowsTwo / $totalRowsOne) * 100 : 0;
        $overallProgressRows = min(100, $overallProgressRows);

        return response()->json([
            'syncData' => $syncData,
            'schemas' => $schemas,
            'overallProgressTables' => round($overallProgressTables, 2),
            'totalTablesAll' => $totalTablesAll,
            'completedTablesAll' => $completedTablesAll,
            'overallProgressRows' => round($overallProgressRows, 2),
            'totalRowsOne' => $totalRowsOne,
            'totalRowsTwo' => $totalRowsTwo,
        ]);
    }
}
