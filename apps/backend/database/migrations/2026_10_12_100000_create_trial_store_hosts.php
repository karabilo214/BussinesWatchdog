<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_store_hosts', function (Blueprint $table) {
            $table->text('host')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->timestampTz('claimed_at');

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_store_hosts');
    }
};
