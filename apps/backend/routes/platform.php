<?php

use App\Http\Controllers\Platform\AuditController;
use App\Http\Controllers\Platform\ContactRequestController;
use App\Http\Controllers\Platform\DiscountController;
use App\Http\Controllers\Platform\OverviewController;
use App\Http\Controllers\Platform\PageBlockController;
use App\Http\Controllers\Platform\PageController;
use App\Http\Controllers\Platform\PaymentController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\PlatformAuthController;
use App\Http\Controllers\Platform\PublicStorefrontCheckHistoryController;
use App\Http\Controllers\Platform\RefundController;
use App\Http\Controllers\Platform\ServiceHealthController;
use App\Http\Controllers\Platform\SettingsController;
use App\Http\Controllers\Platform\SiteMediaController;
use App\Http\Controllers\Platform\StaffController;
use App\Http\Controllers\Platform\StaffInvitationController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Platform\TenantDeletionController;
use App\Http\Controllers\Platform\TenantStoreController;
use App\Http\Controllers\Platform\TenantTeamController;
use App\Support\Platform\PlatformCapabilities;
use Illuminate\Support\Facades\Route;

Route::prefix('/platform-api/v1')->middleware('platform')->group(function (): void {
    Route::get('/csrf-cookie', [PlatformAuthController::class, 'csrf']);
    Route::post('/auth/login', [PlatformAuthController::class, 'login'])->middleware('throttle:platform-login');
    Route::post('/auth/mfa', [PlatformAuthController::class, 'mfa'])->middleware('throttle:platform-mfa');
    Route::get('/staff-invitations/lookup', [StaffInvitationController::class, 'lookup'])->middleware('throttle:platform-invitation');
    Route::post('/staff-invitations/start', [StaffInvitationController::class, 'start'])->middleware('throttle:platform-invitation');
    Route::post('/staff-invitations/confirm', [StaffInvitationController::class, 'confirm'])->middleware('throttle:platform-mfa');

    Route::middleware('platform.staff')->group(function (): void {
        Route::get('/me', [PlatformAuthController::class, 'show']);
        Route::post('/auth/logout', [PlatformAuthController::class, 'logout']);
    });

    Route::middleware('platform.staff:'.PlatformCapabilities::PLANS_READ)->get('/plans', [PlanController::class, 'index']);
    Route::middleware('platform.staff:'.PlatformCapabilities::PLANS_READ)->get('/discounts', [DiscountController::class, 'index']);
    Route::middleware('platform.staff:'.PlatformCapabilities::BILLING_READ)->get('/discount-applications', [DiscountController::class, 'applications']);
    Route::middleware('platform.staff:'.PlatformCapabilities::TENANTS_READ)->get('/tenants/lookup', [DiscountController::class, 'tenants']);
    Route::middleware('platform.staff:'.PlatformCapabilities::TENANTS_READ)->get('/tenants', [TenantController::class, 'index']);
    Route::middleware('platform.staff:'.PlatformCapabilities::TENANTS_READ)->get('/tenants/{tenant}', [TenantController::class, 'show']);
    Route::middleware('platform.staff:'.PlatformCapabilities::TENANTS_READ)->get('/tenants/{tenant}/reminders', [TenantController::class, 'reminders'])->whereUuid('tenant');
    Route::middleware('platform.staff:'.PlatformCapabilities::BILLING_READ)->get('/subscriptions', [TenantController::class, 'subscriptions']);
    Route::middleware('platform.staff:'.PlatformCapabilities::BILLING_READ)->get('/payments', [PaymentController::class, 'index']);
    Route::middleware('platform.staff:'.PlatformCapabilities::BILLING_READ)->get('/overview', [OverviewController::class, 'show']);
    Route::middleware('platform.staff:'.PlatformCapabilities::SERVICE_READ)->get('/health', [ServiceHealthController::class, 'show']);
    Route::middleware('platform.staff:'.PlatformCapabilities::SERVICE_READ)->get('/public-storefront-checks', [PublicStorefrontCheckHistoryController::class, 'index']);
    Route::middleware('platform.staff:'.PlatformCapabilities::SETTINGS_MANAGE)->group(function (): void {
        Route::get('/settings', [SettingsController::class, 'show']);
        Route::post('/settings/maintenance', [SettingsController::class, 'scheduleMaintenance'])->middleware('throttle:platform-step-up');
        Route::post('/settings/maintenance/cancel', [SettingsController::class, 'cancelMaintenance'])->middleware('throttle:platform-step-up');
    });
    Route::middleware('platform.staff:'.PlatformCapabilities::AUDIT_READ)->group(function (): void {
        Route::get('/audit', [AuditController::class, 'index']);
        Route::get('/audit/export', [AuditController::class, 'export'])->middleware('throttle:platform-step-up');
    });
    Route::middleware('platform.staff:'.PlatformCapabilities::STAFF_MANAGE)->group(function (): void {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff/invitations', [StaffController::class, 'invite'])->middleware('throttle:platform-step-up');
        Route::post('/staff/invitations/{invitation}/revoke', [StaffController::class, 'revokeInvitation'])->whereUuid('invitation');
        Route::patch('/staff/{staff}', [StaffController::class, 'update'])->whereUuid('staff')->middleware('throttle:platform-step-up');
        Route::post('/staff/{staff}/sessions/revoke', [StaffController::class, 'revokeSessions'])->whereUuid('staff');
        Route::post('/staff/{staff}/mfa/reset', [StaffController::class, 'resetMfa'])->whereUuid('staff')->middleware('throttle:platform-step-up');
    });
    Route::middleware('platform.staff:'.PlatformCapabilities::BILLING_READ)->get('/refunds', [RefundController::class, 'index']);
    Route::post('/tenants/{tenant}/members/{user}/mfa/reset', [TenantTeamController::class, 'resetMfa'])
        ->whereUuid(['tenant', 'user'])->middleware(['platform.staff:'.PlatformCapabilities::CUSTOMER_MFA_RESET, 'throttle:platform-step-up']);
    Route::middleware(['platform.staff:'.PlatformCapabilities::TENANTS_SUPPORT, 'throttle:platform-step-up'])->group(function (): void {
        Route::post('/tenants/{tenant}/stores/{store}/checks', [TenantStoreController::class, 'rerunCheck'])->whereUuid(['tenant', 'store']);
        Route::post('/tenants/{tenant}/incidents/{incident}/acknowledge', [TenantStoreController::class, 'acknowledgeIncident'])->whereUuid(['tenant', 'incident']);
        Route::post('/tenants/{tenant}/incidents/{incident}/resolve', [TenantStoreController::class, 'resolveIncident'])->whereUuid(['tenant', 'incident']);
    });
    Route::middleware('platform.staff:'.PlatformCapabilities::REFUNDS_REQUEST)->post('/payments/{payment}/refunds', [RefundController::class, 'store'])->whereUuid('payment');
    Route::middleware(['platform.staff:'.PlatformCapabilities::TENANTS_DELETE, 'throttle:platform-step-up'])->group(function (): void {
        Route::post('/tenants/{tenant}/deletion', [TenantDeletionController::class, 'store'])->whereUuid('tenant');
        Route::post('/tenants/{tenant}/deletion/cancel', [TenantDeletionController::class, 'cancel'])->whereUuid('tenant');
    });
    Route::middleware('platform.staff:'.PlatformCapabilities::REFUNDS_APPROVE)->group(function (): void {
        Route::post('/refunds/{refund}/approve', [RefundController::class, 'approve'])->whereUuid('refund')->middleware('throttle:platform-step-up');
        Route::post('/refunds/{refund}/reject', [RefundController::class, 'reject'])->whereUuid('refund');
    });
    Route::middleware('platform.staff:'.PlatformCapabilities::TENANTS_MANAGE)->group(function (): void {
        Route::post('/tenants/{tenant}/actions/{action}/preview', [TenantController::class, 'preview']);
        Route::post('/tenants/{tenant}/actions/{action}', [TenantController::class, 'act'])->middleware('throttle:platform-step-up');
        Route::post('/tenants/{tenant}/reminders/preview', [TenantController::class, 'reminderPreview']);
        Route::post('/tenants/{tenant}/reminders', [TenantController::class, 'remind']);
        Route::put('/reminder-templates/{topic}/{locale}', [TenantController::class, 'saveReminderTemplate'])->where(['topic' => 'subscription|payment|other', 'locale' => 'ru|en|de']);
        Route::post('/tenants/{tenant}/stores', [TenantStoreController::class, 'create']);
        Route::post('/tenants/{tenant}/stores/{store}/delete', [TenantStoreController::class, 'delete'])->whereUuid(['tenant', 'store'])->middleware('throttle:platform-step-up');
        Route::get('/tenants/{tenant}/invitations', [TenantTeamController::class, 'invitations']);
        Route::post('/tenants/{tenant}/invitations', [TenantTeamController::class, 'invite'])->middleware('throttle:invitations');
        Route::post('/tenants/{tenant}/invitations/{invitation}/revoke', [TenantTeamController::class, 'revoke']);
        Route::patch('/tenants/{tenant}/members/{user}', [TenantTeamController::class, 'changeRole'])->whereUuid(['tenant', 'user']);
        Route::post('/tenants/{tenant}/members/{user}/remove', [TenantTeamController::class, 'removeMember'])->whereUuid(['tenant', 'user']);
        Route::get('/tenants/{tenant}/stores/{store}', [TenantStoreController::class, 'show']);
        Route::get('/tenants/{tenant}/stores/{store}/findings', [TenantStoreController::class, 'findings']);
        Route::get('/tenants/{tenant}/stores/{store}/orders', [TenantStoreController::class, 'orders']);
        Route::get('/tenants/{tenant}/stores/{store}/checks', [TenantStoreController::class, 'checks']);
        Route::get('/tenants/{tenant}/stores/{store}/incidents', [TenantStoreController::class, 'incidents']);
        Route::get('/tenants/{tenant}/orders/{order}', [TenantStoreController::class, 'order']);
        Route::get('/tenants/{tenant}/checks/{check}', [TenantStoreController::class, 'check']);
        Route::get('/tenants/{tenant}/artifacts/{artifact}/download', [TenantStoreController::class, 'artifact']);
        Route::get('/tenants/{tenant}/incidents/{incident}', [TenantStoreController::class, 'incident']);
    });

    Route::middleware('platform.staff:'.PlatformCapabilities::CONTENT_MANAGE)->group(function (): void {
        Route::get('/pages', [PageController::class, 'index']);
        Route::get('/site-media', [SiteMediaController::class, 'index']);
        Route::post('/site-media', [SiteMediaController::class, 'store']);
        Route::post('/site-media/{media}/delete', [SiteMediaController::class, 'delete'])->whereUuid('media');
        Route::get('/page-blocks', [PageBlockController::class, 'index']);
        Route::post('/page-blocks', [PageBlockController::class, 'store']);
        Route::get('/page-blocks/{block}', [PageBlockController::class, 'show'])->whereUuid('block');
        Route::patch('/page-blocks/{block}', [PageBlockController::class, 'update'])->whereUuid('block');
        Route::post('/page-blocks/{block}/archive', [PageBlockController::class, 'archive'])->whereUuid('block');
        Route::post('/pages', [PageController::class, 'store']);
        Route::get('/pages/{page}', [PageController::class, 'show'])->whereUuid('page');
        Route::patch('/pages/{page}', [PageController::class, 'update'])->whereUuid('page');
        Route::post('/pages/{page}/preview', [PageController::class, 'preview'])->whereUuid('page')->middleware('throttle:platform-preview');
        Route::put('/pages/{page}/locales/{locale}/draft', [PageController::class, 'saveDraft'])->whereUuid('page');
        Route::delete('/pages/{page}/locales/{locale}/draft', [PageController::class, 'discardDraft'])->whereUuid('page');
        Route::post('/pages/{page}/locales/{locale}/publish', [PageController::class, 'publish'])->whereUuid('page');
        Route::post('/pages/{page}/locales/{locale}/unpublish', [PageController::class, 'unpublish'])->whereUuid('page');
        Route::post('/pages/{page}/versions/{version}/restore', [PageController::class, 'restore'])->whereUuid(['page', 'version']);
        Route::post('/pages/{page}/versions/{version}/delete', [PageController::class, 'deleteVersion'])->whereUuid(['page', 'version']);
        Route::post('/pages/{page}/archive', [PageController::class, 'archive'])->whereUuid('page');
    });
    Route::middleware('platform.staff:'.PlatformCapabilities::CONTACTS_READ)->group(function (): void {
        Route::get('/contact-requests', [ContactRequestController::class, 'index']);
        Route::get('/contact-requests/{contactRequest}', [ContactRequestController::class, 'show'])->whereUuid('contactRequest');
        Route::get('/contact-requests/{contactRequest}/attachment', [ContactRequestController::class, 'attachment'])->whereUuid('contactRequest');
    });
    Route::middleware('platform.staff:'.PlatformCapabilities::CONTACTS_MANAGE)->patch('/contact-requests/{contactRequest}', [ContactRequestController::class, 'update'])->whereUuid('contactRequest');
    Route::middleware('platform.staff:'.PlatformCapabilities::CONTACTS_MANAGE)->post('/contact-requests/{contactRequest}/notes', [ContactRequestController::class, 'note'])->whereUuid('contactRequest');
    Route::middleware('platform.staff:'.PlatformCapabilities::CONTACTS_MANAGE)->patch('/contact-requests/{contactRequest}/notes/{note}', [ContactRequestController::class, 'editNote'])->whereUuid(['contactRequest', 'note']);
    Route::middleware('platform.staff:'.PlatformCapabilities::CONTACTS_MANAGE)->put('/contact-reply-templates/{code}/{locale}', [ContactRequestController::class, 'saveTemplate'])->where(['code' => 'answer|more_details|resolved', 'locale' => 'ru|en|de']);
    Route::middleware(['platform.staff:'.PlatformCapabilities::CONTACTS_MANAGE, 'throttle:contact-replies'])->post('/contact-requests/{contactRequest}/replies', [ContactRequestController::class, 'reply'])->whereUuid('contactRequest');

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
