<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Incidents\IncidentController;
use App\Http\Controllers\Api\V1\Incidents\SuppressionController;
use App\Http\Controllers\Api\V1\Ingest\EventsController;
use App\Http\Controllers\Api\V1\Ingest\HeartbeatController;
use App\Http\Controllers\Api\V1\Integrations\IntegrationController;
use App\Http\Controllers\Api\V1\Notifications\NotificationChannelController;
use App\Http\Controllers\Api\V1\Notifications\NotificationDeliveryController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Pairing\PairingCodeController;
use App\Http\Controllers\Api\V1\Pairing\PairingExchangeController;
use App\Http\Controllers\Api\V1\Payments\PaymentAllocationController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\Payments\RefundAllocationController;
use App\Http\Controllers\Api\V1\Payments\UnmatchedPaymentController;
use App\Http\Controllers\Api\V1\Reconciliation\ReconciliationController;
use App\Http\Controllers\Api\V1\Stores\StoreController;
use App\Http\Controllers\Api\V1\StoreVerifications\StoreVerificationController;
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
    Route::post('/stores/{store}/verify', [StoreVerificationController::class, 'store'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeManage()));
    Route::get('/stores/{store}/verification', [StoreVerificationController::class, 'show'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::post('/stores/{store}/pairing-codes', [PairingCodeController::class, 'store'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeManage()));
    Route::get('/integrations', [IntegrationController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::integrationRead()));
    Route::get('/integrations/{integration}', [IntegrationController::class, 'show'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::integrationRead()));
    Route::post('/integrations/{integration}/revoke', [IntegrationController::class, 'revoke'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::integrationManage()));

    Route::post('/payment-allocations', [PaymentAllocationController::class, 'store'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::allocationManage()), 'idempotency']);
    Route::post('/payment-allocations/{paymentAllocation}/revoke', [PaymentAllocationController::class, 'revoke'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::allocationManage()));
    Route::post('/refund-allocations', [RefundAllocationController::class, 'store'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::allocationManage()), 'idempotency']);
    Route::post('/refund-allocations/{refundAllocation}/revoke', [RefundAllocationController::class, 'revoke'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::allocationManage()));

    Route::post('/stores/{store}/reconciliations', [ReconciliationController::class, 'store'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::reconciliationTrigger()), 'idempotency']);
    Route::get('/stores/{store}/findings', [ReconciliationController::class, 'findings'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::findingsRead()));
    Route::get('/stores/{store}/unmatched-payments', [UnmatchedPaymentController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::findingsRead()));

    Route::get('/orders/{order}', [OrderController::class, 'show'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));

    Route::get('/incidents', [IncidentController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::incidentRead()));
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::incidentRead()));
    Route::post('/incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::incidentManage()));
    Route::post('/incidents/{incident}/resolve', [IncidentController::class, 'resolve'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::incidentManage()));
    Route::post('/incidents/{incident}/comments', [IncidentController::class, 'comment'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::incidentManage()));
    Route::post('/incidents/{incident}/snooze', [IncidentController::class, 'snooze'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::incidentSnooze()));
    Route::post('/suppressions/{suppression}/revoke', [SuppressionController::class, 'revoke'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::incidentSnooze()));

    Route::get('/notification-channels', [NotificationChannelController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::notificationManage()));
    Route::post('/notification-channels', [NotificationChannelController::class, 'store'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::notificationManage()), 'idempotency']);
    Route::patch('/notification-channels/{notificationChannel}', [NotificationChannelController::class, 'update'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::notificationManage()));
    Route::post('/notification-channels/{notificationChannel}/verify', [NotificationChannelController::class, 'verify'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::notificationManage()));
    Route::post('/notification-channels/{notificationChannel}/test', [NotificationChannelController::class, 'test'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::notificationManage()));
    Route::get('/notification-deliveries', [NotificationDeliveryController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::notificationDeliveryRead()));
});

Route::post('/v1/pairing/exchange', [PairingExchangeController::class, 'store']);
Route::post('/v1/ingest/heartbeat', [HeartbeatController::class, 'store'])
    ->middleware('integration.hmac');
Route::post('/v1/ingest/events', [EventsController::class, 'store'])
    ->middleware('integration.hmac');
