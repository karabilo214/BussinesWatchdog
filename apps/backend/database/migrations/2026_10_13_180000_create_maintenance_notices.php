<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version')->unique();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->jsonb('messages');
            $table->uuid('created_by');
            $table->text('reason');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();

            $table->index(['cancelled_at', 'ends_at']);
            $table->foreign('created_by')->references('id')->on('platform_staff');
            $table->foreign('cancelled_by')->references('id')->on('platform_staff');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_notices');
    }
};
