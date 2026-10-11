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

Schedule::command('exports:purge')
    ->dailyAt('03:25')
    ->timezone('UTC')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('stripe:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('woocommerce-api:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer();

Schedule::command('woocommerce-api:sync --recent')
    ->hourlyAt(20)
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('woocommerce-api:sync --audit')
    ->dailyAt('03:20')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('shopware:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->onOneServer();

Schedule::command('shopware:sync --audit')
    ->dailyAt('03:40')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('paypal:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('paypal:sync --pointers')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('paypal:sync --audit')
    ->hourlyAt(50)
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('stripe:sync --audit')
    ->dailyAt('03:45')
    ->timezone('UTC')
    ->withoutOverlapping(120)
    ->onOneServer();
