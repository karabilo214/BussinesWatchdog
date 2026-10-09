<?php

namespace App\Http\Controllers\Api\V1\Checks;

use App\Http\Controllers\Controller;
use App\Http\Dto\Checks\CheckDto;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\CheckAttempt;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\Store;
use App\Support\Api\UuidCursor;
use App\Support\Browser\ArtifactStore;
use App\Support\Browser\CheckScheduler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckRunController extends Controller
{
    public const ERROR_RUN_NOT_ACTIVE = 'check_run_not_active';

    public function __construct(
        private readonly CheckDto $dto,
    ) {}

    public function index(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('list check runs'), 404);
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:128'],
        ]);
        $limit = (int) ($validated['limit'] ?? 25);
        $query = CheckRun::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id);

        if (isset($validated['cursor'])) {
            $cursorId = UuidCursor::decode($validated['cursor']);
            abort_if($cursorId === null, 422, 'invalid_cursor');
            $query->where('id', '<', $cursorId);
        }

        $runs = $query->orderByDesc('id')->limit($limit + 1)->get();
        $page = $runs->take($limit);

        return response()->json([
            'data' => $page->map(fn (CheckRun $run): array => $this->dto->run($run))->values()->all(),
            'next_cursor' => $runs->count() > $limit ? UuidCursor::encode((string) $page->last()->id) : null,
        ]);
    }

    public function store(Request $request, Store $store, TenantContext $tenantContext, CheckScheduler $scheduler): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('start check'), 404);
        $validated = $request->validate(['scenario_id' => ['required', 'uuid']]);
        $scenario = CheckScenario::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->whereKey($validated['scenario_id'])->first();

        abort_if($scenario === null, 404);

        $run = $scheduler->runNow($scenario);

        if (is_string($run)) {
            $status = match ($run) {
                CheckScheduler::ERROR_RATE_LIMITED => 429,
                CheckScheduler::ERROR_RUN_ACTIVE => 409,
                default => 422,
            };

            return response()->json(['code' => $run, 'message' => 'The check cannot be started now.'], $status);
        }

        return response()->json(array_merge(['run_id' => $run->id], $this->dto->run($run)), 202);
    }

    public function show(CheckRun $checkRun, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($checkRun->tenant_id === $tenantContext->requireTenantId('read check run'), 404);

        return response()->json($this->dto->runDetail($checkRun));
    }

    public function cancel(Request $request, CheckRun $checkRun, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($checkRun->tenant_id === $tenantContext->requireTenantId('cancel check run'), 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $cancelled = DB::transaction(function () use ($request, $checkRun, $validated): ?CheckRun {
            /** @var CheckRun $run */
            $run = CheckRun::query()->whereKey($checkRun->id)->lockForUpdate()->firstOrFail();

            if (! in_array($run->status, CheckRun::ACTIVE_STATUSES, true)) {
                return null;
            }

            $now = Carbon::now();
            CheckAttempt::query()
                ->where('run_id', $run->id)
                ->where('status', CheckAttempt::STATUS_RUNNING)
                ->update(['status' => CheckRun::STATUS_CANCELLED, 'finished_at' => $now, 'error_code' => 'cancelled_by_user']);
            $run->forceFill([
                'status' => CheckRun::STATUS_CANCELLED,
                'finished_at' => $now,
                'started_at' => $run->started_at ?? $now,
                'error_code' => 'cancelled_by_user',
                'last_fencing_token' => $run->last_fencing_token + 1,
            ])->save();

            AuditLog::query()->create([
                'tenant_id' => $run->tenant_id,
                'store_id' => $run->store_id,
                'actor_user_id' => $request->user()?->id,
                'actor_type' => AuditLog::ACTOR_USER,
                'action' => AuditLog::ACTION_CHECK_RUN_CANCELLED,
                'entity_type' => AuditLog::ENTITY_CHECK_RUN,
                'entity_id' => $run->id,
                'changes' => ['reason' => $validated['reason']],
                'request_id' => (string) Str::uuid(),
                'created_at' => $now,
            ]);

            return $run;
        });

        if ($cancelled === null) {
            return response()->json(['code' => self::ERROR_RUN_NOT_ACTIVE, 'message' => 'Only queued or running checks can be cancelled.'], 409);
        }

        return response()->json($this->dto->run($cancelled));
    }

    public function download(Artifact $artifact, TenantContext $tenantContext, ArtifactStore $artifacts): JsonResponse
    {
        abort_unless($artifact->tenant_id === $tenantContext->requireTenantId('read artifact'), 404);
        abort_unless($artifact->state === Artifact::STATE_READY && $artifact->expires_at->isFuture(), 404);

        return response()->json(array_merge($artifacts->temporaryUrl($artifact), [
            'content_type' => $artifact->content_type,
            'size_bytes' => $artifact->size_bytes,
        ]))->header('Cache-Control', 'no-store');
    }
}
