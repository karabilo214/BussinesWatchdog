<?php

use App\Console\Commands\CheckStoreVerifications;
use App\Console\Commands\DeliverNotifications;
use App\Console\Commands\DispatchDomainOutbox;
use App\Console\Commands\ManageTelegramWebhook;
use App\Console\Commands\PlanNotificationReminders;
use App\Console\Commands\ProcessDirtyReconciliation;
use App\Console\Commands\ReencryptSecrets;
use App\Console\Commands\RunNightlyReconciliationSweep;
use App\Exceptions\Tenancy\MissingTenantContext;
use App\Http\Middleware\Api\AssignRequestId;
use App\Http\Middleware\Auth\RequireStatefulSession;
use App\Http\Middleware\Browser\AuthenticateBrowserWorker;
use App\Http\Middleware\Idempotency\EnsureIdempotencyKey;
use App\Http\Middleware\Integrations\AuthenticateIntegrationHmac;
use App\Http\Middleware\Platform\RecordPlatformChanges;
use App\Http\Middleware\Platform\RequirePlatformStaff;
use App\Http\Middleware\Platform\UsePlatformSession;
use App\Http\Middleware\Platform\ValidatePlatformCsrfToken;
use App\Http\Middleware\Tenancy\RequireTenantRole;
use App\Http\Middleware\Tenancy\SetTenantContextFromSession;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware([])->group(__DIR__.'/../routes/internal.php');
            Route::group([], __DIR__.'/../routes/platform.php');
            Route::group([], __DIR__.'/../routes/site.php');
        },
    )
    ->withCommands([
        DispatchDomainOutbox::class,
        DeliverNotifications::class,
        ProcessDirtyReconciliation::class,
        RunNightlyReconciliationSweep::class,
        ReencryptSecrets::class,
        CheckStoreVerifications::class,
        ManageTelegramWebhook::class,
        PlanNotificationReminders::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: ['api/v1/shopware/app/connect']);

        if (($proxies = (string) env('TRUSTED_PROXIES', '')) !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') || $request->is('platform-api/*') || $request->is('internal/*') ? null : '/app/login');
        $middleware->api(prepend: [AssignRequestId::class]);
        $middleware->group('platform', [
            AssignRequestId::class,
            UsePlatformSession::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidatePlatformCsrfToken::class,
            SubstituteBindings::class,
            RecordPlatformChanges::class,
        ]);
        $middleware->alias([
            'platform.staff' => RequirePlatformStaff::class,
            'browser.worker' => AuthenticateBrowserWorker::class,
            'idempotency' => EnsureIdempotencyKey::class,
            'stateful.session' => RequireStatefulSession::class,
            'integration.hmac' => AuthenticateIntegrationHmac::class,
            'tenant.role' => RequireTenantRole::class,
            'tenant.session' => SetTenantContextFromSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('internal/*') || $request->is('platform-api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (PostTooLargeException $exception, Request $request) => $request->is('platform-api/v1/site-media')
            ? response()->json(['code' => 'media_too_large', 'message' => 'The file is larger than the server accepts.'], 413)
            : null);

        $exceptions->render(fn (MissingTenantContext $exception, Request $request) => $request->is('api/*')
            ? response()->json(['code' => 'tenant_forbidden', 'message' => 'No access to an active team.'], 403)
            : null);
    })->create();
