<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Support\Billing\StoreCapacity;
use App\Support\Providers\ProviderWebhookCheck;
use App\Support\Providers\Stripe\StripeConnector;
use App\Support\Providers\Stripe\StripeObjectIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe → us. The signature is checked by the official library over the unmodified raw body; only payment intent,
 * charge, refund and dispute objects are taken in, through the same change-detecting path as polling.
 */
class StripeWebhookController extends Controller
{
    private const OBJECTS = ['payment_intent', 'charge', 'refund', 'dispute'];

    public function __construct(
        private readonly StripeConnector $connector,
        private readonly StripeObjectIngestor $ingestor,
    ) {}

    public function store(Request $request, string $integration): JsonResponse
    {
        /** @var Integration|null $target */
        $target = Str::isUuid($integration) ? Integration::query()->whereKey($integration)->where('provider', 'stripe')->first() : null;

        if ($target === null) {
            return response()->json(['code' => 'integration_not_found'], 404);
        }

        $secret = $this->connector->secret($target, IntegrationCredential::KIND_STRIPE_WEBHOOK);

        if ($secret === null) {
            return response()->json(['code' => 'webhook_not_configured'], 409);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret, (int) config('watchdog.stripe.webhook_tolerance_seconds'));
        } catch (SignatureVerificationException|UnexpectedValueException) {
            if (preg_match('/(^|,)t=\d+,.*v1=[0-9a-f]{64}/', (string) $request->header('Stripe-Signature')) === 1) {
                ProviderWebhookCheck::remember($target, 'webhook_rejected', ['reason' => 'signature_invalid', 'at' => Carbon::now()->toJSON()]);
            }

            return response()->json(['code' => 'signature_invalid'], 400);
        }

        ProviderWebhookCheck::remember($target, 'webhook', ['last_received_at' => Carbon::now()->toJSON(), 'last_type' => $event->type]);

        if ($target->status !== Integration::STATUS_ACTIVE) {
            return response()->json(['received' => true, 'ignored' => 'integration_not_active']);
        }

        if (StoreCapacity::paused($target)) {
            return response()->json(['received' => true, 'ignored' => StoreCapacity::ERROR_PAUSED]);
        }

        $object = $event->data->object->toArray();

        if (! in_array($object['object'] ?? null, self::OBJECTS, true)) {
            return response()->json(['received' => true, 'ignored' => 'object_not_used']);
        }

        $summary = $this->ingestor->ingest($target, [$object]);

        return response()->json(['received' => true, 'emitted' => $summary['emitted']]);
    }
}
