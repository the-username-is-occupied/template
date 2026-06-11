<?php

use App\Http\Controllers\Admin\TechAccountController;
use App\Http\Controllers\TG\TGScraperController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/admin', '/admin/tech-accounts');

Route::prefix('admin/tech-accounts')->name('admin.tech-accounts.')->group(function (): void {
    Route::get('/', [TechAccountController::class, 'index'])->name('index');
    Route::get('/create', [TechAccountController::class, 'create'])->name('create');
    Route::post('/', [TechAccountController::class, 'store'])->name('store');
    Route::get('/{techAccount}/edit', [TechAccountController::class, 'edit'])->name('edit');
    Route::put('/{techAccount}', [TechAccountController::class, 'update'])->name('update');
    Route::delete('/{techAccount}', [TechAccountController::class, 'destroy'])->name('destroy');
});

Route::prefix('tg')->group(function () {
    Route::get('status', [TGScraperController::class, 'status']);
    Route::post('scrape', [TGScraperController::class, 'scrape']);
    Route::get('channel/{channel}', [TGScraperController::class, 'channelInfo']);
    Route::get('post/{channel}/{postId}', [TGScraperController::class, 'post']);
});
