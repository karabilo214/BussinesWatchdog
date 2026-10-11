<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_object_states', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->default(0);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE integration_credentials DROP CONSTRAINT integration_credentials_kind_check');
        DB::statement("ALTER TABLE integration_credentials ADD CONSTRAINT integration_credentials_kind_check CHECK (kind IN ('plugin_hmac','stripe_api','stripe_webhook','paypal_client','paypal_webhook','woocommerce_api'))");
    }

    public function down(): void
    {
        Schema::table('provider_object_states', function (Blueprint $table) {
            $table->dropColumn('revision');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE integration_credentials DROP CONSTRAINT integration_credentials_kind_check');
        DB::statement("ALTER TABLE integration_credentials ADD CONSTRAINT integration_credentials_kind_check CHECK (kind IN ('plugin_hmac','stripe_api','stripe_webhook','paypal_client','paypal_webhook'))");
    }
};
