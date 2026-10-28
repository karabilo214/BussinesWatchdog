<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_pages', function (Blueprint $table) {
            $table->string('footer_group', 20)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE public_pages ADD CONSTRAINT public_pages_footer_group_check CHECK (footer_group IS NULL OR footer_group IN ('product', 'company', 'legal'))");
        }
    }

    public function down(): void
    {
        Schema::table('public_pages', function (Blueprint $table) {
            $table->dropColumn('footer_group');
        });
    }
};
