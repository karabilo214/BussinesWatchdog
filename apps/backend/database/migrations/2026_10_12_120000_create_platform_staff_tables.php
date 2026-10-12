<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->text('role');
            $table->text('state')->default('active');
            $table->boolean('mfa_required')->default(true);
            $table->unsignedBigInteger('permission_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('disabled_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('platform_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_staff_id')->nullable();
            $table->text('actor_kind');
            $table->text('action');
            $table->uuid('target_tenant_id')->nullable();
            $table->text('target_type');
            $table->text('target_id');
            $table->text('reason')->nullable();
            $table->uuid('request_id');
            $table->jsonb('sanitized_changes');
            $table->text('result');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('actor_staff_id')->references('id')->on('platform_staff');
            $table->index('created_at', 'platform_audit_time_idx');
            $table->index(['target_tenant_id', 'created_at'], 'platform_audit_target_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE platform_staff ADD CONSTRAINT platform_staff_role_check CHECK (role IN ('platform_owner','platform_admin','platform_support','platform_billing'))");
            DB::statement("ALTER TABLE platform_staff ADD CONSTRAINT platform_staff_state_check CHECK (state IN ('active','disabled'))");
            DB::statement('ALTER TABLE platform_staff ADD CONSTRAINT platform_staff_mfa_check CHECK (mfa_required = true)');
            DB::statement('ALTER TABLE platform_staff ADD CONSTRAINT platform_staff_permission_version_check CHECK (permission_version > 0)');
            DB::statement("ALTER TABLE platform_audit_log ADD CONSTRAINT platform_audit_actor_check CHECK (actor_kind IN ('staff','system'))");
            DB::statement("ALTER TABLE platform_audit_log ADD CONSTRAINT platform_audit_result_check CHECK (result IN ('requested','succeeded','failed','denied','unknown'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_log');
        Schema::dropIfExists('platform_staff');
    }
};
