<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plan_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contract_id');
            $table->uuid('actor_user_id')->nullable();
            $table->text('kind');
            $table->text('status');
            $table->uuid('from_price_id');
            $table->unsignedInteger('from_extra_store_quantity');
            $table->uuid('to_plan_version_id');
            $table->uuid('to_price_id');
            $table->uuid('to_extra_store_price_id')->nullable();
            $table->unsignedInteger('to_extra_store_quantity');
            $table->json('retained_store_ids')->nullable();
            $table->string('currency', 3);
            $table->smallInteger('currency_exponent');
            $table->bigInteger('due_now_minor');
            $table->bigInteger('next_amount_before_minor');
            $table->bigInteger('next_amount_minor');
            $table->unsignedInteger('contract_version');
            $table->timestampTz('preview_expires_at');
            $table->timestampTz('effective_at')->nullable();
            $table->text('idempotency_key')->nullable();
            $table->text('error_code')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('discounts_settled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['contract_id', 'status']);
            $table->index(['status', 'effective_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('contract_id')->references('id')->on('billing_subscription_contracts');
            $table->foreign('from_price_id')->references('id')->on('billing_prices');
            $table->foreign('to_plan_version_id')->references('id')->on('billing_plan_versions');
            $table->foreign('to_price_id')->references('id')->on('billing_prices');
            $table->foreign('to_extra_store_price_id')->references('id')->on('billing_prices');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->timestampTz('capacity_paused_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE billing_plan_changes ADD CONSTRAINT billing_plan_changes_kind_check CHECK (kind IN ('immediate','next_period'))");
            DB::statement("ALTER TABLE billing_plan_changes ADD CONSTRAINT billing_plan_changes_status_check CHECK (status IN ('previewed','applying','applied','scheduled','failed','unknown','cancelled','expired'))");
            DB::statement('ALTER TABLE billing_plan_changes ADD CONSTRAINT billing_plan_changes_amount_check CHECK (due_now_minor >= 0 AND next_amount_minor >= 0 AND next_amount_before_minor >= next_amount_minor)');
            DB::statement("CREATE UNIQUE INDEX billing_plan_changes_one_scheduled ON billing_plan_changes (contract_id) WHERE status = 'scheduled'");
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('capacity_paused_at');
        });
        Schema::dropIfExists('billing_plan_changes');
    }
};
