<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->text('photo_key')->nullable();
            $table->timestampTz('photo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_staff', function (Blueprint $table) {
            $table->dropColumn(['photo_key', 'photo_updated_at']);
        });
    }
};
