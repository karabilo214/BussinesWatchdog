<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->uuid('attempt_id');
            $table->text('kind');
            $table->text('object_key')->unique();
            $table->text('content_type');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->text('redaction_version');
            $table->text('state');
            $table->timestampTz('expires_at');
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'store_id', 'id']);
            $table->foreign(['tenant_id', 'store_id', 'attempt_id'])->references(['tenant_id', 'store_id', 'id'])->on('check_attempts');
            $table->index(['state', 'expires_at'], 'artifacts_retention_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE artifacts ADD CONSTRAINT artifacts_kind_check CHECK (kind IN ('screenshot','trace','diagnostics'))");
            DB::statement("ALTER TABLE artifacts ADD CONSTRAINT artifacts_state_check CHECK (state IN ('pending','ready','rejected','deleted'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
