<?php

namespace App\Http\Controllers\Api\V1\Checks;

use App\Http\Controllers\Controller;
use App\Models\CheckAttempt;
use App\Models\CheckRun;
use App\Models\CheckScenario;
use App\Models\Store;
use App\Rules\PublicHttpsUrl;
use App\Support\Api\UuidCursor;
use App\Support\Browser\CheckScheduler;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CheckScenarioController extends Controller
{
    public function __construct(
        private readonly ScenarioDefinition $definition,
    ) {}

    public function show(Store $store, TenantContext $tenantContext): JsonResponse
    {
        $this->authorizeStore($store, $tenantContext);
        $scenario = $this->scenario($store);

        abort_if($scenario === null, 404);

        return response()->json($this->scenarioDto($scenario));
    }

    public function upsert(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $this->authorizeStore($store, $tenantContext);
        $validated = $request->validate([
            'product_url' => ['required', 'string', 'max:2048', new PublicHttpsUrl],
            'cart_url' => ['sometimes', 'nullable', 'string', 'max:2048', new PublicHttpsUrl],
            'checkout_url' => ['sometimes', 'nullable', 'string', 'max:2048', new PublicHttpsUrl],
            'product_external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'interval_seconds' => ['sometimes', 'integer', 'between:300,86400'],
            'enabled' => ['sometimes', 'boolean'],
            'extra_allowed_origins' => ['sometimes', 'array', 'max:10'],
            'extra_allowed_origins.*' => ['string', 'max:255', new PublicHttpsUrl],
            'synthetic_location' => ['sometimes', 'nullable', 'array'],
            'synthetic_location.country' => ['required_with:synthetic_location', 'string', 'size:2', 'alpha'],
            'synthetic_location.postcode' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9 \-]+$/'],
        ]);

        $origin = $this->definition->origin($store->base_url);

        foreach (['product_url', 'cart_url', 'checkout_url'] as $field) {
            if (isset($validated[$field]) && $this->definition->origin($validated[$field]) !== $origin) {
                return response()->json(['code' => 'url_outside_store_origin', 'message' => 'Scenario URLs must be on the store origin.', 'errors' => [$field => ['url_outside_store_origin']]], 422);
            }
        }

        if (($validated['enabled'] ?? false) === true && $store->verified_at === null) {
            return response()->json(['code' => 'store_not_verified', 'message' => 'Browser checks require a verified store domain.'], 422);
        }

        $scenario = DB::transaction(function () use ($store, $validated): CheckScenario {
            $now = Carbon::now();
            /** @var CheckScenario|null $scenario */
            $scenario = CheckScenario::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->lockForUpdate()->first();
            $definition = [
                'product_url' => $validated['product_url'],
                'cart_url' => $validated['cart_url'] ?? null,
                'checkout_url' => $validated['checkout_url'] ?? null,
                'extra_allowed_origins' => array_values(array_unique(array_map(fn (string $url): string => $this->definition->origin($url), $validated['extra_allowed_origins'] ?? []))),
                'synthetic_location' => isset($validated['synthetic_location']) ? [
                    'country' => strtoupper($validated['synthetic_location']['country']),
                    'postcode' => $validated['synthetic_location']['postcode'] ?? null,
                ] : null,
            ];
            $enabled = $validated['enabled'] ?? $scenario?->enabled ?? false;
            $attributes = [
                'definition' => $definition,
                'product_external_id' => $validated['product_external_id'] ?? $scenario?->product_external_id,
                'interval_seconds' => $validated['interval_seconds'] ?? $scenario?->interval_seconds ?? (int) config('watchdog.browser.default_interval_seconds'),
                'enabled' => $enabled,
                'adapter_version' => ScenarioDefinition::ADAPTER_VERSION,
                'updated_at' => $now,
            ];

            if ($scenario === null) {
                return CheckScenario::query()->create(array_merge($attributes, [
                    'tenant_id' => $store->tenant_id,
                    'store_id' => $store->id,
                    'name' => 'Payment form',
                    'mode' => CheckScenario::MODE_PAYMENT_FORM,
                    'version' => 1,
                    'next_due_at' => $enabled ? $now : null,
                    'created_at' => $now,
                ]));
            }

            $scenario->forceFill(array_merge($attributes, [
                'version' => $scenario->version + 1,
                'next_due_at' => $enabled && ! $scenario->enabled ? $now : $scenario->next_due_at,
            ]))->save();

            return $scenario;
        });

        return response()->json($this->scenarioDto($scenario));
    }

    public function runNow(Request $request, Store $store, TenantContext $tenantContext, CheckScheduler $scheduler): JsonResponse
    {
        $this->authorizeStore($store, $tenantContext);
        $validated = $request->validate(['scenario_id' => ['required', 'uuid']]);
        $scenario = $this->scenario($store);

        abort_if($scenario === null || $scenario->id !== $validated['scenario_id'], 404);

        $run = $scheduler->runNow($scenario);

        if (is_string($run)) {
            $status = match ($run) {
                CheckScheduler::ERROR_RATE_LIMITED => 429,
                CheckScheduler::ERROR_RUN_ACTIVE => 409,
                default => 422,
            };

            return response()->json(['code' => $run, 'message' => 'The check cannot be started now.'], $status);
        }

        return response()->json(array_merge(['run_id' => $run->id], $this->runDto($run)), 202);
    }

    public function runs(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        $this->authorizeStore($store, $tenantContext);
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
            'data' => $page->map(fn (CheckRun $run): array => $this->runDto($run))->values()->all(),
            'next_cursor' => $runs->count() > $limit ? UuidCursor::encode((string) $page->last()->id) : null,
        ]);
    }

    public function showRun(CheckRun $checkRun, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($checkRun->tenant_id === $tenantContext->requireTenantId('read check run'), 404);

        $attempts = CheckAttempt::query()
            ->where('tenant_id', $checkRun->tenant_id)
            ->where('run_id', $checkRun->id)
            ->with('steps')
            ->orderBy('attempt_number')
            ->get();

        return response()->json(array_merge($this->runDto($checkRun), [
            'attempts' => $attempts->map(fn (CheckAttempt $attempt): array => [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status,
                'error_code' => $attempt->error_code,
                'location' => $attempt->location,
                'browser_version' => $attempt->browser_version,
                'started_at' => $attempt->started_at->toJSON(),
                'finished_at' => $attempt->finished_at?->toJSON(),
                'diagnostics' => $attempt->sanitized_error,
                'steps' => $attempt->steps->map(fn ($step): array => [
                    'index' => $step->step_index,
                    'code' => $step->step_code,
                    'status' => $step->status,
                    'error_code' => $step->error_code,
                    'started_at' => $step->started_at->toJSON(),
                    'finished_at' => $step->finished_at->toJSON(),
                    'assertions' => $step->assertions,
                    'network_summary' => $step->network_summary,
                ])->values()->all(),
            ])->values()->all(),
        ]));
    }

    private function authorizeStore(Store $store, TenantContext $tenantContext): void
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('browser checks'), 404);
    }

    private function scenario(Store $store): ?CheckScenario
    {
        return CheckScenario::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function scenarioDto(CheckScenario $scenario): array
    {
        return [
            'id' => $scenario->id,
            'store_id' => $scenario->store_id,
            'mode' => $scenario->mode,
            'version' => $scenario->version,
            'enabled' => $scenario->enabled,
            'adapter_version' => $scenario->adapter_version,
            'interval_seconds' => $scenario->interval_seconds,
            'product_external_id' => $scenario->product_external_id,
            'definition' => $scenario->definition,
            'next_due_at' => $scenario->next_due_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runDto(CheckRun $run): array
    {
        return [
            'id' => $run->id,
            'store_id' => $run->store_id,
            'scenario_id' => $run->scenario_id,
            'scenario_version' => $run->scenario_version,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'error_code' => $run->error_code,
            'scheduled_at' => $run->scheduled_at->toJSON(),
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
        ];
    }
}
