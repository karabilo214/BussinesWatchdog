<?php

namespace App\Http\Controllers\Platform;

use App\Exceptions\Incidents\IncidentActionRejected;
use App\Http\Controllers\Api\V1\Checks\CheckRunController;
use App\Http\Controllers\Api\V1\Incidents\IncidentController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Reconciliation\ReconciliationController;
use App\Http\Controllers\Api\V1\Stores\StoreController;
use App\Http\Controllers\Controller;
use App\Http\Dto\Checks\CheckDto;
use App\Http\Dto\Incidents\IncidentDto;
use App\Http\Dto\Orders\OrderDto;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\Incident;
use App\Models\Order;
use App\Models\PlatformStaff;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Api\UuidCursor;
use App\Support\Billing\HistoryWindow;
use App\Support\Browser\CheckScheduler;
use App\Support\Incidents\IncidentLifecycleService;
use App\Support\Platform\PlatformAudit;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
     * Support reruns a browser check of a customer's store (ADR 0048): reason required, audited for the platform and
     * the organisation, never counted against the customer's manual runs.
     */
    public function rerunCheck(Request $request, string $tenant, string $store, CheckScheduler $scheduler, CheckDto $dto): JsonResponse
    {
        $validated = $request->validate(['scenario_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->as($tenant, function (Tenant $model) use ($request, $store, $validated, $scheduler, $dto): JsonResponse {
            $found = $this->find(Store::class, $model, $store);
            $scenario = CheckScenario::query()->where('tenant_id', $model->id)->where('store_id', $found->id)->whereKey($validated['scenario_id'])->firstOr(fn () => abort(404));
            $run = $scheduler->runForSupport($scenario);

            if (is_string($run)) {
                return response()->json(['code' => $run, 'message' => 'The check cannot be started now.'], match ($run) {
                    CheckScheduler::ERROR_RATE_LIMITED => 429,
                    CheckScheduler::ERROR_RUN_ACTIVE => 409,
                    CheckScheduler::ERROR_NOT_IN_PLAN, CheckScheduler::ERROR_CAPACITY => 403,
                    default => 422,
                });
            }

            $this->recordSupport($request, $model, $found->id, 'tenant.check_rerun', 'check_run', $run->id, ['scenario_id' => $scenario->id], 'check_run.support_started', trim($validated['reason']));

            return response()->json(array_merge(['run_id' => $run->id], $dto->run($run)), 202);
        });
    }

    public function acknowledgeIncident(Request $request, string $tenant, string $incident, IncidentLifecycleService $lifecycle, IncidentDto $dto): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->incidentAction($request, $tenant, $incident, $validated['reason'], 'tenant.incident_acknowledged', 'incident.acknowledged_by_platform', fn (Incident $found, array $context): Incident => $lifecycle->acknowledge($found, null, null, $context), $dto);
    }

    public function resolveIncident(Request $request, string $tenant, string $incident, IncidentLifecycleService $lifecycle, IncidentDto $dto): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->incidentAction($request, $tenant, $incident, $validated['reason'], 'tenant.incident_resolved', 'incident.resolved_by_platform', fn (Incident $found, array $context): Incident => $lifecycle->resolve($found, trim($validated['reason']), null, null, $context), $dto);
    }

    /**
     * @param  Closure(Incident, array<string, mixed>): Incident  $change
     */
    private function incidentAction(Request $request, string $tenant, string $incident, string $reason, string $platformAction, string $tenantAction, Closure $change, IncidentDto $dto): JsonResponse
    {
        return $this->as($tenant, function (Tenant $model) use ($request, $incident, $reason, $platformAction, $tenantAction, $change, $dto): JsonResponse {
            $found = $this->find(Incident::class, $model, $incident);
            $reason = trim($reason);

            try {
                $changed = $change($found, ['by' => 'platform', 'reason' => $reason]);
            } catch (IncidentActionRejected $exception) {
                return response()->json(['code' => $exception->reasonCode, 'message' => $exception->getMessage()], $exception->httpStatus());
            }

            $this->recordSupport($request, $model, $found->store_id, $platformAction, 'incident', $found->id, ['state' => $changed->state], $tenantAction, $reason);

            return response()->json($dto->toArray($changed, null, true));
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function recordSupport(Request $request, Tenant $tenant, ?string $storeId, string $platformAction, string $targetType, string $targetId, array $changes, string $tenantAction, string $reason): void
    {
        $this->audit->record($this->staff($request)->id, $platformAction, $targetType, $targetId, PlatformAudit::RESULT_SUCCEEDED, $changes, $reason, $tenant->id);
        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $storeId,
            'actor_user_id' => null,
            'actor_type' => AuditLog::ACTOR_SYSTEM,
            'action' => $tenantAction,
            'entity_type' => $targetType,
            'entity_id' => $targetId,
            'changes' => $changes + ['by' => 'platform', 'reason' => $reason],
            'request_id' => (string) ($request->attributes->get('request_id') ?? Str::uuid()),
            'created_at' => Carbon::now(),
        ]);
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
