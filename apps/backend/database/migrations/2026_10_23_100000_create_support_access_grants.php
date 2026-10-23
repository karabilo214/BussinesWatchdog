<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('staff_id');
            $table->uuid('requested_by_staff_id')->nullable();
            $table->uuid('requested_by_user_id')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->jsonb('scopes');
            $table->string('reason', 500);
            $table->string('case_reference', 120);
            $table->string('state', 16)->default('pending');
            $table->unsignedSmallInteger('duration_hours')->default(24);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->foreign('staff_id')->references('id')->on('platform_staff');
            $table->foreign('requested_by_staff_id')->references('id')->on('platform_staff');
            $table->foreign('requested_by_user_id')->references('id')->on('users');
            $table->foreign('approved_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'store_id', 'staff_id', 'state', 'expires_at'], 'support_grants_access_idx');
            $table->index(['tenant_id', 'created_at'], 'support_grants_tenant_time_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE support_access_grants ADD CONSTRAINT support_grants_state_check CHECK (state IN ('pending','approved','denied','revoked','expired'))");
            DB::statement('ALTER TABLE support_access_grants ADD CONSTRAINT support_grants_duration_check CHECK (duration_hours BETWEEN 1 AND 72)');
            DB::statement('ALTER TABLE support_access_grants ADD CONSTRAINT support_grants_requester_check CHECK ((requested_by_staff_id IS NOT NULL) <> (requested_by_user_id IS NOT NULL))');
            DB::statement('ALTER TABLE support_access_grants ADD CONSTRAINT support_grants_version_check CHECK (version > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_grants');
    }
};
