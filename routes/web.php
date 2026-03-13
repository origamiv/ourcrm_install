<?php

use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SlugController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/slug', [ServiceController::class, 'index'])->name('slug');
Route::post('/service/git-merge', [ServiceController::class, 'gitMerge'])->name('service.git-merge');
Route::post('/service/redis-command', [ServiceController::class, 'redisCommand'])->name('service.redis-command');
Route::get('/service/branches/{project}', [ServiceController::class, 'branches'])->name('service.branches');
Route::post('/service/deploy-site', [ServiceController::class, 'deploySite'])->name('service.deploy-site');

Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
Route::get('/sync/data', [SyncController::class, 'data'])->name('sync.data');
Route::get('/merge/{project}/{to_branch}', [SlugController::class, 'merge'])->name('git.merge');
