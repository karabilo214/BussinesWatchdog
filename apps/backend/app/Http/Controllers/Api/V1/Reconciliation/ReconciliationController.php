<?php

namespace App\Http\Controllers\Api\V1\Reconciliation;

use App\Http\Controllers\Controller;
use App\Http\Dto\Reconciliation\ReconciliationFindingDto;
use App\Http\Requests\Reconciliation\ListFindingsRequest;
use App\Http\Requests\Reconciliation\TriggerReconciliationRequest;
use App\Models\Order;
use App\Models\ReconciliationFinding;
use App\Models\ReconciliationRun;
use App\Models\Store;
use App\Support\Api\UuidCursor;
use App\Support\Incidents\MoneyIncidentCorrelator;
use App\Support\Reconciliation\GraceRecheckScheduler;
use App\Support\Reconciliation\OrderReconciliationService;
use App\Support\Reconciliation\UnmatchedPaymentScanner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReconciliationController extends Controller
{
    public function __construct(
        private readonly ReconciliationFindingDto $findingDto,
    ) {}

    public function store(
        TriggerReconciliationRequest $request,
        Store $store,
        TenantContext $tenantContext,
        OrderReconciliationService $orderService,
        UnmatchedPaymentScanner $scanner,
        MoneyIncidentCorrelator $correlator,
        GraceRecheckScheduler $graceScheduler,
    ): JsonResponse {
        $tenantId = $tenantContext->requireTenantId('trigger reconciliation');

        abort_unless($store->tenant_id === $tenantId, 404);

        $validated = $request->validated();

        if (($validated['dry_run'] ?? false) === true) {
            return response()->json([
                'code' => 'dry_run_not_supported',
                'message' => 'dry_run is not yet supported by this endpoint.',
            ], 422);
        }

        $orderIds = $validated['order_ids'] ?? [];
        $runs = [];

        if ($orderIds !== []) {
            $orders = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $store->id)
                ->whereIn('id', $orderIds)
                ->get()
                ->keyBy('id');

            if ($orders->count() !== count($orderIds)) {
                return response()->json([
                    'code' => 'order_not_found',
                    'message' => 'One or more order_ids were not found for this store.',
                ], 422);
            }

            foreach ($orderIds as $orderId) {
                $run = $orderService->evaluate($orders[$orderId], 'api');
                $correlator->correlate($run);
                $graceScheduler->scheduleFor($run);
                $runs[] = $this->runSummary($run);
            }
        } else {
            $run = $scanner->scan($store, 'api');
            $correlator->correlate($run);
            $graceScheduler->scheduleFor($run);
            $runs[] = $this->runSummary($run);
        }

        return response()->json(['data' => $runs], 202);
    }

    public function findings(ListFindingsRequest $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list findings');

        abort_unless($store->tenant_id === $tenantId, 404);

        $validated = $request->validated();
        $limit = $validated['limit'] ?? 50;

        $query = ReconciliationFinding::query()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $store->id);

        if ($request->boolean('current')) {
            $query->whereIn('id', $this->currentFindingIds($tenantId, $store->id));
        }

        if (isset($validated['from'])) {
            $query->where('evaluated_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->where('evaluated_at', '<', $validated['to']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['rule_code'])) {
            $query->where('rule_code', $validated['rule_code']);
        }

        if (isset($validated['currency'])) {
            $query->where('currency', $validated['currency']);
        }

        if (isset($validated['cursor'])) {
            $cursorId = UuidCursor::decode($validated['cursor']);
            abort_if($cursorId === null, 400, 'Invalid cursor.');

            $query->where('id', '<', $cursorId);
        }

        $items = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $items = $items->take($limit);

        $nextCursor = null;

        if ($hasMore && $items->isNotEmpty()) {
            /** @var ReconciliationFinding $last */
            $last = $items->last();
            $nextCursor = UuidCursor::encode($last->id);
        }

        $orderNumbers = Order::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $items->pluck('order_id')->filter()->unique()->values())
            ->pluck('display_number', 'id');

        return response()->json([
            'data' => $items
                ->map(fn (ReconciliationFinding $finding): array => [
                    ...$this->findingDto->toArray($finding),
                    'order_display_number' => $finding->order_id === null ? null : ($orderNumbers[$finding->order_id] ?? null),
                ])
                ->values()
                ->all(),
            'next_cursor' => $nextCursor,
        ]);
    }

    /**
     * Findings of the latest run per order, plus those of the latest store-wide unmatched-payment scan.
     */
    private function currentFindingIds(string $tenantId, string $storeId): \Illuminate\Database\Query\Builder
    {
        $ranked = DB::table('reconciliation_findings')
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->selectRaw("id, DENSE_RANK() OVER (PARTITION BY COALESCE(CAST(order_id AS TEXT), '') ORDER BY evaluated_at DESC, run_id DESC) AS position");

        return DB::query()->fromSub($ranked, 'ranked')->where('position', 1)->select('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function runSummary(ReconciliationRun $run): array
    {
        $scope = $run->scope ?? [];

        return [
            'run_id' => $run->id,
            'store_id' => $run->store_id,
            'order_id' => $scope['order_id'] ?? null,
            'status' => $run->status,
            'algorithm_version' => $run->algorithm_version,
            'config_version' => $run->config_version,
            'findings_count' => $run->counters['findings'] ?? 0,
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
        ];
    }
}
