<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->text('status')->default('active');
            $table->text('locale')->default('ru');
            $table->text('timezone')->default('Europe/Kyiv');
            $table->unsignedBigInteger('config_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active','suspended','deleting','deleted'))");
        DB::statement('ALTER TABLE tenants ADD CONSTRAINT tenants_config_version_check CHECK (config_version > 0)');

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->text('role');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['tenant_id', 'user_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['user_id', 'tenant_id'], 'memberships_user_idx');
        });

        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_role_check CHECK (role IN ('owner','admin','operator','viewer'))");

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('email');
            $table->text('role');
            $table->char('token_hash', 64)->unique();
            $table->uuid('invited_by');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('invited_by')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('admin','operator','viewer'))");

        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('name');
            $table->text('base_url');
            $table->text('platform')->default('woocommerce');
            $table->text('timezone');
            $table->text('locale')->default('ru');
            $table->string('default_currency', 3);
            $table->text('status')->default('onboarding');
            $table->timestampTz('verified_at')->nullable();
            $table->boolean('browser_enabled')->default(false);
            $table->boolean('telemetry_enabled')->default(false);
            $table->unsignedBigInteger('config_version')->default(1);
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status'], 'stores_tenant_idx');
        });

        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_base_url_check CHECK (base_url LIKE 'https://%')");
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_default_currency_check CHECK (default_currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE stores ADD CONSTRAINT stores_status_check CHECK (status IN ('onboarding','active','paused','degraded','deleted'))");
        DB::statement('ALTER TABLE stores ADD CONSTRAINT stores_config_version_check CHECK (config_version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('tenants');
    }
};
