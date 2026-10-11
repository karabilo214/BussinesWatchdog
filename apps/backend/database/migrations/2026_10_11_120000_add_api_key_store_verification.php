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

        DB::statement('ALTER TABLE store_verifications DROP CONSTRAINT store_verifications_method_check');
        DB::statement("ALTER TABLE store_verifications ADD CONSTRAINT store_verifications_method_check CHECK (method IN ('plugin_challenge','dns','api_key'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE store_verifications DROP CONSTRAINT store_verifications_method_check');
        DB::statement("ALTER TABLE store_verifications ADD CONSTRAINT store_verifications_method_check CHECK (method IN ('plugin_challenge','dns'))");
    }
};
