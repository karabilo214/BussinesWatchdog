<?php

use App\Http\Controllers\Api\V1\Account\AccountController;
use App\Http\Controllers\Api\V1\Account\EmailChangeController;
use App\Http\Controllers\Api\V1\Account\MfaController;
use App\Http\Controllers\Api\V1\Account\PasskeyController;
use App\Http\Controllers\Api\V1\Account\SessionController;
use App\Http\Controllers\Api\V1\Account\SupportGrantController;
use App\Http\Controllers\Api\V1\Account\TeamController;
use App\Http\Controllers\Api\V1\Account\TrustedDeviceController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Billing\CheckoutController;
use App\Http\Controllers\Api\V1\Billing\EntitlementController;
use App\Http\Controllers\Api\V1\Billing\InvoiceController;
use App\Http\Controllers\Api\V1\Billing\PaddleWebhookController;
use App\Http\Controllers\Api\V1\Billing\PlanCatalogController;
use App\Http\Controllers\Api\V1\Billing\SubscriptionController;
use App\Http\Controllers\Api\V1\Checks\CheckRunController;
use App\Http\Controllers\Api\V1\Checks\CheckScenarioController;
use App\Http\Controllers\Api\V1\Exports\ExportController;
use App\Http\Controllers\Api\V1\Incidents\IncidentController;
use App\Http\Controllers\Api\V1\Incidents\SuppressionController;
use App\Http\Controllers\Api\V1\Ingest\CredentialRotationController;
use App\Http\Controllers\Api\V1\Ingest\EventsController;
use App\Http\Controllers\Api\V1\Ingest\HeartbeatController;
use App\Http\Controllers\Api\V1\Integrations\IntegrationController;
use App\Http\Controllers\Api\V1\Integrations\ObservationTextsController;
use App\Http\Controllers\Api\V1\Integrations\PayPalIntegrationController;
use App\Http\Controllers\Api\V1\Integrations\PayPalWebhookController;
use App\Http\Controllers\Api\V1\Integrations\PluginDownloadController;
use App\Http\Controllers\Api\V1\Integrations\ProviderSyncController;
use App\Http\Controllers\Api\V1\Integrations\ProviderWebhookCheckController;
use App\Http\Controllers\Api\V1\Integrations\ShopwareAppController;
use App\Http\Controllers\Api\V1\Integrations\ShopwareAppInstallationController;
use App\Http\Controllers\Api\V1\Integrations\ShopwareIntegrationController;
use App\Http\Controllers\Api\V1\Integrations\StripeIntegrationController;
use App\Http\Controllers\Api\V1\Integrations\StripeWebhookController;
use App\Http\Controllers\Api\V1\Integrations\WooCommerceApiIntegrationController;
use App\Http\Controllers\Api\V1\Integrations\WooCommerceWebhookController;
use App\Http\Controllers\Api\V1\Notifications\NotificationChannelController;
use App\Http\Controllers\Api\V1\Notifications\NotificationDeliveryController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Overview\MaintenanceController;
use App\Http\Controllers\Api\V1\Overview\OverviewController;
use App\Http\Controllers\Api\V1\Pairing\PairingCodeController;
use App\Http\Controllers\Api\V1\Pairing\PairingExchangeController;
use App\Http\Controllers\Api\V1\Pairing\PluginConnectController;
use App\Http\Controllers\Api\V1\Payments\PaymentAllocationController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\Payments\RefundAllocationController;
use App\Http\Controllers\Api\V1\Payments\UnmatchedPaymentController;
use App\Http\Controllers\Api\V1\Privacy\OrganizationDeletionController;
use App\Http\Controllers\Api\V1\Reconciliation\ReconciliationController;
use App\Http\Controllers\Api\V1\Stores\StoreCheckoutLayoutController;
use App\Http\Controllers\Api\V1\Stores\StoreController;
use App\Http\Controllers\Api\V1\Stores\StoreSalesExclusionController;
use App\Http\Controllers\Api\V1\StoreVerifications\StoreVerificationController;
use App\Http\Controllers\Api\V1\Tenancy\AttentionController;
use App\Http\Controllers\Api\V1\Tenancy\MoneyVisibilityController;
use App\Http\Controllers\Api\V1\Tenancy\OrganizationController;
use App\Http\Controllers\Api\V1\Tenancy\TenantController;
use App\Support\Auth\TenantPermissions;
use Illuminate\Support\Facades\Route;

Route::middleware('stateful.session')->prefix('/v1/auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware(['guest', 'throttle:auth-signup']);
    Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:auth-login']);
    Route::post('/password-reset/request', [AccountController::class, 'requestPasswordReset'])->middleware('throttle:auth-password-reset');
    Route::post('/password-reset/complete', [AccountController::class, 'completePasswordReset'])->middleware('throttle:auth-password-reset-complete');
    Route::post('/mfa/challenge', [MfaController::class, 'challenge'])->middleware(['guest', 'throttle:auth-mfa-challenge']);
    Route::get('/passkeys/login/options', [PasskeyController::class, 'loginOptions'])->middleware(['guest', 'throttle:auth-mfa-challenge']);
    Route::post('/passkeys/login', [PasskeyController::class, 'login'])->middleware(['guest', 'throttle:auth-mfa-challenge']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AccountController::class, 'updateProfile']);
        Route::post('/password', [AccountController::class, 'changePassword'])->middleware('throttle:auth-login');
        Route::post('/email-verification/resend', [AccountController::class, 'resendVerification'])->middleware('throttle:auth-email-verification');
        Route::post('/email-change', [EmailChangeController::class, 'store'])->middleware(['throttle:auth-step-up', 'throttle:auth-email-change']);
        Route::post('/email-change/resend', [EmailChangeController::class, 'resend'])->middleware(['throttle:auth-email-verification', 'throttle:auth-email-change']);
        Route::post('/email-change/cancel', [EmailChangeController::class, 'cancel']);
        Route::get('/mfa', [MfaController::class, 'show']);
        Route::post('/mfa/setup', [MfaController::class, 'setup'])->middleware('throttle:auth-step-up');
        Route::post('/mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:auth-step-up');
        Route::post('/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:auth-step-up');
        Route::post('/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:auth-step-up');
        Route::get('/passkeys', [PasskeyController::class, 'index']);
        Route::post('/passkeys/authorize', [PasskeyController::class, 'authorizeRegistration'])->middleware('throttle:auth-step-up');
        Route::get('/passkeys/register/options', [PasskeyController::class, 'registrationOptions'])->middleware('throttle:auth-step-up');
        Route::post('/passkeys/register', [PasskeyController::class, 'register'])->middleware('throttle:auth-step-up');
        Route::delete('/passkeys/{passkey}', [PasskeyController::class, 'destroy'])->middleware('throttle:auth-step-up');
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::post('/sessions/revoke-all', [SessionController::class, 'destroyAll'])->middleware('throttle:auth-step-up');
        Route::get('/trusted-devices', [TrustedDeviceController::class, 'index']);
        Route::delete('/trusted-devices/{device}', [TrustedDeviceController::class, 'destroy'])->middleware('throttle:auth-step-up');
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::post('/v1/webhooks/stripe/{integration}', [StripeWebhookController::class, 'store'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/webhooks/paypal/{integration}', [PayPalWebhookController::class, 'store'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/webhooks/woocommerce/{integration}', [WooCommerceWebhookController::class, 'store'])
    ->middleware('throttle:provider-webhook');
Route::get('/v1/shopware/app/register', [ShopwareAppInstallationController::class, 'register'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/shopware/app/confirm', [ShopwareAppInstallationController::class, 'confirm'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/shopware/app/webhook', [ShopwareAppInstallationController::class, 'webhook'])
    ->middleware('throttle:provider-webhook');
Route::get('/v1/shopware/app/connect', [ShopwareAppInstallationController::class, 'module'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/shopware/app/connect', [ShopwareAppInstallationController::class, 'connect'])
    ->middleware('throttle:pairing-exchange');
Route::get('/v1/shopware/app/{integration}/register', [ShopwareAppController::class, 'register'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/shopware/app/{integration}/confirm', [ShopwareAppController::class, 'confirm'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/shopware/app/{integration}/webhook', [ShopwareAppController::class, 'webhook'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/webhooks/paddle', [PaddleWebhookController::class, 'store'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/webhooks/telegram/bind', [NotificationChannelController::class, 'bindTelegram'])
    ->middleware('throttle:provider-webhook');
Route::post('/v1/webhooks/telegram/updates', [NotificationChannelController::class, 'telegramUpdate'])
    ->middleware('throttle:provider-webhook');

// Opened from an email: the one-time token in the link is the only credential (Step 267).
Route::get('/v1/auth/email-change/{request}/confirm/{token}', [EmailChangeController::class, 'confirm'])
    ->whereUuid('request')->middleware('throttle:auth-password-reset-complete');
Route::get('/v1/auth/email-change/{request}/cancel/{token}', [EmailChangeController::class, 'cancelFromOldAddress'])
    ->whereUuid('request')->middleware('throttle:auth-password-reset-complete');

// Opened from an email: no Origin/Referer, so no SPA session; the signed URL is the only credential.
Route::get('/v1/auth/email-verification/{user}/{hash}', [AccountController::class, 'verifyEmail'])
    ->name('auth.email-verification.verify')
    ->middleware('throttle:auth-password-reset-complete');

Route::middleware('stateful.session')->prefix('/v1/invitations')->group(function (): void {
    Route::get('/lookup', [TeamController::class, 'lookup'])->middleware('throttle:invitation-lookup');
    Route::post('/accept', [TeamController::class, 'accept'])->middleware(['auth:sanctum', 'throttle:invitation-lookup']);
});

Route::middleware(['stateful.session', 'auth:sanctum', 'tenant.session'])->prefix('/v1')->group(function (): void {
    Route::get('/support-grants', [SupportGrantController::class, 'index'])->middleware('tenant.can:'.TenantPermissions::SUPPORT_ACCESS);
    Route::post('/support-grants', [SupportGrantController::class, 'store'])->middleware('tenant.can:'.TenantPermissions::SUPPORT_ACCESS);
    Route::get('/support-staff', [SupportGrantController::class, 'staff'])->middleware('tenant.can:'.TenantPermissions::SUPPORT_ACCESS);
    Route::post('/support-grants/{grant}/approve', [SupportGrantController::class, 'approve'])->whereUuid('grant')->middleware('tenant.can:'.TenantPermissions::SUPPORT_ACCESS);
    Route::post('/support-grants/{grant}/deny', [SupportGrantController::class, 'deny'])->whereUuid('grant')->middleware('tenant.can:'.TenantPermissions::SUPPORT_ACCESS);
    Route::post('/support-grants/{grant}/revoke', [SupportGrantController::class, 'revoke'])->whereUuid('grant')->middleware('tenant.can:'.TenantPermissions::SUPPORT_ACCESS);
    Route::get('/support-grants/{grant}/activity', [SupportGrantController::class, 'activity'])->whereUuid('grant')->middleware('tenant.can:'.TenantPermissions::SUPPORT_ACCESS);
    Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate']);
    Route::get('/tenants/context', [TenantController::class, 'context']);

    Route::get('/stores', [StoreController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::post('/stores', [StoreController::class, 'store'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_MANAGE);
    Route::post('/stores/detect-platform', [StoreController::class, 'detectPlatform'])
        ->middleware(['tenant.can:'.TenantPermissions::STORES_MANAGE, 'throttle:20,1']);
    Route::get('/stores/{store}', [StoreController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::patch('/stores/{store}', [StoreController::class, 'update'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_MANAGE);
    Route::get('/stores/{store}/sales-exclusions', [StoreSalesExclusionController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::put('/stores/{store}/sales-exclusions', [StoreSalesExclusionController::class, 'update'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_MANAGE);
    Route::put('/stores/{store}/checkout-layout', [StoreCheckoutLayoutController::class, 'update'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_MANAGE);
    Route::post('/stores/{store}/delete', [StoreController::class, 'destroy'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_DELETE);
    Route::post('/stores/{store}/verify', [StoreVerificationController::class, 'store'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_MANAGE);
    Route::get('/stores/{store}/verification', [StoreVerificationController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::post('/stores/{store}/verification/check', [StoreVerificationController::class, 'check'])
        ->middleware(['tenant.can:'.TenantPermissions::STORES_MANAGE, 'throttle:store-verification-check']);
    Route::post('/stores/{store}/pairing-codes', [PairingCodeController::class, 'store'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::get('/plugin-connect', [PluginConnectController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/plugin-connect', [PluginConnectController::class, 'store'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/plugin-connect/cancel', [PluginConnectController::class, 'destroy'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/stores/{store}/integrations/stripe', [StripeIntegrationController::class, 'connect'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::put('/integrations/{integration}/webhook-secret', [StripeIntegrationController::class, 'webhookSecret'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/integrations/{integration}/woocommerce-webhook', [WooCommerceWebhookController::class, 'setUp'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/integrations/{integration}/woocommerce-webhook/check', [WooCommerceWebhookController::class, 'check'])
        ->middleware(['tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE, 'throttle:provider-sync']);
    Route::post('/stores/{store}/integrations/paypal', [PayPalIntegrationController::class, 'connect'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/stores/{store}/integrations/woocommerce-api', [WooCommerceApiIntegrationController::class, 'connect'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/stores/{store}/integrations/shopware', [ShopwareIntegrationController::class, 'connect'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/stores/{store}/integrations/shopware-app', [ShopwareAppController::class, 'package'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/stores/{store}/integrations/shopware-app/update', [ShopwareAppController::class, 'update'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::put('/integrations/{integration}/webhook-id', [PayPalIntegrationController::class, 'webhookId'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/integrations/{integration}/webhook-check', [ProviderWebhookCheckController::class, 'check'])
        ->middleware(['tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE, 'throttle:provider-sync']);
    Route::post('/integrations/{integration}/sync', [ProviderSyncController::class, 'sync'])
        ->middleware(['tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE, 'throttle:provider-sync']);
    Route::post('/billing/checkout', [CheckoutController::class, 'checkout'])
        ->middleware('tenant.can:'.TenantPermissions::BILLING_MANAGE);
    Route::post('/billing/trial', [PlanCatalogController::class, 'trial'])
        ->middleware(['tenant.can:'.TenantPermissions::BILLING_MANAGE, 'idempotency']);
    Route::get('/billing/checkouts/{checkout}', [CheckoutController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::BILLING_MANAGE);
    Route::get('/billing/discounts/check', [CheckoutController::class, 'checkDiscount'])
        ->middleware(['tenant.can:'.TenantPermissions::BILLING_MANAGE, 'throttle:discount-check']);
    Route::get('/billing/plans', [PlanCatalogController::class, 'plans'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/billing/subscription', [SubscriptionController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::BILLING_MANAGE);
    Route::get('/billing/invoices', [InvoiceController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::BILLING_MANAGE);
    Route::get('/billing/invoices/{invoice}/download', [InvoiceController::class, 'download'])
        ->middleware(['tenant.can:'.TenantPermissions::BILLING_MANAGE, 'throttle:artifact-url']);
    Route::post('/billing/payment-method', [InvoiceController::class, 'paymentMethod'])
        ->middleware(['tenant.can:'.TenantPermissions::BILLING_MANAGE, 'idempotency']);
    Route::post('/billing/plan-change-preview', [SubscriptionController::class, 'previewPlanChange'])
        ->middleware(['tenant.can:'.TenantPermissions::BILLING_MANAGE, 'throttle:plan-change-preview']);
    Route::post('/billing/plan-change', [SubscriptionController::class, 'changePlan'])
        ->middleware('tenant.can:'.TenantPermissions::BILLING_MANAGE);
    Route::post('/billing/plan-change/cancel', [SubscriptionController::class, 'cancelPlanChange'])
        ->middleware(['tenant.can:'.TenantPermissions::BILLING_MANAGE, 'idempotency']);
    Route::post('/billing/cancel', [SubscriptionController::class, 'cancel'])
        ->middleware(['tenant.can:'.TenantPermissions::BILLING_MANAGE, 'idempotency']);
    Route::post('/billing/resume', [SubscriptionController::class, 'resume'])
        ->middleware(['tenant.can:'.TenantPermissions::BILLING_MANAGE, 'idempotency']);
    Route::put('/billing/retained-stores', [SubscriptionController::class, 'retainStores'])
        ->middleware('tenant.can:'.TenantPermissions::BILLING_MANAGE);
    Route::get('/entitlements', [EntitlementController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/integrations', [IntegrationController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/integrations/{integration}', [IntegrationController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::post('/integrations/{integration}/revoke', [IntegrationController::class, 'revoke'])
        ->middleware('tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE);
    Route::post('/integrations/{integration}/rotate', [IntegrationController::class, 'rotate'])
        ->middleware(['tenant.can:'.TenantPermissions::INTEGRATIONS_MANAGE, 'idempotency']);

    Route::get('/stores/{store}/scenarios', [CheckScenarioController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::post('/stores/{store}/scenarios', [CheckScenarioController::class, 'store'])
        ->middleware('tenant.can:'.TenantPermissions::CHECKS_RUN);
    Route::get('/scenarios/{scenario}', [CheckScenarioController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::patch('/scenarios/{scenario}', [CheckScenarioController::class, 'update'])
        ->middleware('tenant.can:'.TenantPermissions::CHECKS_RUN);
    Route::get('/stores/{store}/checks', [CheckRunController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::post('/stores/{store}/checks', [CheckRunController::class, 'store'])
        ->middleware(['tenant.can:'.TenantPermissions::CHECKS_RUN, 'idempotency']);
    Route::get('/checks/{checkRun}', [CheckRunController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::post('/checks/{checkRun}/cancel', [CheckRunController::class, 'cancel'])
        ->middleware('tenant.can:'.TenantPermissions::CHECKS_RUN);
    Route::get('/artifacts/{artifact}/download', [CheckRunController::class, 'download'])
        ->middleware(['tenant.can:'.TenantPermissions::STORES_READ, 'throttle:artifact-url']);
    Route::get('/stores/{store}/integrations', [IntegrationController::class, 'forStore'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/downloads/woocommerce-plugin', [PluginDownloadController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/downloads/woocommerce-plugin/file', [PluginDownloadController::class, 'download'])
        ->middleware(['tenant.can:'.TenantPermissions::STORES_READ, 'throttle:artifact-url']);
    Route::get('/downloads/shopware-app', [ShopwareAppInstallationController::class, 'download'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/downloads/observation-texts', [ObservationTextsController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);

    Route::post('/payment-allocations', [PaymentAllocationController::class, 'store'])
        ->middleware(['tenant.can:'.TenantPermissions::ALLOCATIONS_MANAGE, 'idempotency']);
    Route::post('/payment-allocations/{paymentAllocation}/revoke', [PaymentAllocationController::class, 'revoke'])
        ->middleware('tenant.can:'.TenantPermissions::ALLOCATIONS_MANAGE);
    Route::post('/refund-allocations', [RefundAllocationController::class, 'store'])
        ->middleware(['tenant.can:'.TenantPermissions::ALLOCATIONS_MANAGE, 'idempotency']);
    Route::post('/refund-allocations/{refundAllocation}/revoke', [RefundAllocationController::class, 'revoke'])
        ->middleware('tenant.can:'.TenantPermissions::ALLOCATIONS_MANAGE);

    Route::post('/stores/{store}/reconciliations', [ReconciliationController::class, 'store'])
        ->middleware(['tenant.can:'.TenantPermissions::RECONCILIATION_RUN, 'idempotency']);
    Route::get('/stores/{store}/findings', [ReconciliationController::class, 'findings'])
        ->middleware('tenant.can:'.TenantPermissions::RECONCILIATION_READ);
    Route::get('/stores/{store}/unmatched-payments', [UnmatchedPaymentController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::RECONCILIATION_READ);
    Route::post('/stores/{store}/exports', [ExportController::class, 'store'])
        ->middleware(['tenant.can:'.TenantPermissions::STORES_READ, 'idempotency']);
    Route::get('/exports/{export}', [ExportController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/exports/{export}/download', [ExportController::class, 'download'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);

    Route::get('/orders/{order}', [OrderController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);

    Route::get('/memberships', [TeamController::class, 'members'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::patch('/memberships/{user}', [TeamController::class, 'updateMember'])
        ->middleware('tenant.can:'.TenantPermissions::TEAM_MANAGE);
    Route::delete('/memberships/{user}', [TeamController::class, 'removeMember'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/invitations', [TeamController::class, 'invitations'])
        ->middleware('tenant.can:'.TenantPermissions::TEAM_MANAGE);
    Route::post('/invitations', [TeamController::class, 'invite'])
        ->middleware(['tenant.can:'.TenantPermissions::TEAM_MANAGE, 'throttle:invitations']);
    Route::post('/ownership-transfer', [TeamController::class, 'transferOwnership'])
        ->middleware(['tenant.can:'.TenantPermissions::OWNERSHIP_TRANSFER, 'throttle:auth-step-up']);
    Route::post('/memberships/{user}/mfa/reset', [TeamController::class, 'resetMemberMfa'])
        ->whereUuid('user')->middleware(['tenant.can:'.TenantPermissions::MEMBERS_MFA_RESET, 'throttle:auth-step-up']);
    Route::get('/organization-deletion', [OrganizationDeletionController::class, 'show']);
    Route::post('/organization-deletion', [OrganizationDeletionController::class, 'store'])->middleware('throttle:auth-step-up');
    Route::post('/organization-deletion/cancel', [OrganizationDeletionController::class, 'cancel'])->middleware('throttle:auth-step-up');
    Route::patch('/invitations/{invitation}', [TeamController::class, 'updateInvitation'])
        ->whereUuid('invitation')->middleware('tenant.can:'.TenantPermissions::TEAM_MANAGE);
    Route::post('/invitations/{invitation}/revoke', [TeamController::class, 'revoke'])
        ->middleware('tenant.can:'.TenantPermissions::TEAM_MANAGE);
    Route::get('/overview', [OverviewController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/money-visibility', [MoneyVisibilityController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/organization', [OrganizationController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/attention', [AttentionController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/maintenance', [MaintenanceController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
    Route::get('/incidents', [IncidentController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::INCIDENTS_READ);
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])
        ->middleware('tenant.can:'.TenantPermissions::INCIDENTS_READ);
    Route::post('/incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])
        ->middleware('tenant.can:'.TenantPermissions::INCIDENTS_MANAGE);
    Route::post('/incidents/{incident}/resolve', [IncidentController::class, 'resolve'])
        ->middleware('tenant.can:'.TenantPermissions::INCIDENTS_MANAGE);
    Route::post('/incidents/{incident}/comments', [IncidentController::class, 'comment'])
        ->middleware('tenant.can:'.TenantPermissions::INCIDENTS_MANAGE);
    Route::post('/incidents/{incident}/snooze', [IncidentController::class, 'snooze'])
        ->middleware('tenant.can:'.TenantPermissions::INCIDENTS_SNOOZE);
    Route::post('/suppressions/{suppression}/revoke', [SuppressionController::class, 'revoke'])
        ->middleware('tenant.can:'.TenantPermissions::INCIDENTS_SNOOZE);

    Route::get('/notification-channels', [NotificationChannelController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::NOTIFICATIONS_MANAGE);
    Route::post('/notification-channels', [NotificationChannelController::class, 'store'])
        ->middleware(['tenant.can:'.TenantPermissions::NOTIFICATIONS_MANAGE, 'idempotency']);
    Route::patch('/notification-channels/{notificationChannel}', [NotificationChannelController::class, 'update'])
        ->middleware('tenant.can:'.TenantPermissions::NOTIFICATIONS_MANAGE);
    Route::post('/notification-channels/{notificationChannel}/verify', [NotificationChannelController::class, 'verify'])
        ->middleware('tenant.can:'.TenantPermissions::NOTIFICATIONS_MANAGE);
    Route::post('/notification-channels/{notificationChannel}/verification-code', [NotificationChannelController::class, 'resendVerification'])
        ->middleware('tenant.can:'.TenantPermissions::NOTIFICATIONS_MANAGE);
    Route::post('/notification-channels/{notificationChannel}/test', [NotificationChannelController::class, 'test'])
        ->middleware('tenant.can:'.TenantPermissions::NOTIFICATIONS_MANAGE);
    Route::get('/notification-deliveries', [NotificationDeliveryController::class, 'index'])
        ->middleware('tenant.can:'.TenantPermissions::STORES_READ);
});

Route::post('/v1/pairing/exchange', [PairingExchangeController::class, 'store'])
    ->middleware('throttle:pairing-exchange');
Route::post('/v1/ingest/heartbeat', [HeartbeatController::class, 'store'])
    ->middleware('integration.hmac');
Route::post('/v1/ingest/events', [EventsController::class, 'store'])
    ->middleware('integration.hmac');
Route::post('/v1/ingest/credentials/rotate', [CredentialRotationController::class, 'store'])
    ->middleware('integration.hmac');
