<?php

use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
Route::get('/sync/data', [SyncController::class, 'data'])->name('sync.data');
