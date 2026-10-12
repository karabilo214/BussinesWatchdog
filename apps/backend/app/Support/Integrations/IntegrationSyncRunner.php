<?php

namespace App\Support\Integrations;

use App\Jobs\SyncIntegration;
use App\Models\Integration;
use App\Support\Providers\PayPal\PayPalSync;
use App\Support\Providers\Shopware\ShopwareConnector;
use App\Support\Providers\Shopware\ShopwareSync;
use App\Support\Providers\Stripe\StripeSync;
use App\Support\Providers\WooCommerce\WooCommerceApiConnector;
use App\Support\Providers\WooCommerce\WooCommerceApiSync;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Which integrations a provider sync covers, and the call that syncs one of them. The scheduler commands use it to
 * queue one job per integration (oldest successful sync first); the job uses it to run the sync.
 */
class IntegrationSyncRunner
{
    public const MODE_POINTERS = 'pointers';

    /** @var array<string, list<string>> */
    private const STATUSES = [
        'stripe' => [Integration::STATUS_ACTIVE],
        'paypal' => [Integration::STATUS_ACTIVE],
        WooCommerceApiConnector::PROVIDER => [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED],
        ShopwareConnector::PROVIDER => [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED],
    ];

    /**
     * @return Builder<Integration>
     */
    public function due(string $provider): Builder
    {
        return Integration::query()
            ->where('provider', $provider)
            ->whereIn('status', self::STATUSES[$provider] ?? throw new InvalidArgumentException('Unknown provider '.$provider))
            ->orderByRaw('last_successful_sync_at IS NOT NULL')
            ->orderBy('last_successful_sync_at')
            ->orderBy('id');
    }

    /**
     * Requests one job per integration; a request for an integration and mode that is still queued is dropped. The
     * ids are read in one query before anything is queued: workers update `last_successful_sync_at` meanwhile, and
     * paging over that order would skip some integrations and repeat others.
     *
     * @return array{requested: int}
     */
    public function dispatch(string $provider, string $mode): array
    {
        $ids = $this->due($provider)->pluck('id')->all();

        foreach ($ids as $id) {
            SyncIntegration::dispatch($id, $mode);
        }

        return ['requested' => count($ids)];
    }

    public function eligible(Integration $integration): bool
    {
        return in_array($integration->status, self::STATUSES[$integration->provider] ?? [], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Integration $integration, string $mode): array
    {
        return match ($integration->provider) {
            'stripe' => app(StripeSync::class)->run($integration, $mode),
            'paypal' => $mode === self::MODE_POINTERS ? app(PayPalSync::class)->syncPointers($integration) : app(PayPalSync::class)->run($integration, $mode),
            WooCommerceApiConnector::PROVIDER => app(WooCommerceApiSync::class)->run($integration, $mode),
            ShopwareConnector::PROVIDER => app(ShopwareSync::class)->run($integration, $mode),
            default => throw new InvalidArgumentException('Unknown provider '.$integration->provider),
        };
    }
}
