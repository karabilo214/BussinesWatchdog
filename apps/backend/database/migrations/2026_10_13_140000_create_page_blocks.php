<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('category');
            $table->text('name');
            $table->jsonb('bodies');
            $table->integer('sort')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
            $table->timestampTz('archived_at')->nullable();

            $table->index(['archived_at', 'sort']);
            $table->foreign('created_by')->references('id')->on('platform_staff');
            $table->foreign('updated_by')->references('id')->on('platform_staff');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE page_blocks ADD CONSTRAINT page_blocks_category_check CHECK (category IN ('hero','features','steps','pricing','faq','cta','text','other'))");
        }

        $now = Carbon::now();

        foreach (require database_path('data/page_blocks.php') as $index => $block) {
            DB::table('page_blocks')->insert([
                'id' => (string) Str::uuid7(),
                'category' => $block['category'],
                'name' => $block['name'],
                'bodies' => json_encode($block['bodies'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_blocks');
    }
};
