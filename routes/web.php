<?php

use App\Http\Controllers\SlugController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/slug', function () {
    return view('welcome'); // Временно используем ту же страницу для демонстрации
})->name('slug');

Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
Route::get('/sync/data', [SyncController::class, 'data'])->name('sync.data');
Route::get('/merge/{project}/{to_branch}', [SlugController::class, 'merge'])->name('git.merge');
