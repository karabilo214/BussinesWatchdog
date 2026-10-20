<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_deletion_journal', function (Blueprint $table): void {
            $table->uuid('store_id')->primary();
            $table->uuid('tenant_id');
            $table->timestampTz('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_deletion_journal');
    }
};
