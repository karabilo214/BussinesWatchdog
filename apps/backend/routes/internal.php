<?php

use App\Http\Controllers\Internal\Browser\BrowserLeaseController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1/browser')->middleware(['browser.worker', 'throttle:browser-worker'])->group(function (): void {
    Route::post('/leases', [BrowserLeaseController::class, 'lease']);
    Route::post('/attempts/{attemptId}/heartbeat', [BrowserLeaseController::class, 'heartbeat'])->whereUuid('attemptId');
    Route::post('/attempts/{attemptId}/result', [BrowserLeaseController::class, 'result'])->whereUuid('attemptId');
    Route::post('/attempts/{attemptId}/artifacts', [BrowserLeaseController::class, 'artifact'])->whereUuid('attemptId');
});
