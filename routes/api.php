<?php

use Illuminate\Support\Facades\Route;
use Ogunsakin01\VideoDemo\Http\Controllers\Library\RequestsApiController;
use Ogunsakin01\VideoDemo\Http\Controllers\Library\StatsApiController;

// Lets the studio on a developer's machine read the videos users asked for
// in production, using the token in video-demo.library.requests_token.
Route::get('/api/requests', RequestsApiController::class)->name('requests');
// How the videos are watched, when video-demo.library.stats is on.
Route::get('/api/stats', StatsApiController::class)->name('stats');
