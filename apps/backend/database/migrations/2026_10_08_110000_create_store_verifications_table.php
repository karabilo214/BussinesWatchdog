<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->text('method');
            $table->char('challenge_hash', 64);
            $table->text('verified_origin');
            $table->text('status')->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->index(['tenant_id', 'store_id', 'status'], 'store_verifications_store_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE store_verifications ADD CONSTRAINT store_verifications_method_check CHECK (method IN ('wordpress_challenge','dns'))");
            DB::statement("ALTER TABLE store_verifications ADD CONSTRAINT store_verifications_status_check CHECK (status IN ('pending','verified','expired','failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_verifications');
    }
};
