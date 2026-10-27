<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Support\Providers\WooCommerce\WooCommerceApiConnector;
use App\Support\Providers\WooCommerce\WooCommerceApiWebhooks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * After WooCommerce pinged a webhook address, the store's webhook list is read once so the cabinet shows whether the
 * ping belongs to a real, active webhook of this store (ADR 0077).
 */
class CheckWooCommerceWebhooks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function __construct(public readonly string $integrationId)
    {
        $this->onQueue(SyncIntegration::QUEUE);
        $this->delay(now()->addSeconds(5));
    }

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function handle(WooCommerceApiWebhooks $webhooks): void
    {
        $integration = Integration::query()->whereKey($this->integrationId)->where('provider', WooCommerceApiConnector::PROVIDER)
            ->whereIn('status', [Integration::STATUS_ACTIVE, Integration::STATUS_DEGRADED])->first();

        if ($integration !== null) {
            $webhooks->check($integration);
        }
    }
}
