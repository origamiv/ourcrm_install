<?php

use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
