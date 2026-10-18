<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_alert_state', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->text('notified_state');
            $table->jsonb('problems')->nullable();
            $table->timestampTz('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_alert_state');
    }
};
