<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_observation_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('integration_id');
            $table->timestampTz('window_start');
            $table->unsignedInteger('confirm_rendered')->default(0);
            $table->unsignedInteger('confirm_rendered_noscript')->default(0);
            $table->unsignedInteger('finish_rendered')->default(0);
            $table->unsignedInteger('script_loaded')->default(0);
            $table->unsignedInteger('form_ready')->default(0);
            $table->unsignedInteger('returned_with_error')->default(0);
            $table->unsignedInteger('submit_clicked')->default(0);
            $table->unsignedInteger('submit_stalled')->default(0);
            $table->unsignedInteger('finish_reached')->default(0);
            $table->unsignedInteger('js_errors')->default(0);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['store_id', 'window_start'], 'checkout_observation_windows_store_window_unique');
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_observation_windows');
    }
};
