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
            $table->uuid('menu_parent_id')->nullable();
            $table->jsonb('menu_labels')->nullable();
            $table->index('menu_parent_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public_pages ADD CONSTRAINT public_pages_menu_parent_id_foreign FOREIGN KEY (menu_parent_id) REFERENCES public_pages(id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public_pages DROP CONSTRAINT public_pages_menu_parent_id_foreign');
        }

        Schema::table('public_pages', function (Blueprint $table) {
            $table->dropIndex(['menu_parent_id']);
            $table->dropColumn(['menu_parent_id', 'menu_labels']);
        });
    }
};
