<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempt_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->uuid('event_id')->nullable();
            $table->text('payment_method');
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->unsignedInteger('paid')->default(0);
            $table->unsignedInteger('on_hold')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('pending_stuck')->default(0);
            $table->unsignedInteger('late_success')->default(0);
            $table->unsignedInteger('rejected_before_order')->default(0);
            $table->unsignedInteger('trailing_failures')->default(0);
            $jsonDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('failure_classes')->default($jsonDefault);
            $table->unsignedBigInteger('source_revision');
            $table->text('payload_hash');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'store_id', 'payment_method', 'window_start'], 'payment_attempt_windows_method_window_unique');
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->foreign('integration_id')->references('id')->on('integrations');
            $table->index(['tenant_id', 'store_id', 'payment_method', 'window_start'], 'payment_attempt_windows_lookup_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_attempt_windows ADD CONSTRAINT payment_attempt_windows_range_check CHECK (window_end > window_start)');
            DB::statement('ALTER TABLE payment_attempt_windows ADD CONSTRAINT payment_attempt_windows_trailing_check CHECK (trailing_failures <= failed + pending_stuck)');
            DB::statement('ALTER TABLE payment_attempt_windows ADD CONSTRAINT payment_attempt_windows_scoped_event_foreign FOREIGN KEY (tenant_id, store_id, event_id) REFERENCES event_inbox(tenant_id, store_id, id) ON DELETE SET NULL (event_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempt_windows');
    }
};
