<?php

use Illuminate\Support\Facades\Route;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\RecorderLoginController;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\StyleController;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\SuggestionController;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\VideoController;
use Ogunsakin01\VideoDemo\Http\Middleware\AuthorizeStudio;

// Everything except the recorder's sign-in link, which is signed instead
Route::middleware(AuthorizeStudio::class)->group(function () {
    Route::get('/', [VideoController::class, 'index'])->name('index');
    Route::get('/create', [VideoController::class, 'create'])->name('create');
    Route::post('/', [VideoController::class, 'store'])->name('store');
    Route::post('/estimate', [VideoController::class, 'estimate'])->name('estimate');
    Route::post('/complete', [VideoController::class, 'complete'])->name('complete');
    Route::get('/requests', [VideoController::class, 'requests'])->name('requests');
    Route::get('/examples', [VideoController::class, 'examples'])->name('examples');
    Route::get('/ideas', [SuggestionController::class, 'index'])->name('suggestions.index');
    Route::post('/ideas', [SuggestionController::class, 'store'])->name('suggestions.store');
    Route::delete('/ideas/{idea}', [SuggestionController::class, 'destroy'])->name('suggestions.destroy');

    Route::get('/styles', [StyleController::class, 'index'])->name('styles.index');
    Route::post('/styles', [StyleController::class, 'store'])->name('styles.store');
    Route::put('/styles/{style}', [StyleController::class, 'update'])->name('styles.update');
    Route::delete('/styles/{style}', [StyleController::class, 'destroy'])->name('styles.destroy');

    Route::get('/{video}', [VideoController::class, 'show'])->name('show');
    Route::get('/{video}/status', [VideoController::class, 'status'])->name('status');
    Route::get('/{video}/file/{device}/{kind}', [VideoController::class, 'file'])->whereIn('kind', ['video', 'poster'])->name('file');
    Route::patch('/{video}', [VideoController::class, 'update'])->name('update');
    Route::post('/{video}/regenerate', [VideoController::class, 'regenerate'])->name('regenerate');
    Route::post('/{video}/rerecord', [VideoController::class, 'rerecord'])->name('rerecord');
    Route::post('/{video}/retry', [VideoController::class, 'retry'])->name('retry');
    Route::post('/{video}/stop', [VideoController::class, 'stop'])->name('stop');
    Route::post('/{video}/publish', [VideoController::class, 'publish'])->name('publish');
    Route::post('/{video}/unpublish', [VideoController::class, 'unpublish'])->name('unpublish');
    Route::delete('/{video}', [VideoController::class, 'destroy'])->name('destroy');
});

// The recorder signs in through this signed link before recording starts
Route::get('/_recorder/login/{user}', RecorderLoginController::class)
    ->middleware('signed:relative')
    ->name('recorder-login');
