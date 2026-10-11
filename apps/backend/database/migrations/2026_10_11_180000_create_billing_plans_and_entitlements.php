<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('code')->unique();
            $table->text('state');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('billing_plan_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plan_id');
            $table->unsignedBigInteger('version');
            $table->text('state');
            $table->jsonb('localized_content');
            $table->jsonb('entitlements');
            $table->integer('trial_days')->default(14);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['plan_id', 'version']);
            $table->foreign('plan_id')->references('id')->on('billing_plans');
        });

        Schema::create('billing_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plan_version_id');
            $table->text('provider');
            $table->text('provider_account_ref');
            $table->text('provider_price_ref')->nullable();
            $table->text('price_kind')->default('base');
            $table->text('mode');
            $table->string('currency', 3);
            $table->smallInteger('currency_exponent');
            $table->bigInteger('amount_minor');
            $table->text('billing_interval');
            $table->text('tax_behavior');
            $table->text('state');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['provider', 'provider_account_ref', 'mode', 'provider_price_ref']);
            $table->unique(['plan_version_id', 'price_kind', 'currency', 'billing_interval', 'mode']);
            $table->index(['state', 'mode', 'currency', 'billing_interval'], 'billing_prices_public_idx');
            $table->foreign('plan_version_id')->references('id')->on('billing_plan_versions');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->text('provider');
            $table->text('provider_customer_ref')->nullable();
            $table->text('provider_subscription_ref')->nullable();
            $table->text('plan_code');
            $table->uuid('plan_version_id')->nullable();
            $table->jsonb('entitlements');
            $table->unsignedInteger('extra_store_quantity')->default(0);
            $table->text('status');
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('past_due_since')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('entitlement_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->jsonb('entitlements');
            $table->text('reason');
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'starts_at', 'ends_at'], 'overrides_active_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE billing_plans ADD CONSTRAINT billing_plans_state_check CHECK (state IN ('draft','published','retired'))");
        DB::statement("ALTER TABLE billing_plan_versions ADD CONSTRAINT billing_plan_versions_state_check CHECK (state IN ('draft','published','retired'))");
        DB::statement('ALTER TABLE billing_plan_versions ADD CONSTRAINT billing_plan_versions_version_check CHECK (version > 0)');
        DB::statement('ALTER TABLE billing_plan_versions ADD CONSTRAINT billing_plan_versions_trial_check CHECK (trial_days BETWEEN 0 AND 90)');
        DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_mode_check CHECK (mode IN ('live','test'))");
        DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_exponent_check CHECK (currency_exponent BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_amount_check CHECK (amount_minor >= 0)');
        DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_interval_check CHECK (billing_interval IN ('month','year'))");
        DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_tax_check CHECK (tax_behavior IN ('inclusive','exclusive','provider_calculated'))");
        DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_state_check CHECK (state IN ('draft','published','retired'))");
        DB::statement("ALTER TABLE billing_prices ADD CONSTRAINT billing_prices_kind_check CHECK (price_kind IN ('base','extra_store'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trial','active','past_due','suspended','cancelled'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_period_check CHECK (period_end IS NULL OR period_start IS NULL OR period_end >= period_start)');
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_version_check CHECK (version > 0)');
        DB::statement('ALTER TABLE entitlement_overrides ADD CONSTRAINT entitlement_overrides_interval_check CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_overrides');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('billing_prices');
        Schema::dropIfExists('billing_plan_versions');
        Schema::dropIfExists('billing_plans');
    }
};
