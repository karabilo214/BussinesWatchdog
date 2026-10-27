<?php

namespace App\Console\Commands;

use App\Support\Providers\Shopware\ShopwareStoreApp;
use Illuminate\Console\Command;

class BuildShopwareStoreApp extends Command
{
    protected $signature = 'shopware:app-store-package {directory : Where to write the ZIP}';

    protected $description = 'Build the Shopware Store version of the universal app (technical name from WATCHDOG_SHOPWARE_STORE_APP_NAME, no secret in the manifest)';

    public function handle(ShopwareStoreApp $app): int
    {
        if (ShopwareStoreApp::name() === null) {
            $this->error('WATCHDOG_SHOPWARE_STORE_APP_NAME is not set: the technical name from the Shopware account, starting with the extension partner prefix');

            return self::FAILURE;
        }

        $directory = rtrim((string) $this->argument('directory'), '/');

        if (! is_dir($directory)) {
            $this->error('directory_missing: '.$directory);

            return self::FAILURE;
        }

        $package = $app->package();
        $path = $directory.'/'.$package['filename'];

        if (file_put_contents($path, $package['archive']) === false) {
            $this->error('write_failed: '.$path);

            return self::FAILURE;
        }

        $this->line('file='.$path);
        $this->line('sha256='.hash_file('sha256', $path));

        return self::SUCCESS;
    }
}
