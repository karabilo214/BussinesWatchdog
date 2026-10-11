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
            $table->text('provider_product_ref')->nullable();
        });

        Schema::create('billing_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('provider');
            $table->text('provider_account_ref');
            $table->text('provider_customer_ref');
            $table->text('mode');
            $table->timestampTz('source_updated_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'provider', 'provider_account_ref', 'mode']);
            $table->unique(['provider', 'provider_account_ref', 'mode', 'provider_customer_ref']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('billing_subscription_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id')->nullable();
            $table->uuid('plan_version_id')->nullable();
            $table->uuid('price_id')->nullable();
            $table->text('provider');
            $table->text('provider_account_ref');
            $table->text('provider_subscription_ref');
            $table->text('mode');
            $table->text('normalized_state');
            $table->text('provider_state')->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('extra_store_quantity')->default(0);
            $table->jsonb('entitlement_snapshot');
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->timestampTz('paid_until')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('next_charge_at')->nullable();
            $table->text('problem_code')->nullable();
            $table->timestampTz('source_updated_at');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['provider', 'provider_account_ref', 'mode', 'provider_subscription_ref']);
            $table->index(['tenant_id', 'normalized_state']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('customer_id')->references('id')->on('billing_customers');
            $table->foreign('plan_version_id')->references('id')->on('billing_plan_versions');
            $table->foreign('price_id')->references('id')->on('billing_prices');
        });

        Schema::create('billing_checkout_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('actor_user_id');
            $table->uuid('contract_id')->nullable();
            $table->uuid('plan_version_id');
            $table->uuid('price_id');
            $table->uuid('extra_store_price_id')->nullable();
            $table->unsignedInteger('extra_store_quantity')->default(0);
            $table->text('provider');
            $table->text('provider_account_ref');
            $table->text('provider_session_ref')->nullable();
            $table->text('mode');
            $table->text('status');
            $table->text('error_code')->nullable();
            $table->text('idempotency_key');
            $table->char('request_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['provider', 'provider_account_ref', 'mode', 'provider_session_ref']);
            $table->index(['status', 'expires_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('actor_user_id')->references('id')->on('users');
            $table->foreign('contract_id')->references('id')->on('billing_subscription_contracts');
            $table->foreign('plan_version_id')->references('id')->on('billing_plan_versions');
            $table->foreign('price_id')->references('id')->on('billing_prices');
            $table->foreign('extra_store_price_id')->references('id')->on('billing_prices');
        });

        Schema::create('billing_event_inbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('provider');
            $table->text('provider_account_ref');
            $table->text('mode');
            $table->text('provider_event_ref');
            $table->text('event_type');
            $table->text('entity_type');
            $table->text('entity_ref');
            $table->uuid('tenant_id')->nullable();
            $table->jsonb('sanitized_payload');
            $table->char('payload_hash', 64);
            $table->text('api_version');
            $table->text('source');
            $table->text('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->useCurrent();
            $table->timestampTz('lease_until')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->text('error_code')->nullable();

            $table->unique(['provider', 'provider_account_ref', 'mode', 'provider_event_ref']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('billing_sync_cursors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('provider');
            $table->text('provider_account_ref');
            $table->text('mode');
            $table->text('cursor')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->text('last_error_code')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['provider', 'provider_account_ref', 'mode']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE billing_customers ADD CONSTRAINT billing_customers_mode_check CHECK (mode IN ('live','test'))");
        DB::statement("ALTER TABLE billing_subscription_contracts ADD CONSTRAINT billing_contracts_mode_check CHECK (mode IN ('live','test'))");
        DB::statement("ALTER TABLE billing_subscription_contracts ADD CONSTRAINT billing_contracts_state_check CHECK (normalized_state IN ('trial','pending','requires_action','active','past_due','unpaid','cancelled','unknown'))");
        DB::statement('ALTER TABLE billing_subscription_contracts ADD CONSTRAINT billing_contracts_period_check CHECK (period_end IS NULL OR period_start IS NULL OR period_end >= period_start)');
        DB::statement("ALTER TABLE billing_checkout_sessions ADD CONSTRAINT billing_checkout_mode_check CHECK (mode IN ('live','test'))");
        DB::statement("ALTER TABLE billing_checkout_sessions ADD CONSTRAINT billing_checkout_status_check CHECK (status IN ('creating','pending','requires_action','confirmed','failed','cancelled','expired','unknown'))");
        DB::statement("ALTER TABLE billing_event_inbox ADD CONSTRAINT billing_inbox_mode_check CHECK (mode IN ('live','test'))");
        DB::statement("ALTER TABLE billing_event_inbox ADD CONSTRAINT billing_inbox_status_check CHECK (status IN ('received','processing','processed','ignored','quarantined','dead_letter'))");
        DB::statement("ALTER TABLE billing_event_inbox ADD CONSTRAINT billing_inbox_source_check CHECK (source IN ('webhook','events_api'))");
        DB::statement("ALTER TABLE billing_sync_cursors ADD CONSTRAINT billing_sync_cursors_mode_check CHECK (mode IN ('live','test'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_sync_cursors');
        Schema::dropIfExists('billing_event_inbox');
        Schema::dropIfExists('billing_checkout_sessions');
        Schema::dropIfExists('billing_subscription_contracts');
        Schema::dropIfExists('billing_customers');

        Schema::table('billing_prices', function (Blueprint $table) {
            $table->dropColumn('provider_product_ref');
        });
    }
};
