<?php

namespace App\Http\Controllers\Api\V1\Stores;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Store;
use App\Support\Sales\SalesCoverage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StoreSalesExclusionController extends Controller
{
    public function index(Request $request, Store $store, TenantContext $tenants): JsonResponse
    {
        abort_unless($store->tenant_id === $tenants->requireTenantId('sales.exclusions.read'), 404);
        $validated = $request->validate(['mode' => ['sometimes', 'required', 'in:live,test']]);

        return response()->json($this->data($store, $validated['mode'] ?? 'live'));
    }

    public function update(Request $request, Store $store, TenantContext $tenants, SalesCoverage $coverage): JsonResponse
    {
        $tenantId = $tenants->requireTenantId('sales.exclusions.update');
        abort_unless($store->tenant_id === $tenantId, 404);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', 'in:promotion,holiday'],
            'mode' => ['required', 'in:live,test'],
            'excluded' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('sales_exclusion_boolean_required');
                }
            }],
        ]);
        if (array_diff(array_keys($request->all()), ['date', 'kind', 'mode', 'excluded']) !== []) {
            throw ValidationException::withMessages(['date' => 'sales_exclusion_invalid']);
        }

        try {
            $updated = DB::transaction(function () use ($store, $tenantId, $request, $coverage, $validated): Store {
                $locked = Store::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($store->id);
                $today = CarbonImmutable::now($locked->timezone)->startOfDay();
                $day = CarbonImmutable::parse($validated['date'], $locked->timezone)->startOfDay();
                if ($day->lessThan($today->subDays(28)) || $day->greaterThan($today)) {
                    throw new InvalidArgumentException('sales_exclusion_range_invalid');
                }
                $key = $validated['mode'].':'.$validated['date'].':'.$validated['kind'];
                $before = $locked->sales_baseline_exclusions[$key] ?? null;
                if ($validated['excluded'] && $before === null) {
                    $coverage->excludeDay($locked->id, $validated['date'], $validated['kind'], $validated['mode']);
                } elseif (! $validated['excluded']) {
                    $coverage->removeManualDay($locked->id, $validated['date'], $validated['kind'], $validated['mode']);
                }
                $locked->refresh();
                $after = $locked->sales_baseline_exclusions[$key] ?? null;
                if ($before !== $after) {
                    AuditLog::query()->create([
                        'tenant_id' => $tenantId, 'store_id' => $locked->id,
                        'actor_user_id' => $request->user()->id, 'actor_type' => AuditLog::ACTOR_USER,
                        'action' => AuditLog::ACTION_STORE_SALES_EXCLUSION_SET, 'entity_type' => AuditLog::ENTITY_STORE,
                        'entity_id' => $locked->id, 'changes' => $validated, 'request_id' => (string) Str::uuid(), 'created_at' => now(),
                    ]);
                }

                return $locked;
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['date' => $exception->getMessage()]);
        }

        return response()->json($this->data($updated, $validated['mode']));
    }

    private function data(Store $store, string $mode): array
    {
        $today = CarbonImmutable::now($store->timezone)->startOfDay();
        $minimum = $today->subDays(28)->toDateString();
        $maximum = $today->toDateString();
        $entries = [];
        foreach ($store->sales_baseline_exclusions ?? [] as $entry) {
            if ($entry['mode'] !== $mode || $entry['date'] < $minimum || $entry['date'] > $maximum) {
                continue;
            }
            $entries[] = [
                'date' => $entry['date'], 'kind' => $entry['kind'], 'timezone' => $entry['timezone'],
                'from' => $entry['from'], 'to' => $entry['to'], 'editable' => in_array($entry['kind'], ['promotion', 'holiday'], true),
            ];
        }
        usort($entries, fn (array $a, array $b): int => ($b['date'] <=> $a['date']) ?: ($a['kind'] <=> $b['kind']));

        return ['timezone' => $store->timezone, 'min_date' => $minimum, 'max_date' => $maximum, 'mode' => $mode, 'entries' => $entries];
    }
}
