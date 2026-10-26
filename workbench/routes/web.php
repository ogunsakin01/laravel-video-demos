<?php

use Illuminate\Support\Facades\Route;

// Pages of the demo "host app", with the package's components embedded
Route::view('/', 'workbench::dashboard');
Route::view('/jobs/create', 'workbench::jobs-create');
// For trying the player that pauses while the viewer is away, by hand
Route::view('/demo/player', 'workbench::player');
