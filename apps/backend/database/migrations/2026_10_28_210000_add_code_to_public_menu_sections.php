<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_menu_sections', function (Blueprint $table) {
            $table->string('code', 60)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('public_menu_sections', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
