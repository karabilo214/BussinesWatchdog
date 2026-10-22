<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_worker_heartbeats', function (Blueprint $table) {
            $table->text('worker')->primary();
            $table->text('connection');
            $table->text('queue');
            $table->timestampTz('seen_at');
            $table->timestampTz('busy_since')->nullable();
            $table->timestampTz('last_job_at')->nullable();
            $table->bigInteger('processed')->default(0);

            $table->index('seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_worker_heartbeats');
    }
};
