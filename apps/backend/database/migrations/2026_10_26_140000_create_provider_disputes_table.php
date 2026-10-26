<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->uuid('payment_id')->nullable();
            $table->string('external_id', 255);
            $table->string('payment_external_id', 255);
            $table->string('charge_ref', 255)->nullable();
            $table->string('mode', 8);
            $table->string('currency', 3);
            $table->smallInteger('currency_exponent');
            $table->bigInteger('amount_minor');
            $table->string('status', 32);
            $table->string('reason', 64)->nullable();
            $table->timestampTz('evidence_due_by')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->char('incident_fingerprint', 64);
            $table->unsignedBigInteger('source_revision');
            $table->uuid('source_event_id')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['integration_id', 'external_id'], 'provider_disputes_integration_external_unique');
            $table->index(['tenant_id', 'store_id', 'status'], 'provider_disputes_store_status_idx');
            $table->index(['payment_id'], 'provider_disputes_payment_idx');
            $table->index(['tenant_id', 'incident_fingerprint'], 'provider_disputes_fingerprint_idx');
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->foreign(['tenant_id', 'store_id', 'integration_id'])->references(['tenant_id', 'store_id', 'id'])->on('integrations');
            $table->foreign(['tenant_id', 'store_id', 'payment_id'])->references(['tenant_id', 'store_id', 'id'])->on('payments');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE provider_disputes ADD CONSTRAINT provider_disputes_status_check CHECK (status IN ('needs_response','under_review','won','lost','warning_needs_response','warning_under_review','warning_closed','prevented'))");
            DB::statement("ALTER TABLE provider_disputes ADD CONSTRAINT provider_disputes_mode_check CHECK (mode IN ('live','test'))");
            DB::statement('ALTER TABLE provider_disputes ADD CONSTRAINT provider_disputes_amount_check CHECK (amount_minor >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_disputes');
    }
};
