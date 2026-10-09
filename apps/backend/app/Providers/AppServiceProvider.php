<?php

namespace App\Providers;

use App\Support\Network\DnsClient;
use App\Support\Network\SystemDnsClient;
use App\Support\Notifications\Channels\EmailNotificationSender;
use App\Support\Notifications\Channels\NotificationSenderRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(DnsClient::class, SystemDnsClient::class);
        $this->app->singleton(NotificationSenderRegistry::class, fn ($app): NotificationSenderRegistry => new NotificationSenderRegistry([
            $app->make(EmailNotificationSender::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', fn (Request $request): Limit => Limit::perMinute((int) config('watchdog.rate_limits.login_per_minute'))
            ->by(mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip()));

        RateLimiter::for('pairing-exchange', fn (Request $request): Limit => Limit::perHour((int) config('watchdog.rate_limits.pairing_per_hour'))
            ->by((string) $request->ip()));

        RateLimiter::for('store-verification-check', fn (Request $request): Limit => Limit::perMinute(6)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('browser-worker', fn (Request $request): Limit => Limit::perMinute(600)
            ->by((string) ($request->attributes->get('browser_worker')?->id ?? $request->ip())));

        RateLimiter::for('artifact-url', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('auth-signup', fn (Request $request): Limit => Limit::perHour((int) config('watchdog.rate_limits.signup_per_hour'))
            ->by((string) $request->ip()));
    }
}
