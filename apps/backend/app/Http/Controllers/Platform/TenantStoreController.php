<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Api\V1\Checks\CheckRunController;
use App\Http\Controllers\Api\V1\Incidents\IncidentController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Reconciliation\ReconciliationController;
use App\Http\Controllers\Api\V1\Stores\StoreController;
use App\Http\Controllers\Controller;
use App\Http\Dto\Orders\OrderDto;
use App\Models\Artifact;
use App\Models\CheckRun;
use App\Models\Incident;
use App\Models\Order;
use App\Models\PlatformStaff;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Api\UuidCursor;
use App\Support\Billing\HistoryWindow;
use App\Support\Platform\PlatformAudit;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantStoreController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlatformAudit $audit,
    ) {}

    public function create(Request $request, string $tenant): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->as($tenant, function (Tenant $model) use ($request): JsonResponse {
            /** @var JsonResponse $response */
            $response = app()->call([app(StoreController::class), 'store']);

            if ($response->getStatusCode() === 201) {
                $store = $response->getData(true);
                $this->audit->record($this->staff($request)->id, 'tenant.store_created', 'store', (string) $store['id'], PlatformAudit::RESULT_SUCCEEDED, ['name' => $store['name'], 'base_url' => $store['base_url'], 'platform' => $store['platform'] ?? null], trim((string) $request->input('reason')), $model->id);
            }

            return $response;
        });
    }

    public function show(Request $request, string $tenant, string $store): JsonResponse
    {
        return $this->as($tenant, function (Tenant $model) use ($request, $store): JsonResponse {
            $found = $this->find(Store::class, $model, $store);
            $this->audit->record($this->staff($request)->id, 'tenant.store_viewed', 'store', $found->id, PlatformAudit::RESULT_SUCCEEDED, [], null, $model->id);

            return app()->call([app(StoreController::class), 'show'], ['store' => $found]);
        });
    }

    public function findings(string $tenant, string $store): JsonResponse
    {
        return $this->as($tenant, fn (Tenant $model): JsonResponse => app()->call([app(ReconciliationController::class), 'findings'], ['store' => $this->find(Store::class, $model, $store)]));
    }

    public function orders(Request $request, string $tenant, string $store, OrderDto $dto): JsonResponse
    {
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,100'], 'cursor' => ['sometimes', 'string', 'max:128']]);

        return $this->as($tenant, function (Tenant $model) use ($store, $validated, $dto): JsonResponse {
            $found = $this->find(Store::class, $model, $store);
            $limit = (int) ($validated['limit'] ?? 50);
            $query = Order::query()->where('tenant_id', $model->id)->where('store_id', $found->id);
            $cutoff = app(HistoryWindow::class)->cutoff($model->id);

            if ($cutoff !== null) {
                $query->where(fn ($visible) => $visible->whereNull('source_created_at')->orWhere('source_created_at', '>=', $cutoff));
            }

            if (isset($validated['cursor'])) {
                $cursorId = UuidCursor::decode($validated['cursor']);
                abort_if($cursorId === null, 422, 'invalid_cursor');
                $query->where('id', '<', $cursorId);
            }

            $orders = $query->orderByDesc('id')->limit($limit + 1)->get();
            $page = $orders->take($limit);

            return response()->json([
                'data' => $page->map(fn (Order $order): array => $dto->toArray($order))->values()->all(),
                'next_cursor' => $orders->count() > $limit && $page->isNotEmpty() ? UuidCursor::encode($page->last()->id) : null,
            ]);
        });
    }

    public function order(string $tenant, string $order): JsonResponse
    {
        return $this->as($tenant, fn (Tenant $model): JsonResponse => app()->call([app(OrderController::class), 'show'], ['order' => $this->find(Order::class, $model, $order)]));
    }

    public function checks(string $tenant, string $store): JsonResponse
    {
        return $this->as($tenant, fn (Tenant $model): JsonResponse => app()->call([app(CheckRunController::class), 'index'], ['store' => $this->find(Store::class, $model, $store)]));
    }

    public function check(string $tenant, string $check): JsonResponse
    {
        return $this->as($tenant, fn (Tenant $model): JsonResponse => app()->call([app(CheckRunController::class), 'show'], ['checkRun' => $this->find(CheckRun::class, $model, $check)]));
    }

    public function artifact(string $tenant, string $artifact): JsonResponse
    {
        return $this->as($tenant, fn (Tenant $model): JsonResponse => app()->call([app(CheckRunController::class), 'download'], ['artifact' => $this->find(Artifact::class, $model, $artifact)]));
    }

    public function incidents(Request $request, string $tenant, string $store): JsonResponse
    {
        return $this->as($tenant, function (Tenant $model) use ($request, $store): JsonResponse {
            $request->query->set('store_id', $this->find(Store::class, $model, $store)->id);

            return app()->call([app(IncidentController::class), 'index']);
        });
    }

    public function incident(string $tenant, string $incident): JsonResponse
    {
        return $this->as($tenant, fn (Tenant $model): JsonResponse => app()->call([app(IncidentController::class), 'show'], ['incident' => $this->find(Incident::class, $model, $incident)]));
    }

    /**
     * @param  Closure(Tenant): JsonResponse  $action
     */
    private function as(string $tenant, Closure $action): JsonResponse
    {
        $model = Str::isUuid($tenant) ? Tenant::query()->whereKey($tenant)->whereNotIn('status', ['deleting', 'deleted'])->first() : null;

        if ($model === null) {
            return response()->json(['code' => 'not_found', 'message' => 'Not found.'], 404);
        }

        return $this->context->scope($model->id, 'platform_staff', fn (): JsonResponse => $action($model));
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function find(string $class, Tenant $tenant, string $id): Model
    {
        abort_unless(Str::isUuid($id), 404);

        return $class::query()->where('tenant_id', $tenant->id)->whereKey($id)->firstOr(fn () => abort(404));
    }

    private function staff(Request $request): PlatformStaff
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        return $staff;
    }
}
