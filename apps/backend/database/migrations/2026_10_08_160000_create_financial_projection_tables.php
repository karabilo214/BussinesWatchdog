<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->uuid('order_id');
            $table->text('external_id');
            $table->unsignedBigInteger('source_revision');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('currency_exponent');
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('external_required')->nullable();
            $table->text('provider_ref')->nullable();
            $table->text('status');
            $table->timestampTz('occurred_at');
            $table->char('current_payload_hash', 64);
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['integration_id', 'external_id']);
            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'integration_id'])->references(['tenant_id', 'store_id', 'id'])->on('integrations');
            $table->foreign(['tenant_id', 'store_id', 'order_id'])->references(['tenant_id', 'store_id', 'id'])->on('orders');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->text('external_id');
            $table->text('intent_ref')->nullable();
            $table->text('charge_ref')->nullable();
            $table->text('mode');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('currency_exponent');
            $table->text('status');
            $table->text('source_authority');
            $table->timestampTz('source_updated_at');
            $table->char('current_payload_hash', 64);
            $metadataDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('metadata')->default($metadataDefault);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['integration_id', 'external_id']);
            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'integration_id'])->references(['tenant_id', 'store_id', 'id'])->on('integrations');
            $table->index(['store_id', 'intent_ref', 'charge_ref'], 'payments_refs_idx');
        });

        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->uuid('payment_id')->nullable();
            $table->text('external_operation_id');
            $table->text('kind');
            $table->text('status');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('currency_exponent');
            $table->unsignedBigInteger('amount_minor');
            $table->timestampTz('occurred_at');
            $table->uuid('source_event_id')->nullable();
            $table->text('source_authority');
            $table->char('operation_hash', 64);
            $metadataDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('metadata')->default($metadataDefault);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['integration_id', 'kind', 'external_operation_id']);
            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'integration_id'])->references(['tenant_id', 'store_id', 'id'])->on('integrations');
            $table->foreign(['tenant_id', 'store_id', 'payment_id'])->references(['tenant_id', 'store_id', 'id'])->on('payments');
            $table->foreign('source_event_id')->references('id')->on('event_inbox')->nullOnDelete();
            $table->index(['tenant_id', 'store_id', 'occurred_at'], 'transactions_window_idx');
            $table->index(['payment_id', 'kind', 'status'], 'transactions_payment_idx');
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('payment_id');
            $table->uuid('capture_transaction_id');
            $table->uuid('order_id');
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->text('strategy');
            $evidenceDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('evidence')->default($evidenceDefault);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'payment_id'])->references(['tenant_id', 'store_id', 'id'])->on('payments');
            $table->foreign(['tenant_id', 'store_id', 'capture_transaction_id'])->references(['tenant_id', 'store_id', 'id'])->on('financial_transactions');
            $table->foreign(['tenant_id', 'store_id', 'order_id'])->references(['tenant_id', 'store_id', 'id'])->on('orders');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['order_id'], 'allocations_order_idx');
        });

        Schema::create('refund_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('refund_id');
            $table->uuid('refund_transaction_id');
            $table->uuid('payment_allocation_id');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->text('strategy');
            $evidenceDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('evidence')->default($evidenceDefault);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'refund_id'])->references(['tenant_id', 'store_id', 'id'])->on('refunds');
            $table->foreign(['tenant_id', 'store_id', 'refund_transaction_id'])->references(['tenant_id', 'store_id', 'id'])->on('financial_transactions');
            $table->foreign(['tenant_id', 'store_id', 'payment_allocation_id'])->references(['tenant_id', 'store_id', 'id'])->on('payment_allocations');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_currency_exponent_check CHECK (currency_exponent BETWEEN 0 AND 6)');
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('requested','recorded','cancelled','deleted'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_mode_check CHECK (mode IN ('live','test'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_currency_exponent_check CHECK (currency_exponent BETWEEN 0 AND 6)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','authorized','captured','failed','cancelled','unknown'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_source_authority_check CHECK (source_authority IN ('store_reported','independent_provider'))");
            DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_currency_exponent_check CHECK (currency_exponent BETWEEN 0 AND 6)');
            DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_kind_check CHECK (kind IN ('capture','refund','fee','dispute_debit','dispute_credit','adjustment'))");
            DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_status_check CHECK (status IN ('pending','succeeded','failed','cancelled'))");
            DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transactions_source_authority_check CHECK (source_authority IN ('store_reported','independent_provider'))");
            DB::statement("ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_strategy_check CHECK (strategy IN ('exact_reference','verified_metadata','manual'))");
            DB::statement("CREATE UNIQUE INDEX active_capture_order_allocation ON payment_allocations(capture_transaction_id,order_id) WHERE revoked_at IS NULL");
            DB::statement("CREATE INDEX active_allocations_order_idx ON payment_allocations(order_id) WHERE revoked_at IS NULL");
            DB::statement("ALTER TABLE refund_allocations ADD CONSTRAINT refund_allocations_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE refund_allocations ADD CONSTRAINT refund_allocations_strategy_check CHECK (strategy IN ('exact_reference','verified_metadata','manual'))");
            DB::statement("CREATE UNIQUE INDEX active_refund_allocation ON refund_allocations(refund_id,refund_transaction_id,payment_allocation_id) WHERE revoked_at IS NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_allocations');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('refunds');
    }
};
