<?php

use Illuminate\Support\Facades\Route;
use Ogunsakin01\VideoDemo\Http\Controllers\Library\RequestsApiController;

// Lets the studio on a developer's machine read the videos users asked for
// in production, using the token in video-demo.library.requests_token.
Route::get('/api/requests', RequestsApiController::class)->name('requests');
