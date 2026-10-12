<?php

use App\Http\Controllers\Platform\DiscountController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\PlatformAuthController;
use App\Support\Platform\PlatformCapabilities;
use Illuminate\Support\Facades\Route;

Route::prefix('/platform-api/v1')->middleware('platform')->group(function (): void {
    Route::get('/csrf-cookie', [PlatformAuthController::class, 'csrf']);
    Route::post('/auth/login', [PlatformAuthController::class, 'login'])->middleware('throttle:platform-login');
    Route::post('/auth/mfa', [PlatformAuthController::class, 'mfa'])->middleware('throttle:platform-mfa');

    Route::middleware('platform.staff')->group(function (): void {
        Route::get('/me', [PlatformAuthController::class, 'show']);
        Route::post('/auth/logout', [PlatformAuthController::class, 'logout']);
    });

    Route::middleware('platform.staff:'.PlatformCapabilities::PLANS_READ)->get('/plans', [PlanController::class, 'index']);
    Route::middleware('platform.staff:'.PlatformCapabilities::PLANS_READ)->get('/discounts', [DiscountController::class, 'index']);
    Route::middleware('platform.staff:'.PlatformCapabilities::TENANTS_READ)->get('/tenants/lookup', [DiscountController::class, 'tenants']);

    Route::middleware('platform.staff:'.PlatformCapabilities::PLANS_MANAGE)->group(function (): void {
        Route::post('/plans', [PlanController::class, 'store']);
        Route::post('/plans/{plan}/versions', [PlanController::class, 'newVersion']);
        Route::patch('/plan-versions/{version}', [PlanController::class, 'update']);
        Route::post('/plan-versions/{version}/prices', [PlanController::class, 'addPrice']);
        Route::delete('/plan-prices/{price}', [PlanController::class, 'removePrice']);
        Route::post('/plan-versions/{version}/publish', [PlanController::class, 'publish'])->middleware('throttle:platform-step-up');
        Route::post('/plan-versions/{version}/retire', [PlanController::class, 'retire'])->middleware('throttle:platform-step-up');
        Route::post('/discounts', [DiscountController::class, 'store'])->middleware('throttle:platform-step-up');
        Route::patch('/discounts/{discount}', [DiscountController::class, 'update'])->middleware('throttle:platform-step-up');
        Route::post('/discounts/{discount}/archive', [DiscountController::class, 'archive'])->middleware('throttle:platform-step-up');
    });
});
