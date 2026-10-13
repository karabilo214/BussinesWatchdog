<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Api\V1\Billing\Concerns\RespondsToBilling;
use App\Http\Controllers\Controller;
use App\Models\BillingCheckoutSession;
use App\Models\BillingPrice;
use App\Support\Billing\BillingCatalog;
use App\Support\Billing\BillingCheckoutRejected;
use App\Support\Billing\DiscountLedger;
use App\Support\Billing\DiscountResolver;
use App\Support\Billing\EntitlementCalculator;
use App\Support\Billing\Paddle\PaddleCheckout;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Site\Policies;
use App\Support\Site\PolicyAcceptanceRequired;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Buying a plan (platform-billing-api.yaml): start a checkout, follow it, check a promo code.
 * Paid access comes only from the provider's verified state, never from the browser returning from the checkout.
 */
class CheckoutController extends Controller
{
    use RespondsToBilling;

    public function __construct(private readonly Policies $policies) {}

    public function checkout(Request $request, TenantContext $tenantContext, PaddleCheckout $checkout): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('start checkout');
        $key = $this->idempotencyKey($request);

        if ($key === null) {
            return $this->idempotencyKeyRequired();
        }

        $validated = $request->validate([
            'price_id' => ['required', 'uuid'],
            'extra_stores' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'discount_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'faster_checks' => ['sometimes', 'boolean'],
            ...self::POLICY_RULES,
        ]);

        try {
            $accepted = $this->policies->verify(Policies::PURPOSE_PURCHASE, $validated['policy_version_ids'] ?? [], $this->locale($request));
        } catch (PolicyAcceptanceRequired $exception) {
            return $this->policyRequired($exception);
        }

        try {
            $session = $checkout->start($tenantId, (string) $request->user()->getAuthIdentifier(), $validated['price_id'], (int) ($validated['extra_stores'] ?? 0), $key, $validated['discount_code'] ?? null, (bool) ($validated['faster_checks'] ?? false));
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The checkout cannot be started.'], $exception->status());
        }

        $this->policies->record((string) $request->user()->getAuthIdentifier(), $tenantId, Policies::PURPOSE_PURCHASE, $accepted, $session->id);

        return response()->json($this->checkoutDto($session, $tenantId), 202);
    }

    public function show(TenantContext $tenantContext, string $checkout): JsonResponse
    {
        $tenantId = $tenantContext->requireTenantId('read checkout');
        /** @var BillingCheckoutSession|null $session */
        $session = Str::isUuid($checkout) ? BillingCheckoutSession::query()->where('tenant_id', $tenantId)->whereKey($checkout)->first() : null;

        if ($session === null) {
            return response()->json(['code' => 'not_found', 'message' => 'Checkout not found.'], 404);
        }

        return response()->json($this->checkoutDto($session, $tenantId));
    }

    public function checkDiscount(Request $request, TenantContext $tenantContext, BillingCatalog $catalog, DiscountResolver $discounts): JsonResponse
    {
        $tenantContext->requireTenantId('check discount');
        $validated = $request->validate(['code' => ['required', 'string', 'max:32'], 'price_id' => ['required', 'uuid']]);
        /** @var BillingPrice|null $price */
        $price = $catalog->prices()->with('planVersion')->whereKey($validated['price_id'])->where('price_kind', BillingPrice::KIND_BASE)->first();

        if ($price === null) {
            return response()->json(['code' => 'price_not_available', 'message' => 'The price is not available.'], 422);
        }

        try {
            $discount = $discounts->byCode($price, $validated['code']);
        } catch (BillingCheckoutRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The code cannot be used.'], $exception->status());
        }

        return response()->json(DiscountResolver::dto($discount));
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutDto(BillingCheckoutSession $session, string $tenantId): array
    {
        $open = $session->status === BillingCheckoutSession::STATUS_PENDING && $session->expires_at->isFuture() && $session->provider_session_ref !== null;

        return [
            'id' => $session->id,
            'status' => $session->status === BillingCheckoutSession::STATUS_PENDING && $session->expires_at->isPast() ? BillingCheckoutSession::STATUS_EXPIRED : $session->status,
            'error_code' => $session->error_code,
            'expires_at' => $session->expires_at->toJSON(),
            'extra_store_quantity' => $session->extra_store_quantity,
            'discounts' => app(DiscountLedger::class)->checkoutBreakdown($session->id),
            'access_state' => app(EntitlementCalculator::class)->forTenant($tenantId)->access,
            'provider_checkout' => $open ? [
                'provider' => $session->provider,
                'environment' => PaddleConfig::environment(),
                'client_token' => PaddleConfig::clientToken(),
                'transaction_id' => $session->provider_session_ref,
            ] : null,
        ];
    }
}
