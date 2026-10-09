<?php

use App\Console\Commands\CheckStoreVerifications;
use App\Console\Commands\DeliverNotifications;
use App\Console\Commands\DispatchDomainOutbox;
use App\Console\Commands\ProcessDirtyReconciliation;
use App\Console\Commands\ReencryptSecrets;
use App\Console\Commands\RunNightlyReconciliationSweep;
use App\Http\Middleware\Auth\RequireStatefulSession;
use App\Http\Middleware\Idempotency\EnsureIdempotencyKey;
use App\Http\Middleware\Integrations\AuthenticateIntegrationHmac;
use App\Http\Middleware\Tenancy\RequireTenantRole;
use App\Http\Middleware\Tenancy\SetTenantContextFromSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        $middleware->alias([
            'idempotency' => EnsureIdempotencyKey::class,
            'stateful.session' => RequireStatefulSession::class,
            'integration.hmac' => AuthenticateIntegrationHmac::class,
            'tenant.role' => RequireTenantRole::class,
            'tenant.session' => SetTenantContextFromSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
