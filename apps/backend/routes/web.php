<?php

use App\Http\Controllers\Health\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::get('/', function () {
    return view('welcome');
});
