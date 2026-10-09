<?php

namespace BusinessWatchdog\WooCommerce\Compat;

interface Scheduler
{
    public function ensureRecurring(string $hook, int $intervalSeconds): void;

    public function enqueueAsync(string $hook, array $args = []): void;

    public function clear(string $hook): void;

    public function name(): string;
}
