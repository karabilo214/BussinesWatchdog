<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('created_by')->nullable();
            $table->text('kind');
            $table->text('format');
            $table->json('filters');
            $table->text('state');
            $table->text('object_key')->unique()->nullable();
            $table->text('content_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('error_code')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['tenant_id', 'store_id', 'created_at'], 'exports_store_created_idx');
            $table->index(['state', 'expires_at'], 'exports_retention_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_kind_check CHECK (kind IN ('findings','incidents'))");
            DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_format_check CHECK (format IN ('csv'))");
            DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_state_check CHECK (state IN ('processing','ready','failed','deleted'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
