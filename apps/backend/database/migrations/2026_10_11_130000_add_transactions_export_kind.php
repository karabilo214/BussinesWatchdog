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

        DB::statement('ALTER TABLE exports DROP CONSTRAINT exports_kind_check');
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_kind_check CHECK (kind IN ('findings','incidents','transactions'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE exports DROP CONSTRAINT exports_kind_check');
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_kind_check CHECK (kind IN ('findings','incidents'))");
    }
};
