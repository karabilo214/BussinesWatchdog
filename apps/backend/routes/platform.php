<?php

use App\Http\Controllers\Platform\PlatformAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('/platform-api/v1')->middleware('platform')->group(function (): void {
    Route::get('/csrf-cookie', [PlatformAuthController::class, 'csrf']);
    Route::post('/auth/login', [PlatformAuthController::class, 'login'])->middleware('throttle:platform-login');
    Route::post('/auth/mfa', [PlatformAuthController::class, 'mfa'])->middleware('throttle:platform-mfa');

    Route::middleware('platform.staff')->group(function (): void {
        Route::get('/me', [PlatformAuthController::class, 'show']);
        Route::post('/auth/logout', [PlatformAuthController::class, 'logout']);
    });
});
