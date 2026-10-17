<?php

use Illuminate\Support\Facades\Route;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\RecorderLoginController;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\SoundController;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\StyleController;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\SuggestionController;
use Ogunsakin01\VideoDemo\Http\Controllers\Studio\VideoController;
use Ogunsakin01\VideoDemo\Http\Middleware\AuthorizeStudio;

// Every studio route except the recorder's sign-in link, which is
// protected by its signature instead.
Route::middleware(AuthorizeStudio::class)->group(function () {
    Route::get('/', [VideoController::class, 'index'])->name('index');
    Route::get('/create', [VideoController::class, 'create'])->name('create');
    Route::post('/', [VideoController::class, 'store'])->name('store');
    Route::post('/estimate', [VideoController::class, 'estimate'])->name('estimate');
    Route::post('/complete', [VideoController::class, 'complete'])->name('complete');
    Route::get('/requests', [VideoController::class, 'requests'])->name('requests');
    Route::get('/pages', [VideoController::class, 'pages'])->name('pages');
    Route::get('/examples', [VideoController::class, 'examples'])->name('examples');
    Route::get('/ideas', [SuggestionController::class, 'index'])->name('suggestions.index');
    Route::post('/ideas', [SuggestionController::class, 'store'])->name('suggestions.store');
    Route::delete('/ideas/{idea}', [SuggestionController::class, 'destroy'])->name('suggestions.destroy');

    Route::get('/voice-sample', [SoundController::class, 'sample'])->name('voice-sample');
    Route::get('/music/{key}', [SoundController::class, 'play'])->name('music.play');
    Route::post('/music', [SoundController::class, 'upload'])->name('music.upload');
    Route::delete('/music/{key}', [SoundController::class, 'destroy'])->name('music.destroy');

    Route::get('/styles', [StyleController::class, 'index'])->name('styles.index');
    Route::post('/styles', [StyleController::class, 'store'])->name('styles.store');
    Route::put('/styles/{style}', [StyleController::class, 'update'])->name('styles.update');
    Route::delete('/styles/{style}', [StyleController::class, 'destroy'])->name('styles.destroy');
    Route::get('/styles/{style}/logo', [StyleController::class, 'logo'])->name('styles.logo');

    Route::post('/bulk', [VideoController::class, 'bulk'])->name('bulk');
    Route::post('/restore', [VideoController::class, 'restore'])->name('restore');
    Route::get('/delete', [VideoController::class, 'confirmDelete'])->name('delete');
    Route::get('/spending', [VideoController::class, 'spending'])->name('spending');
    Route::get('/stats', [VideoController::class, 'stats'])->name('stats');
    Route::get('/{video}/stats', [VideoController::class, 'videoStats'])->name('video-stats');
    Route::get('/{video}', [VideoController::class, 'show'])->name('show');
    Route::get('/{video}/status', [VideoController::class, 'status'])->name('status');
    Route::get('/{video}/file/{device}/{kind}', [VideoController::class, 'file'])->whereIn('kind', ['video', 'poster', 'subtitles'])->name('file');
    Route::get('/{video}/error.jpg', [VideoController::class, 'errorScreenshot'])->name('error-screenshot');
    Route::post('/{video}/spots', [VideoController::class, 'findSpots'])->name('spots');
    Route::get('/{video}/spots/{file}', [VideoController::class, 'spotShot'])->where('file', '\d+-[a-z]+(-(closed|open))?\.jpg')->name('spot-shot');
    Route::patch('/{video}', [VideoController::class, 'update'])->name('update');
    Route::post('/{video}/again', [VideoController::class, 'again'])->name('again');
    Route::post('/{video}/improve', [VideoController::class, 'improve'])->name('improve');
    Route::post('/{video}/retry', [VideoController::class, 'retry'])->name('retry');
    Route::post('/{video}/exports', [VideoController::class, 'export'])->name('export');
    Route::get('/{video}/exports/{format}', [VideoController::class, 'download'])->whereIn('format', ['gif', 'square', 'vertical'])->name('download');
    Route::post('/{video}/translations', [VideoController::class, 'translate'])->name('translate');
    Route::put('/{video}/plan', [VideoController::class, 'updatePlan'])->name('plan');
    Route::post('/{video}/verify', [VideoController::class, 'verify'])->name('verify');
    Route::get('/{video}/verify.jpg', [VideoController::class, 'verifyScreenshot'])->name('verify-screenshot');
    Route::post('/{video}/stop', [VideoController::class, 'stop'])->name('stop');
    Route::post('/{video}/publish', [VideoController::class, 'publish'])->name('publish');
    Route::post('/{video}/unpublish', [VideoController::class, 'unpublish'])->name('unpublish');
    Route::delete('/{video}', [VideoController::class, 'destroy'])->name('destroy');
});

// The recorder signs in through this signed link before recording starts.
Route::get('/_recorder/login/{user}', RecorderLoginController::class)
    ->middleware('signed:relative')
    ->name('recorder-login');

// For a separate frontend, the recorder fetches what its browser needs to be
// signed in (a token, cookies or headers) from this signed link instead.
Route::get('/_recorder/sign-in/{user}', [RecorderLoginController::class, 'signIn'])
    ->middleware('signed:relative')
    ->name('recorder-sign-in');
