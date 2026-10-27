<?php

namespace App\Support\Providers;

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Support\Providers\PayPal\PayPalClient;
use App\Support\Providers\PayPal\PayPalConnector;
use App\Support\Providers\PayPal\PayPalRequestFailed;
use App\Support\Providers\Stripe\StripeClient;
use App\Support\Providers\Stripe\StripeConnector;
use App\Support\Providers\Stripe\StripeRequestFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shows the merchant whether the webhook set up at Stripe or PayPal points to this connection and listens to the
 * events it needs, read with the connection's own read access. Only the matching webhook is looked at; other
 * endpoints of the account and their addresses are not kept. Whether the secret or webhook id is right is proven only
 * by a verified delivery.
 */
class ProviderWebhookCheck
{
    public const STRIPE_EVENTS = ['payment_intent', 'charge', 'refund'];

    public const PAYPAL_EVENTS = [
        'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED', 'CHECKOUT.ORDER.COMPLETED',
    ];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripeConnector $stripeConnector,
        private readonly PayPalClient $paypal,
        private readonly PayPalConnector $paypalConnector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(Integration $integration): array
    {
        $result = $integration->provider === 'stripe' ? $this->stripe($integration) : $this->paypal($integration);
        $result = ['checked_at' => Carbon::now()->toJSON(), ...$result];
        $result['state'] ??= ($result['issues'] ?? []) === [] ? 'ok' : 'issues';

        self::remember($integration, 'webhook_check', $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function remember(Integration $integration, string $key, array $value): void
    {
        DB::transaction(function () use ($integration, $key, $value): void {
            $locked = Integration::query()->whereKey($integration->id)->lockForUpdate()->first();

            if ($locked !== null) {
                $locked->forceFill(['health' => [...($locked->health ?? []), $key => $value]])->save();
                $integration->setRawAttributes($locked->getAttributes(), true);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function stripe(Integration $integration): array
    {
        $key = $this->stripeConnector->secret($integration, IntegrationCredential::KIND_STRIPE_API);

        if ($key === null) {
            return ['state' => 'not_readable', 'error_code' => 'stripe_key_rejected'];
        }

        try {
            $endpoints = $this->stripe->get($key, '/v1/webhook_endpoints', ['limit' => 100])['data'] ?? [];
        } catch (StripeRequestFailed $failure) {
            return $failure->reasonCode === 'stripe_permission_missing' ? ['state' => 'permission_missing'] : ['state' => 'not_readable', 'error_code' => $failure->reasonCode];
        }

        $address = $this->address($integration);
        $endpoint = collect(is_array($endpoints) ? $endpoints : [])->first(fn (mixed $item): bool => is_array($item) && rtrim((string) ($item['url'] ?? ''), '/') === $address);

        if ($endpoint === null) {
            return ['issues' => ['endpoint_missing'], 'missing_events' => []];
        }

        $events = array_map('strval', is_array($endpoint['enabled_events'] ?? null) ? $endpoint['enabled_events'] : []);
        $missing = in_array('*', $events, true) ? [] : array_values(array_map(fn (string $family): string => $family.'.*', array_filter(
            self::STRIPE_EVENTS,
            fn (string $family): bool => ! collect($events)->contains(fn (string $event): bool => str_starts_with($event, $family.'.')),
        )));
        $issues = [];

        if (($endpoint['status'] ?? 'enabled') !== 'enabled') {
            $issues[] = 'endpoint_disabled';
        }

        if ($missing !== []) {
            $issues[] = 'events_missing';
        }

        return ['issues' => $issues, 'missing_events' => $missing];
    }

    /**
     * @return array<string, mixed>
     */
    private function paypal(Integration $integration): array
    {
        $credentials = $this->paypalConnector->credentials($integration);
        $webhookId = $this->paypalConnector->secret($integration, IntegrationCredential::KIND_PAYPAL_WEBHOOK);

        if ($credentials === null) {
            return ['state' => 'not_readable', 'error_code' => 'paypal_credentials_rejected'];
        }

        try {
            $token = $this->paypal->token($integration->mode, $credentials['client_id'], $credentials['client_secret'])['token'];
            $webhooks = $this->paypal->get($integration->mode, $token, '/v1/notifications/webhooks')['webhooks'] ?? [];
        } catch (PayPalRequestFailed $failure) {
            return ['state' => 'not_readable', 'error_code' => $failure->reasonCode];
        }

        $address = $this->address($integration);
        $ours = collect(is_array($webhooks) ? $webhooks : [])->filter(fn (mixed $item): bool => is_array($item) && rtrim((string) ($item['url'] ?? ''), '/') === $address)->values();

        if ($ours->isEmpty()) {
            return ['issues' => ['endpoint_missing'], 'missing_events' => []];
        }

        $webhook = $ours->first(fn (array $item): bool => ($item['id'] ?? null) === $webhookId);
        $issues = [];

        if ($webhook === null) {
            $issues[] = 'webhook_id_mismatch';
            $webhook = $ours->first();
        }

        $events = collect($webhook['event_types'] ?? [])->map(fn (mixed $type): string => is_array($type) ? (string) ($type['name'] ?? '') : '')->all();
        $missing = in_array('*', $events, true) ? [] : array_values(array_diff(self::PAYPAL_EVENTS, $events));

        if ($missing !== []) {
            $issues[] = 'events_missing';
        }

        return ['issues' => $issues, 'missing_events' => $missing];
    }

    private function address(Integration $integration): string
    {
        return rtrim(url('/api/v1/webhooks/'.$integration->provider.'/'.$integration->id), '/');
    }
}
