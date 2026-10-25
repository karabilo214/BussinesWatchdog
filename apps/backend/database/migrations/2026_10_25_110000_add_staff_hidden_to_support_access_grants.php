<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_access_grants', function (Blueprint $table) {
            $table->timestampTz('staff_hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_access_grants', function (Blueprint $table) {
            $table->dropColumn('staff_hidden_at');
        });
    }
};
