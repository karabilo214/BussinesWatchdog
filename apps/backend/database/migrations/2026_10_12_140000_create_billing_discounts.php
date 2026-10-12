<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('provider');
            $table->text('provider_account_ref');
            $table->text('mode');
            $table->text('provider_discount_ref')->nullable();
            $table->text('name');
            $table->jsonb('label');
            $table->text('kind');
            $table->unsignedInteger('percent_bps')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->smallInteger('currency_exponent')->nullable();
            $table->text('audience');
            $table->text('code')->nullable();
            $table->text('duration');
            $table->unsignedSmallInteger('duration_periods')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->jsonb('plan_ids');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->text('state')->default('active');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
            $table->timestampTz('archived_at')->nullable();

            $table->unique(['provider', 'provider_account_ref', 'mode', 'provider_discount_ref'], 'billing_discounts_provider_unique');
            $table->unique(['mode', 'code'], 'billing_discounts_code_unique');
            $table->index(['mode', 'state', 'audience'], 'billing_discounts_lookup_idx');
            $table->foreign('created_by')->references('id')->on('platform_staff');
        });

        Schema::create('billing_discount_tenants', function (Blueprint $table) {
            $table->uuid('discount_id');
            $table->uuid('tenant_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['discount_id', 'tenant_id']);
            $table->index('tenant_id');
            $table->foreign('discount_id')->references('id')->on('billing_discounts');
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::table('billing_checkout_sessions', function (Blueprint $table) {
            $table->uuid('discount_id')->nullable();
            $table->foreign('discount_id')->references('id')->on('billing_discounts');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE billing_discounts ADD CONSTRAINT billing_discounts_mode_check CHECK (mode IN ('live','test'))");
            DB::statement("ALTER TABLE billing_discounts ADD CONSTRAINT billing_discounts_kind_check CHECK ((kind = 'percentage' AND percent_bps BETWEEN 1 AND 10000 AND amount_minor IS NULL AND currency IS NULL) OR (kind = 'flat' AND percent_bps IS NULL AND amount_minor > 0 AND currency ~ '^[A-Z]{3}$'))");
            DB::statement("ALTER TABLE billing_discounts ADD CONSTRAINT billing_discounts_audience_check CHECK ((audience = 'code' AND code IS NOT NULL) OR (audience IN ('tenant','automatic') AND code IS NULL))");
            DB::statement("ALTER TABLE billing_discounts ADD CONSTRAINT billing_discounts_duration_check CHECK ((duration = 'periods' AND duration_periods BETWEEN 1 AND 1000) OR (duration IN ('once','forever') AND duration_periods IS NULL))");
            DB::statement('ALTER TABLE billing_discounts ADD CONSTRAINT billing_discounts_usage_check CHECK (usage_limit IS NULL OR usage_limit > 0)');
            DB::statement('ALTER TABLE billing_discounts ADD CONSTRAINT billing_discounts_window_check CHECK (starts_at IS NULL OR expires_at IS NULL OR expires_at > starts_at)');
            DB::statement("ALTER TABLE billing_discounts ADD CONSTRAINT billing_discounts_state_check CHECK (state IN ('active','archived'))");
        }
    }

    public function down(): void
    {
        Schema::table('billing_checkout_sessions', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
            $table->dropColumn('discount_id');
        });
        Schema::dropIfExists('billing_discount_tenants');
        Schema::dropIfExists('billing_discounts');
    }
};
