<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_inbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->text('provider_event_id');
            $table->text('schema_version');
            $table->text('event_type');
            $table->text('aggregate_type');
            $table->text('aggregate_external_id');
            $table->unsignedBigInteger('aggregate_revision')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('observed_at');
            $table->timestampTz('received_at')->useCurrent();
            $table->boolean('is_synthetic')->default(false);
            $payloadDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('payload')->default($payloadDefault);
            $table->char('payload_hash', 64);
            $table->unsignedInteger('canonicalization_version')->default(1);
            $table->text('status')->default('received');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->useCurrent();
            $table->timestampTz('processing_lease_until')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->text('error_code')->nullable();
            $table->uuid('request_id');

            $table->unique(['integration_id', 'provider_event_id']);
            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'integration_id'])->references(['tenant_id', 'store_id', 'id'])->on('integrations');
            $table->index(['tenant_id', 'integration_id', 'received_at'], 'inbox_history_idx');
            $table->index(['next_attempt_at', 'received_at'], 'inbox_pending_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE event_inbox ADD CONSTRAINT event_inbox_status_check CHECK (status IN ('received','processing','processed','quarantined','dead_letter'))");
            DB::statement('ALTER TABLE event_inbox ADD CONSTRAINT event_inbox_attempt_count_check CHECK (attempt_count >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_inbox');
    }
};
