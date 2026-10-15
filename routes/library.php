<?php

use Illuminate\Support\Facades\Route;
use Ogunsakin01\VideoDemo\Http\Controllers\Library\LibraryController;

Route::get('/', [LibraryController::class, 'index'])->name('index');
Route::post('/requests', [LibraryController::class, 'request'])->middleware('throttle:10,60')->name('request');
Route::get('/{slug}', [LibraryController::class, 'show'])->name('show');
Route::post('/{slug}/events', [LibraryController::class, 'event'])->middleware('throttle:120,1')->name('events');
