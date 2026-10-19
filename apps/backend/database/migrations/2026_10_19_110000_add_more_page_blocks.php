<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const CATEGORIES = "('hero','features','steps','pricing','faq','cta','text','other','testimonials','logos','comparison','stats','team','gallery','contact')";

    /**
     * More built-in block templates (ADR 0046, composition confirmed by the owner on 2026-10-19). A template with the
     * same name that already exists is left alone.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE page_blocks DROP CONSTRAINT IF EXISTS page_blocks_category_check');
            DB::statement('ALTER TABLE page_blocks ADD CONSTRAINT page_blocks_category_check CHECK (category IN '.self::CATEGORIES.')');
        }

        $now = Carbon::now();
        $sort = (int) DB::table('page_blocks')->max('sort');

        foreach (require database_path('data/page_blocks_more.php') as $block) {
            if (DB::table('page_blocks')->where('name', $block['name'])->exists()) {
                continue;
            }

            $sort += 10;
            DB::table('page_blocks')->insert([
                'id' => (string) Str::uuid7(),
                'category' => $block['category'],
                'name' => $block['name'],
                'bodies' => json_encode($block['bodies'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('page_blocks')->whereIn('name', array_column(require database_path('data/page_blocks_more.php'), 'name'))->whereNull('updated_by')->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE page_blocks DROP CONSTRAINT IF EXISTS page_blocks_category_check');
            DB::statement("ALTER TABLE page_blocks ADD CONSTRAINT page_blocks_category_check CHECK (category IN ('hero','features','steps','pricing','faq','cta','text','other'))");
        }
    }
};
