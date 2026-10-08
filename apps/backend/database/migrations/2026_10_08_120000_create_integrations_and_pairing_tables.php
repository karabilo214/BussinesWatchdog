<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->text('provider');
            $table->text('external_account_id')->nullable();
            $table->uuid('install_id')->nullable();
            $table->text('mode')->default('live');
            $table->text('source_authority');
            $table->text('status')->default('pending');
            $capabilitiesDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('capabilities')->default($capabilitiesDefault);
            $table->text('api_version')->nullable();
            $table->text('connector_version');
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('last_successful_sync_at')->nullable();
            $healthDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('health')->default($healthDefault);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->index(['status', 'last_successful_sync_at'], 'integrations_sync_idx');
        });

        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->text('kind');
            $table->text('key_id')->unique();
            $table->text('ciphertext');
            $table->unsignedInteger('key_version');
            $table->text('fingerprint');
            $table->text('status')->default('active');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'store_id', 'integration_id'])->references(['tenant_id', 'store_id', 'id'])->on('integrations');
        });

        Schema::create('pairing_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->char('code_hash', 64)->unique();
            $table->uuid('created_by');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->text('actor_type');
            $table->text('action');
            $table->text('entity_type');
            $table->uuid('entity_id');
            $changesDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('changes')->default($changesDefault);
            $table->uuid('request_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'created_at'], 'audit_log_tenant_time_idx');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'audit_log_entity_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE integrations ADD CONSTRAINT integrations_provider_check CHECK (provider ~ '^[a-z][a-z0-9_]{1,63}$')");
            DB::statement("ALTER TABLE integrations ADD CONSTRAINT integrations_mode_check CHECK (mode IN ('live','test'))");
            DB::statement("ALTER TABLE integrations ADD CONSTRAINT integrations_source_authority_check CHECK (source_authority IN ('store_reported','independent_provider'))");
            DB::statement("ALTER TABLE integrations ADD CONSTRAINT integrations_status_check CHECK (status IN ('pending','active','degraded','revoked','disabled'))");
            DB::statement("CREATE UNIQUE INDEX one_active_store_provider_integration ON integrations(store_id, provider) WHERE status IN ('pending','active','degraded')");
            DB::statement("ALTER TABLE integration_credentials ADD CONSTRAINT integration_credentials_kind_check CHECK (kind IN ('plugin_hmac','stripe_api','stripe_webhook'))");
            DB::statement('ALTER TABLE integration_credentials ADD CONSTRAINT integration_credentials_key_version_check CHECK (key_version > 0)');
            DB::statement("ALTER TABLE integration_credentials ADD CONSTRAINT integration_credentials_status_check CHECK (status IN ('active','draining','revoked'))");
            DB::statement('ALTER TABLE pairing_codes ADD CONSTRAINT pairing_codes_attempt_count_check CHECK (attempt_count >= 0)');
            DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_actor_type_check CHECK (actor_type IN ('user','connector','system'))");
            DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_action_check CHECK (action ~ '^[a-z][a-z0-9_.]{1,127}$')");
            DB::statement("ALTER TABLE audit_log ADD CONSTRAINT audit_log_entity_type_check CHECK (entity_type ~ '^[a-z][a-z0-9_]{1,63}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_codes');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('integration_credentials');
        Schema::dropIfExists('integrations');
    }
};
