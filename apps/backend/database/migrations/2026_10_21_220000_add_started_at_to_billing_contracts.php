<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscription_contracts', fn (Blueprint $table) => $table->timestampTz('started_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('billing_subscription_contracts', fn (Blueprint $table) => $table->dropColumn('started_at'));
    }
};
