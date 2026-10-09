<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $jsonDefault = fn (string $value) => DB::getDriverName() === 'pgsql' ? DB::raw("'{$value}'::jsonb") : $value;

        Schema::create('browser_workers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name')->unique();
            $table->char('token_hash', 64)->unique();
            $table->text('status')->default('active');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('revoked_at')->nullable();
        });

        Schema::create('check_scenarios', function (Blueprint $table) use ($jsonDefault) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->text('name');
            $table->text('mode')->default('payment_form');
            $table->unsignedBigInteger('version');
            $table->boolean('enabled')->default(false);
            $table->text('adapter_version');
            $table->jsonb('definition')->default($jsonDefault('{}'));
            $table->text('product_external_id')->nullable();
            $table->unsignedInteger('interval_seconds')->default(900);
            $table->timestampTz('next_due_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->unique(['store_id', 'mode'], 'check_scenarios_store_mode_unique');
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->index(['enabled', 'next_due_at'], 'check_scenarios_due_idx');
        });

        Schema::create('check_runs', function (Blueprint $table) use ($jsonDefault) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('scenario_id');
            $table->unsignedBigInteger('scenario_version');
            $table->text('trigger');
            $table->text('dedupe_key');
            $table->text('status')->default('queued');
            $table->jsonb('config_snapshot')->default($jsonDefault('{}'));
            $table->timestampTz('scheduled_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedBigInteger('last_fencing_token')->default(0);
            $table->timestampTz('next_attempt_at');
            $table->text('error_code')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['store_id', 'dedupe_key']);
            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'scenario_id'])->references(['tenant_id', 'store_id', 'id'])->on('check_scenarios');
            $table->index(['store_id', 'created_at'], 'check_runs_store_idx');
        });

        Schema::create('check_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('run_id');
            $table->unsignedInteger('attempt_number');
            $table->uuid('worker_id');
            $table->unsignedBigInteger('fencing_token');
            $table->char('lease_token_hash', 64);
            $table->timestampTz('lease_until');
            $table->timestampTz('absolute_deadline_at');
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->text('status');
            $table->text('browser_version');
            $table->text('location');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->text('error_code')->nullable();
            $table->jsonb('sanitized_error')->nullable();

            $table->unique(['run_id', 'attempt_number']);
            $table->unique(['run_id', 'fencing_token']);
            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'run_id'])->references(['tenant_id', 'store_id', 'id'])->on('check_runs');
            $table->foreign('worker_id')->references('id')->on('browser_workers');
        });

        Schema::create('check_steps', function (Blueprint $table) use ($jsonDefault) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('attempt_id');
            $table->unsignedInteger('step_index');
            $table->text('step_code');
            $table->text('status');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');
            $table->jsonb('assertions')->default($jsonDefault('[]'));
            $table->jsonb('network_summary')->default($jsonDefault('[]'));
            $table->text('error_code')->nullable();

            $table->unique(['attempt_id', 'step_index']);
            $table->foreign(['tenant_id', 'store_id', 'attempt_id'])->references(['tenant_id', 'store_id', 'id'])->on('check_attempts');
        });

        DB::statement("CREATE UNIQUE INDEX one_active_scenario_run ON check_runs(scenario_id) WHERE status IN ('queued','running')");
        DB::statement("CREATE UNIQUE INDEX one_active_store_run ON check_runs(store_id) WHERE status IN ('queued','running')");
        DB::statement("CREATE INDEX checks_due_idx ON check_runs(next_attempt_at, scheduled_at) WHERE status = 'queued'");
        DB::statement("CREATE INDEX attempt_lease_idx ON check_attempts(lease_until) WHERE status = 'running'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE browser_workers ADD CONSTRAINT browser_workers_status_check CHECK (status IN ('active','revoked'))");
            DB::statement("ALTER TABLE check_scenarios ADD CONSTRAINT check_scenarios_mode_check CHECK (mode = 'payment_form')");
            DB::statement('ALTER TABLE check_scenarios ADD CONSTRAINT check_scenarios_version_check CHECK (version > 0)');
            DB::statement('ALTER TABLE check_scenarios ADD CONSTRAINT check_scenarios_interval_check CHECK (interval_seconds BETWEEN 300 AND 86400)');
            DB::statement("ALTER TABLE check_runs ADD CONSTRAINT check_runs_trigger_check CHECK (trigger IN ('scheduled','manual','incident'))");
            DB::statement("ALTER TABLE check_runs ADD CONSTRAINT check_runs_status_check CHECK (status IN ('queued','running','passed','failed','inconclusive','blocked','unsupported','cancelled'))");
            DB::statement('ALTER TABLE check_runs ADD CONSTRAINT check_runs_finished_check CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at >= started_at)');
            DB::statement('ALTER TABLE check_attempts ADD CONSTRAINT check_attempts_number_check CHECK (attempt_number > 0 AND fencing_token > 0)');
            DB::statement("ALTER TABLE check_attempts ADD CONSTRAINT check_attempts_status_check CHECK (status IN ('running','passed','failed','inconclusive','blocked','unsupported','cancelled','expired'))");
            DB::statement('ALTER TABLE check_attempts ADD CONSTRAINT check_attempts_deadline_check CHECK (absolute_deadline_at >= started_at AND (finished_at IS NULL OR finished_at >= started_at))');
            DB::statement("ALTER TABLE check_steps ADD CONSTRAINT check_steps_status_check CHECK (status IN ('passed','failed','skipped','inconclusive','blocked'))");
            DB::statement('ALTER TABLE check_steps ADD CONSTRAINT check_steps_time_check CHECK (finished_at >= started_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('check_steps');
        Schema::dropIfExists('check_attempts');
        Schema::dropIfExists('check_runs');
        Schema::dropIfExists('check_scenarios');
        Schema::dropIfExists('browser_workers');
    }
};
