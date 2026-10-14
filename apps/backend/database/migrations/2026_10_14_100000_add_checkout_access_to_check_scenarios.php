<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_scenarios', function (Blueprint $table) {
            $table->text('checkout_access')->nullable();
            $table->text('sign_in_ciphertext')->nullable();
            $table->unsignedInteger('sign_in_key_version')->nullable();
        });

        DB::table('check_scenarios')
            ->whereIn('store_id', DB::table('stores')->where('platform', 'shopware')->select('id'))
            ->update(['adapter_version' => 'shopware-payment-form@1', 'checkout_access' => 'stop_before_sign_in']);
    }

    public function down(): void
    {
        DB::table('check_scenarios')
            ->whereIn('store_id', DB::table('stores')->where('platform', 'shopware')->select('id'))
            ->update(['adapter_version' => 'woocommerce-payment-form@1']);

        Schema::table('check_scenarios', function (Blueprint $table) {
            $table->dropColumn(['checkout_access', 'sign_in_ciphertext', 'sign_in_key_version']);
        });
    }
};
