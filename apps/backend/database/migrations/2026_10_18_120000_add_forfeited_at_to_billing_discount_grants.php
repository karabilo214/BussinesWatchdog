<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_discount_grants', function (Blueprint $table) {
            $table->timestampTz('forfeited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_discount_grants', function (Blueprint $table) {
            $table->dropColumn('forfeited_at');
        });
    }
};
