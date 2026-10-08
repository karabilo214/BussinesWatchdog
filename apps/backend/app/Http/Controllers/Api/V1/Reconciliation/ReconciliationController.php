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
use App\Support\Reconciliation\OrderReconciliationService;
use App\Support\Reconciliation\UnmatchedPaymentScanner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

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
                $runs[] = $this->runSummary($orderService->evaluate($orders[$orderId], 'api'));
            }
        } else {
            $runs[] = $this->runSummary($scanner->scan($store, 'api'));
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
            $cursorId = $this->decodeCursor($validated['cursor']);
            abort_if($cursorId === null, 400, 'Invalid cursor.');

            $query->where('id', '>', $cursorId);
        }

        $items = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $items = $items->take($limit);

        $nextCursor = null;

        if ($hasMore && $items->isNotEmpty()) {
            /** @var ReconciliationFinding $last */
            $last = $items->last();
            $nextCursor = $this->encodeCursor($last->id);
        }

        return response()->json([
            'data' => $this->findingDto->collection($items),
            'next_cursor' => $nextCursor,
        ]);
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

    private function encodeCursor(string $id): string
    {
        return base64_encode($id);
    }

    private function decodeCursor(string $cursor): ?string
    {
        $decoded = base64_decode($cursor, true);

        if ($decoded === false || ! Str::isUuid($decoded)) {
            return null;
        }

        return $decoded;
    }
}
