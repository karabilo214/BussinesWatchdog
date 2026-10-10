<?php

use App\Exceptions\Tenancy\MissingTenantContext;
use App\Console\Commands\CheckStoreVerifications;
use App\Console\Commands\DeliverNotifications;
use App\Console\Commands\DispatchDomainOutbox;
use App\Console\Commands\ProcessDirtyReconciliation;
use App\Console\Commands\ReencryptSecrets;
use App\Console\Commands\RunNightlyReconciliationSweep;
use App\Http\Middleware\Api\AssignRequestId;
use App\Http\Middleware\Auth\RequireStatefulSession;
use App\Http\Middleware\Browser\AuthenticateBrowserWorker;
use App\Http\Middleware\Idempotency\EnsureIdempotencyKey;
use App\Http\Middleware\Integrations\AuthenticateIntegrationHmac;
use App\Http\Middleware\Tenancy\RequireTenantRole;
use App\Http\Middleware\Tenancy\SetTenantContextFromSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware([])->group(__DIR__.'/../routes/internal.php');
        },
    )
    ->withCommands([
        DispatchDomainOutbox::class,
        DeliverNotifications::class,
        ProcessDirtyReconciliation::class,
        RunNightlyReconciliationSweep::class,
        ReencryptSecrets::class,
        CheckStoreVerifications::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(prepend: [AssignRequestId::class]);
        $middleware->alias([
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
            fn (Request $request) => $request->is('api/*') || $request->is('internal/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (MissingTenantContext $exception, Request $request) => $request->is('api/*')
            ? response()->json(['code' => 'tenant_forbidden', 'message' => 'No access to an active team.'], 403)
            : null);
    })->create();
