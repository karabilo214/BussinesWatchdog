<?php

namespace App\Http\Controllers\Api\V1\Checks;

use App\Http\Controllers\Controller;
use App\Http\Dto\Checks\CheckDto;
use App\Models\AuditLog;
use App\Models\CheckScenario;
use App\Models\Store;
use App\Rules\PublicHttpsUrl;
use App\Support\Browser\ScenarioDefinition;
use App\Support\Security\Keyring;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckScenarioController extends Controller
{
    public function __construct(
        private readonly ScenarioDefinition $definition,
        private readonly CheckDto $dto,
        private readonly Keyring $keyring,
    ) {}

    public function index(Store $store, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('list check scenarios'), 404);

        $scenarios = CheckScenario::query()->where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->orderBy('created_at')->get();

        return response()->json([
            'data' => $scenarios->map(fn (CheckScenario $scenario): array => $this->dto->scenario($scenario))->values()->all(),
            'next_cursor' => null,
        ]);
    }

    public function store(Request $request, Store $store, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($store->tenant_id === $tenantContext->requireTenantId('create check scenario'), 404);
        $validated = $request->validate($this->rules(creating: true));

        if (($problem = $this->problem($store, $validated, null)) !== null) {
            return $problem;
        }

        $scenario = DB::transaction(function () use ($request, $store, $validated): ?CheckScenario {
            if (CheckScenario::query()->where('store_id', $store->id)->where('mode', CheckScenario::MODE_PAYMENT_FORM)->lockForUpdate()->exists()) {
                return null;
            }

            $now = Carbon::now();
            $enabled = $validated['enabled'] ?? false;
            $scenario = CheckScenario::query()->create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'name' => $validated['name'] ?? 'Payment form',
                'mode' => CheckScenario::MODE_PAYMENT_FORM,
                'version' => 1,
                'enabled' => $enabled,
                'adapter_version' => $this->definition->adapterFor($store),
                'definition' => $this->definitionFrom($validated, []),
                'product_external_id' => $validated['product_external_id'] ?? null,
                ...$this->accessFrom($store, $validated, null),
                'interval_seconds' => $validated['interval_seconds'] ?? (int) config('watchdog.browser.default_interval_seconds'),
                'next_due_at' => $enabled ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit($request, $scenario, array_key_exists('sign_in', $validated));

            return $scenario;
        });

        if ($scenario === null) {
            return response()->json(['code' => 'scenario_exists', 'message' => 'This store already has a payment form scenario.'], 409);
        }

        return $this->respond($scenario, 201);
    }

    public function show(CheckScenario $scenario, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($scenario->tenant_id === $tenantContext->requireTenantId('read check scenario'), 404);

        return $this->respond($scenario);
    }

    public function update(Request $request, CheckScenario $scenario, TenantContext $tenantContext): JsonResponse
    {
        abort_unless($scenario->tenant_id === $tenantContext->requireTenantId('update check scenario'), 404);
        $expected = $this->expectedVersion($request);
        $validated = $request->validate($this->rules(creating: false));
        /** @var Store $store */
        $store = Store::query()->where('tenant_id', $scenario->tenant_id)->findOrFail($scenario->store_id);

        if (($problem = $this->problem($store, $validated, $scenario)) !== null) {
            return $problem;
        }

        $updated = DB::transaction(function () use ($request, $scenario, $store, $validated, $expected): ?CheckScenario {
            /** @var CheckScenario $locked */
            $locked = CheckScenario::query()->whereKey($scenario->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $expected) {
                return null;
            }

            $enabled = $validated['enabled'] ?? $locked->enabled;
            $locked->forceFill([
                'name' => $validated['name'] ?? $locked->name,
                'enabled' => $enabled,
                'definition' => $this->definitionFrom($validated, $locked->definition ?? []),
                'product_external_id' => array_key_exists('product_external_id', $validated) ? $validated['product_external_id'] : $locked->product_external_id,
                'adapter_version' => $this->definition->adapterFor($store),
                ...$this->accessFrom($store, $validated, $locked),
                'interval_seconds' => $validated['interval_seconds'] ?? $locked->interval_seconds,
                'version' => $locked->version + 1,
                'next_due_at' => $enabled ? ($locked->enabled ? $locked->next_due_at : Carbon::now()) : null,
                'updated_at' => Carbon::now(),
            ])->save();
            $this->audit($request, $locked, array_key_exists('sign_in', $validated));

            return $locked;
        });

        if ($updated === null) {
            return response()->json(['code' => 'version_conflict', 'message' => 'Resource version conflict.'], 409);
        }

        return $this->respond($updated);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'product_url' => [$creating ? 'required' : 'sometimes', 'string', 'max:2048', new PublicHttpsUrl],
            'cart_url' => ['sometimes', 'nullable', 'string', 'max:2048', new PublicHttpsUrl],
            'checkout_url' => ['sometimes', 'nullable', 'string', 'max:2048', new PublicHttpsUrl],
            'product_external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'interval_seconds' => ['sometimes', 'integer', 'between:300,86400'],
            'enabled' => ['sometimes', 'boolean'],
            'extra_allowed_origins' => ['sometimes', 'array', 'max:10'],
            'extra_allowed_origins.*' => ['string', 'max:255', new PublicHttpsUrl],
            'load_payment_providers' => ['sometimes', 'boolean'],
            'synthetic_location' => ['sometimes', 'nullable', 'array:country,postcode'],
            'synthetic_location.country' => ['required_with:synthetic_location', 'string', 'size:2', 'alpha'],
            'synthetic_location.postcode' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'checkout_access' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', CheckScenario::ACCESSES)],
            'sign_in' => ['sometimes', 'nullable', 'array:email,password'],
            'sign_in.email' => ['required_with:sign_in', 'string', 'email', 'max:255'],
            'sign_in.password' => ['required_with:sign_in', 'string', 'min:1', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function problem(Store $store, array $validated, ?CheckScenario $current): ?JsonResponse
    {
        if ($this->definition->adapterFor($store) === null) {
            return response()->json(['code' => 'platform_not_supported', 'message' => 'Browser checks are not available for this store platform yet.'], 422);
        }

        $origin = $this->definition->origin($store->base_url);

        foreach (['product_url', 'cart_url', 'checkout_url'] as $field) {
            if (isset($validated[$field]) && $this->definition->origin($validated[$field]) !== $origin) {
                return response()->json(['code' => 'url_outside_store_origin', 'message' => 'Scenario URLs must be on the store origin.', 'errors' => [$field => ['url_outside_store_origin']]], 422);
            }
        }

        if (($validated['enabled'] ?? false) === true && $store->verified_at === null) {
            return response()->json(['code' => 'store_not_verified', 'message' => 'Browser checks require a verified store domain.'], 422);
        }

        if (! $this->definition->usesCheckoutAccess($store)) {
            if (($validated['checkout_access'] ?? null) !== null || ($validated['sign_in'] ?? null) !== null) {
                return response()->json(['code' => 'checkout_access_not_applicable', 'message' => 'This store platform shows payment methods without signing in.', 'errors' => ['checkout_access' => ['checkout_access_not_applicable']]], 422);
            }

            return null;
        }

        $access = $this->accessOf($validated, $current);

        if (($validated['sign_in'] ?? null) !== null && $access !== CheckScenario::ACCESS_TEST_CUSTOMER) {
            return response()->json(['code' => 'sign_in_not_applicable', 'message' => 'A test customer account is used only with the test customer way.', 'errors' => ['sign_in' => ['sign_in_not_applicable']]], 422);
        }

        $keeps = $current !== null && $current->checkout_access === CheckScenario::ACCESS_TEST_CUSTOMER && $current->sign_in_ciphertext !== null;

        if ($access === CheckScenario::ACCESS_TEST_CUSTOMER && ($validated['sign_in'] ?? null) === null && ! $keeps) {
            return response()->json(['code' => 'sign_in_required', 'message' => 'Enter the e-mail and password of the test customer account.', 'errors' => ['sign_in' => ['sign_in_required']]], 422);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function definitionFrom(array $validated, array $current): array
    {
        $definition = $current;

        foreach (['product_url', 'cart_url', 'checkout_url'] as $field) {
            if (array_key_exists($field, $validated)) {
                $definition[$field] = $validated[$field];
            }
        }

        if (array_key_exists('extra_allowed_origins', $validated)) {
            $definition['extra_allowed_origins'] = array_values(array_unique(array_map(fn (string $url): string => $this->definition->origin($url), $validated['extra_allowed_origins'])));
        }

        if (array_key_exists('load_payment_providers', $validated)) {
            $definition['load_payment_providers'] = (bool) $validated['load_payment_providers'];
        }

        if (array_key_exists('synthetic_location', $validated)) {
            $definition['synthetic_location'] = $validated['synthetic_location'] === null ? null : [
                'country' => strtoupper($validated['synthetic_location']['country']),
                'postcode' => $validated['synthetic_location']['postcode'] ?? null,
            ];
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function accessOf(array $validated, ?CheckScenario $current): string
    {
        return $validated['checkout_access'] ?? $current?->checkout_access ?? CheckScenario::ACCESS_STOP_BEFORE_SIGN_IN;
    }

    /**
     * The way a check reaches the payment step and the encrypted test customer account (ADR 0055). The account is
     * kept only while the test customer way is chosen.
     *
     * @param  array<string, mixed>  $validated
     * @return array{checkout_access: ?string, sign_in_ciphertext?: ?string, sign_in_key_version?: ?int}
     */
    private function accessFrom(Store $store, array $validated, ?CheckScenario $current): array
    {
        if (! $this->definition->usesCheckoutAccess($store)) {
            return ['checkout_access' => null, 'sign_in_ciphertext' => null, 'sign_in_key_version' => null];
        }

        $access = $this->accessOf($validated, $current);

        if ($access !== CheckScenario::ACCESS_TEST_CUSTOMER) {
            return ['checkout_access' => $access, 'sign_in_ciphertext' => null, 'sign_in_key_version' => null];
        }

        if (($validated['sign_in'] ?? null) === null) {
            return ['checkout_access' => $access];
        }

        $encrypted = $this->keyring->encrypt(json_encode(['email' => $validated['sign_in']['email'], 'password' => $validated['sign_in']['password']], JSON_THROW_ON_ERROR));

        return ['checkout_access' => $access, 'sign_in_ciphertext' => $encrypted['ciphertext'], 'sign_in_key_version' => $encrypted['key_version']];
    }

    private function respond(CheckScenario $scenario, int $status = 200): JsonResponse
    {
        return response()->json($this->dto->scenario($scenario), $status)->setEtag((string) $scenario->version);
    }

    private function expectedVersion(Request $request): int
    {
        $header = $request->header('If-Match');

        abort_if($header === null, 428, 'If-Match header is required.');

        if (! preg_match('/^"?([1-9][0-9]*)"?$/', $header, $matches)) {
            abort(400, 'Invalid If-Match header.');
        }

        return (int) $matches[1];
    }

    private function audit(Request $request, CheckScenario $scenario, bool $signInChanged): void
    {
        AuditLog::query()->create([
            'tenant_id' => $scenario->tenant_id,
            'store_id' => $scenario->store_id,
            'actor_user_id' => $request->user()?->id,
            'actor_type' => AuditLog::ACTOR_USER,
            'action' => AuditLog::ACTION_CHECK_SCENARIO_SAVED,
            'entity_type' => AuditLog::ENTITY_CHECK_SCENARIO,
            'entity_id' => $scenario->id,
            'changes' => array_filter(['version' => $scenario->version, 'enabled' => $scenario->enabled, 'checkout_access' => $scenario->checkout_access, 'sign_in_changed' => $signInChanged ?: null], fn ($value): bool => $value !== null),
            'request_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ]);
    }
}
