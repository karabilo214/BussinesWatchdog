<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pairing_codes', function (Blueprint $table): void {
            $table->string('code_challenge', 64)->nullable();
            $table->string('funnel_journey', 32)->nullable();
        });

        Schema::create('plugin_funnel_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('stage', 20);
            $table->string('journey', 32)->nullable();
            $table->string('placement', 32)->nullable();
            $table->string('path', 16)->nullable();
            $table->string('locale', 2)->nullable();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('store_id')->nullable();
            $table->string('dedupe_key', 120)->unique();
            $table->timestampTz('created_at');
            $table->index(['created_at', 'stage']);
            $table->index(['tenant_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_funnel_events');

        Schema::table('pairing_codes', function (Blueprint $table): void {
            $table->dropColumn(['code_challenge', 'funnel_journey']);
        });
    }
};
