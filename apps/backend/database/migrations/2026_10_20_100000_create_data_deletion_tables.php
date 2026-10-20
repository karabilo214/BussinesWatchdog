<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('requested_by');
            $table->text('requested_via');
            $table->text('reason')->nullable();
            $table->text('previous_status');
            $table->text('status');
            $table->jsonb('cursor')->default('{}');
            $table->text('error_code')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('requested_at');
            $table->timestampTz('purge_after');
            $table->timestampTz('lease_until')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('requested_by')->references('id')->on('users');
            $table->foreign('cancelled_by')->references('id')->on('users');
            $table->index(['status', 'purge_after']);
            $table->index('tenant_id');
        });

        Schema::create('data_deletion_journal', function (Blueprint $table) {
            $table->uuid('request_id')->primary();
            $table->uuid('tenant_id');
            $table->text('requested_via');
            $table->timestampTz('requested_at');
            $table->timestampTz('completed_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE data_deletion_requests ADD CONSTRAINT data_deletion_requests_status_check CHECK (status IN ('requested','revoking','deleting_objects','purging','completed','cancelled','failed'))");
            DB::statement("ALTER TABLE data_deletion_requests ADD CONSTRAINT data_deletion_requests_via_check CHECK (requested_via IN ('owner','platform'))");
            DB::statement("CREATE UNIQUE INDEX data_deletion_requests_open_unique ON data_deletion_requests (tenant_id) WHERE status NOT IN ('completed','cancelled')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_journal');
        Schema::dropIfExists('data_deletion_requests');
    }
};
