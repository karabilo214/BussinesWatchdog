<?php

namespace App\Console\Commands;

use App\Support\Sales\SalesHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Throwable;

class SalesHistoryReport extends Command
{
    protected $signature = 'sales:history {--tenant=} {--store=} {--from=} {--to=} {--mode=live}';

    protected $description = 'Read tenant-scoped hourly sales observations without inferring a healthy baseline or sending alerts.';

    public function handle(TenantContext $tenants, SalesHistory $history): int
    {
        $tenant = (string) $this->option('tenant');
        $store = (string) $this->option('store');

        if (! Str::isUuid($tenant) || ! Str::isUuid($store) || ! in_array($this->option('mode'), ['live', 'test'], true)) {
            $this->components->error('sales_history_scope_invalid');

            return self::FAILURE;
        }

        try {
            $to = $this->date($this->option('to')) ?? CarbonImmutable::now()->utc()->startOfHour();
            $from = $this->date($this->option('from')) ?? $to->subDays(28);
            $report = $tenants->scope($tenant, 'sales:history', fn () => $history->report($store, $from, $to, (string) $this->option('mode')));
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

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new \InvalidArgumentException;
        }

        try {
            $date = CarbonImmutable::parse($value);

            if ($date->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $value)) {
                throw new \InvalidArgumentException;
            }

            return $date;
        } catch (Throwable $error) {
            throw new \InvalidArgumentException('sales_history_range_invalid', previous: $error);
        }
    }
}
