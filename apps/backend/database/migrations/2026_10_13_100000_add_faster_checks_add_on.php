<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_plan_versions', function (Blueprint $table) {
            $table->unsignedSmallInteger('faster_check_interval_minutes')->nullable();
        });

        Schema::table('billing_checkout_sessions', function (Blueprint $table) {
            $table->uuid('faster_checks_price_id')->nullable();
            $table->foreign('faster_checks_price_id')->references('id')->on('billing_prices');
        });

        Schema::table('billing_subscription_contracts', function (Blueprint $table) {
            $table->boolean('faster_checks')->default(false);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('faster_check_interval_minutes')->nullable();
        });

        Schema::table('billing_plan_changes', function (Blueprint $table) {
            $table->boolean('from_faster_checks')->default(false);
            $table->boolean('to_faster_checks')->default(false);
            $table->uuid('to_faster_checks_price_id')->nullable();
            $table->foreign('to_faster_checks_price_id')->references('id')->on('billing_prices');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE billing_prices DROP CONSTRAINT billing_prices_kind_check');
            DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_kind_check CHECK (price_kind IN ('base','extra_store','faster_checks'))");
            DB::statement('ALTER TABLE billing_plan_versions ADD CONSTRAINT billing_plan_versions_faster_check CHECK (faster_check_interval_minutes IS NULL OR faster_check_interval_minutes BETWEEN 1 AND 1440)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE billing_plan_versions DROP CONSTRAINT IF EXISTS billing_plan_versions_faster_check');
            DB::statement('ALTER TABLE billing_prices DROP CONSTRAINT billing_prices_kind_check');
            DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_kind_check CHECK (price_kind IN ('base','extra_store'))");
        }

        Schema::table('billing_plan_changes', function (Blueprint $table) {
            $table->dropForeign(['to_faster_checks_price_id']);
            $table->dropColumn(['from_faster_checks', 'to_faster_checks', 'to_faster_checks_price_id']);
        });
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('faster_check_interval_minutes'));
        Schema::table('billing_subscription_contracts', fn (Blueprint $table) => $table->dropColumn('faster_checks'));
        Schema::table('billing_checkout_sessions', function (Blueprint $table) {
            $table->dropForeign(['faster_checks_price_id']);
            $table->dropColumn('faster_checks_price_id');
        });
        Schema::table('billing_plan_versions', fn (Blueprint $table) => $table->dropColumn('faster_check_interval_minutes'));
    }
};
