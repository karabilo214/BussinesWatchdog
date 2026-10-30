<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\Store;
use App\Support\Sales\SalesCoverage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class FinalizeSalesCoverage extends Command
{
    protected $signature = 'sales:finalize-coverage';

    protected $description = 'Finalize polled order coverage after inbox processing and exclude currently paused days from the baseline.';

    public function handle(TenantContext $tenants, SalesCoverage $coverage): int
    {
        $checked = 0;
        Integration::query()->whereNotNull('sales_coverage')->select(['id', 'tenant_id'])->orderBy('id')->chunk(100, function ($rows) use ($tenants, $coverage, &$checked): void {
            foreach ($rows as $row) {
                $tenants->scope($row->tenant_id, 'sales:finalize-coverage', fn () => $coverage->finalize($row->id));
                $checked++;
            }
        });
        Store::query()->whereHas('integrations', fn ($query) => $query->whereNotNull('sales_coverage'))
            ->select(['id', 'tenant_id'])->orderBy('id')->chunk(100, function ($rows) use ($tenants, $coverage): void {
                foreach ($rows as $row) {
                    $tenants->scope($row->tenant_id, 'sales:finalize-coverage', function () use ($row, $coverage): void {
                        $store = Store::query()->where('tenant_id', $row->tenant_id)->findOrFail($row->id);
                        $date = CarbonImmutable::now($store->timezone)->toDateString();
                        if (! $coverage->isPaused($store)) {
                            return;
                        }

                        foreach (['live', 'test'] as $mode) {
                            $coverage->excludeDay($row->id, $date, 'pause', $mode);
                        }
                    });
                }
            });
        Store::query()->whereNotNull('sales_baseline_exclusions')->select(['id', 'tenant_id'])->orderBy('id')->chunk(100, function ($rows) use ($tenants, $coverage): void {
            foreach ($rows as $row) {
                $tenants->scope($row->tenant_id, 'sales:finalize-coverage', fn () => $coverage->pruneExclusions($row->id));
            }
        });
        $this->components->info('sales_coverage_checked='.$checked);

        return self::SUCCESS;
    }
}
