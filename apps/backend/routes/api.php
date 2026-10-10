<?php

use App\Http\Controllers\Api\V1\Account\AccountController;
use App\Http\Controllers\Api\V1\Account\MfaController;
use App\Http\Controllers\Api\V1\Account\TeamController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Checks\CheckRunController;
use App\Http\Controllers\Api\V1\Checks\CheckScenarioController;
use App\Http\Controllers\Api\V1\Incidents\IncidentController;
use App\Http\Controllers\Api\V1\Overview\OverviewController;
use App\Http\Controllers\Api\V1\Incidents\SuppressionController;
use App\Http\Controllers\Api\V1\Ingest\CredentialRotationController;
use App\Http\Controllers\Api\V1\Ingest\EventsController;
use App\Http\Controllers\Api\V1\Ingest\HeartbeatController;
use App\Http\Controllers\Api\V1\Integrations\IntegrationController;
use App\Http\Controllers\Api\V1\Integrations\StripeIntegrationController;
use App\Http\Controllers\Api\V1\Integrations\StripeWebhookController;
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

Route::middleware('stateful.session')->prefix('/v1/auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware(['guest', 'throttle:auth-signup']);
    Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:auth-login']);
    Route::post('/password-reset/request', [AccountController::class, 'requestPasswordReset'])->middleware('throttle:auth-password-reset');
    Route::post('/password-reset/complete', [AccountController::class, 'completePasswordReset'])->middleware('throttle:auth-password-reset-complete');
    Route::post('/mfa/challenge', [MfaController::class, 'challenge'])->middleware(['guest', 'throttle:auth-mfa-challenge']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AccountController::class, 'updateProfile']);
        Route::post('/password', [AccountController::class, 'changePassword'])->middleware('throttle:auth-login');
        Route::post('/email-verification/resend', [AccountController::class, 'resendVerification'])->middleware('throttle:auth-email-verification');
        Route::get('/mfa', [MfaController::class, 'show']);
        Route::post('/mfa/setup', [MfaController::class, 'setup'])->middleware('throttle:auth-step-up');
        Route::post('/mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:auth-step-up');
        Route::post('/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:auth-step-up');
        Route::post('/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:auth-step-up');
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::post('/v1/webhooks/stripe/{integration}', [StripeWebhookController::class, 'store'])
    ->middleware('throttle:provider-webhook');

// Opened from an email: no Origin/Referer, so no SPA session; the signed URL is the only credential.
Route::get('/v1/auth/email-verification/{user}/{hash}', [AccountController::class, 'verifyEmail'])
    ->name('auth.email-verification.verify')
    ->middleware('throttle:auth-password-reset-complete');

Route::middleware('stateful.session')->prefix('/v1/invitations')->group(function (): void {
    Route::get('/lookup', [TeamController::class, 'lookup'])->middleware('throttle:invitation-lookup');
    Route::post('/accept', [TeamController::class, 'accept'])->middleware(['auth:sanctum', 'throttle:invitation-lookup']);
});

Route::middleware(['stateful.session', 'auth:sanctum', 'tenant.session'])->prefix('/v1')->group(function (): void {
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
    Route::post('/stores/{store}/verification/check', [StoreVerificationController::class, 'check'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::storeManage()), 'throttle:store-verification-check']);
    Route::post('/stores/{store}/pairing-codes', [PairingCodeController::class, 'store'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeManage()));
    Route::post('/stores/{store}/integrations/stripe', [StripeIntegrationController::class, 'connect'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::integrationManage()));
    Route::put('/integrations/{integration}/webhook-secret', [StripeIntegrationController::class, 'webhookSecret'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::integrationManage()));
    Route::post('/integrations/{integration}/sync', [StripeIntegrationController::class, 'sync'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::integrationManage()), 'throttle:provider-sync']);
    Route::get('/integrations', [IntegrationController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::integrationRead()));
    Route::get('/integrations/{integration}', [IntegrationController::class, 'show'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::integrationRead()));
    Route::post('/integrations/{integration}/revoke', [IntegrationController::class, 'revoke'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::integrationManage()));
    Route::post('/integrations/{integration}/rotate', [IntegrationController::class, 'rotate'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::integrationManage()), 'idempotency']);

    Route::get('/stores/{store}/scenarios', [CheckScenarioController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::post('/stores/{store}/scenarios', [CheckScenarioController::class, 'store'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeManage()));
    Route::get('/scenarios/{scenario}', [CheckScenarioController::class, 'show'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::patch('/scenarios/{scenario}', [CheckScenarioController::class, 'update'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeManage()));
    Route::get('/stores/{store}/checks', [CheckRunController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::post('/stores/{store}/checks', [CheckRunController::class, 'store'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::storeManage()), 'idempotency']);
    Route::get('/checks/{checkRun}', [CheckRunController::class, 'show'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::post('/checks/{checkRun}/cancel', [CheckRunController::class, 'cancel'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeManage()));
    Route::get('/artifacts/{artifact}/download', [CheckRunController::class, 'download'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::storeRead()), 'throttle:artifact-url']);
    Route::get('/stores/{store}/integrations', [IntegrationController::class, 'forStore'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::integrationRead()));

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

    Route::get('/memberships', [TeamController::class, 'members'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::patch('/memberships/{user}', [TeamController::class, 'updateMember'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::teamManage()));
    Route::delete('/memberships/{user}', [TeamController::class, 'removeMember'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::storeRead()));
    Route::get('/invitations', [TeamController::class, 'invitations'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::teamManage()));
    Route::post('/invitations', [TeamController::class, 'invite'])
        ->middleware(['tenant.role:'.implode(',', TenantRoles::teamManage()), 'throttle:invitations']);
    Route::post('/ownership-transfer', [TeamController::class, 'transferOwnership'])
        ->middleware(['tenant.role:'.TenantRoles::OWNER, 'throttle:auth-step-up']);
    Route::post('/invitations/{invitation}/revoke', [TeamController::class, 'revoke'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::teamManage()));
    Route::get('/overview', [OverviewController::class, 'show'])
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
    Route::post('/notification-channels/{notificationChannel}/verification-code', [NotificationChannelController::class, 'resendVerification'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::notificationManage()));
    Route::post('/notification-channels/{notificationChannel}/test', [NotificationChannelController::class, 'test'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::notificationManage()));
    Route::get('/notification-deliveries', [NotificationDeliveryController::class, 'index'])
        ->middleware('tenant.role:'.implode(',', TenantRoles::notificationDeliveryRead()));
});

Route::post('/v1/pairing/exchange', [PairingExchangeController::class, 'store'])
    ->middleware('throttle:pairing-exchange');
Route::post('/v1/ingest/heartbeat', [HeartbeatController::class, 'store'])
    ->middleware('integration.hmac');
Route::post('/v1/ingest/events', [EventsController::class, 'store'])
    ->middleware('integration.hmac');
Route::post('/v1/ingest/credentials/rotate', [CredentialRotationController::class, 'store'])
    ->middleware('integration.hmac');
