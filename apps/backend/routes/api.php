<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Stores\StoreController;
use App\Http\Controllers\Api\V1\Tenancy\TenantController;
use App\Support\Auth\TenantRoles;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('/v1/auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('guest');
    Route::post('/login', [AuthController::class, 'login'])->middleware('guest');

    Route::middleware('auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['web', 'auth', 'tenant.session'])->prefix('/v1')->group(function (): void {
    Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate']);
    Route::get('/tenants/context', [TenantController::class, 'context']);

    Route::get('/stores', [StoreController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::post('/stores', [StoreController::class, 'store'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeManage()));
    Route::get('/stores/{store}', [StoreController::class, 'show'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::patch('/stores/{store}', [StoreController::class, 'update'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeManage()));
});
