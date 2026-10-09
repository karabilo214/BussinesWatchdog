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
