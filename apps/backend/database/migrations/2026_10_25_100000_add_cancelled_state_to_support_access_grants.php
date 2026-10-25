<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE support_access_grants DROP CONSTRAINT support_grants_state_check');
            DB::statement("ALTER TABLE support_access_grants ADD CONSTRAINT support_grants_state_check CHECK (state IN ('pending','approved','denied','revoked','expired','cancelled'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE support_access_grants DROP CONSTRAINT support_grants_state_check');
            DB::statement("ALTER TABLE support_access_grants ADD CONSTRAINT support_grants_state_check CHECK (state IN ('pending','approved','denied','revoked','expired'))");
        }
    }
};
