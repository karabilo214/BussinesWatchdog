<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopware_app_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('shop_id', 64)->unique();
            $table->string('shop_url', 2048);
            $table->string('app_version', 16)->nullable();
            $table->text('secrets');
            $table->string('status', 16);
            $table->foreignUuid('integration_id')->nullable()->constrained('integrations');
            $table->timestampTz('registered_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('bound_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'registered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopware_app_installations');
    }
};
