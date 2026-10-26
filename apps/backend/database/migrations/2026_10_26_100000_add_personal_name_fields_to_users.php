<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('salutation')->nullable();
            $table->text('first_name')->nullable();
            $table->text('last_name')->nullable();
            $table->text('company_name')->nullable();
        });

        DB::table('users')->update(['last_name' => DB::raw('name')]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN last_name SET NOT NULL');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_salutation_check CHECK (salutation IS NULL OR salutation IN ('mr','ms','diverse'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_salutation_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['salutation', 'first_name', 'last_name', 'company_name']);
        });
    }
};
