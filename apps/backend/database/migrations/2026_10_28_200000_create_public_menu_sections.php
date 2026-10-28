<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_menu_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('menu', 20);
            $table->jsonb('labels');
            $table->integer('sort')->default(0);
            $table->string('footer_key', 20)->nullable()->unique();
            $table->timestampsTz();
        });

        foreach ([
            'product' => ['ru' => 'Продукт', 'en' => 'Product', 'de' => 'Produkt'],
            'company' => ['ru' => 'О сервисе', 'en' => 'About us', 'de' => 'Über uns'],
            'legal' => ['ru' => 'Правовая информация', 'en' => 'Legal information', 'de' => 'Rechtliche Informationen'],
        ] as $key => $labels) {
            DB::table('public_menu_sections')->insert(['id' => (string) Str::uuid7(), 'menu' => 'footer', 'labels' => json_encode($labels, JSON_THROW_ON_ERROR), 'sort' => array_search($key, ['product', 'company', 'legal'], true), 'footer_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('public_pages', function (Blueprint $table) {
            $table->uuid('menu_section_id')->nullable();
            $table->index('menu_section_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public_pages ADD CONSTRAINT public_pages_menu_section_id_foreign FOREIGN KEY (menu_section_id) REFERENCES public_menu_sections(id) ON DELETE SET NULL');
            DB::statement("ALTER TABLE public_menu_sections ADD CONSTRAINT public_menu_sections_menu_check CHECK (menu IN ('header', 'footer'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public_pages DROP CONSTRAINT public_pages_menu_section_id_foreign');
        }
        Schema::table('public_pages', function (Blueprint $table) {
            $table->dropIndex(['menu_section_id']);
            $table->dropColumn('menu_section_id');
        });
        Schema::dropIfExists('public_menu_sections');
    }
};
