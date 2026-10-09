<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Dto\Notifications\NotificationDeliveryDto;
use App\Http\Requests\Notifications\ListNotificationDeliveriesRequest;
use App\Models\NotificationDelivery;
use App\Support\Api\UuidCursor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class NotificationDeliveryController extends Controller
{
    public function __construct(
        private readonly NotificationDeliveryDto $deliveryDto,
    ) {}

    public function index(ListNotificationDeliveriesRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('list notification deliveries');
        $validated = $request->validated();
        $limit = $validated['limit'] ?? 50;

        $query = NotificationDelivery::query()->where('tenant_id', $tenantId);

        foreach (['incident_id', 'channel_id', 'status'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (isset($validated['cursor'])) {
            $cursorId = UuidCursor::decode($validated['cursor']);
            abort_if($cursorId === null, 400, 'Invalid cursor.');

            $query->where('id', '>', $cursorId);
        }

        $items = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $items = $items->take($limit);

        return response()->json([
            'data' => $this->deliveryDto->collection($items),
            'next_cursor' => ($hasMore && $items->isNotEmpty()) ? UuidCursor::encode($items->last()->id) : null,
        ]);
    }
}
