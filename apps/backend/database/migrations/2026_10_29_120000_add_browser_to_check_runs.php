<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_runs', function (Blueprint $table) {
            $table->text('browser')->default('chromium');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE check_runs ADD CONSTRAINT check_runs_browser_check CHECK (browser IN ('chromium','firefox','webkit'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE check_runs DROP CONSTRAINT IF EXISTS check_runs_browser_check');
        }

        Schema::table('check_runs', function (Blueprint $table) {
            $table->dropColumn('browser');
        });
    }
};
