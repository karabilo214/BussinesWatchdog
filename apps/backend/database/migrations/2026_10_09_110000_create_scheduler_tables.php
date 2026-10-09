<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_dirty_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('store_id');
            $table->text('subject_type');
            $table->uuid('subject_id');
            $table->text('reason');
            $table->unsignedBigInteger('mark_version')->default(1);
            $table->timestampTz('first_marked_at');
            $table->timestampTz('last_marked_at');
            $table->timestampTz('due_at');
            $table->timestampTz('lease_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_code')->nullable();

            $table->unique(['tenant_id', 'subject_type', 'subject_id']);
            $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores');
            $table->index(['due_at'], 'reconciliation_dirty_due_idx');
        });

        Schema::create('scheduled_job_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('job');
            $table->text('window_key');
            $table->text('status');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->text('error_code')->nullable();
            $table->jsonb('result')->nullable();

            $table->unique(['job', 'window_key']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reconciliation_dirty_subjects ADD CONSTRAINT reconciliation_dirty_subjects_type_check CHECK (subject_type IN ('order','store_unmatched_payments'))");
            DB::statement("ALTER TABLE scheduled_job_windows ADD CONSTRAINT scheduled_job_windows_status_check CHECK (status IN ('running','completed','failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_job_windows');
        Schema::dropIfExists('reconciliation_dirty_subjects');
    }
};
