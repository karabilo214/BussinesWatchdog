<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_case_counters', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->unsignedBigInteger('next_number');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE support_case_counters ADD CONSTRAINT support_case_next_number_check CHECK (next_number > 0)');
        }

        // Keep numbers previously entered in this format from being reused after the upgrade.
        $highest = 0;
        foreach (DB::table('support_access_grants')->pluck('case_reference') as $reference) {
            if (is_string($reference) && preg_match('/^SUP-(\d{1,17})$/', $reference, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        DB::table('support_case_counters')->insert(['name' => 'support_access', 'next_number' => $highest + 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_counters');
    }
};
