<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_app_oauth_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('actor_user_id');
            $table->char('state_hash', 64)->unique();
            $table->string('mode', 8);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at');
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->foreign('actor_user_id')->references('id')->on('users');
            $table->index(['tenant_id', 'store_id', 'expires_at']);
        });

        Schema::create('stripe_app_oauth_connections', function (Blueprint $table): void {
            $table->uuid('integration_id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->text('access_ciphertext');
            $table->text('refresh_ciphertext');
            $table->unsignedInteger('key_version');
            $table->timestampTz('access_expires_at');
            $table->timestampTz('refresh_expires_at');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->foreign(['tenant_id', 'store_id', 'integration_id'])->references(['tenant_id', 'store_id', 'id'])->on('integrations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_app_oauth_connections');
        Schema::dropIfExists('stripe_app_oauth_attempts');
    }
};
