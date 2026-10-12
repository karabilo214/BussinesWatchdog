<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_order_volumes', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->date('month');
            $table->unsignedBigInteger('orders')->default(0);
            $table->timestampTz('updated_at')->nullable();

            $table->primary(['store_id', 'month']);
            $table->index(['tenant_id', 'month']);
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedBigInteger('order_volume_average')->nullable();
            $table->timestampTz('order_volume_checked_at')->nullable();
            $table->timestampTz('order_volume_over_since')->nullable();
            $table->timestampTz('order_volume_notified_at')->nullable();
        });

        Schema::table('billing_plan_versions', function (Blueprint $table) {
            $table->unsignedSmallInteger('order_volume_grace_days')->default(30);
            $table->boolean('contact_sales')->default(false);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE billing_plan_versions ADD CONSTRAINT billing_plan_versions_grace_check CHECK (order_volume_grace_days BETWEEN 0 AND 365)');
        }
    }

    public function down(): void
    {
        Schema::table('billing_plan_versions', function (Blueprint $table) {
            $table->dropColumn(['order_volume_grace_days', 'contact_sales']);
        });
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['order_volume_average', 'order_volume_checked_at', 'order_volume_over_since', 'order_volume_notified_at']);
        });
        Schema::dropIfExists('store_order_volumes');
    }
};
