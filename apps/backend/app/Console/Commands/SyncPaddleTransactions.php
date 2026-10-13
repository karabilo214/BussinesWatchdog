<?php

namespace App\Console\Commands;

use App\Models\BillingSubscriptionContract;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleTransactions;
use Illuminate\Console\Command;

class SyncPaddleTransactions extends Command
{
    protected $signature = 'billing:paddle-transactions {--tenant= : Only this organisation}';

    protected $description = 'Read every transaction of the subscriptions of the current Paddle mode again (invoices and payments)';

    public function handle(PaddleTransactions $transactions): int
    {
        if (! PaddleConfig::configured()) {
            $this->line('Paddle is not configured.');

            return self::SUCCESS;
        }

        $contracts = BillingSubscriptionContract::query()
            ->where('provider', PaddleConfig::PROVIDER)->where('provider_account_ref', PaddleConfig::accountRef())->where('mode', PaddleConfig::mode())
            ->whereNotNull('provider_subscription_ref')
            ->when($this->option('tenant'), fn ($query, $tenant) => $query->where('tenant_id', $tenant))
            ->get();
        $total = 0;

        foreach ($contracts as $contract) {
            $total += $transactions->syncSubscription((string) $contract->provider_subscription_ref);
        }

        $this->line(sprintf('subscriptions=%d transactions=%d', $contracts->count(), $total));

        return self::SUCCESS;
    }
}
