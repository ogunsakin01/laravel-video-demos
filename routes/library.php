<?php

use Illuminate\Support\Facades\Route;
use Ogunsakin01\VideoDemo\Http\Controllers\Library\LibraryController;

Route::get('/', [LibraryController::class, 'index'])->name('index');
Route::post('/requests', [LibraryController::class, 'request'])->middleware('throttle:10,60')->name('request');
// Before the videos, as a slug never has a dot.
Route::get('/sitemap.xml', [LibraryController::class, 'sitemap'])->name('sitemap');
// For frontends that aren't Blade (Vue, React…): <video-demo-player slug="…"> and the video it plays.
Route::get('/embed.js', [LibraryController::class, 'embedScript'])->name('embed-script');
Route::get('/for-page.json', [LibraryController::class, 'forPage'])->name('for-page');
Route::get('/{slug}.json', [LibraryController::class, 'json'])->name('json');
Route::get('/{slug}', [LibraryController::class, 'show'])->name('show');
// Only adds to daily counts, so players on other sites (<video-demo-player>) may send it without a CSRF token.
// Named as strings: Laravel 13 calls the check PreventRequestForgery, Laravel 12 ValidateCsrfToken, and older apps keep their own VerifyCsrfToken.
Route::post('/{slug}/events', [LibraryController::class, 'event'])->middleware('throttle:120,1')
    ->withoutMiddleware([
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'App\Http\Middleware\VerifyCsrfToken',
    ])
    ->name('events');
