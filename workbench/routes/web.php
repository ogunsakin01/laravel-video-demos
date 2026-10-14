<?php

use Illuminate\Support\Facades\Route;

// Pages of the demo "host app", with the package's components embedded
Route::view('/', 'workbench::dashboard');
Route::view('/jobs/create', 'workbench::jobs-create');
