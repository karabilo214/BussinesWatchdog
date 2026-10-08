<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->text('status');
            $table->text('algorithm_version');
            $table->unsignedBigInteger('config_version');
            $table->string('currency', 3)->nullable();
            $scopeDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('scope')->default($scopeDefault);
            $table->jsonb('coverage_snapshot')->default($scopeDefault);
            $countersDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('counters')->default($countersDefault);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('error_code')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
        });

        Schema::create('reconciliation_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('run_id');
            $table->uuid('order_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->text('rule_code');
            $table->text('status');
            $table->text('reason_code');
            $table->string('currency', 3)->nullable();
            $table->unsignedSmallInteger('currency_exponent')->nullable();
            $table->bigInteger('expected_minor')->nullable();
            $table->bigInteger('actual_minor')->nullable();
            $table->bigInteger('difference_minor')->nullable();
            $table->bigInteger('gross_minor')->nullable();
            $table->bigInteger('captured_minor')->nullable();
            $table->bigInteger('refund_expected_minor')->nullable();
            $table->bigInteger('refund_actual_minor')->nullable();
            $evidenceDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('evidence')->default($evidenceDefault);
            $table->jsonb('config_snapshot')->default($evidenceDefault);
            $table->timestampTz('evaluated_at');

            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'run_id'])->references(['tenant_id', 'store_id', 'id'])->on('reconciliation_runs');
            $table->foreign(['tenant_id', 'store_id', 'order_id'])->references(['tenant_id', 'store_id', 'id'])->on('orders');
            $table->foreign(['tenant_id', 'store_id', 'payment_id'])->references(['tenant_id', 'store_id', 'id'])->on('payments');
            $table->index(['tenant_id', 'store_id', 'status', 'evaluated_at'], 'findings_recent_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_status_check CHECK (status IN ('queued','running','completed','failed','cancelled'))");
            DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_currency_check CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_timing_check CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at >= started_at)');
            DB::statement("ALTER TABLE reconciliation_findings ADD CONSTRAINT reconciliation_findings_status_check CHECK (status IN ('ok','pending','mismatch','unsupported','unknown'))");
            DB::statement("ALTER TABLE reconciliation_findings ADD CONSTRAINT reconciliation_findings_currency_check CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE reconciliation_findings ADD CONSTRAINT reconciliation_findings_currency_exponent_check CHECK (currency_exponent IS NULL OR currency_exponent BETWEEN 0 AND 6)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_findings');
        Schema::dropIfExists('reconciliation_runs');
    }
};
