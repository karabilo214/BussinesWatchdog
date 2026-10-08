<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->text('signal_type');
            $table->text('family');
            $table->text('component');
            $table->text('dedupe_key');
            $table->text('severity');
            $table->text('confidence');
            $table->text('rule_version');
            $table->unsignedBigInteger('config_version');
            $table->string('currency', 3)->nullable();
            $table->uuid('finding_id')->nullable();
            $table->uuid('check_run_id')->nullable();
            $table->uuid('baseline_id')->nullable();
            $table->timestampTz('observed_start')->nullable();
            $table->timestampTz('observed_end')->nullable();
            $jsonDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('evidence')->default($jsonDefault);
            $table->jsonb('data_quality')->default($jsonDefault);
            $table->timestampTz('detected_at');

            $table->unique(['tenant_id', 'dedupe_key']);
            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->foreign(['tenant_id', 'store_id', 'finding_id'])->references(['tenant_id', 'store_id', 'id'])->on('reconciliation_findings');
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->text('family');
            $table->text('component');
            $table->text('fingerprint');
            $table->text('state')->default('open');
            $table->text('severity');
            $table->text('title_code');
            $table->string('currency', 3)->nullable();
            $table->bigInteger('verified_discrepancy_minor')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('last_good_at')->nullable();
            $table->timestampTz('first_bad_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('incident_signals', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('incident_id');
            $table->uuid('signal_id');
            $table->text('association_reason');
            $table->timestampTz('linked_at')->useCurrent();

            $table->primary(['incident_id', 'signal_id']);
            $table->foreign(['tenant_id', 'store_id', 'incident_id'])->references(['tenant_id', 'store_id', 'id'])->on('incidents');
            $table->foreign(['tenant_id', 'store_id', 'signal_id'])->references(['tenant_id', 'store_id', 'id'])->on('signals');
        });

        Schema::create('incident_activity', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('incident_id');
            $table->text('kind');
            $table->uuid('actor_id')->nullable();
            $table->unsignedBigInteger('incident_revision');
            $sanitizedDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('sanitized_data')->default($sanitizedDefault);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'store_id', 'incident_id'])->references(['tenant_id', 'store_id', 'id'])->on('incidents');
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'store_id', 'incident_id', 'created_at'], 'incident_activity_timeline_idx');
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('incident_id')->nullable();
            $scopeDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('scope')->default($scopeDefault);
            $table->text('reason');
            $table->uuid('created_by');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->foreign(['tenant_id', 'store_id', 'incident_id'])->references(['tenant_id', 'store_id', 'id'])->on('incidents');
            $table->foreign('created_by')->references('id')->on('users');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE signals ADD CONSTRAINT signals_severity_check CHECK (severity IN ('info','warning','critical'))");
            DB::statement("ALTER TABLE signals ADD CONSTRAINT signals_confidence_check CHECK (confidence IN ('observed','corroborated','inferred','unknown'))");
            DB::statement("ALTER TABLE signals ADD CONSTRAINT signals_currency_check CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE signals ADD CONSTRAINT signals_observed_window_check CHECK (observed_end IS NULL OR observed_start IS NULL OR observed_end >= observed_start)');

            DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_state_check CHECK (state IN ('open','acknowledged','resolved'))");
            DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_severity_check CHECK (severity IN ('info','warning','critical'))");
            DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_currency_check CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$')");
            DB::statement('ALTER TABLE incidents ADD CONSTRAINT incidents_revision_check CHECK (revision > 0)');
            DB::statement('ALTER TABLE incidents ADD CONSTRAINT incidents_seen_window_check CHECK (last_seen_at >= first_seen_at)');
            DB::statement('CREATE UNIQUE INDEX incidents_active_fingerprint ON incidents(tenant_id, store_id, fingerprint) WHERE state IN (\'open\',\'acknowledged\')');
            DB::statement('CREATE INDEX incidents_list_idx ON incidents(tenant_id, store_id, state, last_seen_at DESC)');

            DB::statement("ALTER TABLE incident_activity ADD CONSTRAINT incident_activity_kind_check CHECK (kind IN ('created','signal_linked','acknowledged','comment','resolved','reopened','severity_changed','suppressed'))");

            DB::statement('ALTER TABLE suppressions ADD CONSTRAINT suppressions_window_check CHECK (ends_at > starts_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('incident_activity');
        Schema::dropIfExists('incident_signals');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('signals');
    }
};
