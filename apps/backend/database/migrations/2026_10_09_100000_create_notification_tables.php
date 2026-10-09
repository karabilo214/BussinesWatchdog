<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $jsonDefault = DB::getDriverName() === 'pgsql'
            ? DB::raw("'{}'::jsonb")
            : '{}';

        Schema::create('notification_channels', function (Blueprint $table) use ($jsonDefault) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('kind');
            $table->text('destination_ciphertext');
            $table->unsignedInteger('key_version');
            $table->text('label');
            $table->boolean('enabled')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->jsonb('preferences');
            $table->jsonb('health')->default($jsonDefault);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('notification_channel_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_id');
            $table->text('code_hash');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'channel_id'])->references(['tenant_id', 'id'])->on('notification_channels');
            $table->index(['tenant_id', 'channel_id', 'created_at'], 'notification_channel_verifications_lookup_idx');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) use ($jsonDefault) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('incident_id')->nullable();
            $table->uuid('channel_id');
            $table->text('notification_kind');
            $table->text('dedupe_key');
            $table->unsignedBigInteger('incident_revision')->nullable();
            $table->text('template_version');
            $table->jsonb('sanitized_content')->default($jsonDefault);
            $table->text('status')->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at');
            $table->text('provider_message_id')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('error_code')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'channel_id', 'dedupe_key']);
            $table->foreign(['tenant_id', 'channel_id'])->references(['tenant_id', 'id'])->on('notification_channels');
            $table->foreign(['tenant_id', 'incident_id'])->references(['tenant_id', 'id'])->on('incidents');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE notification_channels ADD CONSTRAINT notification_channels_kind_check CHECK (kind IN ('email','telegram'))");
            DB::statement('ALTER TABLE notification_channels ADD CONSTRAINT notification_channels_key_version_check CHECK (key_version > 0)');

            DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('queued','sending','sent','uncertain','failed','dead_letter','suppressed'))");
            DB::statement('ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_attempts_check CHECK (attempts >= 0)');
            DB::statement("CREATE INDEX deliveries_due_idx ON notification_deliveries(next_attempt_at) WHERE status IN ('queued','failed')");
        } else {
            Schema::table('notification_deliveries', function (Blueprint $table) {
                $table->index(['status', 'next_attempt_at'], 'deliveries_due_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_channel_verifications');
        Schema::dropIfExists('notification_channels');
    }
};
