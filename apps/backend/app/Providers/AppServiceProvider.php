<?php

namespace App\Providers;

use App\Support\Notifications\Channels\EmailNotificationSender;
use App\Support\Notifications\Channels\NotificationSenderRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(NotificationSenderRegistry::class, fn ($app): NotificationSenderRegistry => new NotificationSenderRegistry([
            $app->make(EmailNotificationSender::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
