<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE provider_disputes DROP CONSTRAINT provider_disputes_status_check');
        DB::statement("ALTER TABLE provider_disputes ADD CONSTRAINT provider_disputes_status_check CHECK (status IN ('needs_response','under_review','won','lost','warning_needs_response','warning_under_review','warning_closed','prevented','closed'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE provider_disputes DROP CONSTRAINT provider_disputes_status_check');
        DB::statement("ALTER TABLE provider_disputes ADD CONSTRAINT provider_disputes_status_check CHECK (status IN ('needs_response','under_review','won','lost','warning_needs_response','warning_under_review','warning_closed','prevented'))");
    }
};
