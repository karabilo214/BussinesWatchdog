<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_verifications', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_checked_at')->nullable();
            $table->text('last_error_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_verifications', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'last_checked_at', 'last_error_code']);
        });
    }
};
