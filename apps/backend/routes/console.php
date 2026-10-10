<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('outbox:dispatch')
    ->everyTenSeconds()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('reconciliation:process-dirty')
    ->everyThirtySeconds()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('notifications:deliver')
    ->everyThirtySeconds()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('reconciliation:nightly-sweep')
    ->dailyAt('02:30')
    ->timezone('UTC')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('stores:check-verifications')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('integrations:check-freshness')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('browser:schedule')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('artifacts:purge')
    ->dailyAt('03:15')
    ->timezone('UTC')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('stripe:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('stripe:sync --audit')
    ->dailyAt('03:45')
    ->timezone('UTC')
    ->withoutOverlapping(120)
    ->onOneServer();
