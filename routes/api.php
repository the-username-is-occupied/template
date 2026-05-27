<?php

declare(strict_types=1);

use App\Http\Controllers\DebugSseController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => app()->version())->name('api');
Route::get('/debug/sse/config', [DebugSseController::class, 'config'])->name('api.debug.sse.config');
Route::post('/debug/sse/publish', [DebugSseController::class, 'publish'])->name('api.debug.sse.publish');
