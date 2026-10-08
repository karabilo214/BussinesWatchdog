<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->text('external_id');
            $table->text('display_number');
            $table->unsignedBigInteger('source_revision');
            $table->text('status');
            $table->text('gateway')->nullable();
            $table->text('mode')->default('live');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('currency_exponent');
            $table->unsignedBigInteger('total_minor');
            $table->boolean('payment_expected');
            $table->timestampTz('paid_marked_at')->nullable();
            $table->text('transaction_ref')->nullable();
            $table->text('financial_support')->default('unknown');
            $table->boolean('is_synthetic')->default(false);
            $table->timestampTz('source_created_at');
            $table->timestampTz('source_updated_at');
            $table->timestampTz('deleted_at')->nullable();
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
            $table->index(['tenant_id', 'store_id', 'source_updated_at'], 'orders_recent_idx');
            $table->index(['store_id', 'gateway', 'transaction_ref'], 'orders_transaction_ref_idx');
        });

        Schema::create('order_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('order_id');
            $table->uuid('event_id')->nullable();
            $table->unsignedBigInteger('source_revision');
            $snapshotDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('snapshot')->default($snapshotDefault);
            $table->char('payload_hash', 64);
            $table->timestampTz('observed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['order_id', 'source_revision']);
            $table->foreign(['tenant_id', 'store_id', 'order_id'])->references(['tenant_id', 'store_id', 'id'])->on('orders');
            $table->foreign('event_id')->references('id')->on('event_inbox')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_mode_check CHECK (mode IN ('live','test'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_currency_exponent_check CHECK (currency_exponent BETWEEN 0 AND 6)');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_financial_support_check CHECK (financial_support IN ('supported','unsupported','unknown'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_revisions');
        Schema::dropIfExists('orders');
    }
};
