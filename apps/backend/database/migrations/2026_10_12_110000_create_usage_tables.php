<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_buckets', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->text('metric_key');
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->bigInteger('value')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['tenant_id', 'metric_key', 'period_start']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('usage_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('metric_key');
            $table->timestampTz('period_start');
            $table->text('operation_key');
            $table->bigInteger('delta');
            $table->text('reason_code');
            $table->timestampTz('occurred_at');

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'metric_key', 'operation_key']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usage_buckets ADD CONSTRAINT usage_buckets_value_check CHECK (value >= 0)');
            DB::statement('ALTER TABLE usage_buckets ADD CONSTRAINT usage_buckets_period_check CHECK (period_end > period_start)');
            DB::statement('ALTER TABLE usage_entries ADD CONSTRAINT usage_entries_delta_check CHECK (delta <> 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_entries');
        Schema::dropIfExists('usage_buckets');
    }
};
