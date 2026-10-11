<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE integration_credentials DROP CONSTRAINT integration_credentials_kind_check');
        DB::statement("ALTER TABLE integration_credentials ADD CONSTRAINT integration_credentials_kind_check CHECK (kind IN ('plugin_hmac','stripe_api','stripe_webhook','paypal_client','paypal_webhook','woocommerce_api','shopware_integration','shopware_app'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE integration_credentials DROP CONSTRAINT integration_credentials_kind_check');
        DB::statement("ALTER TABLE integration_credentials ADD CONSTRAINT integration_credentials_kind_check CHECK (kind IN ('plugin_hmac','stripe_api','stripe_webhook','paypal_client','paypal_webhook','woocommerce_api','shopware_integration'))");
    }
};
