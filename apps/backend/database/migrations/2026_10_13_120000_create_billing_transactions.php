<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contract_id')->nullable();
            $table->text('provider');
            $table->text('provider_account_ref');
            $table->text('mode');
            $table->text('provider_transaction_ref');
            $table->text('provider_subscription_ref')->nullable();
            $table->text('status');
            $table->text('origin')->nullable();
            $table->text('invoice_number')->nullable();
            $table->string('currency', 3);
            $table->unsignedSmallInteger('currency_exponent');
            $table->bigInteger('subtotal_minor')->nullable();
            $table->bigInteger('discount_minor')->nullable();
            $table->bigInteger('tax_minor')->nullable();
            $table->bigInteger('total_minor')->nullable();
            $table->bigInteger('fee_minor')->nullable();
            $table->bigInteger('earnings_minor')->nullable();
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->timestampTz('billed_at')->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('source_updated_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['provider', 'provider_account_ref', 'mode', 'provider_transaction_ref'], 'billing_transactions_provider_unique');
            $table->index(['tenant_id', 'billed_at']);
            $table->index(['mode', 'status', 'billed_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('contract_id')->references('id')->on('billing_subscription_contracts');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE billing_transactions ADD CONSTRAINT billing_transactions_status_check CHECK (status IN ('draft','ready','billed','paid','completed','canceled','past_due'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_transactions');
    }
};
