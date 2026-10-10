<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Support\Providers\PayPal\PayPalClient;
use App\Support\Providers\PayPal\PayPalConnector;
use App\Support\Providers\PayPal\PayPalRequestFailed;
use App\Support\Providers\PayPal\PayPalSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PayPal → us. PayPal verifies its own signature (verify-webhook-signature with the stored webhook id); the event
 * body is then only a hint: the affected PayPal order is read through the API and mapped like a poll, so nothing
 * from the webhook body (payer data included) is stored.
 */
class PayPalWebhookController extends Controller
{
    private const HEADERS = [
        'auth_algo' => 'PAYPAL-AUTH-ALGO',
        'cert_url' => 'PAYPAL-CERT-URL',
        'transmission_id' => 'PAYPAL-TRANSMISSION-ID',
        'transmission_sig' => 'PAYPAL-TRANSMISSION-SIG',
        'transmission_time' => 'PAYPAL-TRANSMISSION-TIME',
    ];

    public function __construct(
        private readonly PayPalConnector $connector,
        private readonly PayPalClient $client,
        private readonly PayPalSync $sync,
    ) {}

    public function store(Request $request, string $integration): JsonResponse
    {
        /** @var Integration|null $target */
        $target = Str::isUuid($integration) ? Integration::query()->whereKey($integration)->where('provider', 'paypal')->first() : null;

        if ($target === null) {
            return response()->json(['code' => 'integration_not_found'], 404);
        }

        $webhookId = $this->connector->secret($target, IntegrationCredential::KIND_PAYPAL_WEBHOOK);
        $credentials = $this->connector->credentials($target);

        if ($webhookId === null || $credentials === null) {
            return response()->json(['code' => 'webhook_not_configured'], 409);
        }

        $headers = [];

        foreach (self::HEADERS as $key => $header) {
            $headers[$key] = (string) $request->header($header);
        }

        $sentAt = strtotime($headers['transmission_time']);
        $event = json_decode($request->getContent(), true);

        if (in_array('', $headers, true) || $sentAt === false || abs(time() - $sentAt) > (int) config('watchdog.paypal.webhook_tolerance_seconds') || ! is_array($event)
            || ! str_starts_with($headers['cert_url'], 'https://api.paypal.com/') && ! str_starts_with($headers['cert_url'], 'https://api.sandbox.paypal.com/') && ! str_starts_with($headers['cert_url'], 'https://api-m.paypal.com/') && ! str_starts_with($headers['cert_url'], 'https://api-m.sandbox.paypal.com/')) {
            return response()->json(['code' => 'signature_invalid'], 400);
        }

        try {
            $token = $this->client->token($target->mode, $credentials['client_id'], $credentials['client_secret'])['token'];

            if (! $this->client->verifyWebhook($target->mode, $token, $webhookId, $headers, $request->getContent())) {
                return response()->json(['code' => 'signature_invalid'], 400);
            }
        } catch (PayPalRequestFailed) {
            return response()->json(['code' => 'verification_unavailable'], 503);
        }

        if ($target->status !== Integration::STATUS_ACTIVE) {
            return response()->json(['received' => true, 'ignored' => 'integration_not_active']);
        }

        $type = (string) ($event['event_type'] ?? '');

        try {
            $orderId = $this->orderId($target, $type, is_array($event['resource'] ?? null) ? $event['resource'] : []);

            if ($orderId === null) {
                return response()->json(['received' => true, 'ignored' => 'event_not_used']);
            }

            $summary = $this->sync->syncOrder($target, $orderId);
        } catch (PayPalRequestFailed) {
            return response()->json(['code' => 'provider_unavailable'], 503);
        }

        $target->forceFill(['health' => array_merge($target->health ?? [], ['webhook' => ['last_received_at' => Carbon::now()->toJSON(), 'last_type' => $type]])])->save();

        return response()->json(['received' => true, 'emitted' => $summary['emitted']]);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function orderId(Integration $integration, string $type, array $resource): ?string
    {
        $id = match (true) {
            str_starts_with($type, 'CHECKOUT.ORDER.') => $resource['id'] ?? null,
            $type === 'PAYMENT.CAPTURE.REFUNDED' || str_starts_with($type, 'PAYMENT.REFUND.') => $this->refundedCapture($integration, $resource),
            str_starts_with($type, 'PAYMENT.CAPTURE.'), str_starts_with($type, 'PAYMENT.AUTHORIZATION.') => $resource['supplementary_data']['related_ids']['order_id'] ?? null,
            default => null,
        };

        return is_string($id) && preg_match('/^[A-Z0-9]{1,36}$/', $id) === 1 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function refundedCapture(Integration $integration, array $resource): ?string
    {
        foreach (is_array($resource['links'] ?? null) ? $resource['links'] : [] as $link) {
            if (($link['rel'] ?? null) === 'up' && preg_match('#/v2/payments/captures/([A-Z0-9]{1,36})$#', (string) ($link['href'] ?? ''), $match) === 1) {
                return $this->sync->orderOfCapture($integration, $match[1]);
            }
        }

        return null;
    }
}
