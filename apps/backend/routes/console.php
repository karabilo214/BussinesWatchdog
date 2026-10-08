<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\DispatchDomainOutbox;

Artisan::starting(function ($artisan): void {
    $artisan->resolveCommands([
        DispatchDomainOutbox::class,
    ]);
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
