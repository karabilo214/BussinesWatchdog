<?php

namespace App\Support\Sales;

use App\Models\Order;
use App\Models\Store;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalesOrderObservations
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function latestIds(Store $store): \Illuminate\Database\Query\Builder
    {
        $tenantId = $this->tenants->requireTenantId('sales.orders');
        if ($store->tenant_id !== $tenantId) {
            throw new InvalidArgumentException('sales_orders_scope_invalid');
        }

        $ranked = DB::table('orders')->where('tenant_id', $tenantId)->where('store_id', $store->id)
            ->selectRaw('id, row_number() over (partition by external_id order by source_updated_at desc, updated_at desc, id desc) as position');

        return DB::query()->fromSub($ranked, 'ranked_orders')->where('position', 1)->select('id');
    }

    public function query(Store $store, string $mode): Builder
    {
        if (! in_array($mode, ['live', 'test'], true)) {
            throw new InvalidArgumentException('sales_orders_mode_invalid');
        }

        return Order::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)
            ->whereIn('id', $this->latestIds($store))->where('mode', $mode)
            ->where('is_synthetic', false)->whereNull('deleted_at');
    }
}
