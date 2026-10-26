<?php

namespace App\Providers;

use App\Support\Mail\DkimMailSigner;
use App\Support\Network\DnsClient;
use App\Support\Network\SystemDnsClient;
use App\Support\Notifications\Channels\EmailNotificationSender;
use App\Support\Notifications\Channels\NotificationSenderRegistry;
use App\Support\Notifications\Channels\TelegramNotificationSender;
use App\Support\Platform\QueueWorkerHeartbeats;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Passkeys::ignoreRoutes();
        $this->app->scoped(TenantContext::class);
        $this->app->bind(DnsClient::class, SystemDnsClient::class);
        $this->app->singleton(NotificationSenderRegistry::class, fn ($app): NotificationSenderRegistry => new NotificationSenderRegistry([
            $app->make(EmailNotificationSender::class),
            $app->make(TelegramNotificationSender::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event): void {
            app(DkimMailSigner::class)->sign($event->message);
        });

        Event::listen(Looping::class, fn (Looping $event) => app(QueueWorkerHeartbeats::class)->waiting($event->connectionName, $event->queue));
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => app(QueueWorkerHeartbeats::class)->started($event->connectionName, $event->job->getQueue()));
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => app(QueueWorkerHeartbeats::class)->finished($event->connectionName, $event->job->getQueue()));
        Event::listen(JobFailed::class, fn (JobFailed $event) => app(QueueWorkerHeartbeats::class)->finished($event->connectionName, $event->job->getQueue()));

        RateLimiter::for('platform-login', fn (Request $request): array => [
            Limit::perMinute((int) config('watchdog.platform.login_per_minute'))->by('platform|'.$request->ip()),
            Limit::perHour(30)->by('platform-email|'.mb_strtolower(trim((string) $request->input('email')))),
        ]);

        RateLimiter::for('discount-check', fn (Request $request): Limit => Limit::perMinute(10)->by('discount-check|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('plan-change-preview', fn (Request $request): Limit => Limit::perMinute(20)->by('plan-change-preview|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('platform-step-up', fn (Request $request): Limit => Limit::perMinute(10)->by('platform-step-up|'.$request->ip()));

        RateLimiter::for('platform-preview', fn (Request $request): Limit => Limit::perMinute(120)->by('platform-preview|'.$request->ip()));

        RateLimiter::for('platform-mfa', fn (Request $request): Limit => Limit::perMinute(10)->by('platform-mfa|'.$request->ip()));

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

        RateLimiter::for('auth-password-reset', fn (Request $request): array => [
            Limit::perHour(3)->by('reset-email|'.mb_strtolower(trim((string) $request->input('email')))),
            Limit::perHour(20)->by('reset-ip|'.$request->ip()),
        ]);

        RateLimiter::for('auth-password-reset-complete', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));

        RateLimiter::for('auth-email-verification', fn (Request $request): Limit => Limit::perMinute(1)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('auth-email-change', fn (Request $request): Limit => Limit::perHour(5)
            ->by('email-change|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('auth-mfa-challenge', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));

        RateLimiter::for('auth-step-up', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('platform-invitation', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->ip()));

        RateLimiter::for('contact-replies', fn (Request $request): Limit => Limit::perHour(60)
            ->by((string) ($request->attributes->get('platform_staff')?->id ?? $request->ip())));

        RateLimiter::for('invitation-lookup', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));

        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perHour(30)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('provider-sync', fn (Request $request): Limit => Limit::perMinute(2)
            ->by((string) ($request->route('integration')?->id ?? $request->ip())));

        RateLimiter::for('provider-webhook', fn (Request $request): Limit => Limit::perMinute(600)
            ->by((string) $request->route('integration')));

        RateLimiter::for('auth-signup', fn (Request $request): Limit => Limit::perHour((int) config('watchdog.rate_limits.signup_per_hour'))
            ->by((string) $request->ip()));
    }
}
