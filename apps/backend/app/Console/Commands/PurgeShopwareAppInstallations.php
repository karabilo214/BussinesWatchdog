<?php

namespace App\Console\Commands;

use App\Support\Providers\Shopware\ShopwareAppInstallations;
use Illuminate\Console\Command;

class PurgeShopwareAppInstallations extends Command
{
    protected $signature = 'shopware:app-installations-purge';

    protected $description = 'Delete universal Shopware app installations that were not bound to a store within 7 days';

    public function handle(ShopwareAppInstallations $installations): int
    {
        $this->line('deleted='.$installations->pruneUnbound());

        return self::SUCCESS;
    }
}
