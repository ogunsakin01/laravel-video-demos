<?php

use Illuminate\Support\Facades\Route;
use Ogunsakin01\VideoDemo\Http\Controllers\Library\RequestsApiController;

// Lets the studio on a developer's machine read the questions users asked
// in production, with the token from video-demo.library.requests_token
Route::get('/api/requests', RequestsApiController::class)->name('requests');
