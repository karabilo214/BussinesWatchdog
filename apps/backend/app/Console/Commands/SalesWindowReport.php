<?php

namespace App\Console\Commands;

use App\Support\Sales\SalesReportTime;
use App\Support\Sales\SalesRollingReport;
use App\Support\Sales\SalesRollingWindows;
use App\Support\Sales\SalesWindowSelection;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SalesWindowReport extends Command
{
    protected $signature = 'sales:window {--tenant=} {--store=} {--mode=live} {--at=} {--lag-seconds=} {--alpha=} {--min-expected-orders='.SalesWindowSelection::DEFAULT_MIN_EXPECTED_ORDERS.'}';

    protected $description = 'Read completed rolling order windows with explicit lag and coverage without sending alerts.';

    public function handle(TenantContext $tenants, SalesRollingReport $report, SalesRollingWindows $geometry, SalesWindowSelection $windows, SalesReportTime $time): int
    {
        $tenant = (string) $this->option('tenant');
        $store = (string) $this->option('store');
        $mode = (string) $this->option('mode');
        if (! Str::isUuid($tenant) || ! Str::isUuid($store) || ! in_array($mode, ['live', 'test'], true)) {
            $this->components->error('sales_window_scope_invalid');

            return self::FAILURE;
        }
        try {
            $geometry->lag((string) $this->option('lag-seconds'));
            $minimum = $windows->minimum((string) $this->option('min-expected-orders'));
            $at = $time->parse($this->option('at')) ?? CarbonImmutable::now()->utc()->startOfSecond();
            $result = $tenants->scope($tenant, 'sales:window', fn () => $report->report($store, $mode, $at, (string) $this->option('lag-seconds'), $minimum, $this->option('alpha')));
        } catch (ModelNotFoundException) {
            $this->components->error('sales_window_store_not_found');

            return self::FAILURE;
        } catch (InvalidArgumentException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
