<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE check_runs DROP CONSTRAINT IF EXISTS check_runs_trigger_check');
            DB::statement("ALTER TABLE check_runs ADD CONSTRAINT check_runs_trigger_check CHECK (trigger IN ('scheduled','manual','incident','support'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE check_runs SET trigger = 'manual' WHERE trigger = 'support'");
            DB::statement('ALTER TABLE check_runs DROP CONSTRAINT IF EXISTS check_runs_trigger_check');
            DB::statement("ALTER TABLE check_runs ADD CONSTRAINT check_runs_trigger_check CHECK (trigger IN ('scheduled','manual','incident'))");
        }
    }
};
