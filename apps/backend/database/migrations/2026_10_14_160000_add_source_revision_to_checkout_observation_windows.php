<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_observation_windows', function (Blueprint $table) {
            $table->unsignedBigInteger('source_revision')->nullable();
            $table->uuid('event_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_observation_windows', function (Blueprint $table) {
            $table->dropColumn(['source_revision', 'event_id']);
        });
    }
};
