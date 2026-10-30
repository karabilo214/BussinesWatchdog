<?php

namespace App\Console\Commands;

use App\Support\Sales\SalesHistory;
use App\Support\Sales\SalesReportTime;
use App\Support\Sales\SalesWindowSelection;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class SalesHistoryReport extends Command
{
    protected $signature = 'sales:history {--tenant=} {--store=} {--from=} {--to=} {--mode=live} {--min-expected-orders='.SalesWindowSelection::DEFAULT_MIN_EXPECTED_ORDERS.'}';

    protected $description = 'Read tenant-scoped hourly sales observations without inferring a healthy baseline or sending alerts.';

    public function handle(TenantContext $tenants, SalesHistory $history, SalesWindowSelection $windows, SalesReportTime $time): int
    {
        $tenant = (string) $this->option('tenant');
        $store = (string) $this->option('store');

        if (! Str::isUuid($tenant) || ! Str::isUuid($store) || ! in_array($this->option('mode'), ['live', 'test'], true)) {
            $this->components->error('sales_history_scope_invalid');

            return self::FAILURE;
        }

        try {
            $minimum = $windows->minimum((string) $this->option('min-expected-orders'));
        } catch (\InvalidArgumentException) {
            $this->components->error('sales_history_min_expected_orders_invalid');

            return self::FAILURE;
        }

        try {
            $to = $time->parse($this->option('to')) ?? CarbonImmutable::now()->utc()->startOfHour();
            $from = $time->parse($this->option('from')) ?? $to->subDays(28);
            $report = $tenants->scope($tenant, 'sales:history', fn () => $history->report($store, $from, $to, (string) $this->option('mode'), $minimum));
        } catch (ModelNotFoundException) {
            $this->components->error('sales_history_store_not_found');

            return self::FAILURE;
        } catch (\InvalidArgumentException) {
            $this->components->error('sales_history_range_invalid');

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
