<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_storefront_checks', function (Blueprint $table): void {
            $table->string('request_ip', 45)->nullable();
            $table->text('request_user_agent')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->index(['created_at', 'id'], 'public_storefront_checks_history_idx');
        });
    }

    public function down(): void
    {
        Schema::table('public_storefront_checks', function (Blueprint $table): void {
            $table->dropIndex('public_storefront_checks_history_idx');
            $table->dropColumn(['request_ip', 'request_user_agent', 'purged_at']);
        });
    }
};
