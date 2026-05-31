<?php

use App\Http\Controllers\HippoRAGTestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/test-hipporag', '/hipporag-index');

Route::prefix('hipporag-index')->name('hipporag.')->group(function (): void {
    Route::get('/', [HippoRAGTestController::class, 'index'])->name('index');
    Route::post('/spaces', [HippoRAGTestController::class, 'storeSpace'])->name('spaces.store');
    Route::post('/spaces/{userSpace}/select', [HippoRAGTestController::class, 'selectSpace'])->name('spaces.select');
    Route::delete('/spaces/{userSpace}', [HippoRAGTestController::class, 'destroySpace'])->name('spaces.destroy');
    Route::post('/index', [HippoRAGTestController::class, 'indexFiles'])->name('index-files');
    Route::post('/query', [HippoRAGTestController::class, 'query'])->name('query');
    Route::get('/sources/{source}', [HippoRAGTestController::class, 'downloadSource'])->name('sources.show');
});
