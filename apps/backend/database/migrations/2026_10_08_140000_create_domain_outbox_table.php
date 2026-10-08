<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('topic');
            $table->text('dedupe_key');
            $payloadDefault = DB::getDriverName() === 'pgsql'
                ? DB::raw("'{}'::jsonb")
                : '{}';
            $table->jsonb('payload')->default($payloadDefault);
            $table->text('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->useCurrent();
            $table->timestampTz('lease_until')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->text('error_code')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'topic', 'dedupe_key']);
            $table->index(['next_attempt_at'], 'outbox_due_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE domain_outbox ADD CONSTRAINT domain_outbox_status_check CHECK (status IN ('pending','leased','published','dead_letter'))");
            DB::statement('ALTER TABLE domain_outbox ADD CONSTRAINT domain_outbox_attempts_check CHECK (attempts >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_outbox');
    }
};
