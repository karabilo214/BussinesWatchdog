<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_prices', function (Blueprint $table) {
            $table->unsignedSmallInteger('billing_interval_count')->default(1);
        });

        Schema::table('billing_prices', function (Blueprint $table) {
            $table->dropUnique(['plan_version_id', 'price_kind', 'currency', 'billing_interval', 'mode']);
            $table->unique(['plan_version_id', 'price_kind', 'currency', 'billing_interval', 'billing_interval_count', 'mode'], 'billing_prices_version_kind_period_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE billing_prices DROP CONSTRAINT billing_prices_interval_check');
            DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_interval_check CHECK (billing_interval IN ('day','week','month','year'))");
            DB::statement('ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_interval_count_check CHECK (billing_interval_count BETWEEN 1 AND 365)');
            DB::statement('ALTER TABLE billing_plan_versions DROP CONSTRAINT billing_plan_versions_trial_check');
            DB::statement('ALTER TABLE billing_plan_versions ADD CONSTRAINT billing_plan_versions_trial_check CHECK (trial_days BETWEEN 0 AND 365)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE billing_plan_versions DROP CONSTRAINT billing_plan_versions_trial_check');
            DB::statement('ALTER TABLE billing_plan_versions ADD CONSTRAINT billing_plan_versions_trial_check CHECK (trial_days BETWEEN 0 AND 90)');
            DB::statement('ALTER TABLE billing_prices DROP CONSTRAINT billing_prices_interval_count_check');
            DB::statement('ALTER TABLE billing_prices DROP CONSTRAINT billing_prices_interval_check');
            DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_interval_check CHECK (billing_interval IN ('month','year'))");
        }

        Schema::table('billing_prices', function (Blueprint $table) {
            $table->dropUnique('billing_prices_version_kind_period_unique');
            $table->unique(['plan_version_id', 'price_kind', 'currency', 'billing_interval', 'mode']);
        });

        Schema::table('billing_prices', function (Blueprint $table) {
            $table->dropColumn('billing_interval_count');
        });
    }
};
