<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscription_contracts', function (Blueprint $table): void {
            $table->timestamp('access_revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscription_contracts', function (Blueprint $table): void {
            $table->dropColumn('access_revoked_at');
        });
    }
};
