<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->timestampTz('capacity_last_paused_from')->nullable();
            $table->timestampTz('capacity_last_resumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['capacity_last_paused_from', 'capacity_last_resumed_at']);
        });
    }
};
