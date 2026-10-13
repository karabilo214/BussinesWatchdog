<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('transaction_id');
            $table->text('mode');
            $table->text('kind');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('currency_exponent');
            $table->text('reason');
            $table->boolean('cancel_subscription')->default(false);
            $table->text('status');
            $table->text('provider_adjustment_ref')->nullable();
            $table->text('provider_status')->nullable();
            $table->bigInteger('tax_minor')->nullable();
            $table->text('error_code')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('source_updated_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['mode', 'provider_adjustment_ref'], 'billing_refunds_adjustment_unique');
            $table->index(['status', 'created_at']);
            $table->index('transaction_id');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('transaction_id')->references('id')->on('billing_transactions');
            $table->foreign('requested_by')->references('id')->on('platform_staff');
            $table->foreign('decided_by')->references('id')->on('platform_staff');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE billing_refunds ADD CONSTRAINT billing_refunds_status_check CHECK (status IN ('requested','rejected','submitting','pending','refunded','declined','failed','unknown'))");
            DB::statement("ALTER TABLE billing_refunds ADD CONSTRAINT billing_refunds_kind_check CHECK (kind IN ('full','partial'))");
            DB::statement('ALTER TABLE billing_refunds ADD CONSTRAINT billing_refunds_amount_check CHECK (amount_minor > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_refunds');
    }
};
