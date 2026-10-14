<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observed_payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->text('transaction_ref');
            $table->text('order_ref');
            $table->text('payment_method');
            $table->timestampTz('attempted_at');
            $table->text('outcome');
            $table->text('failure_class')->nullable();
            $table->timestampTz('outcome_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['store_id', 'transaction_ref'], 'observed_payment_attempts_transaction_unique');
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->index(['outcome', 'attempted_at'], 'observed_payment_attempts_pending_idx');
            $table->index(['tenant_id', 'store_id', 'payment_method', 'outcome_at'], 'observed_payment_attempts_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observed_payment_attempts');
    }
};
