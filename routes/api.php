<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SourceDraftController;
use App\Http\Controllers\DebugSseController;
use App\Http\Controllers\TelegramHookController;
use App\Http\Controllers\TG\TGScraperController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => app()->version())->name('api');
Route::get('/debug/sse/config', [DebugSseController::class, 'config'])->name('api.debug.sse.config');
Route::post('/debug/sse/publish', [DebugSseController::class, 'publish'])->name('api.debug.sse.publish');
Route::post('/hook', TelegramHookController::class)->name('api.telegram.hook');

Route::prefix('tg')->group(function () {
    Route::get('status', [TGScraperController::class, 'status']);
    Route::post('scrape', [TGScraperController::class, 'scrape']);
    Route::get('channel/{channel}', [TGScraperController::class, 'channelInfo']);
    Route::get('post/{channel}/{postId}', [TGScraperController::class, 'post']);
});

Route::middleware('auth')->prefix('source-drafts')->group(function () {
    Route::post('/', [SourceDraftController::class, 'store']);
    Route::get('/{draft}', [SourceDraftController::class, 'show']);
    Route::delete('/{draft}', [SourceDraftController::class, 'destroy']);
});
