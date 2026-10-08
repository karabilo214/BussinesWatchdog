<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('actor_user_id')->nullable();
            $table->text('route');
            $table->text('idempotency_key');
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('expires_at');

            $table->unique(['tenant_id', 'route', 'idempotency_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['expires_at'], 'idempotency_keys_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
