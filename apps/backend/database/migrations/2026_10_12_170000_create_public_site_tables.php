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
        Schema::create('public_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('kind');
            $table->uuid('parent_id')->nullable();
            $table->text('slug')->nullable();
            $table->text('path');
            $table->text('menu')->default('none');
            $table->integer('sort')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
            $table->timestampTz('archived_at')->nullable();

            $table->foreign('created_by')->references('id')->on('platform_staff');
        });

        Schema::table('public_pages', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('public_pages');
        });

        DB::statement('CREATE UNIQUE INDEX public_pages_path_unique ON public_pages(path) WHERE archived_at IS NULL');
        DB::statement("CREATE UNIQUE INDEX public_pages_system_unique ON public_pages(kind) WHERE kind <> 'page'");

        Schema::create('public_page_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('page_id');
            $table->text('locale');
            $table->unsignedInteger('version');
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('body');
            $table->char('content_hash', 64);
            $table->text('state');
            $table->uuid('created_by')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();

            $table->unique(['page_id', 'locale', 'version']);
            $table->foreign('page_id')->references('id')->on('public_pages');
            $table->foreign('created_by')->references('id')->on('platform_staff');
            $table->foreign('published_by')->references('id')->on('platform_staff');
        });

        DB::statement("CREATE UNIQUE INDEX public_page_versions_published ON public_page_versions(page_id, locale) WHERE state = 'published'");
        DB::statement("CREATE UNIQUE INDEX public_page_versions_draft ON public_page_versions(page_id, locale) WHERE state = 'draft'");

        Schema::create('contact_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('locale');
            $table->text('name');
            $table->text('email');
            $table->text('company')->nullable();
            $table->text('message');
            $table->text('status')->default('new');
            $table->text('note')->nullable();
            $table->uuid('handled_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('notified_at')->nullable();

            $table->index(['status', 'created_at']);
            $table->index('notified_at');
            $table->foreign('handled_by')->references('id')->on('platform_staff');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE public_pages ADD CONSTRAINT public_pages_kind_check CHECK (kind IN ('home','pricing','contact','page'))");
            DB::statement("ALTER TABLE public_pages ADD CONSTRAINT public_pages_menu_check CHECK (menu IN ('none','header','footer'))");
            DB::statement("ALTER TABLE public_pages ADD CONSTRAINT public_pages_slug_check CHECK ((kind = 'home' AND slug IS NULL AND path = '') OR (kind <> 'home' AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'))");
            DB::statement("ALTER TABLE public_page_versions ADD CONSTRAINT public_page_versions_locale_check CHECK (locale IN ('ru','en','de'))");
            DB::statement("ALTER TABLE public_page_versions ADD CONSTRAINT public_page_versions_state_check CHECK (state IN ('draft','published','retired'))");
            DB::statement("ALTER TABLE public_page_versions ADD CONSTRAINT public_page_versions_published_check CHECK (state = 'draft' OR published_at IS NOT NULL)");
            DB::statement("ALTER TABLE contact_requests ADD CONSTRAINT contact_requests_status_check CHECK (status IN ('new','in_progress','closed'))");
            DB::statement("ALTER TABLE contact_requests ADD CONSTRAINT contact_requests_locale_check CHECK (locale IN ('ru','en','de'))");
        }

        $now = Carbon::now();

        foreach ([['home', null, '', 0], ['pricing', 'pricing', 'pricing', 10], ['contact', 'contact', 'contact', 20]] as [$kind, $slug, $path, $sort]) {
            DB::table('public_pages')->insert([
                'id' => (string) Str::uuid7(),
                'kind' => $kind,
                'slug' => $slug,
                'path' => $path,
                'menu' => $kind === 'home' ? 'none' : 'header',
                'sort' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_requests');
        Schema::dropIfExists('public_page_versions');
        Schema::dropIfExists('public_pages');
    }
};
